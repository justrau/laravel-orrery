<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\Fixtures\Metrics\SeedsSamples;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * Two queue connection names on one jobs table (a supported setup):
 * the wait time and the workload measure
 * the shared table once per queue, and they name both connections.
 */
final class SharedTableWorkloadTest extends ControllerTest
{
    use SeedsSamples;
    use WorkerFixtures;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.connections.database-twin', [
            'driver' => 'database',
            'connection' => TestEnvironment::PRIMARY,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('orrery.waits', ['*' => 60]);
    }

    /**
     * Rejects: one row per queue connection for a shared table (the backlog listed and counted twice), or a capacity of one connection's workers.
     */
    public function test_two_connections_on_one_table_are_measured_once(): void
    {
        // One completion of 1000 ms and one of 3000 ms: the aggregation counts both names under the primary alias, so the average is 2000 ms.
        $this->seedCompletion('queue', 'database:default', 1000.0);
        $this->seedCompletion('queue', 'database:default', 3000.0);

        for ($i = 0; $i < 6; $i++) {
            Queue::connection($i % 2 === 0 ? 'database' : 'database-twin')->push(new BasicJob);
        }

        $this->insertHeartbeats([
            $this->heartbeatRow('host-a', 101, ['connection' => 'database']),
            $this->heartbeatRow('host-b', 201, ['connection' => 'database-twin']),
            $this->heartbeatRow('host-c', 301, ['connection' => 'database-twin', 'subscribed_queues' => 'emails']),
        ]);

        $rows = array_values(array_filter(
            $this->app->make(WaitTime::class)->measure(),
            static fn (array $row): bool => $row['queue'] === 'default',
        ));
        self::assertCount(1, $rows, 'one row for the queue of the shared table');
        self::assertSame(['database', ['database', 'database-twin'], 6], [$rows[0]['connection'], $rows[0]['connections'], $rows[0]['backlog']]);
        self::assertEqualsWithDelta(2000.0, (float) $rows[0]['runtime_ms'], 0.001, 'the samples of both names, under the primary alias');
        self::assertSame(6, $rows[0]['wait'], '6 x 2000 ms / 1000 / 2 live workers of both names');

        $workload = (array) $this->actingAs(new User)->getJson('/orrery/api/workload')->assertOk()->json();
        $default = array_values(array_filter($workload, static fn (array $row): bool => $row['name'] === 'default'));
        self::assertCount(1, $default);
        self::assertSame(
            ['name' => 'default', 'connection' => 'database', 'connections' => ['database', 'database-twin'], 'length' => 6, 'wait' => 6, 'processes' => 2],
            array_intersect_key($default[0], array_flip(['name', 'connection', 'connections', 'length', 'wait', 'processes'])),
        );
    }
}
