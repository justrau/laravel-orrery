<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\Samples;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;

/**
 * Samples::latestRuns() (one statement,
 * read by the wait gauge): the run that the latest fresh sample of each queue carries,
 * and its freshness. SamplesTest tests how the sample phase carries the
 * values from minute to minute.
 */
final class OverLimitSinceTest extends TestCase
{
    private const M = 1_790_000_040;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a reader that takes the oldest or any fresh sample instead of the latest one.
     */
    public function test_the_latest_fresh_sample_gives_its_carried_run(): void
    {
        $this->sample('database', 'reports', 0, true, self::M - 600, true);
        $this->sample('database', 'reports', 1, true, self::M - 600, true);
        $this->sample('database', 'reports', 2, true, self::M + 120, false);

        self::assertSame(['since' => self::M + 120, 'censored' => false], $this->runs(self::M + 130)["database\0reports"]);
    }

    /**
     * Rejects: a freshness bound of 181 s (the boundary is tested at 180 and 180.5).
     */
    public function test_a_latest_sample_older_than_180_seconds_gives_no_run(): void
    {
        $this->sample('database', 'reports', 1, true, self::M, false);

        self::assertArrayHasKey("database\0reports", $this->runs(self::M + 60 + 180), 'a sample 180 s old is fresh');
        self::assertArrayNotHasKey("database\0reports", $this->runs(self::M + 60 + 180.5), 'a sample older than 180 s is stale');
    }

    /**
     * Rejects: a reader that keeps the run of an earlier over-limit sample when the latest one is under the limit.
     */
    public function test_a_latest_sample_below_the_limit_or_no_sample_gives_no_run(): void
    {
        $this->sample('database', 'reports', 0, true, self::M, true);
        $this->sample('database', 'reports', 1, true, self::M, true);
        $this->sample('database', 'reports', 2, false, null, false);

        self::assertSame([], $this->runs(self::M + 130));
    }

    /**
     * Rejects: a reader keyed by the queue only (another connection's run leaks in).
     */
    public function test_other_queues_and_connections_are_separate(): void
    {
        $this->sample('database', 'reports', 2, true, self::M, true);
        $this->sample('other', 'reports', 2, false, null, false);
        $this->sample('database', 'mail', 2, true, self::M + 60, false);

        self::assertSame([
            "database\0mail" => ['since' => self::M + 60, 'censored' => false],
            "database\0reports" => ['since' => self::M, 'censored' => true],
        ], $this->sorted($this->runs(self::M + 130)));
    }

    /**
     * Rejects: a threshold of 0 that is "over" for a queue with a backlog.
     */
    public function test_threshold_0_is_never_over_the_limit_after_a_tick(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::M + 5, 'UTC'));
        DB::connection(TestEnvironment::PRIMARY)->table('jobs')->insert([
            'queue' => 'reports', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => self::M - 3600, 'created_at' => self::M - 3600,
        ]);

        $this->app['config']->set('orrery.waits', ['*' => 0]);
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertSame([], $this->runs(self::M + 5), 'a disabled limit is never over');

        Carbon::setTestNow(Carbon::createFromTimestamp(self::M + 65, 'UTC'));
        $this->app['config']->set('orrery.waits', ['*' => 60]);
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertSame(["database\0reports" => ['since' => self::M + 60, 'censored' => false]], $this->runs(self::M + 65), 'the run starts after the sample below the limit');
    }

    /**
     * @return array<string, array{since: int, censored: bool}>
     */
    private function runs(float $now): array
    {
        return $this->app->make(Samples::class)->latestRuns($now);
    }

    /**
     * @param  array<string, array{since: int, censored: bool}>  $runs
     * @return array<string, array{since: int, censored: bool}>
     */
    private function sorted(array $runs): array
    {
        ksort($runs);

        return $runs;
    }

    private function sample(string $connection, string $queue, int $minute, bool $over, ?int $since, bool $censored): void
    {
        $this->mirror()->table('orrery_samples')->insert([
            'scope' => 'queue',
            'series' => SampleCounts::series('queue', $connection, $queue),
            'connection' => $connection,
            'queue' => $queue,
            'minute' => self::M + 60 * $minute,
            'ready' => $over ? 5 : 0,
            'oldest_age' => $over ? '90.000000' : null,
            'workers' => 1,
            'over_limit' => $over,
            'over_limit_since' => $since,
            'over_limit_since_censored' => $censored,
        ]);
    }
}
