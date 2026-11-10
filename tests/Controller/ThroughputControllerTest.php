<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\Throughput;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\ControllerTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/throughput?minutes=: 60-second buckets
 * of completed and failed rows over [now − minutes × 60, now), the
 * retention horizon of each series, and the markers of the window. The
 * counts frontier M is an hour before the window, so every minute here is
 * decided by the scan of the retained rows (SampleCountsTest has the stored
 * minutes and the read rule).
 */
final class ThroughputControllerTest extends ControllerTest
{
    /** A minute boundary. */
    private const M = 1_790_000_040;

    /** The request time: 12.5 s into the minute M. */
    private const NOW = self::M + 12.5;

    /** The counts frontier: before every window of these tests. */
    private const FRONTIER = self::M - 7200;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
        $this->app['config']->set('orrery.trim.completed', 60);
        $this->app['config']->set('orrery.trim.silenced', 60);
        $this->app['config']->set('orrery.trim.failed', 10080);
        $this->app['config']->set('orrery.trim.unknown', 10080);
        $this->mirror()->table('orrery_settings')->insert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) self::FRONTIER],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal(self::NOW)],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Known limit: it would pass a bucket boundary that is off by less than one microsecond (the latest row is at +59.999999 s).
     */
    public function test_completed_and_failed_rows_in_60_second_buckets(): void
    {
        // Fractional times in the second half of a minute: a bucket that rounds would move them.
        $this->row('completed', ['finished_at' => self::M + 1.25]);
        $this->row('completed', ['finished_at' => self::M - 60 + 50.5]);
        $this->row('completed', ['finished_at' => self::M - 60 + 59.999999, 'silenced' => true]);
        $this->row('failed', ['failed_at' => self::M - 60 + 40.75, 'finished_at' => self::M + 2]);
        $this->row('failed', ['failed_at' => self::M - 240]);
        // A retried row that waits again keeps its latest failure; a reserved row is no outcome.
        $this->row('pending', ['finished_at' => self::M + 3, 'failed_at' => self::M + 3]);
        $this->row('reserved', ['finished_at' => self::M + 4]);
        // Outside the window [now - 300, now), but inside the minutes that it touches: each minute is counted whole.
        $this->row('completed', ['finished_at' => self::NOW - 301]);
        $this->row('completed', ['finished_at' => self::NOW + 1]);

        $response = $this->getJson('/orrery/api/throughput?minutes=5')->assertOk();

        self::assertSame(self::NOW, $response->json('as_of'));
        self::assertSame(self::NOW - 300, $response->json('from'));
        self::assertSame(self::NOW, $response->json('to'));
        self::assertSame(60, $response->json('bucket_seconds'));
        self::assertSame(['completed' => self::NOW - 3600, 'failed' => self::NOW - 3600], $response->json('retention_from'), 'the trim horizons (60 minutes)');
        self::assertSame([
            // The first minute is counted whole (the row at now - 301 is in it): partial, because the window cuts it.
            ['at' => self::M - 300, 'completed' => 1, 'failed' => 0, 'partial' => true],
            ['at' => self::M - 240, 'completed' => 0, 'failed' => 1, 'partial' => false],
            ['at' => self::M - 180, 'completed' => 0, 'failed' => 0, 'partial' => false],
            ['at' => self::M - 120, 'completed' => 0, 'failed' => 0, 'partial' => false],
            ['at' => self::M - 60, 'completed' => 2, 'failed' => 1, 'partial' => false],
            ['at' => self::M, 'completed' => 2, 'failed' => 1, 'partial' => true],
        ], $response->json('buckets'), 'the first bucket starts before the window and the current one is not over: both partial');
    }

    /**
     * Known limit: it would pass a horizon that uses trim.completed alone when trim.silenced is not smaller (the second data set guards that).
     *
     * @param  array{completed: int, silenced: int, failed: int, unknown: int}  $trim
     */
    #[DataProvider('horizons')]
    public function test_buckets_before_the_retention_horizon_are_absent(array $trim, int $completedMinutes, int $failedMinutes): void
    {
        foreach ($trim as $key => $minutes) {
            $this->app['config']->set('orrery.trim.'.$key, $minutes);
        }

        $response = $this->getJson('/orrery/api/throughput?minutes=10')->assertOk();

        self::assertSame(
            ['completed' => self::NOW - $completedMinutes * 60, 'failed' => self::NOW - $failedMinutes * 60],
            $response->json('retention_from'),
        );

        foreach ($response->json('buckets') as $bucket) {
            $at = $bucket['at'];

            foreach (['completed' => $completedMinutes, 'failed' => $failedMinutes] as $series => $minutes) {
                $horizon = self::NOW - $minutes * 60;

                if ($at < $horizon) {
                    // A minute that starts before the horizon may have lost rows: unknown, never a partial count.
                    self::assertNull($bucket[$series], "{$series} at {$at} starts before the horizon: unknown");
                } else {
                    self::assertSame(0, $bucket[$series], "{$series} at {$at} is inside the horizon: a known zero");
                }
            }

            self::assertSame($at + 60 > self::NOW || $at < self::NOW - 600, $bucket['partial'], "partial at {$at}: the current bucket and the one that the window cuts");
        }

        self::assertSame((int) (floor((self::NOW - 600) / 60) * 60), $response->json('buckets.0.at'), 'the buckets start at the window');
    }

    /**
     * Rejects: a horizon clamped to a fixed 24 hours (a review nit): with `samples.retention_hours` 1, a minute
     * older than an hour is retired, so retention_from is never earlier than now − 1 h (the frontier, 2 h back,
     * would else stand).
     * Still passes: a clamp that reads the setting once per process (one value per test here).
     */
    public function test_the_horizon_follows_samples_retention_hours(): void
    {
        foreach (['completed', 'silenced', 'failed', 'unknown'] as $key) {
            $this->app['config']->set('orrery.trim.'.$key, 10080);
        }

        $this->app['config']->set('orrery.samples.retention_hours', 1);

        $response = $this->getJson('/orrery/api/throughput?minutes=10')->assertOk();

        self::assertSame(['completed' => self::NOW - 3600, 'failed' => self::NOW - 3600], $response->json('retention_from'));
    }

    /**
     * @return array<string, array{0: array{completed: int, silenced: int, failed: int, unknown: int}, 1: int, 2: int}>
     */
    public static function horizons(): array
    {
        return [
            // The failed horizon is the shortest of every trim: a retried failure can be trimmed as completed, silenced or unknown.
            'trim.completed below the window' => [['completed' => 3, 'silenced' => 60, 'failed' => 10080, 'unknown' => 10080], 3, 3],
            'trim.silenced below the window' => [['completed' => 60, 'silenced' => 4, 'failed' => 10080, 'unknown' => 10080], 4, 4],
            'trim.failed below the window' => [['completed' => 60, 'silenced' => 60, 'failed' => 2, 'unknown' => 10080], 60, 2],
            'trim.unknown below the window' => [['completed' => 60, 'silenced' => 60, 'failed' => 10080, 'unknown' => 5], 60, 5],
            // The horizon is the trim's, not the window's (retention_from does not depend on `minutes`).
            'the trims are longer than the window' => [['completed' => 60, 'silenced' => 60, 'failed' => 60, 'unknown' => 60], 60, 60],
        ];
    }

    /**
     * The failed series counts every retained row whose failed_at is in the
     * window, whatever its current status: a finalised
     * retry keeps failed_at. A row that failed twice holds only its latest
     * failed_at, so it counts once, at its latest failure.
     *
     * Rejects: a failed series filtered on `status = failed`.
     */
    public function test_failed_band_keeps_retried_failures(): void
    {
        // A burst of three failures; two were retried and then completed, one waits again.
        $this->row('failed', ['failed_at' => self::M - 120 + 5]);
        $this->row('completed', ['failed_at' => self::M - 120 + 10, 'finished_at' => self::M - 60 + 1]);
        $this->row('pending', ['failed_at' => self::M - 120 + 15]);
        // Failed twice: only the latest failure (M - 60 + 30) is stored and counted.
        $this->row('retrying', ['failed_at' => self::M - 60 + 30]);

        $buckets = $this->getJson('/orrery/api/throughput?minutes=5')->assertOk()->json('buckets');
        $failed = array_column($buckets, 'failed', 'at');

        self::assertSame(3, $failed[self::M - 120], 'the retried failures of the burst stay in the band');
        self::assertSame(1, $failed[self::M - 60], 'the latest failure of a row that failed twice');
        self::assertSame(4, array_sum($failed));
        self::assertSame(1, array_sum(array_column($buckets, 'completed')), 'the completed series is unchanged');
    }

    /**
     * Rejects: a failed series that misses a row status (the mirror
     * statuses of the Jobs list tabs are every status of orrery_jobs).
     */
    public function test_the_failed_series_names_every_status(): void
    {
        $statuses = array_values(array_unique(JobList::TAB_STATUS));
        $expected = Throughput::STATUSES;
        sort($statuses);
        sort($expected);

        self::assertSame(['completed', 'failed', 'pending', 'reserved', 'retrying', 'unknown'], $statuses);
        self::assertSame($statuses, $expected);
    }

    /**
     * A failure that was retried, completed and then trimmed under
     * trim.completed is gone from the table, so a failed bucket before
     * now − trim.completed is unknown (absent), never a 0.
     *
     * Rejects: a failed horizon from trim.failed alone.
     */
    public function test_failed_horizon_follows_the_shortest_trim(): void
    {
        $this->app['config']->set('orrery.trim.completed', 10);
        // The retained failure inside the horizon; the trimmed one (at now - 20 min) has no row any more.
        $this->row('failed', ['failed_at' => self::NOW - 300]);

        $response = $this->getJson('/orrery/api/throughput?minutes=60')->assertOk();

        self::assertSame(self::NOW - 600, $response->json('retention_from.failed'));
        $failed = array_column($response->json('buckets'), 'failed', 'at');
        $trimmedAt = (int) (floor((self::NOW - 1200) / 60) * 60);

        // Both horizons are now − 10 min here, so the response has no bucket there at all; a bucket with a null value would also be absent.
        self::assertNull($failed[$trimmedAt] ?? null, 'the bucket of the trimmed failure is absent, not 0');
        self::assertSame(1, array_sum(array_filter($failed, static fn (?int $n): bool => $n !== null)));
    }

    /**
     * Rejects: a partial count of the minute that the trim horizon cuts (a minute is
     * known whole or unknown).
     */
    public function test_rows_before_the_horizon_are_not_counted(): void
    {
        $this->app['config']->set('orrery.trim.completed', 3);
        // Still in the table: the retention has not run since. Both are in the minute that the horizon (now - 180 s) cuts.
        $this->row('completed', ['finished_at' => self::NOW - 185]);
        $this->row('completed', ['finished_at' => self::NOW - 170]);
        $this->row('completed', ['finished_at' => self::NOW - 100]);

        $completed = array_column($this->getJson('/orrery/api/throughput?minutes=5')->assertOk()->json('buckets'), 'completed', 'at');

        self::assertNull($completed[self::M - 180], 'the cut minute is unknown');
        self::assertSame(1, $completed[self::M - 120], 'the next minute is whole');
    }

    /**
     * Known limit: it would pass a filter that takes markers from the window start rounded down to the minute (the old marker is 5 minutes before).
     */
    public function test_the_markers_of_the_window(): void
    {
        $this->marker('deploy', self::NOW - 100, 'v1.2');
        $this->marker('restart', self::NOW - 30, 'Restart requested');
        $this->marker('deploy', self::NOW - 301, 'too old');
        // At or after the window's end [from, now): excluded.
        $this->marker('deploy', self::NOW, 'at now');
        $this->marker('restart', self::NOW + 5, 'Restart requested');

        $markers = $this->getJson('/orrery/api/throughput?minutes=5')->assertOk()->json('markers');

        self::assertSame([
            ['kind' => 'restart', 'at' => self::NOW - 30, 'label' => 'Restart requested'],
            ['kind' => 'deploy', 'at' => self::NOW - 100, 'label' => 'v1.2'],
        ], array_map(static fn (array $marker): array => array_intersect_key($marker, array_flip(['kind', 'at', 'label'])), $markers));
    }

    /**
     * Known limit: it would pass a truncation flag that is true from 200 markers (the test has 201, and 199 without the flag is not tested).
     */
    public function test_more_than_200_markers_in_the_window_are_truncated(): void
    {
        $rows = [];

        for ($i = 0; $i < 201; $i++) {
            $rows[] = ['kind' => 'deploy', 'at' => Clock::toDecimal(self::NOW - 200 + $i * 0.5), 'label' => 'd'.$i, 'source' => 'orrery:deployed'];
        }

        $this->mirror()->table('orrery_markers')->insert($rows);

        $response = $this->getJson('/orrery/api/throughput?minutes=5')->assertOk();

        self::assertTrue($response->json('markers_truncated'));
        self::assertCount(200, $response->json('markers'));
        self::assertSame('d200', $response->json('markers.0.label'), 'newest first');
    }

    /**
     * Known limit: it would pass a last bucket that is computed from now - 1 µs (the result is the same at a boundary).
     */
    public function test_a_request_on_a_minute_boundary_has_no_bucket_that_starts_at_now(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::M, 'UTC'));
        $this->row('completed', ['finished_at' => self::M - 0.5]);
        $this->row('completed', ['finished_at' => self::M]);

        $response = $this->getJson('/orrery/api/throughput?minutes=5')->assertOk();

        self::assertSame([
            ['at' => self::M - 300, 'completed' => 0, 'failed' => 0, 'partial' => false],
            ['at' => self::M - 240, 'completed' => 0, 'failed' => 0, 'partial' => false],
            ['at' => self::M - 180, 'completed' => 0, 'failed' => 0, 'partial' => false],
            ['at' => self::M - 120, 'completed' => 0, 'failed' => 0, 'partial' => false],
            ['at' => self::M - 60, 'completed' => 1, 'failed' => 0, 'partial' => false],
        ], $response->json('buckets'), 'five whole buckets; a row at now is outside [from, now)');
    }

    /**
     * Known limit: it would pass a default of 60 that is also used for an invalid value (the 422 cases guard that).
     */
    public function test_the_default_is_60_minutes_and_the_bounds_are_5_and_1440(): void
    {
        self::assertSame(self::NOW - 3600, $this->getJson('/orrery/api/throughput')->assertOk()->json('from'));
        self::assertSame(self::NOW - 3600, $this->getJson('/orrery/api/throughput?minutes=60')->assertOk()->json('from'));
        self::assertSame(self::NOW - 300, $this->getJson('/orrery/api/throughput?minutes=5')->assertOk()->json('from'));
        self::assertSame(self::NOW - 86_400, $this->getJson('/orrery/api/throughput?minutes=1440')->assertOk()->json('from'));
        self::assertCount(1441, $this->getJson('/orrery/api/throughput?minutes=1440')->json('buckets'));
        self::assertCount(61, $this->getJson('/orrery/api/throughput')->json('buckets'), 'an unaligned window touches 61 minutes');
    }

    /**
     * Known limit: it would pass "05" accepted as 5 (leading zeros are not tested).
     */
    #[DataProvider('invalidMinutes')]
    public function test_an_invalid_minutes_value_gives_422(string $query): void
    {
        $this->getJson('/orrery/api/throughput?'.$query)
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_parameter']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidMinutes(): array
    {
        return [
            'below 5' => ['minutes=4'],
            'above 1440' => ['minutes=1441'],
            'a fraction' => ['minutes=5.5'],
            'a word' => ['minutes=hour'],
            'negative' => ['minutes=-10'],
            'an array' => ['minutes[]=10'],
        ];
    }

    /**
     * Known limit: it would pass milliseconds divided by 1000 when every value is an integer second (NOW has a fraction).
     */
    public function test_times_are_epoch_seconds(): void
    {
        $response = $this->getJson('/orrery/api/throughput?minutes=5')->assertOk();

        self::assertIsFloat($response->json('as_of'));
        self::assertEqualsWithDelta(self::NOW, $response->json('as_of'), 0.0001);
        self::assertIsInt($response->json('buckets.0.at'));
        self::assertSame(0, $response->json('buckets.0.at') % 60, 'a UTC minute boundary');
    }

    /**
     * @param  array<string, float|int|bool>  $values
     */
    private function row(string $status, array $values): void
    {
        $row = [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'status' => $status,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => Clock::toDecimal(self::NOW - 3000),
            'updated_at' => Clock::toDecimal(self::NOW),
        ];

        foreach ($values as $column => $value) {
            $row[$column] = is_bool($value) ? $value : Clock::toDecimal((float) $value);
        }

        $this->mirror()->table('orrery_jobs')->insert($row);
    }

    private function marker(string $kind, float $at, string $label): void
    {
        $this->mirror()->table('orrery_markers')->insert([
            'kind' => $kind,
            'at' => Clock::toDecimal($at),
            'label' => $label,
            'source' => $kind === 'deploy' ? 'orrery:deployed' : 'queue:restart',
        ]);
    }
}
