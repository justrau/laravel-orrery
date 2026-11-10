<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Phases\OperationRetentionPhase;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\TestClock;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * The clocks of a retry and the operation retention (retry
 * step 3 and the operation runner). Kind: new behaviour.
 */
final class RetryRunnerTest extends RetryProtocolTestCase
{
    /** The queue's own connection name, so its clock can differ from the mirror's. */
    private const QUEUE_DATABASE = 'testbench_q';

    /**
     * Rejects: a deadline in PHP's or the mirror's clock: PHP's clock 120 s ahead changes nothing; Q's clock 120 s
     * ahead, between the claim and the publication, makes the conditional fence insert refuse (deadline_passed).
     */
    public function test_deadline_uses_the_queue_database_clock(): void
    {
        $this->useQueueDatabase();
        [$uuid, $occurrence] = $this->failedJob();

        Carbon::setTestNow(Carbon::now()->addSeconds(120));

        try {
            self::assertOutcome(RetryResult::ACCEPTED, null, $this->retry($uuid, $occurrence));
        } finally {
            Carbon::setTestNow();
        }

        [$uuid, $occurrence] = $this->failedJob();
        Hooks::$callback = static function (string $point): void {
            if ($point === Publication::BEFORE_FENCE_INSERT) {
                TestClock::shift(self::QUEUE_DATABASE, 120);
            }
        };

        $outcome = $this->retry($uuid, $occurrence);

        self::assertOutcome(RetryResult::FAILED, 'deadline_passed', $outcome);
        $operation = $this->operation((string) $outcome->operationId);
        self::assertSame([Operations::ABORTED, 'deadline_passed'], [$operation->state, $operation->error_code]);
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame(0, $this->jobsOf($operation->id));
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: next_check_at written in Q's clock, and a reschedule that mixes the clocks: with Q's clock 120 s ahead
     * or behind, the claim schedules the check at the mirror's now + 60, and a claimed retry before its deadline in
     * Q's clock is rescheduled at the mirror's now + (deadline − Q's now), without a check count.
     */
    public function test_runner_selects_by_the_mirror_clock(): void
    {
        $this->useQueueDatabase();

        foreach ([120.0, -120.0] as $offset) {
            TestClock::shift(self::QUEUE_DATABASE, $offset);
            TestClock::shift($this->mirrorClockName(), 0.0);
            [$uuid, $occurrence] = $this->failedJob();
            $this->killAt(RetryService::AFTER_CLAIM);
            $before = $this->mirrorNow();
            $queueBefore = DatabaseClock::now($this->app->make(PackageSession::class)->for(self::QUEUE_DATABASE));
            $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_CLAIM);

            $operation = $this->claimedOf($uuid);
            self::assertEqualsWithDelta($before + 60, (float) $operation->next_check_at, 5, 'next_check_at is in the mirror\'s clock.');
            self::assertEqualsWithDelta($queueBefore + 60, (float) $operation->deadline, 5, 'The deadline is in Q\'s clock.');

            // Not due yet by the mirror's clock: the runner does not inspect it.
            $this->runOnce();
            self::assertSame(0, (int) $this->operation($operation->id)->checks);

            // Due by the mirror's clock; Q's clock decides whether the deadline passed.
            TestClock::shift($this->mirrorClockName(), 61.0);
            $this->runOnce();
            $after = $this->operation($operation->id);
            $queueNow = DatabaseClock::now($this->app->make(PackageSession::class)->for(self::QUEUE_DATABASE));
            self::assertSame([Operations::CLAIMED, 0], [$after->state, (int) $after->checks]);
            self::assertEqualsWithDelta($this->mirrorNow() + ((float) $after->deadline - $queueNow), (float) $after->next_check_at, 5);

            $this->mirror()->table(Operations::TABLE)->where('id', $operation->id)->delete();
            $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'failed', 'operation_id' => null]);
        }
    }

    /**
     * Rejects: age-only fence retention and retention by created_at: an old row of a non-terminal (aborting)
     * operation stays; a terminal operation whose deadline is 29 days old by that database's clock stays while its
     * created_at is 40 days old; a terminal one 31 days old goes with all its slots; a row whose operation the ledger
     * no longer has goes. On a host SQLite file the phase deletes nothing (D17).
     */
    public function test_fence_retention_needs_a_terminal_operation_and_an_old_deadline(): void
    {
        $now = DatabaseClock::now($this->app['db']->connection(TestEnvironment::PRIMARY));
        $day = 86400;
        $aborting = $this->ledger(Operations::ABORTING, $now - 40 * $day);
        $young = $this->ledger(Operations::DONE, $now - 40 * $day);
        $old = $this->ledger(Operations::ABORTED, $now - 40 * $day);
        $absent = Operations::newId();

        $this->fence($aborting, Fence::DELETE, $now - 40 * $day, $now - 40 * $day);
        $this->fence($aborting, Fence::RESTORE, $now - 40 * $day, $now - 40 * $day);
        $this->fence($young, Fence::PUBLISH, $now - 29 * $day, $now - 40 * $day);
        $this->fence($old, Fence::DELETE, $now - 31 * $day, $now - 31 * $day);
        $this->fence($old, Fence::RESTORE, $now - 31 * $day, $now - 31 * $day);
        $this->fence($absent, Fence::PUBLISH, $now - 31 * $day, $now - 31 * $day);

        $this->retention()->run(new TickBudget(Clock::monotonic() + 30));

        if ($this->queueIsHostFile()) {
            self::assertSame(6, $this->fenceCount(), 'The phase writes nothing on a host SQLite file.');

            return;
        }

        self::assertSame(2, $this->fenceCount($aborting), 'An operation that can still act keeps its rows.');
        self::assertSame(1, $this->fenceCount($young), 'The deadline decides, not created_at.');
        self::assertSame(0, $this->fenceCount($old), 'All slots of a finished operation go together.');
        self::assertSame(0, $this->fenceCount($absent));
    }

    /**
     * Rejects: an oldest-500 window without a position: 600 held old rows come first, then eligible ones; each run
     * inspects at most 500 rows, and the eligible ones go within three runs.
     */
    public function test_fence_retention_passes_held_rows(): void
    {
        $now = DatabaseClock::now($this->app['db']->connection(TestEnvironment::PRIMARY));
        $old = $now - 40 * 86400;
        $held = [];
        $ledger = [];
        $fences = [];

        for ($i = 0; $i < 600; $i++) {
            $id = sprintf('%032x', $i + 1);
            $held[] = $id;
            $ledger[] = $this->ledgerRow($id, Operations::ABORTING, $old);
            $fences[] = ['operation_id' => $id, 'slot' => Fence::DELETE, 'state' => Fence::DELETED, 'job_id' => null, 'deadline' => Clock::toDecimal($old), 'created_at' => Clock::toDecimal($old + $i / 1000)];
        }

        $eligible = [];

        for ($i = 0; $i < 20; $i++) {
            $id = sprintf('f%031x', $i + 1);
            $eligible[] = $id;
            $fences[] = ['operation_id' => $id, 'slot' => Fence::PUBLISH, 'state' => Fence::CANCELLED, 'job_id' => null, 'deadline' => Clock::toDecimal($old), 'created_at' => Clock::toDecimal($old + 1 + $i / 1000)];
        }

        foreach (array_chunk($ledger, 100) as $chunk) {
            $this->mirror()->table(Operations::TABLE)->insert($chunk);
        }

        foreach (array_chunk($fences, 100) as $chunk) {
            $this->app['db']->connection(TestEnvironment::PRIMARY)->table(Fence::TABLE)->insert($chunk);
        }

        if ($this->queueIsHostFile()) {
            foreach (range(1, 3) as $run) {
                self::assertSame(['inspected' => 0, 'deleted' => 0], $this->retention()->fenceRun(TestEnvironment::PRIMARY), 'No automatic access to a host SQLite file (D17).');
            }

            self::assertSame(20, $this->eligibleLeft($eligible), 'Nothing is deleted on a host SQLite file.');

            return;
        }

        $runs = 0;

        while ($this->eligibleLeft($eligible) > 0 && $runs < 3) {
            $report = $this->retention()->fenceRun(TestEnvironment::PRIMARY);
            self::assertLessThanOrEqual(OperationRetentionPhase::FENCE_ROWS, $report['inspected'], 'A run inspects at most 500 rows.');
            self::assertGreaterThan(0, $report['inspected']);
            $runs++;
        }

        self::assertSame(0, $this->eligibleLeft($eligible), "The eligible rows went within {$runs} runs.");
        self::assertSame(600, (int) $this->app['db']->connection(TestEnvironment::PRIMARY)->table(Fence::TABLE)->whereIn('operation_id', array_slice($held, 0, 600))->count(), 'Held rows stay.');
    }

    /**
     * Rejects: a cursor without a pass boundary: the target becomes due behind the cursor while a
     * pass runs, and before each capped run at least a page of new due operations arrives ahead of the cursor; the
     * target is decided within the next pass.
     *
     * The runner's clock (OperationRunner::CAP_SECONDS and the tick budget on
     * Clock::monotonic()) is frozen: on a loaded machine the first run ended
     * at its 5 s cap after 40 operations (wave-B matrix, MySQL), so the run
     * sizes measured the machine, not the pass boundary.
     */
    public function test_runner_pass_boundary_holds_under_arrivals(): void
    {
        if ($this->queueIsHostFile()) {
            self::markTestSkipped('The resolution writes the queue file, which only an operator may do (D17).');
        }

        Clock::freezeMonotonicForTesting();

        $now = $this->mirrorNow();
        $fingerprint = $this->app->make(Publication::class)->queueFingerprint('database');
        $add = function (float $at) use ($fingerprint): string {
            $id = Operations::newId();
            $this->mirror()->table(Operations::TABLE)->insert(['queue_fingerprint' => $fingerprint, 'deadline' => Clock::toDecimal(1000.0), 'next_check_at' => Clock::toDecimal($at)] + array_diff_key($this->ledgerRow($id, Operations::CLAIMED, 1000.0), ['queue_fingerprint' => true, 'deadline' => true, 'next_check_at' => true]));

            return $id;
        };

        foreach (range(1, 120) as $i) {
            $add($now - 2000 + $i);
        }

        $first = $this->runOnce();
        self::assertSame(100, $first['inspected']);

        // Due now, but behind the cursor: not in this pass.
        $target = $add($now - 5000);
        $runs = 1;

        while ($this->operation($target)->state === Operations::CLAIMED && $runs < 10) {
            foreach (range(1, 100) as $i) {
                $add($this->mirrorNow() - 1 - $i / 1000);
            }

            $this->runOnce();
            $runs++;
        }

        self::assertSame(Operations::ABORTED, $this->operation($target)->state);
        self::assertLessThanOrEqual(3, $runs, 'The rest of this pass, then the target first in the next pass.');
    }

    /**
     * Rejects: a ledger retention that deletes an active operation or a young one.
     */
    public function test_ledger_retention_deletes_old_finished_operations(): void
    {
        $now = $this->mirrorNow();
        $done = $this->ledger(Operations::DONE, $now - 31 * 86400);
        $aborted = $this->ledger(Operations::ABORTED, $now - 31 * 86400);
        $young = $this->ledger(Operations::DONE, $now - 29 * 86400);
        $active = $this->ledger(Operations::CLAIMED, $now - 31 * 86400);

        self::assertSame(2, $this->retention()->ledgerRun());

        $left = $this->mirror()->table(Operations::TABLE)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        sort($left);
        $expected = [$young, $active];
        sort($expected);
        self::assertSame($expected, $left);
        self::assertNotContains($done, $left);
        self::assertNotContains($aborted, $left);
    }

    private function useQueueDatabase(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('Two connection names on one database need a database file or a server.');
        }

        $config = $this->app['config'];
        $config->set('database.connections.'.self::QUEUE_DATABASE, $config->get('database.connections.'.TestEnvironment::PRIMARY));
        $config->set('queue.connections.database.connection', self::QUEUE_DATABASE);
        $this->app->forgetInstance('queue');
        $this->app->forgetInstance('queue.connection');
        \Illuminate\Support\Facades\Queue::clearResolvedInstance('queue');
        $this->useCapturingFailer('failed_jobs', self::QUEUE_DATABASE);
    }

    private function mirrorClockName(): string
    {
        $name = (string) $this->app->make(PackageSession::class)->mirror()->getName();

        return str_starts_with($name, PackageSession::NAME_PREFIX) ? substr($name, strlen(PackageSession::NAME_PREFIX)) : $name;
    }

    private function claimedOf(string $uuid): \stdClass
    {
        $id = $this->rowOrFail($uuid)->operation_id;
        self::assertIsString($id);

        return $this->operation($id);
    }

    private function retention(): OperationRetentionPhase
    {
        return $this->app->make(OperationRetentionPhase::class);
    }

    private function ledger(string $state, float $createdAt): string
    {
        $id = Operations::newId();
        $this->mirror()->table(Operations::TABLE)->insert($this->ledgerRow($id, $state, $createdAt));

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerRow(string $id, string $state, float $createdAt): array
    {
        return [
            'id' => $id, 'action' => Operations::RETRY, 'state' => $state, 'mode' => Operations::IN_TX,
            'job_uuid' => '0f5a3c1e-6b1d-4e57-9a3e-'.substr($id, 0, 12), 'occurrence_id' => 1, 'queue_connection' => 'database', 'queue' => 'default',
            'queue_fingerprint' => str_repeat('0', 64), 'deadline' => Clock::toDecimal($createdAt + 60), 'next_check_at' => Clock::toDecimal($createdAt + 60),
            'checks' => 0, 'source' => 'cli', 'created_at' => Clock::toDecimal($createdAt), 'updated_at' => Clock::toDecimal($createdAt),
        ];
    }

    private function fence(string $operationId, string $slot, float $deadline, float $createdAt): void
    {
        $this->app['db']->connection(TestEnvironment::PRIMARY)->table(Fence::TABLE)->insert([
            'operation_id' => $operationId, 'slot' => $slot, 'state' => $slot === Fence::PUBLISH ? Fence::PUBLISHED : ($slot === Fence::DELETE ? Fence::DELETED : Fence::RESTORED),
            'job_id' => null, 'deadline' => Clock::toDecimal($deadline), 'created_at' => Clock::toDecimal($createdAt),
        ]);
    }

    private function fenceCount(?string $operationId = null): int
    {
        $query = $this->app['db']->connection(TestEnvironment::PRIMARY)->table(Fence::TABLE);

        if ($operationId !== null) {
            $query->where('operation_id', $operationId);
        }

        return (int) $query->count();
    }

    /**
     * @param  list<string>  $ids
     */
    private function eligibleLeft(array $ids): int
    {
        return (int) $this->app['db']->connection(TestEnvironment::PRIMARY)->table(Fence::TABLE)->whereIn('operation_id', $ids)->count();
    }
}
