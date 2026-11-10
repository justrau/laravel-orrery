<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Workers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Listeners\RecordWorkerHeartbeat;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Workers\LiveWorkers;

/**
 * Worker identity and heartbeats: the rewrites of the
 * probes WorkersProbeTest and SyncHeartbeatProbeTest.
 */
final class HeartbeatTest extends ControllerTest
{
    use WorkerFixtures;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);
        $app['config']->set('orrery.workers.heartbeat_seconds', 0);
    }

    /**
     * Rejects: grouping every row by host:ppid.
     */
    public function test_sibling_daemons_are_separate_workers(): void
    {
        // supervisord (pid 900) runs queue:work with numprocs=3.
        $this->insertHeartbeats([
            $this->heartbeatRow('web-1', 1001, ['ppid' => 900]),
            $this->heartbeatRow('web-1', 1002, ['ppid' => 900]),
            $this->heartbeatRow('web-1', 1003, ['ppid' => 900]),
        ]);

        $workers = $this->getJson('/orrery/api/workers')->assertOk()->json();

        self::assertCount(3, app(LiveWorkers::class)->list());
        self::assertCount(3, $workers, 'the sibling daemons of one supervisor collapsed: '.json_encode(array_column($workers, 'name')));
        self::assertSame(['web-1:1001', 'web-1:1002', 'web-1:1003'], array_column($workers, 'name'));
    }

    /**
     * Rejects: heartbeats for non-database connections or outside a worker.
     */
    public function test_sync_dispatch_in_a_web_request_is_not_a_worker(): void
    {
        // A web request runs a job synchronously, and pops a database job.
        $this->actAs(ProcessContext::WEB);
        dispatch_sync(new BasicJob);
        event(new Looping('database', 'default'));
        RecordWorkerHeartbeat::write('database', 'default');

        self::assertSame([], $this->getJson('/orrery/api/workers')->assertOk()->json());
        self::assertSame(0, $this->mirror()->table('orrery_worker_heartbeats')->count(), 'a web process wrote a heartbeat');

        // A worker process of another driver writes nothing, also through a direct call.
        $this->actAsWorker();
        event(new Looping('redis', 'default'));
        RecordWorkerHeartbeat::write('redis', 'default');
        (new RecordWorkerHeartbeat)->handle(new Looping('sync', 'default'));
        self::assertSame(0, $this->mirror()->table('orrery_worker_heartbeats')->count(), 'a redis or sync worker wrote a database heartbeat');

        // The positive control: a database worker writes its heartbeat.
        event(new Looping('database', 'default'));
        self::assertSame(1, $this->mirror()->table('orrery_worker_heartbeats')->count());
        self::assertCount(1, $this->getJson('/orrery/api/workers')->assertOk()->json());
    }

    /**
     * Rejects: a group for a null ppid.
     */
    public function test_null_ppid_has_no_grouping(): void
    {
        // Without a ppid, the process is never a listen child, whatever its parent command says.
        $this->actAsWorker(ppid: null, parentStartedAt: null, parentCommand: 'php artisan queue:listen');
        event(new Looping('database', 'default'));

        $row = $this->mirror()->table('orrery_worker_heartbeats')->sole();
        self::assertNull($row->ppid);
        self::assertNull($row->parent_started_at);
        self::assertSame('daemon', $row->mode);

        // Two rows that claim to be listen children without a ppid stay separate workers.
        $this->insertHeartbeats([
            $this->heartbeatRow('web-2', 11, ['mode' => 'listen-child']),
            $this->heartbeatRow('web-2', 12, ['mode' => 'listen-child']),
        ]);

        $rows = app(LiveWorkers::class)->list();
        self::assertCount(3, $rows);

        foreach ($rows as $worker) {
            self::assertNotSame('listen', $worker->mode);
            self::assertSame([], $worker->children);
        }
    }

    /**
     * Rejects: a worker id without the start time.
     */
    public function test_pid_reuse_gives_a_new_worker_id(): void
    {
        $now = microtime(true);

        $this->actAsWorker(startedAt: $now - 3600, pid: 4242);
        event(new Looping('database', 'default'));

        // The operating system gives the same PID to a new process on the same host.
        RecordWorkerHeartbeat::resetThrottle();
        $this->actAsWorker(startedAt: $now - 10, pid: 4242);
        event(new Looping('database', 'default'));

        $rows = $this->mirror()->table('orrery_worker_heartbeats')->orderBy('started_at')->get();
        self::assertCount(2, $rows, 'the new process overwrote the old process row');
        self::assertNotSame($rows[0]->worker_id, $rows[1]->worker_id);
        self::assertSame([4242, 4242], [(int) $rows[0]->pid, (int) $rows[1]->pid]);
        self::assertSame(ProcessContext::current()->identity(), $rows[1]->worker_id);
        self::assertCount(2, app(LiveWorkers::class)->list());
    }

    /**
     * Rejects: a heartbeat write at every reservation, or no write when the reserved queue changes inside the throttle interval.
     */
    public function test_a_reservation_writes_the_heartbeat_only_when_the_queue_changes(): void
    {
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 15);
        $this->actAsWorker();
        foreach (['default', 'default', 'default', 'high'] as $queue) {
            Queue::push(new BasicJob, '', $queue);
        }

        $writes = 0;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$writes): void {
            if (str_contains($event->sql, 'orrery_worker_heartbeats') && ! str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                $writes++;
            }
        });

        $queue = Queue::connection('database');
        self::assertNotNull($queue->pop('default'));
        self::assertSame(2, $writes, 'the first poll heartbeat and the first reservation');
        self::assertNotNull($queue->pop('default'));
        self::assertNotNull($queue->pop('default'));
        self::assertSame(2, $writes, 'a daemon that works one queue wrote extra heartbeats');
        self::assertSame('default', $this->mirror()->table('orrery_worker_heartbeats')->value('current_queue'));

        self::assertNotNull($queue->pop('high'));
        self::assertSame(3, $writes);
        self::assertSame('high', $this->mirror()->table('orrery_worker_heartbeats')->value('current_queue'));
    }

    /**
     * Rejects: a heartbeat row without the process identity host:pid:start, or a daemon row without its queue and options.
     */
    public function test_worker_heartbeat_row_has_the_process_identity(): void
    {
        $context = $this->actAsWorker(ppid: 77, parentStartedAt: microtime(true) - 500, parentCommand: '/usr/bin/supervisord');
        Event::until(new Looping('database', 'default,high'));

        $row = $this->mirror()->table('orrery_worker_heartbeats')->sole();
        self::assertSame($context->identity(), $row->worker_id);
        self::assertSame(77, (int) $row->ppid);
        self::assertSame('daemon', $row->mode);
        self::assertSame('default,high', $row->subscribed_queues);
        self::assertNull($row->current_queue, 'an idle loop is no reservation');
        self::assertIsArray(json_decode((string) $row->options, true));
    }
}
