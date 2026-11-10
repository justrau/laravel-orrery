<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Queue\Connectors\DatabaseConnector as LaravelDatabaseConnector;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\DatabaseQueue as OrreryDatabaseQueue;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Queue\Instrumentation;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * A queue connection whose queue object is not the package's DatabaseQueue:
 * the connector swap is off, or another connector won
 * the `database` binding. The package mirrors only the outcomes, and
 * `GET /api/health` lists the connection in `uninstrumented_connections`.
 */
final class ForeignQueueTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->name() === 'test_swap_disabled_reports_the_connection') {
            $app['config']->set('orrery.swap_connector', false);
        }

        $app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Orrery::auth(static fn (): bool => true);
    }

    /**
     * Rejects: a kept JobQueued swap-off path (a `pending` row appears), and
     * an outcome listener that skips a foreign queue (no row at all).
     */
    public function test_foreign_queue_mirrors_outcomes_only(): void
    {
        $this->bindForeignConnector();
        $queue = Queue::connection('database');
        self::assertInstanceOf(LaravelDatabaseQueue::class, $queue);
        self::assertNotInstanceOf(OrreryDatabaseQueue::class, $queue, 'the test connector lost to the package connector');

        $queue->push(new BasicJob);
        $queue->push(new FailingJob);

        self::assertSame(2, DB::table('jobs')->count());
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->count(), 'a push of a foreign queue was mirrored');

        $this->work();

        self::assertSame(['completed'], $this->statuses(), 'the completion was not mirrored, or the waiting job got a row');

        $this->work();

        self::assertSame(['completed', 'failed'], $this->statuses());
        self::assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Rejects: a silent fallback (no entry for a connection whose swap is off).
     */
    public function test_swap_disabled_reports_the_connection(): void
    {
        self::assertNotInstanceOf(OrreryDatabaseQueue::class, Queue::connection('database'));

        $this->getJson('/orrery/api/health')
            ->assertOk()
            ->assertJsonPath('uninstrumented_connections', [['connection' => 'database', 'reason' => 'swap_disabled']]);
    }

    /**
     * Rejects: an entry for the package's own queue, and a foreign class
     * reported as swap_disabled.
     */
    public function test_foreign_connector_is_reported_as_a_foreign_queue_class(): void
    {
        $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('uninstrumented_connections', []);

        $this->bindForeignConnector();

        $this->getJson('/orrery/api/health')
            ->assertOk()
            ->assertJsonPath('uninstrumented_connections', [['connection' => 'database', 'reason' => 'foreign_queue_class']]);
    }

    /**
     * Rejects: a health response that fails or omits the connection when
     * its queue object cannot be resolved, and a resolution outside the
     * watcher (no recorded error).
     */
    public function test_unresolvable_queue_connection_is_reported_unknown(): void
    {
        $this->forgetQueueConnection();
        $this->app['queue']->addConnector('database', static function (): never {
            throw new RuntimeException('The test connector cannot connect.');
        });

        $this->getJson('/orrery/api/health')
            ->assertOk()
            ->assertJsonPath('uninstrumented_connections', [['connection' => 'database', 'reason' => 'unknown']]);

        // The resolution ran inside the watcher, which recorded the error under its operation. The request's
        // later mirror statements closed that window, and its terminating flush stored it.
        self::assertSame(['health:queue_object'], $this->mirror()->table('orrery_health_events')->pluck('last_error_operation')->all());

        Degradation::reset();
        self::assertSame([['connection' => 'database', 'reason' => 'unknown']], $this->app->make(Instrumentation::class)->uninstrumented());
        self::assertSame('health:queue_object', Degradation::record()['last_error_operation'] ?? null);
    }

    /**
     * Rejects: removing the whole-worker stop together with the swap-off
     * branch (a foreign queue's pop() never reads the package signals), and
     * a worker CLI that does not bind the foreign connector.
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_global_pause_stops_a_foreign_queue_worker(): void
    {
        $env = $this->sharedEnvironment();
        (new SignalState)->pause('global', '');
        $id = Queue::connection('database')->push(new BasicJob);

        $worker = WorkerProcess::start(
            ['queue:work', 'database', '--sleep=0', '--tries=1', '--max-time=3'],
            $env,
            [WorkerProcess::FOREIGN_QUEUE => '1', 'ORRERY_SIGNAL_CACHE_SECONDS' => '0'],
        );

        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $row = DB::table('jobs')->where('id', $id)->first();
        self::assertNotNull($row, 'the paused foreign queue worker ran the job');
        self::assertNull($row->reserved_at, 'the paused foreign queue worker reserved the job');
        self::assertSame(0, (int) $row->attempts);

        // The control: without the pause, the same worker runs the job.
        (new SignalState)->continue('global', '');
        $worker = WorkerProcess::start(
            ['queue:work', 'database', '--sleep=0', '--tries=1', '--stop-when-empty'],
            $env,
            [WorkerProcess::FOREIGN_QUEUE => '1', 'ORRERY_SIGNAL_CACHE_SECONDS' => '0'],
        );

        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->count(), 'the continued worker did not run the job');
        self::assertSame(['completed'], $this->statuses(), 'the foreign worker did not mirror the outcome');
        self::assertNull($this->mirror()->table('orrery_jobs')->value('reserved_by'), "the child ran the package's queue: its reservation named the worker");
    }

    /**
     * Binds Laravel's own database connector, as another package would.
     */
    private function bindForeignConnector(): void
    {
        $this->forgetQueueConnection();
        $this->app['queue']->addConnector('database', fn (): LaravelDatabaseConnector => new LaravelDatabaseConnector($this->app['db']));
    }

    private function forgetQueueConnection(): void
    {
        $manager = $this->app['queue'];

        \Closure::bind(static function () use ($manager): void {
            unset($manager->connections['database']);
        }, null, $manager::class)();
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        /** @var list<string> */
        return $this->mirror()->table('orrery_jobs')->orderBy('status')->pluck('status')->map(static fn ($status): string => (string) $status)->all();
    }

    private function sharedEnvironment(): TestEnvironment
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        return $env;
    }
}
