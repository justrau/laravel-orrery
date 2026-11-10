<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Monitoring\NativePause;
use Justrau\Orrery\Tests\Controller\Fakes\FailingCacheStore;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * Laravel's own `queue:pause` in GET
 * /api/workload (a `queue:pause` cause in `pauses`), read through
 * NativePause. The rewrite of the probe
 * OperatorViewProbeTest::test_native_queue_pause_is_reported_as_not_paused.
 */
final class WorkloadNativePauseTest extends ControllerTest
{
    /** A minute boundary, plus 12.5 s. */
    private const NOW = 1_790_000_040 + 12.5;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('orrery.waits', ['*' => 60]);
    }

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
     * Rejects: reading only `orrery_signals` (the probe saw `paused: null` and nothing else).
     */
    public function test_queue_pause_shows_a_queue_pause_cause(): void
    {
        $this->readyJob('emails', (int) self::NOW - 10);
        $this->readyJob('reports', (int) self::NOW - 10);
        $this->app['queue']->pause('database', 'emails');

        $rows = $this->workload();

        self::assertSame([['queue', 'queue:pause']], array_map(static fn (array $cause): array => [$cause['scope'], $cause['source']], $rows['emails']['pauses']), 'Orrery has no pause of its own');
        self::assertSame([], $rows['reports']['pauses']);
        self::assertFalse($rows['reports']['paused_unknown']);
    }

    /**
     * Rejects: false on error (an unknown native pause read as "running").
     */
    public function test_throwing_manager_gives_unknown_not_false(): void
    {
        $this->readyJob('emails', (int) self::NOW - 10);
        $store = new FailingCacheStore;
        $store->failGet = true;
        $this->app['cache']->extend('orrery-failing', fn ($app) => $app['cache']->repository($store));
        $this->app['config']->set('cache.stores.orrery-failing', ['driver' => 'orrery-failing']);
        $this->app['config']->set('cache.default', 'orrery-failing');

        $rows = $this->workload();

        self::assertSame([], $rows['emails']['pauses']);
        self::assertTrue($rows['emails']['paused_unknown']);
        self::assertNull($this->app->make(NativePause::class)->isPaused('database', 'emails'));
    }

    /**
     * Rejects: a native pause read outside the shared measurement (the
     * workload asking the manager again within the lifetime of the entry),
     * and an entry that keeps the old native state after its lifetime.
     */
    public function test_a_native_pause_shows_when_the_shared_measurement_expires(): void
    {
        $this->readyJob('emails', (int) self::NOW - 10);
        self::assertSame([], $this->workload()['emails']['pauses']);

        // A native pause from the CLI lands within the lifetime of the shared measurement: the workload still shows its value.
        $this->app['queue']->pause('database', 'emails');
        self::assertSame([], $this->workload()['emails']['pauses']);

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW + 5, 'UTC'));
        self::assertSame(['queue:pause'], array_column($this->workload()['emails']['pauses'], 'source'));
    }

    /**
     * Rejects: a pause from Orrery that the shared measurement hides until its lifetime ends (the native state is cached with it).
     */
    public function test_an_orrery_queue_pause_shows_at_the_next_request(): void
    {
        $this->readyJob('emails', (int) self::NOW - 10);
        self::assertSame([], $this->workload()['emails']['pauses']);

        $this->postJson('/orrery/api/control', ['action' => 'pause', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'emails'])->assertOk();
        self::assertSame(['orrery'], array_column($this->workload()['emails']['pauses'], 'source'));

        $this->postJson('/orrery/api/control', ['action' => 'continue', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'emails'])->assertOk();
        self::assertSame([], $this->workload()['emails']['pauses']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function workload(): array
    {
        $rows = [];

        foreach ((array) $this->getJson('/orrery/api/workload')->assertOk()->json() as $row) {
            self::assertIsArray($row);
            $rows[(string) $row['name']] = $row;
        }

        return $rows;
    }

    private function readyJob(string $queue, int $availableAt): void
    {
        DB::connection(TestEnvironment::PRIMARY)->table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }
}
