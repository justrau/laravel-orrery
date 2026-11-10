<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Fixtures\IndexRecordingQueue;
use Justrau\Orrery\Tests\TestCase;

/**
 * The package pop() forwards every argument that the worker gives it:
 * Laravel's worker calls pop($queue, $index).
 */
final class PopIndexForwardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        IndexRecordingQueue::$calls = [];
    }

    protected function tearDown(): void
    {
        IndexRecordingQueue::$calls = [];

        parent::tearDown();
    }

    /**
     * Rejects: forwarding only $queue.
     */
    public function test_worker_index_reaches_the_parent(): void
    {
        /** @var QueueManager $manager */
        $manager = $this->app['queue'];
        $manager->addConnector('database', fn (): ConnectorInterface => new class($this->app['db']) implements ConnectorInterface
        {
            public function __construct(private readonly \Illuminate\Database\ConnectionResolverInterface $connections) {}

            /** @param  array<string, mixed>  $config */
            public function connect(array $config): Queue
            {
                /** @var \Illuminate\Database\Connection $connection */
                $connection = $this->connections->connection($config['connection'] ?? null);

                return new IndexRecordingQueue($connection, $config['table'], $config['queue'], $config['retry_after'] ?? 60, $config['after_commit'] ?? null);
            }
        });

        $queue = $manager->connection('database');
        self::assertInstanceOf(IndexRecordingQueue::class, $queue);

        $queue->pushOn('low', new BasicJob);

        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->runNextJob('database', 'high,low', $this->workerOptions());

        self::assertSame([['high', 0], ['low', 1]], IndexRecordingQueue::$calls);
        self::assertSame(0, DB::table('jobs')->count(), 'The worker ran the job from the queue low.');
    }
}
