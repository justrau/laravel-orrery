<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\TestCase;

/**
 * A worker on a `failover` queue connection. Laravel's worker
 * puts its own connection argument (`failover`) on the attempt events, while
 * the job comes from the first connection of the failover list.
 */
final class FailoverConnectionTest extends TestCase
{
    use WorkerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('queue.connections.failover', [
            'driver' => 'failover',
            'connections' => ['database', 'sync'],
        ]);
    }

    /**
     * Rejects: a listener filter or an attempt observation that trusts the
     * event's `failover` name, so the completion is never mirrored and the
     * row stays reserved.
     */
    public function test_a_failover_worker_mirrors_the_completion(): void
    {
        Queue::connection('failover')->push(new BasicJob);

        self::assertSame('pending', $this->mirror()->table('orrery_jobs')->value('status'));

        $this->app->make('queue.worker')->runNextJob('failover', 'default', $this->workerOptions());

        self::assertSame(0, DB::table('jobs')->count());
        $row = $this->mirror()->table('orrery_jobs')->first();
        self::assertNotNull($row);
        self::assertSame('completed', $row->status);
        self::assertSame('database', $row->connection);
        self::assertNotNull($row->runtime_ms, 'The runtime clock of JobProcessing did not run.');
    }

    /**
     * Rejects: a heartbeat listener that takes the connection from the
     * event's `failover` name (no database driver, so no heartbeat row).
     */
    public function test_a_failover_worker_writes_its_heartbeat_on_the_database_connection(): void
    {
        $this->actAsWorker();

        $this->app['events']->dispatch(new JobProcessing('failover', new SyncJob($this->app, '{}', 'database', 'default')));

        $row = $this->mirror()->table('orrery_worker_heartbeats')->first();
        self::assertNotNull($row, 'the heartbeat of a failover worker was not written');
        self::assertSame('database', $row->connection);
    }

    /**
     * Rejects: a connection taken from the event name (`failover`) when the
     * job names its own connection, and a Looping or WorkerStarting of a
     * failover connection that is not mapped to its first connection.
     */
    public function test_the_connection_of_an_event(): void
    {
        $job = new SyncJob($this->app, '{}', 'database', 'default');

        self::assertSame('database', DatabaseDriverFilter::connectionOf(new JobProcessed('failover', $job)));
        self::assertTrue(DatabaseDriverFilter::accepts(new JobProcessed('failover', $job)));
        self::assertFalse(DatabaseDriverFilter::accepts(new JobProcessed('database', new SyncJob($this->app, '{}', 'sync', 'default'))));

        self::assertSame('database', DatabaseDriverFilter::connectionOf(new Looping('failover', 'default')));
        self::assertTrue(DatabaseDriverFilter::accepts(new Looping('failover', 'default')));
        self::assertSame('database', DatabaseDriverFilter::connectionOf(new WorkerStarting('failover', 'default', new WorkerOptions)));

        $this->app['config']->set('queue.connections.nested', ['driver' => 'failover', 'connections' => ['failover']]);
        self::assertSame('database', DatabaseDriverFilter::connectionOf(new Looping('nested', 'default')));

        $this->app['config']->set('queue.connections.loop', ['driver' => 'failover', 'connections' => ['loop']]);
        self::assertSame('loop', DatabaseDriverFilter::connectionOf(new Looping('loop', 'default')));
        self::assertFalse(DatabaseDriverFilter::accepts(new Looping('loop', 'default')));

        $this->app['config']->set('queue.connections.redis-first', ['driver' => 'failover', 'connections' => ['redis', 'database']]);
        $this->app['config']->set('queue.connections.redis', ['driver' => 'redis']);
        self::assertFalse(DatabaseDriverFilter::accepts(new Looping('redis-first', 'default')));
    }
}
