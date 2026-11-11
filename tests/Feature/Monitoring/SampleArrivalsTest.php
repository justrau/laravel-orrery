<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Justrau\Orrery\Database\Schema\SchemaOutdated;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Monitoring\BlindGaps;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Retention\RetentionTestCase;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageStatements;
use RuntimeException;

/**
 * The arrivals of the per-minute samples: orrery_samples.pushed,
 * counted by pushed_at over every status into the `queue`, `job` and
 * `total` rows; the stale-row replace step, the write chunks, the clear, the
 * `pushed_at` retention floor, and the outdated schema.
 */
final class SampleArrivalsTest extends RetentionTestCase
{
    /** A minute boundary. */
    private const B = 1_790_000_040;

    private const MAIL = 'App\\Jobs\\Mail';

    private const REPORT = 'App\\Jobs\\Report';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The time share would stop a run on a loaded server.
        $app['config']->set('orrery.samples.count_seconds', 600);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Hooks::$callback = null;

        parent::tearDown();
    }

    /**
     * Rejects: arrivals counted from one status only (or by finished_at), a quiet minute whose
     * total row has null arrivals (it must read as a covered 0), a queue or job row without its
     * arrivals, and arrivals of the current minute.
     */
    public function test_arrivals_are_counted_per_minute_over_every_status(): void
    {
        $statuses = ['pending', 'reserved', 'completed', 'failed', 'retrying', 'unknown'];
        $expected = ['alpha' => 0, 'beta' => 0, self::MAIL => 0, self::REPORT => 0];

        foreach ($statuses as $i => $status) {
            $queue = $i % 2 === 0 ? 'alpha' : 'beta';
            $name = $i % 3 === 0 ? self::MAIL : self::REPORT;
            // Pushed in minute B; any outcome is long after it.
            $this->mirrorJob(['queue' => $queue, 'name' => $name, 'status' => $status, 'pushed_at' => self::B + 1.0 + $i, 'finished_at' => in_array($status, ['completed', 'failed', 'unknown'], true) ? self::B + 250.0 : null]);
            $expected[$queue]++;
            $expected[$name]++;
        }

        // The current minute is not counted.
        $this->mirrorJob(['status' => 'pending', 'pushed_at' => self::B + 300.0 + 1.0, 'finished_at' => null]);
        $this->at(self::B + 300 + 10.0);
        $this->aggregate();

        self::assertSame(6, $this->pushedOf('total', self::B));
        self::assertSame(0, $this->pushedOf('total', self::B + 60), 'A covered quiet minute has 0 arrivals, not null.');
        self::assertSame($expected['alpha'], $this->pushedOf('queue', self::B, ['database', 'alpha']));
        self::assertSame($expected['beta'], $this->pushedOf('queue', self::B, ['database', 'beta']));
        self::assertSame($expected[self::MAIL], $this->pushedOf('job', self::B, [self::MAIL]));
        self::assertSame($expected[self::REPORT], $this->pushedOf('job', self::B, [self::REPORT]));
        self::assertSame(0, $this->mirror()->table(SampleCounts::TABLE)->where('minute', self::B + 300)->where('scope', 'total')->count(), 'The current minute is not counted.');
    }

    /**
     * Rejects: a stale-row check that ignores `pushed` (a push-only group whose rows vanished
     * keeps its old arrivals), and a replace step that leaves `pushed` on a stale row.
     */
    public function test_a_push_only_group_whose_rows_vanish_is_recounted_to_zero(): void
    {
        $x = self::B + 120;
        $this->at(self::B + 300 + 10.0);
        // The only row of queue mail in minute X: pushed, still waiting.
        $mail = $this->mirrorJob(['queue' => 'mail', 'name' => self::MAIL, 'status' => 'pending', 'pushed_at' => $x + 5.0, 'finished_at' => null]);
        $this->mirrorJob(['queue' => 'default', 'name' => self::REPORT, 'status' => 'pending', 'pushed_at' => $x + 6.0, 'finished_at' => null]);
        $this->aggregate();
        self::assertSame(1, $this->pushedOf('queue', $x, ['database', 'mail']));
        self::assertSame(2, $this->pushedOf('total', $x));

        // The mail job is forgotten before the minute leaves the recount window.
        $this->mirror()->table('orrery_jobs')->where('uuid', $mail)->delete();
        $this->at(self::B + 360 + 10.0);
        $this->aggregate();

        self::assertSame(0, $this->pushedOf('queue', $x, ['database', 'mail']), 'The vanished push-only group reads 0.');
        self::assertNull($this->pushedOf('job', $x, [self::MAIL]), 'The vanished job row is deleted.');
        self::assertSame(1, $this->pushedOf('job', $x, [self::REPORT]));
        self::assertSame(1, $this->pushedOf('total', $x));
    }

    /**
     * Rejects: write chunks sized for the columns before `pushed` (queue rows 108 × 10 and job
     * rows 122 × 9 bindings pass the 999 of old SQLite builds).
     * Still passes: chunks smaller than needed (the test bounds them from above only).
     */
    public function test_the_write_chunks_stay_within_999_bindings(): void
    {
        $rows = [];

        for ($i = 0; $i < 260; $i++) {
            $rows[] = ['queue' => 'q'.$i, 'name' => 'App\\Jobs\\J'.$i, 'status' => 'pending', 'pushed_at' => self::B + 1.0, 'finished_at' => null];
        }

        $this->mirrorJobs($rows);
        $this->at(self::B + 300 + 10.0);
        $upserts = [];
        PackageStatements::listen($this->app, static function (string $kind, string $sql) use (&$upserts): void {
            if ($kind === PackageSession::STATEMENT && str_contains($sql, 'orrery_samples') && str_starts_with(strtolower(ltrim($sql)), 'insert')) {
                $upserts[] = substr_count($sql, '?');
            }
        });

        try {
            $this->aggregate();
        } finally {
            PackageStatements::reset();
        }

        self::assertSame(260, $this->pushedOf('total', self::B));
        self::assertGreaterThanOrEqual(5, count($upserts), 'The queue and job rows of the minute need more than one chunk each.');
        self::assertLessThanOrEqual(999, max($upserts), 'Bindings per statement: '.json_encode($upserts));
    }

    /**
     * Rejects: a clear that leaves the arrivals of the queue rows (or sets them to 0: a cleared
     * minute would then read as a covered quiet minute).
     */
    public function test_clear_sets_the_arrivals_to_null(): void
    {
        $this->mirrorJob(['queue' => 'mail', 'status' => 'pending', 'pushed_at' => self::B + 5.0, 'finished_at' => null]);
        $this->at(self::B + 300 + 10.0);
        $this->aggregate();
        self::assertSame(1, $this->pushedOf('queue', self::B, ['database', 'mail']));
        // A queue row that holds only arrivals of 0 and its queue state.
        $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'queue')->where('minute', self::B)->update(['ready' => 3, 'over_limit' => false]);

        $this->app->make(SampleCounts::class)->clear();

        $rows = $this->mirror()->table(SampleCounts::TABLE)->get(['scope', 'pushed', 'completed', 'ready']);
        self::assertSame(['queue'], $rows->pluck('scope')->unique()->values()->all(), 'Only the queue rows stay.');

        foreach ($rows as $row) {
            self::assertNull($row->pushed);
            self::assertSame(0, (int) $row->completed);
        }

        self::assertSame(3, (int) $rows->first()?->ready, 'The queue state stays.');
    }

    /**
     * Rejects: a counts floor on finished_at and failed_at only, which trims a row
     * whose producer's clock ran ahead of the worker's (pushed_at at or after M − 300, finished_at
     * before it) before its arrival minute is counted, and a floor that still holds once the counts
     * bound passed (the partition never finishes). The next test covers the lookahead.
     * Still passes: a range-finishing read without the floor (it only clears the cursor, so the
     * next batch select, which has the floor, finishes the range one batch later).
     * Past the bound, the trimmed row's arrival minute is recorded as invalid.
     */
    public function test_the_floor_holds_rows_by_pushed_at_until_the_bound(): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $start = self::B + 300 + 10.0;
        $this->at($start);
        $this->aggregate();
        self::assertSame(self::B + 300, $this->through());

        // M − 300 = B. The skewed row finished before the floor but was pushed after it.
        $skewed = $this->mirrorJob(['finished_at' => self::B - 50.0, 'pushed_at' => self::B + 200.0]);
        $this->mirrorJob(['finished_at' => self::B - 40.0, 'pushed_at' => self::B - 900.0]);
        $this->mirrorJob(['finished_at' => self::B - 30.0, 'pushed_at' => self::B - 900.0]);
        // One row per batch, so the range ends from a stored cursor.
        Retention::useLimitsForTesting(batch: 1);
        $this->failAggregation();

        $this->at($start + 60);

        try {
            self::assertSame(2, $this->retention()->run());
        } finally {
            Retention::useLimitsForTesting();
        }

        self::assertTrue($this->exists($skewed), 'The floor holds the row by pushed_at.');
        self::assertFalse($this->state()['behind'], 'A held row is not behind, and the range finished.');
        self::assertSame([], $this->invalidIntervals());

        // Past the bound (M moved more than count_wait_minutes ago): no floor, the row is trimmed. The scheduler ran
        // every minute while the aggregation failed, so the blind-gap decision is a minute old (BlindGaps).
        $this->mirror()->table('orrery_settings')->upsert([['key' => BlindGaps::KEY, 'value' => json_encode(['checked_through' => $start + 120 * 60, 'gaps' => []])]], ['key'], ['value']);
        $this->at($start + 121 * 60);
        $this->tick();
        self::assertFalse($this->exists($skewed), 'Past the bound the partition finishes.');
        self::assertSame([[self::B + 180, self::B + 240]], $this->invalidIntervals(), 'The arrival minute of the trimmed row is invalid.');
    }

    /**
     * Rejects: a pushed_at floor in the batch select only. A run that stops with the
     * partition unfinished checks it in the lookahead; a lookahead without the floor finds the
     * skewed row as due and reports the partition behind (lag 40 s), though the floor holds it.
     */
    public function test_the_lookahead_applies_the_pushed_at_floor(): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $start = self::B + 300 + 10.0;
        $this->at($start);
        $this->aggregate();
        $this->mirrorJob(['finished_at' => self::B - 50.0, 'pushed_at' => self::B - 900.0]);
        $skewed = $this->mirrorJob(['finished_at' => self::B - 40.0, 'pushed_at' => self::B + 200.0]);
        // One row in all: the run stops after its first batch, with the partition unfinished.
        Retention::useLimitsForTesting(batch: 1, rows: 1);

        try {
            $this->at($start + 60);
            self::assertSame(1, $this->retention()->run());
        } finally {
            Retention::useLimitsForTesting();
        }

        self::assertTrue($this->exists($skewed));
        $state = $this->state();
        self::assertSame([false, null], [$state['behind'], $state['lag_seconds']], 'The held row is not behind.');
    }

    /**
     * Rejects: a sample_counts phase on a schema before step 7 that fails with a raw query error
     * (or writes the counts without arrivals, so its minutes read as covered quiet minutes) instead
     * of the clear `schema_outdated` error through the watcher.
     */
    #[FreshSchema]
    public function test_sample_counts_on_a_schema_before_step_7_records_schema_outdated(): void
    {
        Exceptions::fake();
        $this->mirrorSchema()->table(SampleCounts::TABLE, static function (Blueprint $table): void {
            $table->dropColumn('pushed');
        });
        $this->mirrorJob(['status' => 'pending', 'pushed_at' => self::B + 5.0, 'finished_at' => null]);
        $this->at(self::B + 300 + 10.0);

        $this->tick();

        Exceptions::assertReported(static fn (WatchedOperationFailed $error): bool => $error->operation === 'tick:sample_counts'
            && $error->causeClass === SchemaOutdated::class
            && str_contains($error->getMessage(), 'schema_outdated'));
        self::assertNull($this->through(), 'No minute is published without its arrivals.');
        self::assertSame(0, $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'total')->count());
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function mirrorJob(array $columns): string
    {
        return $this->mirrorJobs([$columns])[0];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string> the uuids
     */
    private function mirrorJobs(array $rows): array
    {
        $full = [];

        foreach ($rows as $columns) {
            $finished = array_key_exists('finished_at', $columns) ? $columns['finished_at'] : self::B;
            $row = array_replace([
                'uuid' => strtolower((string) Str::uuid()),
                'connection' => 'database',
                'queue' => 'default',
                'name' => self::MAIL,
                'type' => 'job',
                'status' => 'completed',
                'silenced' => false,
                'monitored' => false,
                'payload' => '{}',
                'payload_bytes' => 2,
                'pushed_at' => $finished ?? self::B,
                'finished_at' => $finished,
                'failed_at' => null,
                'updated_at' => self::B,
            ], $columns);

            foreach (['pushed_at', 'finished_at', 'failed_at', 'updated_at'] as $time) {
                if (is_float($row[$time]) || is_int($row[$time])) {
                    $row[$time] = Clock::toDecimal((float) $row[$time]);
                }
            }

            $full[] = $row;
        }

        foreach (array_chunk($full, 50) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }

        return array_map(static fn (array $row): string => (string) $row['uuid'], $full);
    }

    /**
     * The stored arrivals of one series in one minute, or null without a row.
     *
     * @param  'total'|'queue'|'job'  $scope
     * @param  list<string>  $parts
     */
    private function pushedOf(string $scope, int $minute, array $parts = []): ?int
    {
        $row = $this->mirror()->table(SampleCounts::TABLE)->where('scope', $scope)->where('series', SampleCounts::series($scope, ...$parts))->where('minute', $minute)->first(['pushed']);

        if ($row === null) {
            return null;
        }

        self::assertNotNull($row->pushed, "The {$scope} row of minute {$minute} has arrivals.");

        return (int) $row->pushed;
    }

    private function at(float $time): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($time, 'UTC'));
    }

    private function aggregate(): int
    {
        return $this->app->make(SampleCounts::class)->run();
    }

    /** The aggregation throws on every run. */
    private function failAggregation(): void
    {
        Hooks::$callback = static function (string $point): void {
            if ($point === SampleCounts::BEFORE_LOCK) {
                throw new RuntimeException('The aggregation fails.');
            }
        };
    }

    private function through(): ?int
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', SampleCounts::COUNTED_THROUGH)->value('value');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function invalidIntervals(): array
    {
        $value = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->value('value'), true);

        return is_array($value) ? $value['intervals'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $state = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', Retention::STATE_KEY)->value('value'), true);
        self::assertIsArray($state);

        return $state;
    }
}
