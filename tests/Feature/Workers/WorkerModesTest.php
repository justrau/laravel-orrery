<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Workers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use Justrau\Orrery\Workers\LiveWorkers;
use PHPUnit\Framework\Attributes\Group;

/**
 * Worker modes with real worker processes:
 * independent --once workers, real queue:listen children and
 * multi-queue subscriptions. Every child starts through WorkerProcess, and
 * every wait has a deadline.
 */
#[Group('process')]
final class WorkerModesTest extends TestCase
{
    /** Every poll writes a heartbeat. */
    private const EVERY_POLL = ['ORRERY_HEARTBEAT_SECONDS' => '0'];

    /**
     * Rejects: one worker row for separate --once processes.
     */
    #[Group('shared-database')]
    public function test_independent_once_workers(): void
    {
        $env = $this->sharedEnvironment();

        // Two --once workers of one parent (this process), one after the other, on an empty queue.
        $first = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0'], $env, self::EVERY_POLL);
        self::assertSame(0, $first->waitForExit(60), $first->describe());
        $second = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0'], $env, self::EVERY_POLL);
        self::assertSame(0, $second->waitForExit(60), $second->describe());

        $rows = $this->mirror()->table('orrery_worker_heartbeats')->orderBy('pid')->get();
        $expected = [$first->pid(), $second->pid()];
        sort($expected);
        self::assertSame($expected, array_map('intval', $rows->pluck('pid')->all()), $first->describe().$second->describe());
        self::assertSame(['once', 'once'], $rows->pluck('mode')->all());
        self::assertSame([getmypid(), getmypid()], array_map('intval', $rows->pluck('ppid')->all()), 'both workers are children of this process');

        $workers = app(LiveWorkers::class)->list();
        self::assertCount(2, $workers, 'two --once workers of one parent were merged');

        foreach ($workers as $worker) {
            self::assertSame('once', $worker->mode);
            self::assertSame([], $worker->children);
        }
    }

    /**
     * Rejects: a current queue that only the throttled heartbeat writes, so a --once worker exits without it.
     */
    #[Group('shared-database')]
    public function test_a_once_worker_records_its_reserved_queue_with_the_default_throttle(): void
    {
        $env = $this->sharedEnvironment();
        Queue::connection('database')->push(new BasicJob, '', 'mail');

        // The default heartbeat interval (15 s): no ORRERY_HEARTBEAT_SECONDS.
        $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--queue=mail', '--sleep=0', '--tries=1'], $env);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->count(), 'the worker did not run the job: '.$worker->describe());

        $row = $this->mirror()->table('orrery_worker_heartbeats')->where('pid', $worker->pid())->sole();
        self::assertSame('mail', $row->current_queue, 'the once worker did not record the queue of its reservation');
        self::assertSame('once', $row->mode);
    }

    /**
     * Rejects: one row per queue:listen child.
     */
    #[Group('shared-database')]
    public function test_real_queue_listen_children_are_grouped(): void
    {
        $env = $this->sharedEnvironment();

        $listener = WorkerProcess::start(['queue:listen', 'database', '--queue=default', '--sleep=0'], $env, self::EVERY_POLL);
        $children = fn (): int => $this->mirror()->table('orrery_worker_heartbeats')->where('ppid', $listener->pid())->count();
        $listener->waitUntil(static fn (): bool => $children() >= 2, 60, 'two queue:listen children wrote their heartbeats');
        $listener->stop();

        $rows = $this->mirror()->table('orrery_worker_heartbeats')->where('ppid', $listener->pid())->get();
        self::assertGreaterThanOrEqual(2, $rows->count());
        self::assertSame(['listen-child'], $rows->pluck('mode')->unique()->values()->all(), $listener->describe());
        self::assertCount(1, $rows->pluck('parent_started_at')->unique(), 'the children name one parent start');
        self::assertNotNull($rows[0]->parent_started_at);

        $workers = app(LiveWorkers::class)->list();
        self::assertCount(1, $workers, 'each queue:listen child is its own worker row');
        $parent = $workers[0];
        self::assertSame('listen', $parent->mode);
        self::assertSame($listener->pid(), $parent->pid);
        self::assertNull($parent->ppid);
        self::assertSame(gethostname().':'.$listener->pid().':'.Clock::toDecimal((float) $rows[0]->parent_started_at), $parent->worker_id);
        self::assertSame($rows->count(), count($parent->children));
        self::assertSame(['default'], $parent->subscribed_queues);
        self::assertSame(1, app(LiveWorkers::class)->capacity('database', 'default'));
    }

    /**
     * Rejects: only the first queue of --queue=a,b.
     */
    #[Group('shared-database')]
    public function test_multiple_queues_are_subscribed(): void
    {
        $env = $this->sharedEnvironment();

        $worker = WorkerProcess::start(['queue:work', 'database', '--queue=alpha,beta', '--sleep=1'], $env, self::EVERY_POLL);
        $worker->waitUntil(fn (): bool => $this->mirror()->table('orrery_worker_heartbeats')->where('pid', $worker->pid())->exists(), 60, 'its heartbeat row is visible');

        $row = $this->mirror()->table('orrery_worker_heartbeats')->where('pid', $worker->pid())->sole();
        self::assertSame('alpha,beta', $row->subscribed_queues, $worker->describe());
        self::assertSame('daemon', $row->mode);

        $live = app(LiveWorkers::class);
        self::assertSame(1, $live->capacity('database', 'alpha'));
        self::assertSame(1, $live->capacity('database', 'beta'));
        self::assertSame(0, $live->capacity('database', 'default'));

        $worker->stop();
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
