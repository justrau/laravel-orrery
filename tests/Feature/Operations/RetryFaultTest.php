<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\OperationRunner;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Phases\OperationRetentionPhase;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Operations\Fixtures\ThrowingOperationsPdo;
use Justrau\Orrery\Tests\Feature\Operations\Fixtures\ThrowingRollbackPdo;
use Justrau\Orrery\Tests\Support\LostAckCommitPdo;
use Justrau\Orrery\Tests\Support\SessionPdo;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Throwable;

/**
 * Faults of the mirror and of the sessions around a retry:
 * every error that leaves the service is a
 * code, a mirror failure never defers a target, a failed rollback releases
 * its session, and the fence retention skips the connections that the
 * runner deferred. Kind: new behaviour.
 */
final class RetryFaultTest extends RetryProtocolTestCase
{
    /**
     * Rejects: a mirror error that escapes retry() after a committed claim: the mirror fails right after the claim;
     * the result is uncertain / claim_uncertain with the operation id, and the runner ends it after the outage.
     */
    public function test_mirror_outage_after_the_claim_is_uncertain(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $outage = null;
        $start = function () use (&$outage): void {
            $outage = $this->mirrorOutage();
        };
        Hooks::$callback = static function (string $point) use ($start): void {
            if ($point === RetryService::AFTER_CLAIM) {
                $start();
            }
        };

        try {
            $outcome = $this->retry($uuid, $occurrence);
        } finally {
            Hooks::$callback = null;
            $outage?->end();
        }

        self::assertOutcome(RetryResult::UNCERTAIN, 'claim_uncertain', $outcome);
        self::assertNotNull($outcome->operationId);
        self::assertSame(Operations::CLAIMED, $this->operation($outcome->operationId)->state);

        $this->moveDatabaseClocks(120);
        $this->finishByRunnerOrOperator($outcome->operationId);
        self::assertSame(Operations::ABORTED, $this->operation($outcome->operationId)->state);
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a mirror error that escapes retry() after a resolution that read `published`: the publication's
     * COMMIT acknowledgement is lost, the resolution reads `published`, and the mirror refuses the operation's
     * update; the result is accepted with the job id, and after the recovery the runner ends it done in cycle 2.
     */
    public function test_mirror_failure_after_a_published_resolution_is_accepted(): void
    {
        $this->needsSharedDatabase();
        [$uuid, $occurrence] = $this->failedJob();
        $sessions = $this->app->make(PackageSession::class);
        $mirrorName = (string) $sessions->mirror()->getName();
        $mirrorConfig = $this->mirrorConfig();
        $queueConfig = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY);
        $done = false;
        Hooks::$callback = static function (string $point) use ($sessions, $mirrorName, $mirrorConfig, $queueConfig, &$done): void {
            if ($done || $point !== Publication::BEFORE_FENCE_INSERT) {
                return;
            }

            $done = true;
            PackageSession::usePdoFactoryForTesting(static fn (string $session): ?\PDO => $session === $mirrorName ? ThrowingOperationsPdo::forConfig($mirrorConfig) : null);
            $queue = $sessions->forAction(TestEnvironment::PRIMARY);

            if ($queue->getName() !== $mirrorName) {
                // A mirror of its own gets its new PDO now; one shared with Q gets it after the publication's release.
                $sessions->disconnect($sessions->mirror());
            }

            $queue->setPdo(SessionPdo::for($queue, LostAckCommitPdo::forConfig($queueConfig)));
        };

        try {
            $outcome = $this->retry($uuid, $occurrence);
        } finally {
            Hooks::$callback = null;
            PackageSession::usePdoFactoryForTesting(null);
            PackageSession::releaseAll();
        }

        self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
        $id = (string) $outcome->operationId;
        self::assertSame((int) $this->fenceRow($id, Fence::PUBLISH)?->job_id, $outcome->jobId, 'The job id of the published fence row.');
        self::assertSame(Operations::CLAIMED, $this->operation($id)->state, 'The mirror refused the transition.');

        $this->moveDatabaseClocks(120);
        $this->runOnce();
        self::assertSame(Operations::DONE, $this->operation($id)->state);
        self::assertSame(['pending', 2], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->cycle]);
    }

    /**
     * Rejects: a mirror lock timeout that defers a target connection (MySQL and Postgres): mode before, the abort
     * decides the delete on the failed-record connection, then its mirror transaction meets a held row lock; the
     * step is undecided, and no connection is deferred. It runs on the 1 s test lock bound: the error decides,
     * not its duration.
     */
    public function test_mirror_lock_timeout_in_the_abort_defers_no_target(): void
    {
        if (! $this->testEnvironment()->isServer()) {
            self::markTestSkipped('A held row lock needs MySQL or Postgres.');
        }

        PackageSession::useLockWaitSecondsForTesting(1);

        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $this->killAt(RetryService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_CLAIM);
        $id = (string) $this->rowOrFail($uuid)->operation_id;
        $this->app->make(Operations::class)->markAborting($this->mirror(), $id, 'cancelled');
        $this->moveDatabaseClocks(120);

        $name = (string) $this->mirror()->getName();
        $this->app['config']->set('database.connections.testbench_row_holder', $this->app['config']->get('database.connections.'.$name));
        $holder = DB::connection('testbench_row_holder');
        $holder->beginTransaction();

        try {
            $holder->table('orrery_jobs')->where('uuid', $uuid)->lockForUpdate()->first();
            $report = $this->runOnce();
        } finally {
            $holder->rollBack();
        }

        self::assertNotSame(OperationRunner::STOP_BLOCKED, $report['stopped']);
        self::assertNull($report['deferred']);
        self::assertSame(0, (int) $this->mirror()->table('orrery_settings')->where('key', 'like', OperationRunner::DEFER_PREFIX.'%')->count(), 'No connection is deferred.');
        self::assertSame(Operations::ABORTING, $this->operation($id)->state);

        $this->moveDatabaseClocks(4000);
        $this->runOnce();
        self::assertSame(Operations::ABORTED, $this->operation($id)->state);
    }

    /**
     * Rejects: a publication session kept after its rollback failed: the publication finds its fence key cancelled,
     * the rollback throws, and the session is released (its PDO closed).
     */
    public function test_failed_rollback_releases_the_publication_session(): void
    {
        $this->needsSharedDatabase();
        [$uuid, $occurrence] = $this->failedJob();
        $sessions = $this->app->make(PackageSession::class);
        $config = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY);
        $held = null;
        Hooks::$callback = function (string $point) use ($sessions, $config, &$held, $uuid): void {
            if ($held !== null || $point !== Publication::BEFORE_FENCE_INSERT) {
                return;
            }

            // A resolver cancelled first.
            $id = (string) $this->rowOrFail($uuid)->operation_id;
            $now = DatabaseClock::now(DB::connection(TestEnvironment::PRIMARY));
            DB::connection(TestEnvironment::PRIMARY)->table(Fence::TABLE)->insert(['operation_id' => $id, 'slot' => Fence::PUBLISH, 'state' => Fence::CANCELLED, 'job_id' => null, 'deadline' => Clock::toDecimal($now + 60), 'created_at' => Clock::toDecimal($now)]);
            $held = $sessions->forAction(TestEnvironment::PRIMARY);
            $held->setPdo(SessionPdo::for($held, ThrowingRollbackPdo::forConfig($config)));
        };

        try {
            $outcome = $this->retry($uuid, $occurrence);
        } finally {
            Hooks::$callback = null;
        }

        self::assertOutcome(RetryResult::FAILED, 'cancelled', $outcome);
        self::assertNotNull($held);
        self::assertNull($held->getRawPdo(), 'The session was released.');
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame(0, $this->jobsOf((string) $outcome->operationId));
    }

    /**
     * Rejects: a fence retention that runs on a connection that the runner deferred, or that tries a failing
     * connection again at once: a deferred connection keeps its old rows until the deferral ends; after a failed run
     * the connection is skipped for 60 s.
     */
    public function test_operation_retention_skips_deferred_connections(): void
    {
        if ($this->queueIsHostFile()) {
            self::markTestSkipped('The fence retention makes no write on a host SQLite file at all.');
        }

        $old = DatabaseClock::now(DB::connection(TestEnvironment::PRIMARY)) - 40 * 86400;
        $insert = static function (string $id) use ($old): void {
            DB::connection(TestEnvironment::PRIMARY)->table(Fence::TABLE)->insert(['operation_id' => $id, 'slot' => Fence::PUBLISH, 'state' => Fence::CANCELLED, 'job_id' => null, 'deadline' => Clock::toDecimal($old), 'created_at' => Clock::toDecimal($old)]);
        };
        $count = static fn (): int => (int) DB::connection(TestEnvironment::PRIMARY)->table(Fence::TABLE)->count();
        $phase = $this->app->make(OperationRetentionPhase::class);
        $run = static fn () => $phase->run(new TickBudget(Clock::monotonic() + 30));
        $insert(Operations::newId());

        $key = OperationRunner::DEFER_PREFIX.TestEnvironment::PRIMARY;
        $this->mirror()->table('orrery_settings')->insert(['key' => $key, 'value' => json_encode(['until' => $this->mirrorNow() + 600, 'failures' => 1])]);
        $run();
        self::assertSame(1, $count(), 'A deferred connection keeps its rows.');

        $this->mirror()->table('orrery_settings')->where('key', $key)->delete();
        $run();
        self::assertSame(0, $count());

        // A failed run: the connection waits 60 s.
        $insert(Operations::newId());
        Watcher::failOperationsForTesting(['operation_retention:fence']);

        try {
            $run();
        } finally {
            Watcher::failOperationsForTesting([]);
        }

        $run();
        self::assertSame(1, $count(), 'The failing connection is skipped.');
        Clock::advanceMonotonicForTesting(61);
        $run();
        self::assertSame(0, $count());
    }

    /**
     * @return array<string, mixed>
     */
    private function mirrorConfig(): array
    {
        $sessions = $this->app->make(PackageSession::class);
        $sibling = $sessions->siblingPath();

        if ($sibling !== null) {
            return ['driver' => 'sqlite', 'database' => $sibling, 'prefix' => ''];
        }

        $name = (string) $sessions->mirror()->getName();
        $name = str_starts_with($name, PackageSession::NAME_PREFIX) ? substr($name, strlen(PackageSession::NAME_PREFIX)) : $name;

        return (array) $this->app['config']->get('database.connections.'.$name);
    }
}
