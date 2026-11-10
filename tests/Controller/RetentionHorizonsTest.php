<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\SampleCoverage;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * The horizons of the retention overrides: every reader
 * that derives a horizon from `trim.*` uses the shortest effective value of
 * the key over the global value and every override
 * (RetentionPolicy::shortest()); the queue history uses its own queue's
 * effective values. So a minute whose rows a short override trimmed reads
 * as unknown, never as a covered undercount.
 */
final class RetentionHorizonsTest extends ControllerTest
{
    /** A minute boundary. */
    private const M = 1_790_000_040;

    /** The request time: 12.5 s into the minute M. */
    private const NOW = self::M + 12.5;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
        $this->app['config']->set('orrery.trim', ['completed' => 60, 'silenced' => 60, 'failed' => 10080, 'unknown' => 10080, 'monitored' => 10080]);
        // The counts frontier is two hours back, so every minute of these windows is scanned.
        $this->mirror()->table('orrery_settings')->insert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) (self::M - 7200)],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal(self::NOW)],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The `emails` override trimmed its rows older than 10 minutes; the row
     * of `default` 20 minutes ago is still there, but its minute cannot be
     * vouched for across every queue.
     *
     * Rejects: a throughput horizon from the global trim.completed alone,
     * which would count the minute 20 minutes ago as covered (an undercount:
     * the emails completions of that minute are gone).
     */
    public function test_the_throughput_horizon_is_the_shortest_override(): void
    {
        $this->app['config']->set('orrery.trim_overrides', [['queue' => 'emails', 'completed' => 10]]);
        $this->row('default', 'completed', self::NOW - 1200);
        $this->row('default', 'completed', self::NOW - 300);

        $response = $this->getJson('/orrery/api/throughput?minutes=60')->assertOk();

        self::assertSame(self::NOW - 600, $response->json('retention_from.completed'));
        self::assertSame(self::NOW - 600, $response->json('retention_from.failed'), 'a failure later completed is trimmed under the completed override');
        $completed = array_column($response->json('buckets'), 'completed', 'at');
        self::assertNull($completed[self::M - 1200], 'the minute before the shortest horizon is unknown');
        self::assertSame(1, $completed[self::M - 300]);
    }

    /**
     * Rejects: a failure-group horizon from the global trim.failed alone.
     */
    public function test_the_failure_group_horizon_is_the_shortest_failed_override(): void
    {
        $this->app['config']->set('orrery.trim_overrides', [['connection' => 'database', 'queue' => 'emails', 'failed' => 30]]);

        $response = $this->getJson('/orrery/api/failures/groups?'.http_build_query(['from' => (string) (self::NOW - 3600), 'to' => (string) self::NOW]))->assertOk();

        self::assertEquals(self::NOW - 1800, $response->json('retention_from'));
    }

    /**
     * Rejects: a queue history with the shortest horizon of every queue
     * (the `default` queue's own minute would be lost), or with the global
     * value (the `emails` minute would read as covered).
     */
    public function test_the_queue_history_uses_its_own_queues_effective_values(): void
    {
        $this->app['config']->set('orrery.trim_overrides', [['queue' => 'emails', 'completed' => 10]]);
        $this->row('default', 'completed', self::M - 1200 + 5);

        $default = array_column($this->getJson('/orrery/api/queues/database/default/history?minutes=30')->assertOk()->json('buckets'), 'completed', 'at');
        $emails = array_column($this->getJson('/orrery/api/queues/database/emails/history?minutes=30')->assertOk()->json('buckets'), 'completed', 'at');

        self::assertSame(1, $default[self::M - 1200], 'the default queue keeps its rows for 60 minutes');
        self::assertArrayNotHasKey(self::M - 1200, $emails, 'the emails minute before its 10-minute horizon is unknown (absent)');
        self::assertSame(0, $emails[self::M - 300], 'a covered quiet minute of emails is 0');
    }

    /**
     * A pushed row may since have reached any trimmed state, so the arrival
     * horizon is the shortest of completed, silenced, failed and unknown
     * over every override; `monitored` only lengthens a retention.
     *
     * Rejects: an arrival horizon from the global values, or one that lets
     * a monitored override shorten it.
     */
    public function test_the_arrival_horizon_is_the_shortest_state_override(): void
    {
        $this->app['config']->set('orrery.trim_overrides', [
            ['queue' => 'emails', 'unknown' => 15],
            ['queue' => 'reports', 'monitored' => 1],
        ]);
        $this->row('default', 'completed', self::M - 1200 + 5);

        $window = $this->app->make(SampleCoverage::class)->of('total', null, self::M - 1800, self::M, ['pushed']);

        self::assertSame(self::NOW - 900, $window->scannedFrom['pushed']);
        self::assertFalse($window->known(self::M - 1200, 'pushed'), 'before now - 15 min: unknown');
        self::assertTrue($window->known(self::M - 840, 'pushed'));
    }

    private function row(string $queue, string $status, float $finishedAt): void
    {
        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'name' => 'App\\Jobs\\Horizon',
            'status' => $status,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => Clock::toDecimal($finishedAt - 1),
            'finished_at' => Clock::toDecimal($finishedAt),
            'failed_at' => $status === 'failed' ? Clock::toDecimal($finishedAt) : null,
            'updated_at' => Clock::toDecimal(self::NOW),
        ]);
    }
}
