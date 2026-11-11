<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Workers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\SyncJob;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\WorkerEvidence;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Workers\LiveWorkers;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * A worker that stops normally removes its own heartbeat row
 * (Listeners\ForgetStoppedWorker): a daemon on WorkerStopping, a --once
 * worker when its queue:work command finishes. A queue:listen child keeps
 * its row (its parent starts the next child at once), and so does a worker
 * killed for a timed-out job, which can still hold the job's lease.
 */
final class WorkerStoppingTest extends ControllerTest
{
    use WorkerFixtures;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.workers.heartbeat_seconds', 0);
    }

    /**
     * Rejects: no listener on WorkerStopping (the stopped worker stays
     * listed and turns quiet), and a delete of other workers' rows.
     */
    public function test_worker_stopping_removes_only_the_own_row(): void
    {
        $this->insertHeartbeats([$this->heartbeatRow('web-9', 77)]);
        $this->actAsWorker();
        event(new Looping('database', 'default'));
        self::assertCount(2, app(LiveWorkers::class)->list());

        event(new WorkerStopping(0));

        self::assertSame(['web-9'], $this->mirror()->table('orrery_worker_heartbeats')->pluck('host')->all());
        self::assertCount(1, app(LiveWorkers::class)->list(), 'the stopped worker is still listed');
    }

    /**
     * Rejects: a delete statement in a process that wrote no row (a web
     * request or another command that raises the events).
     */
    public function test_a_process_without_a_row_sends_no_delete(): void
    {
        $this->actAsWorker();
        $statements = $this->countPackageStatements(static function (): void {
            event(new WorkerStopping(0));
        });

        self::assertSame(0, $statements);
    }

    /**
     * Rejects: a delete after a timed-out job: the killed worker can still
     * hold the job's lease, which keeps the row for the stuck-job rules.
     */
    public function test_a_worker_killed_for_a_timed_out_job_keeps_its_row(): void
    {
        $this->actAsWorker();
        event(new Looping('database', 'default'));
        event(new JobTimedOut('database', new SyncJob($this->app, '{}', 'database', 'default')));
        event(new WorkerStopping(1));

        self::assertSame(1, $this->mirror()->table('orrery_worker_heartbeats')->count());
    }

    /**
     * Rejects: a --once worker whose row stays after its command (it raises
     * no WorkerStopping), and a delete when a command that a job calls
     * inside the worker finishes.
     */
    public function test_a_once_worker_removes_its_row_when_its_command_finishes(): void
    {
        $this->actAsWorker(evidence: new WorkerEvidence(1, 60, 'database', 'default', true));
        self::assertSame(ProcessContext::ONCE, ProcessContext::current()->mode());
        $input = new ArrayInput([]);
        $output = new NullOutput;

        event(new CommandStarting('queue:work', $input, $output));
        event(new Looping('database', 'default'));
        event(new CommandStarting('cache:clear', $input, $output));
        event(new CommandFinished('cache:clear', $input, $output, 0));
        self::assertSame(1, $this->mirror()->table('orrery_worker_heartbeats')->count(), 'a nested command removed the row');

        event(new CommandFinished('queue:work', $input, $output, 0));
        self::assertSame(0, $this->mirror()->table('orrery_worker_heartbeats')->count());
    }

    /**
     * Rejects: a queue:listen child that removes its row when it exits, so
     * the parent leaves the list between two children.
     */
    public function test_a_queue_listen_child_keeps_its_row(): void
    {
        $this->actAsWorker(ppid: 4000, parentStartedAt: microtime(true) - 100, parentCommand: 'php artisan queue:listen', evidence: new WorkerEvidence(1, 60, 'database', 'default', true));
        self::assertSame(ProcessContext::LISTEN_CHILD, ProcessContext::current()->mode());
        $input = new ArrayInput([]);
        $output = new NullOutput;

        event(new CommandStarting('queue:work', $input, $output));
        event(new Looping('database', 'default'));
        event(new CommandFinished('queue:work', $input, $output, 0));
        event(new WorkerStopping(0));

        self::assertSame(1, $this->mirror()->table('orrery_worker_heartbeats')->count());
    }

    private function countPackageStatements(callable $callback): int
    {
        $count = 0;
        $this->app['events']->listen(QueryExecuted::class, static function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }
}
