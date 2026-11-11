<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Justrau\Orrery\Maintenance\TickOutcome;
use Justrau\Orrery\Monitoring\BlindGaps;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\Throughput;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Retention\RetentionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The blind gaps (BlindGaps): before the counts catch up on a window longer
 * than BlindGaps::GAP_SECONDS, the spans without evidence of observation
 * become invalid minutes (unknown, never 0), and the gap is recorded.
 */
final class BlindGapsTest extends RetentionTestCase
{
    /** A minute boundary. */
    private const B = 1_790_000_040;

    /** The start of the previous tick (its TickOutcome and COUNTED_AT): 5 s into the minute B. */
    private const PREVIOUS = self::B + 5.0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The time share would stop a run on a loaded server.
        $app['config']->set('orrery.samples.count_seconds', 600);
        // The scan of the minutes from M on must reach every row of these tests.
        $app['config']->set('orrery.trim.completed', 10080);
        $app['config']->set('orrery.trim.silenced', 10080);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Hooks::$callback = null;

        parent::tearDown();
    }

    /**
     * Rejects: counting a window in which nothing observed as 0 (today's catch-up), and a gap that the record does not keep.
     * Still passes: a rule that marks one extra minute after the window (the minute of `now` is touched, so it is unknown whole).
     */
    public function test_a_window_without_evidence_reads_as_unknown_not_zero(): void
    {
        $this->frontier(self::B, self::PREVIOUS);
        $now = self::B + 20 * 60 + 3.0;
        $this->at($now);

        $this->aggregate();

        self::assertSame([[self::B, self::B + 21 * 60]], $this->invalidIntervals());
        self::assertSame(0, $this->countTotals(), 'No minute of the window is published as a covered 0.');
        self::assertSame(self::B + 20 * 60, $this->through(), 'M moves past the unknown minutes.');
        self::assertSame([[self::PREVIOUS, $now]], BlindGaps::read());

        foreach ($this->throughput(self::B, self::B + 20 * 60) as $minute => $counts) {
            self::assertSame(['completed' => null, 'failed' => null], $counts, "Minute {$minute} is unknown.");
        }
    }

    /**
     * Rejects: marking a scheduler-only outage unknown while workers observed (their outcomes are evidence).
     */
    public function test_a_scheduler_outage_with_observed_outcomes_keeps_real_counts(): void
    {
        $this->frontier(self::B, self::PREVIOUS);
        $now = self::B + 20 * 60 + 3.0;
        $finished = [];

        // A completion every 200 s, the last 173 s before now.
        for ($t = self::B + 30.0; $t < $now; $t += 200) {
            $finished[] = $t;
            $this->mirrorJob('completed', ['finished_at' => $t]);
        }

        $this->at($now);
        $this->aggregate();

        self::assertSame([], $this->invalidIntervals());
        self::assertSame([], BlindGaps::read());
        $minutes = $this->throughput(self::B, self::B + 20 * 60);
        $expected = [];

        foreach ($minutes as $minute => $counts) {
            $expected[$minute] = count(array_filter($finished, static fn (float $t): bool => SampleCounts::minuteOf($t) === $minute));
            self::assertSame(['completed' => $expected[$minute], 'failed' => 0], $counts, "Minute {$minute} is a real count.");
        }

        self::assertSame(count($finished), array_sum($expected));
    }

    /**
     * Rejects: a trigger that is off by one at GAP_SECONDS (>= instead of >), in either direction.
     */
    #[DataProvider('triggers')]
    public function test_the_trigger_is_a_window_longer_than_gap_seconds(float $window, bool $gap): void
    {
        $this->frontier(self::B, self::PREVIOUS);
        $this->at(self::PREVIOUS + $window);

        $this->aggregate();

        self::assertSame($gap ? [[self::PREVIOUS, self::PREVIOUS + $window]] : [], BlindGaps::read());
        self::assertSame($gap, $this->invalidIntervals() !== []);
        self::assertSame($gap, $this->mirror()->table('orrery_settings')->where('key', BlindGaps::KEY)->exists(), 'A window of GAP_SECONDS is not decided at all.');
    }

    /**
     * @return array<string, array{0: float, 1: bool}>
     */
    public static function triggers(): array
    {
        return [
            'exactly GAP_SECONDS' => [(float) BlindGaps::GAP_SECONDS, false],
            'just longer' => [BlindGaps::GAP_SECONDS + 0.5, true],
        ];
    }

    /**
     * Rejects: a hole of exactly GAP_SECONDS between two evidence times counted as blind, and a longer one counted as observed.
     */
    #[DataProvider('spacings')]
    public function test_a_hole_is_a_span_longer_than_gap_seconds(float $spacing, bool $blind): void
    {
        $this->frontier(self::B, self::PREVIOUS);
        $this->mirrorJob('completed', ['finished_at' => self::PREVIOUS + $spacing]);
        $this->mirrorJob('failed', ['failed_at' => self::PREVIOUS + 2 * $spacing]);
        $now = self::PREVIOUS + 2 * $spacing + 100;
        $this->at($now);

        $this->aggregate();

        self::assertSame($blind ? [
            [self::PREVIOUS, self::PREVIOUS + $spacing],
            [self::PREVIOUS + $spacing, self::PREVIOUS + 2 * $spacing],
        ] : [], BlindGaps::read());
    }

    /**
     * @return array<string, array{0: float, 1: bool}>
     */
    public static function spacings(): array
    {
        return [
            'exactly GAP_SECONDS' => [(float) BlindGaps::GAP_SECONDS, false],
            'just longer' => [BlindGaps::GAP_SECONDS + 0.5, true],
        ];
    }

    /**
     * Rejects: the first tick ever (no COUNTED_AT) treated as the end of a gap, which would make every minute before it unknown.
     */
    public function test_the_first_tick_is_not_a_gap(): void
    {
        $this->at(self::B + 20 * 60 + 3.0);

        $this->aggregate();

        self::assertSame([], BlindGaps::read());
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', BlindGaps::KEY)->value('value'));
        self::assertSame([], $this->invalidIntervals());
        self::assertSame(5, $this->countTotals(), 'The first run counts its recount window as before.');
    }

    /**
     * Rejects: a trigger on the counts' own frontier: ticks that ran while their `sample_counts` phase failed are no
     * gap, so the catch-up counts their quiet minutes as 0, as before.
     */
    public function test_ticks_whose_phases_failed_are_no_gap(): void
    {
        $now = self::B + 20 * 60 + 3.0;
        // M and COUNTED_AT are 20 minutes old, but the previous tick ran a minute ago.
        $this->frontier(self::B, self::PREVIOUS);
        $this->mirror()->table('orrery_settings')->where('key', TickOutcome::KEY)->update(['value' => json_encode([
            'started_at' => $now - 60, 'finished_at' => $now - 59, 'failed' => ['sample_counts'], 'skip_streaks' => (object) [],
        ])]);
        $this->at($now);

        $this->aggregate();

        self::assertSame([], BlindGaps::read());
        self::assertSame([], $this->invalidIntervals());
        self::assertSame(20, $this->countTotals(), 'Every minute of the failed phases is counted.');
        self::assertSame(['completed' => 0, 'failed' => 0], $this->throughput(self::B, self::B + 60)[self::B]);
    }

    /**
     * Rejects: "any evidence in the window means observed" (old workers that finish after the last tick, and new
     * workers that start before the next one, hide the off period between them), and pushed_at as evidence (a job
     * pushed while nothing observed gets its row, with the payload's push time, from a later reservation).
     */
    public function test_the_hole_between_the_last_old_and_the_first_new_observation_is_blind(): void
    {
        $this->frontier(self::B, self::PREVIOUS);
        // Before the off switch: a worker finishes two jobs after the last tick.
        $this->mirrorJob('completed', ['finished_at' => self::B + 20.0]);
        $this->mirrorJob('completed', ['finished_at' => self::B + 40.0]);
        // On again at B + 1500: a job pushed while off is reserved and completes, and one is still reserved.
        $this->mirrorJob('completed', ['pushed_at' => self::B + 700.0, 'finished_at' => self::B + 1510.0]);
        $this->mirrorJob('reserved', ['pushed_at' => self::B + 650.0, 'reserved_at' => self::B + 1500.0, 'finished_at' => null]);
        $now = self::B + 1560.0;
        $this->at($now);

        $this->aggregate();

        self::assertSame([[self::B + 40.0, self::B + 1500.0]], BlindGaps::read());
        self::assertSame([[self::B, self::B + 1500]], $this->invalidIntervals(), 'The touched minute B is unknown whole.');
        $minutes = $this->throughput(self::B, self::B + 1560);

        for ($minute = self::B; $minute < self::B + 1500; $minute += 60) {
            self::assertSame(['completed' => null, 'failed' => null], $minutes[$minute], "Minute {$minute} is unknown.");
        }

        self::assertSame(['completed' => 1, 'failed' => 0], $minutes[self::B + 1500], 'The minute after the hole is counted.');
    }

    /**
     * Rejects: an unbounded walk or record for a gap longer than the samples retention.
     */
    public function test_a_gap_longer_than_the_samples_retention_is_clipped_to_it(): void
    {
        $this->frontier(self::B, self::PREVIOUS);
        $now = self::PREVIOUS + 30 * 3600;
        $this->at($now);

        $this->aggregate();

        self::assertSame([[$now - 24 * 3600, $now]], BlindGaps::read());
        self::assertSame([[SampleCounts::minuteOf($now - 24 * 3600), SampleCounts::minuteOf($now) + 60]], $this->invalidIntervals());
    }

    /**
     * Rejects: two runs that both record the same window (a second tick on another server, or one that overlaps a
     * hung tick): the record and the invalid minutes change once.
     */
    public function test_a_concurrent_run_records_the_gap_once(): void
    {
        $this->frontier(self::B, self::PREVIOUS);
        $now = self::B + 20 * 60 + 3.0;
        $this->at($now);
        $session = $this->app->make(PackageSession::class)->mirror();
        SampleCounts::ensureInvalidRow($session);
        $gaps = $this->app->make(BlindGaps::class);
        $inner = null;

        Hooks::$callback = static function (string $point) use (&$inner, $gaps, $session, $now): void {
            if ($point === BlindGaps::BEFORE_RECORD && $inner === null) {
                $inner = [];
                $inner = $gaps->decide($session, $now, 24);
            }
        };

        $outer = $gaps->decide($session, $now, 24);

        self::assertSame([[self::PREVIOUS, $now]], $inner, 'The run that commits first records the gap.');
        self::assertSame([], $outer, 'The other run sees the newer record under the lock and writes nothing.');
        self::assertSame(1, $this->invalidVersion());
        self::assertSame([[self::PREVIOUS, $now]], BlindGaps::read());
    }

    /**
     * Rejects: a failed decision that lets the counts publish the window as 0.
     */
    public function test_a_failed_decision_publishes_nothing_and_the_next_run_decides(): void
    {
        $this->frontier(self::B, self::PREVIOUS);
        $this->at(self::B + 20 * 60 + 3.0);
        Hooks::$callback = static function (string $point): void {
            if ($point === BlindGaps::BEFORE_RECORD) {
                throw new RuntimeException('The decision fails.');
            }
        };

        try {
            $this->aggregate();
            self::fail('The run fails with its decision.');
        } catch (RuntimeException $error) {
            self::assertSame('The decision fails.', $error->getMessage());
        }

        self::assertSame(0, $this->countTotals());
        self::assertSame(self::B, $this->through());

        Hooks::$callback = null;
        $this->at(self::B + 21 * 60 + 3.0);
        $this->aggregate();

        self::assertSame([[self::PREVIOUS, self::B + 21 * 60 + 3.0]], BlindGaps::read());
        self::assertSame(0, $this->countTotals());
    }

    /**
     * Rejects: a record that grows without bound: a gap that ended before the samples retention goes at the next write.
     */
    public function test_the_record_keeps_only_the_gaps_inside_the_samples_retention(): void
    {
        $now = self::B + 20 * 60 + 3.0;
        $this->mirror()->table('orrery_settings')->insert(['key' => BlindGaps::KEY, 'value' => json_encode([
            'checked_through' => self::PREVIOUS - 7200,
            'gaps' => [[$now - 25 * 3600, $now - 24 * 3600 - 1], [$now - 24 * 3600 - 10, $now - 23 * 3600]],
        ])]);
        $this->frontier(self::B, self::PREVIOUS);
        $this->at($now);

        $this->aggregate();

        self::assertSame([[$now - 24 * 3600 - 10, $now - 23 * 3600], [self::PREVIOUS, $now]], BlindGaps::read());
    }

    /**
     * Rejects: a closed or open end of covering() on the wrong side.
     */
    public function test_covering_is_half_open(): void
    {
        $gaps = [[100.0, 200.0], [300.0, 400.0]];

        self::assertNull(BlindGaps::covering($gaps, 99.999));
        self::assertSame([100.0, 200.0], BlindGaps::covering($gaps, 100.0));
        self::assertSame([100.0, 200.0], BlindGaps::covering($gaps, 199.999));
        self::assertNull(BlindGaps::covering($gaps, 200.0));
        self::assertSame([300.0, 400.0], BlindGaps::covering($gaps, 350.0));
    }

    /**
     * Rejects: a later tick that decides the same window again after the counts caught up.
     */
    public function test_a_normal_tick_after_the_gap_decides_nothing(): void
    {
        $this->frontier(self::B, self::PREVIOUS);
        $now = self::B + 20 * 60 + 3.0;
        $this->at($now);
        $this->aggregate();
        $version = $this->invalidVersion();

        $this->at($now + 60);
        $this->aggregate();

        self::assertSame($version, $this->invalidVersion());
        self::assertSame([[self::PREVIOUS, $now]], BlindGaps::read());
    }

    /**
     * The off switch end to end through orrery:tick: a tick, then 20 minutes in which jobs run but nothing observes
     * (their jobs rows come and go without mirror rows), then a tick: those minutes are unknown, never 0, and the
     * gap is in /api/health's source.
     *
     * Rejects: today's catch-up, which stored 0 for every minute of the off period.
     */
    public function test_the_off_switch_end_to_end(): void
    {
        $this->at(self::PREVIOUS);
        $this->tick();
        $first = SampleCounts::minuteOf(self::PREVIOUS);

        // Off: jobs run unobserved.
        for ($n = 0; $n < 5; $n++) {
            $id = DB::table('jobs')->insertGetId(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => self::B, 'created_at' => self::B]);
            DB::table('jobs')->where('id', $id)->delete();
        }

        $now = self::B + 20 * 60 + 3.0;
        $this->at($now);
        $this->tick();

        self::assertSame([[self::PREVIOUS, $now]], BlindGaps::read());

        foreach ($this->throughput($first, self::B + 20 * 60) as $minute => $counts) {
            self::assertSame(['completed' => null, 'failed' => null], $counts, "Minute {$minute} is unknown, not 0.");
        }
    }

    /**
     * A scheduler-only outage end to end: the tick stops for 20 minutes while a worker observes a job every
     * 3 minutes, then the tick resumes: every minute has its real count.
     *
     * Rejects: marking a scheduler-only outage unknown.
     */
    public function test_a_scheduler_only_outage_end_to_end(): void
    {
        $this->at(self::PREVIOUS);
        $this->tick();
        $now = self::B + 20 * 60 + 3.0;
        $observed = [];

        for ($t = self::B + 20.0; $t < $now; $t += 180) {
            $uuid = $this->uuid();
            $this->observe('push', $uuid, ['jobId' => 1, 'at' => $t - 1]);
            $this->observe('reserve', $uuid, ['jobId' => 1, 'attempt' => 1, 'at' => $t - 0.5]);
            $this->observe('complete', $uuid, ['jobId' => 1, 'attempt' => 1, 'at' => $t]);
            $observed[] = SampleCounts::minuteOf($t);
        }

        $this->at($now);
        $this->tick();

        self::assertSame([], BlindGaps::read());
        $counts = array_count_values($observed);

        foreach ($this->throughput(self::B, self::B + 20 * 60) as $minute => $minuteCounts) {
            self::assertSame(['completed' => $counts[$minute] ?? 0, 'failed' => 0], $minuteCounts, "Minute {$minute} has its real count.");
        }
    }

    private function at(float $time): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($time, 'UTC'));
    }

    private function aggregate(): int
    {
        return $this->app->make(SampleCounts::class)->run();
    }

    /**
     * The state after a tick that started at $tickAt and counted through $through.
     */
    private function frontier(int $through, float $tickAt): void
    {
        $this->mirror()->table('orrery_settings')->upsert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) $through],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal($tickAt)],
            ['key' => TickOutcome::KEY, 'value' => json_encode(['started_at' => $tickAt, 'finished_at' => $tickAt + 1, 'failed' => [], 'skip_streaks' => (object) []])],
        ], ['key'], ['value']);
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

    private function invalidVersion(): int
    {
        $value = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->value('value'), true);

        return is_array($value) ? (int) $value['version'] : -1;
    }

    private function countTotals(): int
    {
        return $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'total')->count();
    }

    /**
     * @return array<int, array{completed: ?int, failed: ?int}>
     */
    private function throughput(int $from, int $to): array
    {
        return $this->app->make(Throughput::class)->minutes($from, $to)['minutes'];
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function mirrorJob(string $status, array $columns): void
    {
        $row = array_replace([
            'uuid' => strtolower((string) Str::uuid()),
            'connection' => 'database',
            'queue' => 'default',
            'name' => 'App\\Jobs\\Mail',
            'type' => 'job',
            'status' => $status,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => self::B - 600.0,
            'updated_at' => self::B,
        ], $columns);

        foreach (['pushed_at', 'reserved_at', 'finished_at', 'failed_at', 'updated_at'] as $time) {
            if (is_float($row[$time] ?? null) || is_int($row[$time] ?? null)) {
                $row[$time] = Clock::toDecimal((float) $row[$time]);
            }
        }

        $this->mirror()->table('orrery_jobs')->insert($row);
    }
}
