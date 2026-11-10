<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retention;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Lists\ListRequest;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Retention\Jobs\TaggedJob;
use Justrau\Orrery\Tests\Feature\Retention\Jobs\TickingJob;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * Partitioned retention: the trimmed statuses, the monitored
 * retention, the dependent rows, the partition cursors and the cutoff. The
 * rewrites of the probes (RetentionProbeTest, LongRunningJobProbeTest).
 */
final class RetentionTest extends RetentionTestCase
{
    private ?string $timezone = null;

    protected function tearDown(): void
    {
        if ($this->timezone !== null) {
            date_default_timezone_set($this->timezone);
        }

        parent::tearDown();
    }

    /**
     * Rejects: trimming `pending` or `reserved` rows.
     */
    public function test_queued_and_long_running_rows_are_never_trimmed(): void
    {
        // A job that runs past every retention window while tick runs.
        Queue::push(new TickingJob(20_000));
        $this->work();

        self::assertSame(0, DB::table('jobs')->count(), 'The long job completed.');
        self::assertSame(['completed'], $this->mirror()->table('orrery_jobs')->pluck('status')->all(), 'The tick during the run kept the reserved row, so its completion was observed.');
        self::assertNotNull($this->mirror()->table('orrery_jobs')->value('finished_at'), 'The completion was observed with its time, so the tick counts it.');
        $this->mirror()->table('orrery_jobs')->delete();

        // A delayed job and a backlog job that no worker takes for an hour.
        Queue::later(7200, new TaggedJob);
        Queue::push(new TaggedJob);
        Carbon::setTestNow(Carbon::now()->addMinutes(61));
        $this->tick();

        self::assertSame(2, DB::table('jobs')->count(), 'Both jobs are still in the queue.');
        self::assertSame(['count' => 2, 'at_least' => false], $this->app->make(JobList::class)->tabCount('pending', 1000), 'The dashboard still counts both queued jobs.');

        // Every protected status, with a finished_at far past every
        // retention, so only the status protects the row.
        $old = Clock::now() - 10 * 365 * 86400;
        $protected = [
            $this->jobRow(['status' => 'pending', 'finished_at' => $old]),
            $this->jobRow(['status' => 'reserved', 'finished_at' => $old, 'reserved_at' => $old]),
            // A retry operation holds the row.
            $this->jobRow(['status' => 'retrying', 'finished_at' => $old, 'operation_id' => str_repeat('c', 32)]),
            $this->jobRow(['status' => 'retrying', 'finished_at' => $old]),
            // A forget operation holds a failed row: retention never trims a held row.
            $this->jobRow(['status' => 'failed', 'finished_at' => $old, 'operation_id' => str_repeat('e', 32)]),
        ];
        $expired = [
            $this->jobRow(['status' => 'completed', 'finished_at' => $old]),
            $this->jobRow(['status' => 'failed', 'finished_at' => $old]),
            $this->jobRow(['status' => 'unknown', 'finished_at' => $old]),
        ];

        $this->tick();

        foreach ($protected as $uuid) {
            self::assertTrue($this->exists($uuid), "The protected row {$uuid} was kept.");
        }

        foreach ($expired as $uuid) {
            self::assertFalse($this->exists($uuid), "The expired row {$uuid} was trimmed.");
        }

        self::assertSame(7, $this->mirror()->table('orrery_jobs')->count(), 'The two queued rows and the five protected rows remain.');
    }

    /**
     * Rejects: the completed window for a monitored row.
     */
    public function test_monitored_rows_use_the_monitored_retention(): void
    {
        $this->app->make(MonitoringVersion::class)->monitor('vip');

        Queue::push(new TaggedJob(['vip']));
        $this->work();

        // trim.completed = 60, trim.monitored = 10080.
        Carbon::setTestNow(Carbon::now()->addMinutes(61));
        $this->tick();

        self::assertSame(1, $this->tagCount('vip'), 'The tag view still counts the job.');
        self::assertSame(['count' => 1, 'at_least' => false], $this->app->make(JobList::class)->total(ListRequest::from(['status' => 'all', 'tag' => 'vip'])), 'The monitored job is kept for trim.monitored.');

        Carbon::setTestNow(Carbon::now()->addMinutes(10080));
        $this->tick();

        self::assertSame(0, $this->tagCount('vip'), 'After trim.monitored the job and its tag rows are trimmed.');
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->count());
    }

    /**
     * Rejects: tag storage and monitoring flags only at P1 and P2.
     */
    public function test_fallback_created_rows_keep_their_monitored_retention(): void
    {
        $this->app['config']->set('orrery.trim', ['completed' => 60, 'failed' => 60, 'monitored' => 600] + (array) $this->app['config']->get('orrery.trim'));
        Carbon::setTestNow(Carbon::createFromFormat('U.u', sprintf('%.6F', self::T)));
        $this->app->make(MonitoringVersion::class)->monitor('vip');
        $tags = ['tags' => ['vip', 'other']];

        // Each first observation creates the row: R7, C3, F1, and R7 of a
        // swap-off bulk job at its reservation.
        $reserved = $this->uuid();
        self::assertSame('reserveWithoutRow', $this->observe('reserve', $reserved, ['jobId' => 11, 'attempt' => 1], $tags)->rule);
        $this->observe('complete', $reserved, ['jobId' => 11, 'attempt' => 1], $tags);

        $completed = $this->uuid();
        self::assertSame('completeWithoutRow', $this->observe('complete', $completed, ['jobId' => 12, 'attempt' => 1], $tags)->rule);

        $failed = $this->uuid();
        self::assertSame('failWithoutRow', $this->observe('fail', $failed, ['jobId' => 13, 'attempt' => 1, 'exception' => new \RuntimeException('first seen as a failure')], $tags)->rule);

        $bulk = $this->uuid();
        self::assertSame('reserveWithoutRow', $this->observe('reserve', $bulk, ['jobId' => 14, 'attempt' => 1, 'instrumented' => false], $tags + ['orreryGen' => null])->rule);
        $this->observe('complete', $bulk, ['jobId' => 14, 'attempt' => 1, 'instrumented' => false], $tags + ['orreryGen' => null]);

        $all = ['reserveWithoutRow' => $reserved, 'completeWithoutRow' => $completed, 'failWithoutRow' => $failed, 'swap-off bulk R7' => $bulk];

        foreach ($all as $rule => $uuid) {
            $row = $this->rowOrFail($uuid);
            self::assertContains($row->status, ['completed', 'failed'], "{$rule}: the job finished.");
            self::assertTrue((bool) $row->monitored, "{$rule}: the row is monitored.");
            self::assertSame(2, $this->rowsOf($uuid)['orrery_job_tags'], "{$rule}: the row has its tag rows.");
        }

        // The ordinary cutoff (60 minutes) keeps every monitored row.
        Carbon::setTestNow(Carbon::now()->addMinutes(61));
        $this->tick();

        foreach ($all as $rule => $uuid) {
            self::assertTrue($this->exists($uuid), "{$rule}: the ordinary cutoff keeps the monitored row.");
            self::assertSame(2, $this->rowsOf($uuid)['orrery_job_tags'], "{$rule}: the row keeps its tag rows.");
        }

        Carbon::setTestNow(Carbon::now()->addMinutes(540));
        $this->tick();

        foreach ($all as $rule => $uuid) {
            self::assertSame(0, $this->rowsOf($uuid)['jobs'] + $this->rowsOf($uuid)['orrery_job_tags'], "{$rule}: the monitored retention ends with the row and its tags.");
        }
    }

    /**
     * Rejects: tag rows that outlive their job.
     */
    #[FreshSchema]
    public function test_tag_rows_and_failed_tags_are_deleted_with_their_job(): void
    {
        $tags = $this->app->make(MonitoringVersion::class);
        $tags->monitor('vip');

        $this->queue()->push(new TaggedJob(['vip', 'order:1'], fails: true));
        $this->workAndLogFailures();
        $old = (string) $this->mirror()->table('orrery_jobs')->value('uuid');
        $this->summaryRow($old);
        $this->incidentRow($old);

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->queue()->push(new TaggedJob(['vip', 'order:1'], fails: true));
        $this->workAndLogFailures();
        $new = (string) $this->mirror()->table('orrery_jobs')->where('uuid', '!=', $old)->value('uuid');
        $this->summaryRow($new);
        $this->incidentRow($new);

        $expected = $this->rowsOf($new);
        self::assertSame(['jobs' => 1, 'orrery_job_tags' => 2, 'orrery_failed_tags' => 2], array_slice($expected, 0, 3, true));
        self::assertGreaterThanOrEqual(1, $expected['orrery_job_history'], 'The failure wrote its history.');
        self::assertSame([1, 1], [$expected['orrery_cycle_summaries'], $expected[IncidentCleanup::TABLE]]);
        self::assertSame($expected, $this->rowsOf($old), 'Both failures have the same rows.');

        // Stopping monitoring keeps the tag rows until their jobs are trimmed.
        $tags->stopMonitoring('vip');
        self::assertSame(2, $this->tagCount('vip'));

        // trim.failed = 10080: the older failure expires, the newer one does not.
        Carbon::setTestNow(Carbon::now()->addMinutes(10080 - 1440 + 1));
        $this->tick();

        self::assertSame(array_map(static fn (): int => 0, $expected), $this->rowsOf($old), 'The trimmed job left no tag, failed tag, history, summary or incident row.');
        self::assertSame($expected, $this->rowsOf($new), 'The retained job keeps every row.');
        self::assertSame(1, $this->tagCount('vip'));
        self::assertSame(1, $this->tagCount('order:1', 'orrery_failed_tags'));
    }

    /**
     * Rejects: one cursor for all partitions.
     */
    public function test_partitions_do_not_consume_each_others_budget(): void
    {
        $now = Clock::now();
        Retention::useLimitsForTesting(batch: 2, rows: 5000);

        $first = [];
        $second = [];

        for ($i = 0; $i < 3; $i++) {
            // The first partition (queue a) finished later than the second (queue b).
            $first[] = $this->jobRow(['queue' => 'a', 'finished_at' => $now - 7200 + $i]);
            $second[] = $this->jobRow(['queue' => 'b', 'finished_at' => $now - 10800 + $i]);
        }

        $kept = $this->jobRow(['queue' => 'c', 'status' => 'failed', 'finished_at' => $now - 7200]);

        self::assertSame(6, $this->retention()->run());

        foreach ($second as $uuid) {
            self::assertFalse($this->exists($uuid), 'The second partition used its own cursor and budget.');
        }

        foreach ($first as $uuid) {
            self::assertFalse($this->exists($uuid));
        }

        self::assertTrue($this->exists($kept), 'The failed partition keeps its row.');

        // The row budget of one run stops the traversal; the next run
        // resumes at each partition's persistent cursor.
        for ($i = 0; $i < 3; $i++) {
            $first[$i] = $this->jobRow(['queue' => 'a', 'finished_at' => $now - 7200 + $i]);
            $second[$i] = $this->jobRow(['queue' => 'b', 'finished_at' => $now - 10800 + $i]);
        }

        Retention::useLimitsForTesting(batch: 2, rows: 3);
        self::assertSame(3, $this->retention()->run());
        self::assertSame([false, false, true], array_map(fn (string $uuid): bool => $this->exists($uuid), $first));
        self::assertSame([false, true, true], array_map(fn (string $uuid): bool => $this->exists($uuid), $second));
        self::assertNotNull($this->mirror()->table('orrery_settings')->where('key', Retention::cursorKey('database', 'a', 'completed', false))->value('value'), 'The first partition stored its cursor.');
        self::assertNotNull($this->mirror()->table('orrery_settings')->where('key', Retention::cursorKey('database', 'b', 'completed', false))->value('value'), 'The second partition stored its cursor.');

        Retention::useLimitsForTesting(batch: 2, rows: 5000);
        self::assertSame(3, $this->retention()->run());
        self::assertSame(1, $this->mirror()->table('orrery_jobs')->count(), 'Only the failed row remains.');
        self::assertSame(0, $this->mirror()->table('orrery_settings')->where('key', 'like', 'retention_cursor:%')->count(), 'A finished partition clears its cursor.');
    }

    /**
     * Two partitions whose names join to the same text, (a, b:c) and
     * (a:b, c), have their own cursors: the held rows of
     * the first never move the second's cursor past its due rows.
     *
     * Rejects: a cursor key that joins the names with `:`.
     */
    public function test_partitions_whose_names_join_alike_have_their_own_cursors(): void
    {
        $this->app['config']->set('orrery.trim.failed', 60);
        $now = Clock::now();

        for ($i = 0; $i < 6; $i++) {
            // Held by an operation: never trimmed, so this partition never finishes.
            $this->jobRow(['connection' => 'a', 'queue' => 'b:c', 'status' => 'failed', 'finished_at' => $now - 2 * 86400 + $i, 'failed_at' => $now - 2 * 86400 + $i, 'operation_id' => str_repeat('f', 32)]);
        }

        $due = [
            $this->jobRow(['connection' => 'a:b', 'queue' => 'c', 'status' => 'failed', 'finished_at' => $now - 3 * 86400, 'failed_at' => $now - 3 * 86400]),
            $this->jobRow(['connection' => 'a:b', 'queue' => 'c', 'status' => 'failed', 'finished_at' => $now - 3 * 86400 + 1, 'failed_at' => $now - 3 * 86400 + 1]),
        ];

        Retention::useLimitsForTesting(batch: 2, seconds: 0.2);
        $this->retention()->run();
        $this->retention()->run();

        self::assertNotSame(Retention::cursorKey('a', 'b:c', 'failed', false), Retention::cursorKey('a:b', 'c', 'failed', false));
        self::assertSame([false, false], array_map(fn (string $uuid): bool => $this->exists($uuid), $due), 'both due rows of (a:b, c) are trimmed');
    }

    /**
     * Rejects: candidates without `operation_id IS NULL`: a partition whose due rows are an exact multiple of
     * the batch and all held by operations never finishes, so the run loops for its whole share and reports
     * retention behind.
     */
    public function test_held_rows_alone_finish_the_partition(): void
    {
        $this->app['config']->set('orrery.trim.failed', 60);
        $now = Clock::now();

        for ($i = 0; $i < 4; $i++) {
            $this->jobRow(['status' => 'failed', 'finished_at' => $now - 2 * 86400 + $i, 'failed_at' => $now - 2 * 86400 + $i, 'operation_id' => str_repeat('f', 32)]);
        }

        Retention::useLimitsForTesting(batch: 2, seconds: 5.0);
        $started = Clock::monotonic();
        self::assertSame(0, $this->retention()->run());

        self::assertLessThan(4.0, Clock::monotonic() - $started, 'The run ended before its share.');
        $state = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', Retention::STATE_KEY)->value('value'), true);
        self::assertFalse($state['behind']);
        self::assertSame(4, $this->mirror()->table('orrery_jobs')->whereNotNull('operation_id')->count());
    }

    /**
     * Rejects: a run that encodes the partition tuple as JSON with JSON_THROW_ON_ERROR: a queue name that is not
     * valid UTF-8 (SQLite stores one) ends every run with an error, so no row of any queue is trimmed.
     */
    public function test_a_queue_name_that_is_not_utf8_is_trimmed(): void
    {
        if ($this->testEnvironment()->driver !== 'sqlite') {
            self::markTestSkipped('Only SQLite stores a text that is not valid UTF-8.');
        }

        $old = Clock::now() - 2 * 86400;
        $invalid = $this->jobRow(['queue' => "q\xFF", 'finished_at' => $old]);
        $valid = $this->jobRow(['queue' => 'default', 'finished_at' => $old]);

        self::assertSame(2, $this->retention()->run());
        self::assertFalse($this->exists($invalid));
        self::assertFalse($this->exists($valid));
        self::assertSame(0, $this->retention()->run(), 'The stored start of the next run reads back.');
    }

    /**
     * Rejects: a cutoff from a `datetime` string in the application timezone.
     */
    public function test_cutoff_ignores_timezone_offsets(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        $this->app['config']->set('app.timezone', 'Pacific/Kiritimati');

        $now = 1790000000.654321;
        Carbon::setTestNow(Carbon::createFromFormat('U.u', sprintf('%.6F', $now))->setTimezone('Pacific/Kiritimati'));
        $cutoff = $now - 3600;

        $before = $this->jobRow(['finished_at' => $cutoff - 0.000001]);
        $at = $this->jobRow(['finished_at' => $cutoff]);
        $after = $this->jobRow(['finished_at' => $cutoff + 1]);
        $monthAgo = $this->jobRow(['finished_at' => $now - 30 * 86400]);

        $this->tick();

        self::assertFalse($this->exists($before), 'A row a microsecond before the cutoff is trimmed.');
        self::assertFalse($this->exists($monthAgo));
        self::assertTrue($this->exists($at), 'The row at the cutoff is kept.');
        self::assertTrue($this->exists($after), 'A row after the cutoff is kept.');
    }

    /**
     * Rejects: a cutoff by `pushed_at` in place of `finished_at`.
     */
    public function test_first_insert_after_an_outage_with_an_old_pushed_at_is_not_trimmed_early(): void
    {
        Queue::push(new TaggedJob);
        $uuid = self::uuidOf((string) DB::table('jobs')->value('payload'));
        // The push observation was lost (an outage): the mirror has no row.
        $this->mirror()->table('orrery_jobs')->delete();

        Carbon::setTestNow(Carbon::now()->addMinutes(120));
        $this->work();

        $row = $this->rowOrFail($uuid);
        self::assertSame('completed', $row->status, 'The reservation created the row (R7), and the job completed.');
        self::assertLessThan((float) $row->finished_at - 7000, (float) $row->pushed_at, 'The row keeps the old pushed_at of its payload.');

        $this->tick();
        self::assertTrue($this->exists($uuid), 'trim.completed counts from finished_at, not from pushed_at.');

        Carbon::setTestNow(Carbon::now()->addMinutes(61));
        $this->tick();
        self::assertFalse($this->exists($uuid));
    }

    /**
     * Rejects: ledger rows deleted with the job: the operation ledger has its own retention.
     */
    public function test_ledger_rows_survive_trim(): void
    {
        $uuid = $this->jobRow(['status' => 'failed', 'finished_at' => Clock::now() - 30 * 86400]);
        $this->failedTagRows($uuid, ['orders']);
        $this->tagRows($uuid, ['orders']);
        $this->historyRow($uuid, 1, 'failed');
        $this->operationRow($uuid);

        $this->tick();

        self::assertSame(['jobs' => 0, 'orrery_job_tags' => 0, 'orrery_failed_tags' => 0, 'orrery_job_history' => 0, 'orrery_cycle_summaries' => 0, 'orrery_operations' => 1, 'orrery_incidents' => 0], $this->rowsOf($uuid), 'The finished operation outlives the trimmed row.');
    }

    /**
     * Rejects: retention before aggregation (the lost minute): with trim.completed = 0, a row that the counts have not passed is trimmed, so its minute is published as a covered 0.
     */
    public function test_retention_waits_for_the_counts(): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $b = 1_790_000_040;
        $counts = $this->app->make(SampleCounts::class);
        $at = static fn (float $time) => Carbon::setTestNow(Carbon::createFromTimestamp($time, 'UTC'));

        $at($b + 310.0);
        $counts->run();
        // A completion of minute B + 180 reaches the mirror 2 minutes after its minute.
        $uuid = $this->jobRow(['finished_at' => $b + 190.0]);
        $this->retention()->run();
        self::assertTrue($this->exists($uuid), 'The counts floor (M - 300) keeps the row.');

        $at($b + 370.0);
        $counts->run();
        self::assertSame(1, (int) $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'total')->where('minute', $b + 180)->value('completed'), 'The recount counted the row.');
        $this->retention()->run();
        self::assertTrue($this->exists($uuid), 'M - 300 is still before the row.');

        $at($b + 550.0);
        $counts->run();
        $this->retention()->run();
        self::assertFalse($this->exists($uuid), 'After the counts passed the row, retention trims it.');
        self::assertSame(1, (int) $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'total')->where('minute', $b + 180)->value('completed'), 'The count stays.');
        self::assertSame([], json_decode((string) $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->value('value'), true)['intervals'], 'A trim within the bound records no invalid minute.');

        // Held rows are held, not behind (the retention state of /api/health).
        $held = $this->jobRow(['finished_at' => $b + 545.0]);
        $this->retention()->run();
        self::assertTrue($this->exists($held));
        $state = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', Retention::STATE_KEY)->value('value'), true);
        self::assertFalse($state['behind'], 'A held row does not make retention behind.');

        Carbon::setTestNow();
    }

    /**
     * The tag rows of a tag in orrery_job_tags (or orrery_failed_tags).
     */
    private function tagCount(string $tag, string $table = 'orrery_job_tags'): int
    {
        return $this->mirror()->table($table)->where('tag', $tag)->count();
    }
}
