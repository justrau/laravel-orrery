<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Queue\Connectors\DatabaseConnector as LaravelDatabaseConnector;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Tests\ControllerTest;
use RuntimeException;

/**
 * `GET /api/workload` for a queue connection whose
 * queue object is not the package's. Its pushes and reservations are not
 * mirrored, so the mirror counts of running jobs and arrivals are unknown
 * (null), never 0. The outcomes are mirrored, so `out_per_min` stays.
 */
final class WorkloadInstrumentationTest extends ControllerTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.dashboard.measure_cache_seconds', 0);
        // The queue is named in the configuration, so it has a row without a job.
        $app['config']->set('orrery.waits', ['*' => 60, 'database:default' => 60]);

        if ($this->name() === 'test_swap_disabled_connection_has_unknown_running_and_arrivals') {
            $app['config']->set('orrery.swap_connector', false);
        }
    }

    /**
     * Rejects: the package's own queue reported as uninstrumented (a null
     * that hides a real 0).
     */
    public function test_instrumented_connection_keeps_its_counts(): void
    {
        $row = $this->defaultRow();

        self::assertTrue($row['instrumented']);
        self::assertSame(0, $row['running']);
        self::assertSame(0.0, $row['in_per_min']);
        self::assertSame(0.0, $row['out_per_min']);
    }

    /**
     * Rejects: `running: 0` and `in_per_min: 0.0` from the mirror rows that
     * a swap-disabled connection never writes, and a null `out_per_min`.
     */
    public function test_swap_disabled_connection_has_unknown_running_and_arrivals(): void
    {
        $this->assertUninstrumented($this->defaultRow());
    }

    /**
     * Rejects: a foreign queue class that is counted as instrumented.
     */
    public function test_foreign_queue_class_has_unknown_running_and_arrivals(): void
    {
        $this->forgetQueueConnection();
        $this->app['queue']->addConnector('database', fn (): LaravelDatabaseConnector => new LaravelDatabaseConnector($this->app['db']));

        $this->assertUninstrumented($this->defaultRow());
    }

    /**
     * Rejects: a queue object that cannot be resolved read as instrumented
     * (unknown is never 0).
     */
    public function test_unresolvable_queue_object_has_unknown_running_and_arrivals(): void
    {
        $this->forgetQueueConnection();
        $this->app['queue']->addConnector('database', static function (): never {
            throw new RuntimeException('The test connector cannot connect.');
        });

        $this->assertUninstrumented($this->defaultRow());
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertUninstrumented(array $row): void
    {
        self::assertFalse($row['instrumented']);
        self::assertNull($row['running'], 'running jobs are not mirrored: unknown, not 0');
        self::assertNull($row['in_per_min'], 'pushes are not mirrored: unknown, not 0');
        self::assertSame(0.0, $row['out_per_min'], 'outcomes are mirrored');
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultRow(): array
    {
        // The counts frontier at the current minute: the rate window is covered.
        $this->app->make(SampleCounts::class)->run();

        foreach ((array) $this->getJson('/orrery/api/workload')->assertOk()->json() as $row) {
            self::assertIsArray($row);

            if ($row['connection'] === 'database' && $row['name'] === 'default') {
                return $row;
            }
        }

        self::fail('The workload has no row for database:default.');
    }

    private function forgetQueueConnection(): void
    {
        $manager = $this->app['queue'];

        \Closure::bind(static function () use ($manager): void {
            unset($manager->connections['database']);
        }, null, $manager::class)();
    }
}
