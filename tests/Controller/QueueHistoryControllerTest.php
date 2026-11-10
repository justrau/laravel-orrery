<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\ControllerTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/queues/history?connection=&queue=&minutes= (the queue state and
 * the counts of orrery_samples).
 */
final class QueueHistoryControllerTest extends ControllerTest
{
    /** A minute boundary. */
    private const M = 1_790_000_040;

    /** The request time: 12.5 s into the minute M. */
    private const NOW = self::M + 12.5;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Known limit: it would pass a window that starts one minute earlier if no sample were in that minute (one is, at M - 300).
     */
    public function test_the_samples_of_the_window_as_60_second_buckets(): void
    {
        $this->sample('database', 'reports', self::M - 300, 9, 400.0, true);
        $this->sample('database', 'reports', self::M - 240, 4, 100.5, true);
        $this->sample('database', 'reports', self::M - 120, 2, null, false);
        $this->sample('database', 'reports', self::M, 1, 3.0, false);
        $this->sample('database', 'mail', self::M - 60, 7, 1.0, false);
        $this->sample('other', 'reports', self::M - 60, 7, 1.0, false);

        $response = $this->getJson('/orrery/api/queues/history?connection=database&queue=reports&minutes=5')->assertOk();

        self::assertSame('database', $response->json('connection'));
        self::assertSame('reports', $response->json('queue'));
        self::assertSame(self::NOW, $response->json('as_of'));
        self::assertSame(self::NOW, $response->json('to'));
        self::assertSame(self::M - 240, $response->json('from'), 'five buckets: the current one and four before it');
        self::assertSame(60, $response->json('bucket_seconds'));
        self::assertSame(self::NOW - 86_400, $response->json('retention_from'), 'the 24-hour retention horizon');
        self::assertNull($response->json('coverage_from'));
        self::assertSame([
            ['at' => self::M - 240, 'ready' => 4, 'oldest_age' => 100.5, 'over_limit' => true, 'completed' => null, 'failed' => null, 'partial' => false],
            ['at' => self::M - 120, 'ready' => 2, 'oldest_age' => null, 'over_limit' => false, 'completed' => null, 'failed' => null, 'partial' => false],
            ['at' => self::M, 'ready' => 1, 'oldest_age' => 3.0, 'over_limit' => false, 'completed' => 0, 'failed' => 0, 'partial' => true],
        ], $response->json('buckets'), 'missing minutes are absent, other queues are not included, and without a counts frontier only the current minute is counted (scanned)');
    }

    /**
     * Rejects: counts zero-filled for an unknown minute, counts of another series (HIGH beside high,
     * another connection), a scan before M, and a minute with known counts dropped because it was
     * not sampled.
     */
    public function test_the_buckets_carry_the_counts_of_the_series_by_the_read_rule(): void
    {
        $series = SampleCounts::series('queue', 'database', 'high');
        // M = M - 120: the minutes before it are stored, M - 120 and later are scanned.
        $this->mirror()->table('orrery_settings')->insert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) (self::M - 120)],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal(self::M - 100)],
        ]);
        // M - 240 is covered with counts, M - 180 is not covered (unknown).
        $this->mirror()->table(SampleCounts::TABLE)->insert([
            ['scope' => 'total', 'series' => SampleCounts::series('total'), 'connection' => null, 'queue' => null, 'minute' => self::M - 240, 'completed' => 9, 'failed' => 2],
            ['scope' => 'queue', 'series' => $series, 'connection' => 'database', 'queue' => 'high', 'minute' => self::M - 240, 'completed' => 4, 'failed' => 1],
            ['scope' => 'queue', 'series' => SampleCounts::series('queue', 'database', 'HIGH'), 'connection' => 'database', 'queue' => 'HIGH', 'minute' => self::M - 240, 'completed' => 5, 'failed' => 1],
            ['scope' => 'queue', 'series' => $series, 'connection' => 'database', 'queue' => 'high', 'minute' => self::M - 180, 'completed' => 7, 'failed' => 0],
        ]);
        $this->sample('database', 'high', self::M - 180, 3, 1.0, false);
        // Scanned minutes: two completions of high and one failure, one completion of HIGH and one of another connection.
        $this->finished('high', self::M - 120 + 5.0);
        $this->finished('high', self::M + 1.0);
        $this->finished('high', self::M + 2.0, failed: true);
        $this->finished('HIGH', self::M + 3.0);
        $this->finished('high', self::M + 4.0, connection: 'other');

        $buckets = $this->getJson('/orrery/api/queues/history?connection=database&queue=high&minutes=5')->assertOk()->json('buckets');

        self::assertSame([
            ['at' => self::M - 240, 'ready' => null, 'oldest_age' => null, 'over_limit' => null, 'completed' => 4, 'failed' => 1, 'partial' => false],
            ['at' => self::M - 180, 'ready' => 3, 'oldest_age' => 1.0, 'over_limit' => false, 'completed' => null, 'failed' => null, 'partial' => false],
            ['at' => self::M - 120, 'ready' => null, 'oldest_age' => null, 'over_limit' => null, 'completed' => 1, 'failed' => 0, 'partial' => false],
            ['at' => self::M - 60, 'ready' => null, 'oldest_age' => null, 'over_limit' => null, 'completed' => 0, 'failed' => 0, 'partial' => false],
            ['at' => self::M, 'ready' => null, 'oldest_age' => null, 'over_limit' => null, 'completed' => 1, 'failed' => 1, 'partial' => true],
        ], $buckets);
    }

    /**
     * Rejects: a series digest of the requested alias instead of its primary alias:
     * two queue connections on one jobs table store one series under the first name, so a
     * request under the second name would read nothing in the history.
     */
    public function test_an_alias_of_a_shared_jobs_table_reads_the_series_of_its_primary_alias(): void
    {
        $this->app['config']->set('queue.connections.database-twin', $this->app['config']->get('queue.connections.database'));
        $this->mirror()->table('orrery_settings')->insert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) (self::M - 60)],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal(self::M - 50)],
        ]);
        $this->mirror()->table(SampleCounts::TABLE)->insert([
            ['scope' => 'total', 'series' => SampleCounts::series('total'), 'connection' => null, 'queue' => null, 'minute' => self::M - 120, 'completed' => 4, 'failed' => 0],
            ['scope' => 'queue', 'series' => SampleCounts::series('queue', 'database', 'reports'), 'connection' => 'database', 'queue' => 'reports', 'minute' => self::M - 120, 'completed' => 4, 'failed' => 0],
        ]);
        $this->sample('database', 'reports', self::M - 120, 6, 2.0, false);
        // A scanned completion through the second name: the samples count it under the primary alias.
        $this->finished('reports', self::M - 60 + 5.0, connection: 'database-twin');

        foreach (['database', 'database-twin'] as $name) {
            $buckets = array_column($this->getJson('/orrery/api/queues/history?connection='.$name.'&queue=reports&minutes=5')->assertOk()->json('buckets'), null, 'at');
            self::assertSame([6, 4], [$buckets[self::M - 120]['ready'], $buckets[self::M - 120]['completed']], "{$name}: the state and the stored count of the primary series");
            self::assertSame(1, $buckets[self::M - 60]['completed'], "{$name}: the scanned completion of either name");
        }
    }

    /**
     * Known limit: it would pass a default of 60 that is also used for an invalid value (the 422 cases guard that).
     */
    public function test_the_default_is_60_minutes_and_the_bounds_5_and_1440_are_accepted(): void
    {
        self::assertSame(self::M - 59 * 60, $this->getJson('/orrery/api/queues/history?connection=database&queue=reports')->assertOk()->json('from'));
        self::assertSame(self::M - 59 * 60, $this->getJson('/orrery/api/queues/history?connection=database&queue=reports&minutes=')->assertOk()->json('from'));
        self::assertSame(self::M - 4 * 60, $this->getJson('/orrery/api/queues/history?connection=database&queue=reports&minutes=5')->assertOk()->json('from'));
        self::assertSame(self::M - 1439 * 60, $this->getJson('/orrery/api/queues/history?connection=database&queue=reports&minutes=1440')->assertOk()->json('from'));
    }

    /**
     * Rejects: a queue named in the path, where `.` and `..` segments are resolved (by a browser, or here by a
     * name that the read cleans), so `x/../default` reads the queue `default`; and a missing or empty name read as
     * a default.
     */
    public function test_dot_segments_stay_part_of_the_queue_name(): void
    {
        $this->sample('database', 'default', self::M, 7, 1.0, false);

        foreach (['x/../default', 'reports/./daily', '../other/q'] as $name) {
            $response = $this->getJson('/orrery/api/queues/history?'.http_build_query(['connection' => 'database', 'queue' => $name, 'minutes' => 5]))->assertOk();
            self::assertSame($name, $response->json('queue'));
            self::assertNotContains(7, array_column((array) $response->json('buckets'), 'ready'), "{$name}: not the samples of the queue default");
        }

        self::assertSame([7], array_column((array) $this->getJson('/orrery/api/queues/history?connection=database&queue=default&minutes=5')->assertOk()->json('buckets'), 'ready'), 'the control');

        foreach (['connection=database', 'connection=database&queue=', 'queue=default', 'connection=&queue=default', 'connection=database&queue[]=x'] as $query) {
            $this->getJson('/orrery/api/queues/history?'.$query)->assertStatus(422)->assertJsonPath('error', 'invalid');
        }
    }

    /**
     * Known limit: it would pass a route that decodes an encoded slash only on some servers (the test uses the framework's request).
     */
    public function test_a_queue_name_with_a_slash_is_one_queue(): void
    {
        $this->sample('database', 'team/reports', self::M, 3, 1.0, false);

        $response = $this->getJson('/orrery/api/queues/history?connection=database&queue=team%2Freports&minutes=5')->assertOk();

        self::assertSame('team/reports', $response->json('queue'));
        self::assertSame([3], array_column((array) $response->json('buckets'), 'ready'));
    }

    /**
     * Known limit: it would pass a check that accepts "05" as 5 (leading zeros are allowed and not tested as invalid).
     */
    #[DataProvider('invalidMinutes')]
    public function test_invalid_minutes_give_422(string $query): void
    {
        $this->getJson('/orrery/api/queues/history?connection=database&queue=reports&'.$query)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_parameter');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidMinutes(): array
    {
        return [
            'below 5' => ['minutes=4'],
            'above 1440' => ['minutes=1441'],
            'zero' => ['minutes=0'],
            'text' => ['minutes=hour'],
            'fraction' => ['minutes=60.5'],
            'negative' => ['minutes=-60'],
            'array' => ['minutes[]=60'],
        ];
    }

    private function sample(string $connection, string $queue, int $at, int $ready, ?float $oldest, bool $over): void
    {
        $this->mirror()->table('orrery_samples')->upsert([
            'scope' => 'queue',
            'series' => SampleCounts::series('queue', $connection, $queue),
            'connection' => $connection,
            'queue' => $queue,
            'minute' => $at,
            'ready' => $ready,
            'oldest_age' => $oldest === null ? null : sprintf('%.6F', $oldest),
            'workers' => 1,
            'over_limit' => $over,
        ], ['scope', 'series', 'minute'], ['ready', 'oldest_age', 'workers', 'over_limit']);
    }

    /**
     * A finished mirror row: completed by finished_at, or failed by failed_at.
     */
    private function finished(string $queue, float $at, bool $failed = false, string $connection = 'database'): void
    {
        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => strtolower((string) Str::uuid()),
            'connection' => $connection,
            'queue' => $queue,
            'name' => 'App\\Jobs\\Report',
            'type' => 'job',
            'status' => $failed ? 'failed' : 'completed',
            'silenced' => false,
            'monitored' => false,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => Clock::toDecimal($at - 1),
            'finished_at' => Clock::toDecimal($at),
            'failed_at' => $failed ? Clock::toDecimal($at) : null,
            'updated_at' => Clock::toDecimal($at),
        ]);
    }
}
