<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Control\StopLatch;
use Justrau\Orrery\Events\JobFailed;
use Justrau\Orrery\Listeners\RecordWorkerHeartbeat;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Tests\Feature\Hooks\Jobs\QueueName;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\Fixtures\Hooks\EnumKeepingConnector;
use Justrau\Orrery\Tests\Support\Fixtures\Hooks\EnumKeepingNativeQueue;
use Justrau\Orrery\Tests\Support\Fixtures\Hooks\EnumKeepingQueue;
use RuntimeException;

/**
 * Queue names given as a UnitEnum (Review Focus 4, todo 1231).
 *
 * Laravel stores the enum's value in its jobs row on every supported
 * release, because the query builder converts an enum binding. But the
 * getQueue() of Laravel 12 returns the enum object, so the
 * package hooks and listeners receive the enum. The package normalises the
 * name with enum_value(), so the mirror row has the queue of the jobs row.
 *
 * The tests that use EnumKeepingConnector give the getQueue() of Laravel
 * 12 also on Laravel 13.
 */
final class EnumQueueNameTest extends HooksTestCase
{
    /**
     * Rejects: storing the enum object, or its case name in place of its value.
     */
    public function test_enum_queue_name_is_stored_as_its_value(): void
    {
        Queue::pushOn(QueueName::Emails, new BasicJob);

        $pushed = $this->onlyJobsRow();
        $uuid = self::uuidOf($pushed['payload']);
        $row = $this->rowOrFail($uuid);
        self::assertSame('mail-out', $row->queue, 'The mirror queue is the enum value.');
        self::assertSame('pending', $row->status);

        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->runNextJob('database', 'mail-out', $this->workerOptions());

        $row = $this->rowOrFail($uuid);
        self::assertSame('completed', $row->status, 'The reservation and the completion match the row.');
        self::assertSame('mail-out', $row->queue);
        self::assertSame(1, (int) $row->attempts);
        self::assertSame(1, $this->counters()['queue:database:mail-out']['throughput'] ?? null);
    }

    /**
     * Rejects: a hook or listener that casts the enum to a string (the cast
     * throws, so the observation is dropped), or that uses the case name;
     * a heartbeat or a queue-scoped terminate check on an empty queue name
     * in place of the enum value (popShouldSkip($connection, '')); a bulk
     * path that accepts only a string queue. (A queue pause is Laravel's
     * own; the terminate replaces its case.)
     */
    public function test_every_hook_mirrors_an_enum_queue_that_get_queue_keeps(): void
    {
        $queue = $this->enumKeepingQueue();
        self::assertInstanceOf(EnumKeepingQueue::class, $queue);

        $queue->pushOn(QueueName::Emails, new BasicJob);
        $queue->laterOn(QueueName::Emails, 600, new BasicJob);
        $queue->pushRaw(self::rawPayload((string) Str::uuid(), ['data' => []]), QueueName::Emails);
        $queue->bulk([new BasicJob], '', QueueName::Emails);

        $jobs = DB::table('jobs')->orderBy('id')->get();
        self::assertCount(4, $jobs);

        foreach ($jobs as $job) {
            self::assertSame('mail-out', $job->queue, 'Laravel stores the enum value.');
            $row = $this->rowOrFail(self::uuidOf((string) $job->payload));
            self::assertSame('mail-out', $row->queue, 'Each push path mirrors the enum value.');
            self::assertSame('pending', $row->status);
        }

        // The control plane matches the enum value. It applies only in a
        // worker process.
        ProcessContext::useForTesting(ProcessContext::current()->withKindForTesting(ProcessContext::WORKER));
        $kills = 0;
        StopLatch::useKillerForTesting(static function () use (&$kills): void {
            $kills++;
        });
        $signals = new SignalState;
        $signals->terminate('queue', 'database:mail-out');
        self::assertNull($queue->pop(QueueName::Emails), 'A terminate of the enum value holds the enum queue.');
        self::assertTrue(StopLatch::isSet(), 'A terminate of the enum value set no stop latch.');
        $this->mirror()->table('orrery_signals')->where('signal', 'terminate')->delete();
        SignalState::flushCache();
        StopLatch::reset();

        // Reservation and release.
        $first = $queue->pop(QueueName::Emails);
        self::assertInstanceOf(DatabaseJob::class, $first);

        // The heartbeat names the enum value as the queue of the last
        // reservation, with the default throttle.
        self::assertSame('mail-out', $this->mirror()->table('orrery_worker_heartbeats')->value('current_queue'), 'The heartbeat has the enum value.');
        $uuid = self::uuidOf($first->getRawBody());
        self::assertSame('reserved', $this->rowOrFail($uuid)->status, 'The reservation is mirrored.');

        $first->release();
        $released = DB::table('jobs')->where('payload', $first->getRawBody())->first();
        self::assertNotNull($released);
        self::assertSame('mail-out', $released->queue);
        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'The release is mirrored.');
        self::assertSame((int) $released->id, (int) $row->job_id, 'The mirror row follows the released jobs row.');

        // Reservation and completion of every available job: the pushRaw()
        // job, the bulk job and the released job, in the order of their ids.
        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        // The released job runs its second attempt.
        $options = $this->workerOptions();
        $options->maxTries = 2;
        $completed = [];

        while (($job = $queue->pop(QueueName::Emails)) instanceof DatabaseJob) {
            $worker->process('database', $job, $options);
            $completed[] = self::uuidOf($job->getRawBody());
            self::assertLessThanOrEqual(3, count($completed), 'Only the three available jobs are reserved.');
        }

        self::assertCount(3, $completed);
        self::assertContains($uuid, $completed);

        foreach ($completed as $done) {
            $row = $this->rowOrFail($done);
            self::assertSame('completed', $row->status, 'The completion is mirrored.');
            self::assertSame('mail-out', $row->queue);
        }

        self::assertSame(2, (int) $this->rowOrFail($uuid)->attempts, 'The released job ran its second attempt.');
        self::assertSame(3, $this->counters()['queue:database:mail-out']['throughput'] ?? null, 'The metrics key has the enum value.');
    }

    /**
     * With the swap off, only the outcome is mirrored.
     *
     * Rejects: an outcome listener that casts the enum to a string or uses
     * the case name, and a kept swap-off push observation (a row before the
     * outcome).
     */
    public function test_swap_off_outcome_listener_mirrors_an_enum_queue_that_get_queue_keeps(): void
    {
        $queue = $this->enumKeepingQueue();
        self::assertInstanceOf(EnumKeepingNativeQueue::class, $queue);

        $queue->pushOn(QueueName::Emails, new BasicJob);

        $pushed = $this->onlyJobsRow();
        $uuid = self::uuidOf($pushed['payload']);
        self::assertSame('mail-out', DB::table('jobs')->value('queue'));
        self::assertNull($this->row($uuid), 'A push of a queue that is not the package\'s is not mirrored.');

        $job = $queue->pop(QueueName::Emails);
        self::assertInstanceOf(DatabaseJob::class, $job);
        self::assertNull($this->row($uuid), 'A reservation of a queue that is not the package\'s is not mirrored.');

        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->process('database', $job, $this->workerOptions());

        $row = $this->rowOrFail($uuid);
        self::assertSame('completed', $row->status, 'The completion is mirrored.');
        self::assertSame('mail-out', $row->queue);
        self::assertSame(1, $this->counters()['queue:database:mail-out']['throughput'] ?? null);
    }

    /**
     * Rejects: a failure path (MarshalFailedEvent, then the package
     * JobFailed and its listener) that gives the enum object to the
     * package event or casts it to a string.
     */
    public function test_a_failure_mirrors_an_enum_queue_that_get_queue_keeps(): void
    {
        $queue = $this->enumKeepingQueue();
        $queue->pushOn(QueueName::Emails, new FailingJob);
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);

        $marshalled = [];
        Event::listen(JobFailed::class, static function (JobFailed $event) use (&$marshalled): void {
            $marshalled[] = $event->queue;
        });

        $job = $queue->pop(QueueName::Emails);
        self::assertInstanceOf(DatabaseJob::class, $job);

        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');

        try {
            $worker->process('database', $job, $this->workerOptions());
            self::fail('The job throws.');
        } catch (RuntimeException $error) {
            self::assertSame('test failure', $error->getMessage());
        }

        self::assertSame(['mail-out'], $marshalled, 'The package JobFailed has the enum value.');
        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status, 'The failure is mirrored.');
        self::assertSame('mail-out', $row->queue);
    }

    /**
     * Rejects: a heartbeat listener that gives the enum object of the job's
     * queue to the heartbeat write, or writes its case name.
     */
    public function test_the_heartbeat_listener_writes_the_enum_value(): void
    {
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 0);
        ProcessContext::useForTesting(ProcessContext::current()->withKindForTesting(ProcessContext::WORKER));
        $queue = $this->enumKeepingQueue();
        $queue->pushOn(QueueName::Emails, new BasicJob);
        $job = $queue->pop(QueueName::Emails);
        self::assertInstanceOf(DatabaseJob::class, $job);
        self::assertSame(QueueName::Emails, $job->getQueue(), 'The job keeps the enum, as on Laravel 12.');
        $this->mirror()->table('orrery_worker_heartbeats')->delete();

        (new RecordWorkerHeartbeat)->handle(new JobProcessing('database', $job));

        self::assertSame(['mail-out'], $this->mirror()->table('orrery_worker_heartbeats')->pluck('current_queue')->all());
    }

    /**
     * The `database` connection through EnumKeepingConnector, in the swap
     * mode of the test. The resolved connections are cleared first.
     */
    private function enumKeepingQueue(): \Illuminate\Queue\DatabaseQueue
    {
        /** @var QueueManager $manager */
        $manager = $this->app['queue'];
        $manager->addConnector('database', fn (): EnumKeepingConnector => new EnumKeepingConnector($this->app['db'], ! $this->swapOff()));
        (function (): void {
            $this->connections = [];
        })->call($manager);

        return $this->queue();
    }
}
