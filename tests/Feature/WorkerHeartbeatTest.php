<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Listeners\RecordWorkerHeartbeat;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;

class WorkerHeartbeatTest extends ControllerTest
{
    use WorkerFixtures;

    /**
     * Rejects: a heartbeat row without the worker id host:pid:start, or with the queue outside current_queue and subscribed_queues.
     */
    public function test_looping_event_writes_a_heartbeat_row(): void
    {
        $this->actAsWorker();
        event(new Looping('database', 'default'));

        $row = $this->mirror()->table('orrery_worker_heartbeats')->first();
        $this->assertNotNull($row);
        $this->assertSame('database', $row->connection);
        $this->assertNull($row->current_queue, 'an idle loop is no reservation');
        $this->assertSame('default', $row->subscribed_queues);
        $this->assertSame('daemon', $row->mode);
        $this->assertSame('{"orrery_heartbeat_seconds":15}', $row->options, 'the options hold the writer\'s interval');
        $this->assertGreaterThan(0, (float) $row->last_seen_at);
        $this->assertSame((int) getmypid(), (int) $row->pid);
        $this->assertSame(sprintf('%s:%d:%.6F', $row->host, getmypid(), (float) $row->started_at), $row->worker_id);
    }

    /**
     * Rejects: a heartbeat that refreshes the row on every Looping event, and a
     * throttle that never writes again after the interval.
     */
    public function test_throttle_prevents_repeated_writes_within_the_interval(): void
    {
        $start = Carbon::createFromTimestamp(1_790_000_000.5, 'UTC');
        Carbon::setTestNow($start);
        $this->actAsWorker();
        event(new Looping('database', 'default'));
        $firstSeenAt = $this->mirror()->table('orrery_worker_heartbeats')->value('last_seen_at');

        // 14.9 s later: still inside the 15 s interval.
        Carbon::setTestNow($start->copy()->addMilliseconds(14_900));

        event(new Looping('database', 'default'));
        event(new Looping('database', 'default'));
        event(new Looping('database', 'default'));

        $this->assertSame(1, $this->mirror()->table('orrery_worker_heartbeats')->count());
        $this->assertSame(
            $firstSeenAt,
            $this->mirror()->table('orrery_worker_heartbeats')->value('last_seen_at'),
            'subsequent Looping events within the throttle window should not refresh the row',
        );

        // 15 s after the first write, the next Looping event writes again.
        Carbon::setTestNow($start->copy()->addSeconds(15));
        event(new Looping('database', 'default'));

        $this->assertSame(
            Clock::toDecimal(1_790_000_015.5),
            $this->decimal($this->mirror()->table('orrery_worker_heartbeats')->value('last_seen_at')),
            'the first Looping event after the interval did not refresh the row',
        );
    }

    /**
     * Rejects: a worker id that changes between two writes of one process, or a worker id from the first write time.
     */
    public function test_one_process_keeps_its_worker_id(): void
    {
        $start = Carbon::createFromTimestamp(1_790_000_000.5, 'UTC');
        Carbon::setTestNow($start);
        $this->actAsWorker();
        event(new Looping('database', 'default'));
        $first = $this->mirror()->table('orrery_worker_heartbeats')->sole();

        // A write after the throttle interval updates the same row.
        Carbon::setTestNow($start->copy()->addSeconds(16));
        event(new Looping('database', 'high'));

        $second = $this->mirror()->table('orrery_worker_heartbeats')->sole();
        $this->assertSame($first->worker_id, $second->worker_id);
        $this->assertSame('high', $second->subscribed_queues);
        $this->assertNull($second->current_queue, 'an idle loop is no reservation');
        $this->assertGreaterThan((float) $first->last_seen_at, (float) $second->last_seen_at);

        // The throttle reset changes nothing: the id is the process identity.
        RecordWorkerHeartbeat::resetThrottle();
        Carbon::setTestNow($start->copy()->addSeconds(17));
        event(new Looping('database', 'default'));

        $this->assertSame([ProcessContext::current()->identity()], $this->mirror()->table('orrery_worker_heartbeats')->pluck('worker_id')->all());
    }

    private function decimal(mixed $value): string
    {
        return Clock::toDecimal((float) $value);
    }

    public function test_tick_command_sweeps_stale_heartbeats(): void
    {
        $this->mirror()->table('orrery_worker_heartbeats')->insert([
            $this->heartbeat('host-a', 1, 'default', microtime(true) - 30),
            $this->heartbeat('host-b', 2, 'default', microtime(true) - 600),
        ]);

        $this->artisan(TickCommand::class)->assertSuccessful();

        $rows = $this->mirror()->table('orrery_worker_heartbeats')->orderBy('host')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('host-a', $rows->first()->host);
    }

    /**
     * Rejects: a worker list that merges daemons without a parent, or lists each child of one queue:listen parent.
     */
    public function test_workers_endpoint_lists_listen_children_of_one_parent_once(): void
    {
        $now = microtime(true);

        $this->mirror()->table('orrery_worker_heartbeats')->insert([
            $this->heartbeat('host-a', 1, 'default', $now - 5),
            $this->heartbeat('host-a', 2, 'default', $now - 5),
            $this->heartbeat('host-a', 11, 'default', $now - 5, ppid: 10, parentStartedAt: $now - 900),
            $this->heartbeat('host-a', 12, 'default', $now - 5, ppid: 10, parentStartedAt: $now - 900),
            $this->heartbeat('host-b', 11, 'default', $now - 5, ppid: 10, parentStartedAt: $now - 900),
        ]);

        $workers = $this->getJson('/orrery/api/workers')->assertOk()->json();
        $children = array_combine(array_column($workers, 'name'), array_map(static fn (array $worker): int => count($worker['children']), $workers));
        ksort($children);

        $this->assertSame(['host-a:1' => 0, 'host-a:2' => 0, 'host-a:listen(10)' => 1, 'host-b:listen(10)' => 1], $children, 'one row per parent, with only its newest child: queue:listen runs one child at a time');
    }

    public function test_workers_endpoint_is_empty_without_a_heartbeat(): void
    {
        // No heartbeat rows, no reservations.
        $this->assertSame([], $this->getJson('/orrery/api/workers')->assertOk()->json());
    }

    public function test_workers_endpoint_returns_live_workers(): void
    {
        $this->mirror()->table('orrery_worker_heartbeats')->insert([
            $this->heartbeat('host-a', 1, 'default', microtime(true) - 5),
            $this->heartbeat('host-b', 2, 'high', microtime(true) - 600),
        ]);

        $workers = $this->getJson('/orrery/api/workers')->json();

        $this->assertCount(1, $workers);
        $this->assertSame('host-a:1', $workers[0]['name']);
        $this->assertSame('default', $workers[0]['queue']);
    }

    /**
     * @return array<string, mixed>
     */
    private function heartbeat(string $host, int $pid, string $queue, float $lastSeenAt, ?int $ppid = null, ?float $parentStartedAt = null): array
    {
        $startedAt = $lastSeenAt - 60;

        return [
            'worker_id' => sprintf('%s:%d:%.6F', $host, $pid, $startedAt),
            'host' => $host,
            'pid' => $pid,
            'started_at' => $startedAt,
            'ppid' => $ppid,
            'parent_started_at' => $parentStartedAt,
            'mode' => $parentStartedAt === null ? 'daemon' : 'listen-child',
            'connection' => 'database',
            'subscribed_queues' => $queue,
            'current_queue' => $queue,
            'options' => '{}',
            'last_seen_at' => $lastSeenAt,
        ];
    }
}
