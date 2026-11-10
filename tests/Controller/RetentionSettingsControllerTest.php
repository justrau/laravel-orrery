<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Maintenance\RetentionSettings;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * GET /api/settings/retention and the `retention_config` field of
 * /api/health: the global values, the overrides, the
 * validation errors, the override entries that match no known queue, and
 * the effective values of the known queues (the `queue` rows of the latest
 * sampled minute, the configured waits and connections, each database
 * connection's default queue and the live workers' queues).
 */
final class RetentionSettingsControllerTest extends ControllerTest
{
    /** A minute boundary. */
    private const M = 1_790_000_040;

    private const NOW = self::M + 12.5;

    private const GLOBAL = ['completed' => 60, 'silenced' => 60, 'failed' => 10080, 'unknown' => 10080, 'monitored' => 10080];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
        $this->app['config']->set('orrery.trim', self::GLOBAL);
        $this->app['config']->set('orrery.waits', ['*' => 60, 'database:reports' => 30]);
        $this->app['config']->set('orrery.connections', ['database' => ['a:b']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: unmatched entries from the configured connections alone (the
     * `removed` entry would be an error or matched by its old samples), a
     * known-queue list from an older sampled minute, or a precedence that
     * reports the wrong rule.
     */
    public function test_the_settings_name_the_effective_values_and_the_unmatched_entries(): void
    {
        $this->app['config']->set('orrery.trim_overrides', [
            ['connection' => 'database', 'queue' => 'stripe', 'completed' => 43200],
            ['queue' => 'emails', 'failed' => 20160],
            ['connection' => 'removed', 'completed' => 5],
            ['queue' => 'old', 'completed' => 5],
            ['queue' => 'a:b', 'unknown' => 1],
        ]);
        $this->sample('database', 'stripe', self::M - 60);
        $this->sample('removed', 'old', self::M - 60);
        $this->sample('database', 'old', self::M - 120);
        $this->heartbeat('emails,default');
        // A worker of the removed connection is still alive: its queue is not a known queue.
        $this->heartbeat('mail', 'removed', 4343);

        $body = $this->getJson('/orrery/api/settings/retention')->assertOk()->json();

        self::assertSame(self::GLOBAL, $body['global']);
        self::assertSame([
            ['connection' => 'database', 'queue' => 'stripe', 'values' => ['completed' => 43200]],
            ['connection' => null, 'queue' => 'emails', 'values' => ['failed' => 20160]],
            ['connection' => 'removed', 'queue' => null, 'values' => ['completed' => 5]],
            ['connection' => null, 'queue' => 'old', 'values' => ['completed' => 5]],
            ['connection' => null, 'queue' => 'a:b', 'values' => ['unknown' => 1]],
        ], $body['overrides']);
        self::assertSame([], $body['errors']);
        self::assertSame([2, 3], $body['unmatched'], 'a removed connection and a queue of an older minute match no known queue');
        self::assertFalse($body['truncated']);
        self::assertSame(
            [['database', 'a:b'], ['database', 'default'], ['database', 'emails'], ['database', 'reports'], ['database', 'stripe']],
            array_map(static fn (array $row): array => [$row['connection'], $row['queue']], $body['effective']),
            'the known queues of the database connections, byte order',
        );

        $effective = array_column($body['effective'], 'values', 'queue');
        self::assertSame(['minutes' => 43200, 'rule' => 'connection+queue'], $effective['stripe']['completed']);
        self::assertSame(['minutes' => 20160, 'rule' => 'queue'], $effective['emails']['failed']);
        self::assertSame(['minutes' => 1, 'rule' => 'queue'], $effective['a:b']['unknown']);
        self::assertSame(['minutes' => 60, 'rule' => 'global'], $effective['default']['completed']);
        self::assertSame(array_keys(self::GLOBAL), array_keys($effective['default']));

        $health = $this->getJson('/orrery/api/health')->assertOk()->json('retention_config');
        self::assertSame(['valid' => true, 'errors' => [], 'unmatched' => [2, 3]], $health);
    }

    /**
     * Rejects: a read API that hides an invalid configuration, or a health
     * field that calls it valid.
     */
    public function test_an_invalid_configuration_is_reported(): void
    {
        $this->app['config']->set('queue.connections.redis', ['driver' => 'redis']);
        $this->app['config']->set('orrery.trim_overrides', [['connection' => 'redis', 'completed' => 5], ['queue' => 'emails', 'failed' => -1]]);

        $body = $this->getJson('/orrery/api/settings/retention')->assertOk()->json();

        self::assertCount(2, $body['errors']);
        self::assertStringContainsString('orrery.trim_overrides.0.connection', $body['errors'][0]);
        self::assertStringContainsString('orrery.trim_overrides.1.failed', $body['errors'][1]);
        self::assertSame(['completed' => 5], $body['overrides'][0]['values']);
        self::assertSame(['failed' => null], $body['overrides'][1]['values'], 'an invalid value reads as null');

        $health = $this->getJson('/orrery/api/health')->assertOk()->json('retention_config');
        self::assertFalse($health['valid']);
        self::assertSame($body['errors'], $health['errors']);
    }

    /**
     * Rejects: an unbounded `effective` list, or a `truncated` that stays
     * false at the cap.
     */
    public function test_the_effective_list_stops_at_its_cap(): void
    {
        $queues = [];

        for ($i = 0; $i <= RetentionSettings::MAX_ROWS; $i++) {
            $queues[] = sprintf('q%04d', $i);
        }

        $this->app['config']->set('orrery.waits', ['*' => 60]);
        $this->app['config']->set('orrery.connections', ['database' => $queues]);
        $this->app['config']->set('orrery.trim_overrides', [['queue' => 'q1000', 'completed' => 1]]);

        $body = $this->getJson('/orrery/api/settings/retention')->assertOk()->json();

        self::assertCount(RetentionSettings::MAX_ROWS, $body['effective']);
        self::assertTrue($body['truncated']);
        self::assertSame([], $body['unmatched'], 'the entry matches a known queue past the cap');
    }

    /**
     * Rejects: an empty unmatched list while the mirror cannot be read
     * (unknown is never "none").
     */
    #[FreshSchema]
    public function test_unmatched_is_unknown_when_the_mirror_cannot_be_read(): void
    {
        $this->app['config']->set('orrery.trim_overrides', [['queue' => 'nowhere', 'completed' => 5]]);
        $this->mirrorOutage();

        $body = $this->getJson('/orrery/api/settings/retention')->assertOk()->json();

        self::assertNull($body['unmatched']);
        self::assertNull($body['truncated']);
        self::assertSame([], $body['errors']);
        self::assertContains(['database', 'default'], array_map(static fn (array $row): array => [$row['connection'], $row['queue']], $body['effective']), 'the configured queues stay');
    }

    /**
     * A configuration published before 2026-11 has no `trim_overrides`:
     * no overrides, no error.
     *
     * Rejects: a reader that requires the key (an error for a missing list).
     */
    public function test_a_configuration_without_trim_overrides_has_no_overrides(): void
    {
        $this->app['config']->set('orrery', array_diff_key((array) $this->app['config']->get('orrery'), ['trim_overrides' => true]));
        self::assertNull($this->app['config']->get('orrery.trim_overrides'));

        $body = $this->getJson('/orrery/api/settings/retention')->assertOk()->json();

        self::assertSame([], $body['overrides']);
        self::assertSame([], $body['errors']);
        self::assertSame([], $body['unmatched']);
        self::assertSame(['minutes' => 60, 'rule' => 'global'], $body['effective'][0]['values']['completed']);
        self::assertSame(['valid' => true, 'errors' => [], 'unmatched' => []], $this->getJson('/orrery/api/health')->assertOk()->json('retention_config'));
    }

    private function sample(string $connection, string $queue, int $minute): void
    {
        $this->mirror()->table(SampleCounts::TABLE)->insert([
            'scope' => 'queue',
            'series' => SampleCounts::series('queue', $connection, $queue),
            'connection' => $connection,
            'queue' => $queue,
            'minute' => $minute,
            'completed' => 1,
            'failed' => 0,
        ]);
    }

    private function heartbeat(string $queues, string $connection = 'database', int $pid = 4242): void
    {
        $started = self::NOW - 60;

        $this->mirror()->table('orrery_worker_heartbeats')->insert([
            'worker_id' => sprintf('host:%d:%.6F', $pid, $started),
            'host' => 'host',
            'pid' => $pid,
            'started_at' => Clock::toDecimal($started),
            'mode' => 'daemon',
            'connection' => $connection,
            'subscribed_queues' => $queues,
            'current_queue' => 'emails',
            'options' => '{}',
            'last_seen_at' => Clock::toDecimal(self::NOW - 1),
        ]);
    }
}
