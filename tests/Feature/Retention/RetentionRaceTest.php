<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retention;

use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Monitoring\MonitoringRepair;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Retention\Jobs\TaggedJob;
use Justrau\Orrery\Tests\Feature\Retry\RetryTestCase;
use Justrau\Orrery\Tests\Support\PackageSessionHelpers;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * The retention ordering races: trim against a
 * retry claim and against a monitoring start, with the tick in a worker
 * process that holds at `retention.after_select`, and an old transaction
 * that commits inside the monitored-cache window.
 */
final class RetentionRaceTest extends RetryTestCase
{
    use PackageSessionHelpers;
    use RetentionFixtures;

    /**
     * Rejects: a delete without the full predicate re-applied.
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_trim_against_a_retry_claim(): void
    {
        $this->needsSharedDatabase();

        [$uuid, $occurrence] = $this->failedJob();
        $old = Clock::toDecimal(Clock::now() - 30 * 86400);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['finished_at' => $old, 'failed_at' => $old]);
        $control = $this->jobRow(['status' => 'failed', 'finished_at' => Clock::now() - 30 * 86400]);

        $tick = WorkerProcess::start(['orrery:tick'], $this->testEnvironment(), $this->holds()->holdEnvironment(Retention::AFTER_SELECT));
        $this->awaitHold($tick, Retention::AFTER_SELECT);

        // The tick selected both expired failures. The retry claims one of
        // them, and the tick deletes while the claim holds the row.
        $trimmed = null;
        Hooks::$callback = function (string $point) use ($tick, &$trimmed): void {
            if ($point !== RetryService::AFTER_CLAIM || $trimmed !== null) {
                return;
            }

            try {
                self::assertSame('retrying', $this->rowOrFail(...$this->claimed())->status, 'The claim committed before the trim deletes.');
                $this->release(Retention::AFTER_SELECT);
                $trimmed = $tick->waitForExit(60);
            } catch (Throwable $error) {
                $trimmed = $error;
            }
        };

        try {
            $result = $this->service()->retry($uuid, $occurrence, 'cli');
        } finally {
            Hooks::$callback = null;
        }

        self::assertSame(0, $trimmed, $tick->describe());
        self::assertFalse($this->exists($control), 'The tick trimmed the unclaimed failure.');
        self::assertSame(RetryResult::ACCEPTED, $result->result, json_encode($result));
        $row = $this->rowOrFail($uuid);
        self::assertSame(['pending', 2], [$row->status, (int) $row->cycle], 'The claimed row survived the trim, and the retry finalised it.');
        self::assertSame(1, $this->queueTable()->count(), 'The retry published the job.');
    }

    /**
     * Rejects: a delete that trusts a stale monitored flag.
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_trim_against_a_monitor_start(): void
    {
        $this->needsSharedDatabase();
        $this->setMonitoring([], 1);
        $old = Clock::now() - 7200;
        $tagged = $this->jobRow(['finished_at' => $old, 'monitor_version' => 1]);
        $this->tagRows($tagged, ['vip']);
        $untagged = $this->jobRow(['finished_at' => $old]);

        $tick = WorkerProcess::start(['orrery:tick'], $this->testEnvironment(), $this->holds()->holdEnvironment(Retention::AFTER_SELECT));
        $this->awaitHold($tick, Retention::AFTER_SELECT);

        // The tick selected both completed rows as unmonitored. Monitoring of
        // the tag starts before its deletion transaction.
        self::assertTrue($this->app->make(MonitoringVersion::class)->monitor('vip'));

        $this->release(Retention::AFTER_SELECT);
        self::assertSame(0, $tick->waitForExit(60), $tick->describe());

        self::assertTrue($this->exists($tagged), 'The trim verified the stale flag and kept the monitored row.');
        self::assertSame([1, 2], [(int) $this->rowOrFail($tagged)->monitored, (int) $this->rowOrFail($tagged)->monitor_version]);
        self::assertFalse($this->exists($untagged), 'The trim deleted the untagged row.');
    }

    /**
     * Rejects: repair by id order.
     */
    public function test_old_transaction_committed_inside_the_monitored_cache_window(): void
    {
        $this->skipUnlessDriver('mysql', 'pgsql');
        $this->setMonitoring([], 1);

        // A producer took its snapshot (V = 1, nothing monitored) and keeps it for 10 s.
        $snapshot = $this->app->make(MonitoredTagCache::class)->snapshot();
        self::assertSame(['tags' => [], 'version' => 1], $snapshot);

        self::assertTrue($this->app->make(MonitoringVersion::class)->monitor('vip'));

        // Inside the cache window, that producer's insert allocates an id
        // and commits only after a later push and a repair.
        $this->defineConnection('b18_old', $this->mirrorSource());
        $producer = $this->db()->connection('b18_old');
        $this->beforeApplicationDestroyed(fn () => $this->db()->purge('b18_old'));
        $producer->beginTransaction();

        try {
            $late = $this->jobRow(['status' => 'pending', 'finished_at' => null, 'monitor_version' => $snapshot['version']], $producer);
            $producer->table('orrery_job_tags')->insert(['tag' => 'vip', 'job_uuid' => $late, 'pushed_at' => Clock::toDecimal(Clock::now())]);

            Queue::connection('database')->push(new TaggedJob(['vip']));
            self::assertSame(0, $this->app->make(MonitoringRepair::class)->run(), 'The later push read the current V; the open row is invisible.');

            $producer->commit();
        } finally {
            if ($producer->transactionLevel() > 0) {
                $producer->rollBack();
            }
        }

        $row = $this->rowOrFail($late);
        self::assertSame([0, 1], [(int) $row->monitored, (int) $row->monitor_version]);
        self::assertLessThan((int) $this->mirror()->table('orrery_jobs')->max('id'), (int) $row->id, 'The late row has the lower id.');

        self::assertSame(1, $this->app->make(MonitoringRepair::class)->run(), 'The repair selects the late row by its version.');
        self::assertSame([1, 2], [(int) $this->rowOrFail($late)->monitored, (int) $this->rowOrFail($late)->monitor_version]);
    }

    /**
     * The uuid of the row that the claim holds.
     *
     * @return array{0: string}
     */
    private function claimed(): array
    {
        return [(string) $this->mirror()->table('orrery_jobs')->where('status', 'retrying')->value('uuid')];
    }
}
