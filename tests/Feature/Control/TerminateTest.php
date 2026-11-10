<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Control;

use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Control\StopLatch;
use Justrau\Orrery\Control\WorkerControl;
use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Terminate: the newest applicable, unexpired signal decides,
 * the stop latch stops every queue of the process, and posix_kill is only
 * the fallback without a bound Worker. The first test is the rewrite of the
 * probe ControlPlaneProbeTest.
 */
#[Group('process')]
final class TerminateTest extends TestCase
{
    use WorkerFixtures;

    /**
     * Rejects: specificity applied before freshness.
     */
    public function test_newer_global_terminate_beats_an_older_queue_terminate(): void
    {
        $workerStartedAt = Clock::now();

        // 10 minutes ago the operator terminated the "emails" workers; the
        // supervisor restarted them, so this worker started after that signal.
        $this->signal('queue', 'database:emails', $workerStartedAt - 600, Carbon::now()->addMinutes(20));
        // Now a deploy issues a global terminate.
        $this->signal('global', '', $workerStartedAt + 5, Carbon::now()->addMinutes(30));

        $signals = new SignalState;
        self::assertSame(Clock::toDecimal($workerStartedAt + 5), Clock::toDecimal((float) $signals->terminateIssuedAfter('database', 'emails', $workerStartedAt)), 'the older queue terminate hid the newer global terminate');

        // An expired signal is ignored at read time, however new it is.
        $this->mirror()->table('orrery_signals')->delete();
        $this->signal('queue', 'database:emails', $workerStartedAt - 600, Carbon::now()->addMinutes(20));
        $this->signal('global', '', $workerStartedAt + 5, Carbon::now()->subMinute());
        SignalState::flushCache();
        self::assertNull($signals->terminateIssuedAfter('database', 'emails', $workerStartedAt), 'an expired terminate stopped the worker');

        // A signal of another queue or connection never applies.
        $this->signal('connection', 'other', $workerStartedAt + 9, Carbon::now()->addMinutes(20));
        $this->signal('queue', 'database:reports', $workerStartedAt + 9, Carbon::now()->addMinutes(20));
        SignalState::flushCache();
        self::assertNull($signals->terminateIssuedAfter('database', 'emails', $workerStartedAt));
    }

    /**
     * Rejects: a terminate that stops only the queue of the current pop().
     */
    #[Group('shared-database')]
    public function test_multi_queue_terminate_latch_stops_every_queue(): void
    {
        $env = $this->sharedEnvironment();
        $barrier = Barrier::forTest();
        $hold = DatabaseQueue::BEFORE_CONTROL;

        // The worker holds before the control check of its first pop(), so the
        // terminate and the job on the second queue exist before that read.
        // The worker reads the signals at every check (cache 0 s): its Looping
        // read before the hold must not answer the read after the hold, so the
        // terminate is first seen inside pop('alpha'), and only the latch
        // stops pop('beta') in the same getNextJob().
        $worker = WorkerProcess::start(
            ['queue:work', 'database', '--queue=alpha,beta', '--sleep=0', '--tries=1'],
            $env,
            $barrier->holdEnvironment($hold) + ['ORRERY_HEARTBEAT_SECONDS' => '0', 'ORRERY_SIGNAL_CACHE_SECONDS' => '0'],
        );
        $barrier->await("{$hold}.reached", 60, $worker->abandonIfExited());

        (new SignalState)->terminate('queue', 'database:alpha');
        $id = Queue::connection('database')->push(new BasicJob, '', 'beta');
        $barrier->arrive("{$hold}.release");

        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $job = DB::table('jobs')->where('id', $id)->first();
        self::assertNotNull($job, 'the worker reserved and ran the job of its second queue after the terminate: '.$worker->describe());
        self::assertNull($job->reserved_at, 'the worker reserved the job of its second queue after the terminate');
        self::assertSame(0, (int) $job->attempts);
    }

    /**
     * Rejects: posix_kill while a Worker instance is bound.
     */
    public function test_without_a_bound_worker_posix_kill_is_used(): void
    {
        $kills = [];
        StopLatch::useKillerForTesting(static function (int $pid, int $signal) use (&$kills): void {
            $kills[] = [$pid, $signal];
        });

        // No Worker instance: the latch sends SIGTERM to this process.
        self::assertFalse($this->app->resolved('queue.worker'), 'a Worker instance is bound already');
        StopLatch::stop();
        self::assertTrue(StopLatch::isSet());
        self::assertSame([[(int) getmypid(), defined('SIGTERM') ? SIGTERM : 15]], $kills);

        // A bound Worker gets shouldQuit, and no signal is sent.
        StopLatch::reset();
        $kills = [];
        $worker = $this->app->make('queue.worker');
        self::assertInstanceOf(Worker::class, $worker);
        self::assertFalse($worker->shouldQuit);
        StopLatch::stop();
        self::assertTrue(StopLatch::isSet());
        self::assertTrue($worker->shouldQuit, 'the bound Worker did not get shouldQuit');
        self::assertSame([], $kills, 'posix_kill was used while a Worker instance is bound');
    }

    /**
     * Rejects: a latch that pop() ignores, or a terminate issued before the worker started.
     */
    public function test_a_terminate_sets_the_latch_and_no_queue_reserves_after_it(): void
    {
        StopLatch::useKillerForTesting(static function (): void {
        });
        $worker = $this->app->make('queue.worker');
        $this->actAsWorker(startedAt: Clock::now() - 60);
        Queue::push(new BasicJob, '', 'alpha');
        Queue::push(new BasicJob, '', 'beta');

        // A terminate issued before the worker started does not apply.
        $this->signal('global', '', Clock::now() - 120, Carbon::now()->addMinutes(20));
        SignalState::flushCache();
        self::assertNotNull(Queue::connection('database')->pop('alpha'));
        self::assertFalse(StopLatch::isSet());

        (new SignalState)->terminate('queue', 'database:beta');
        self::assertNull(Queue::connection('database')->pop('beta'));
        self::assertTrue(StopLatch::isSet());
        self::assertTrue($worker->shouldQuit);

        // The latch holds every queue, also after the signal is gone.
        $this->mirror()->table('orrery_signals')->delete();
        SignalState::flushCache();
        self::assertNull(Queue::connection('database')->pop('beta'));
        self::assertSame(1, DB::table('jobs')->whereNull('reserved_at')->count());
    }

    /**
     * Rejects: a swap-on worker loop that leaves terminate to pop(), which Laravel never calls for a natively paused queue.
     */
    public function test_a_terminate_reaches_a_worker_whose_only_queue_is_natively_paused(): void
    {
        StopLatch::useKillerForTesting(static function (): void {
        });
        self::assertInstanceOf(DatabaseQueue::class, Queue::connection('database'), 'the connector swap is off');
        $worker = $this->app->make('queue.worker');
        $this->actAsWorker(startedAt: Clock::now() - 60);
        Queue::push(new BasicJob);

        $manager = $this->app['queue'];

        // Laravel's Worker::getNextJob() skips this queue and never calls pop().
        $manager->pause('database', 'default');
        self::assertTrue($manager->isPaused('database', 'default'));

        (new SignalState)->terminate('global', '');

        // The worker loop raises Looping before each getNextJob().
        Event::until(new Looping('database', 'default'));

        self::assertTrue(StopLatch::isSet(), 'the terminate did not set the stop latch');
        self::assertTrue($worker->shouldQuit, 'the terminate did not stop the worker loop');
        self::assertSame(1, DB::table('jobs')->whereNull('reserved_at')->count());
    }

    /**
     * Rejects: a terminate decision against the ps start in whole seconds, which stops a replacement worker that started after the signal in the same second.
     */
    public function test_the_precise_worker_start_decides_within_one_second(): void
    {
        StopLatch::useKillerForTesting(static function (): void {
        });
        $this->app->make('queue.worker');
        $second = floor(Clock::now()) - 30;

        // ps gives the whole second; the precise start is 0.5 s later.
        $this->actAsWorker(startedAt: $second, controlStartedAt: $second + 0.5);

        // A terminate 0.2 s before the precise start: the worker started after it.
        $this->signal('global', '', $second + 0.3, Carbon::now()->addMinutes(20));
        SignalState::flushCache();
        self::assertFalse(WorkerControl::popShouldSkip('database', 'default'), 'a terminate from before the start stopped the replacement worker');
        self::assertFalse(StopLatch::isSet());

        // A terminate 0.2 s after the precise start, in the same second.
        $this->mirror()->table('orrery_signals')->delete();
        $this->signal('global', '', $second + 0.7, Carbon::now()->addMinutes(20));
        SignalState::flushCache();
        self::assertTrue(WorkerControl::popShouldSkip('database', 'default'), 'a terminate from after the start did not stop the worker');
        self::assertTrue(StopLatch::isSet());
    }

    /**
     * Rejects: a listen child that compares a terminate with its listen parent's start, so every replacement child of a running parent stops until the signal expires.
     */
    public function test_a_listen_child_compares_with_its_own_start(): void
    {
        StopLatch::useKillerForTesting(static function (): void {
        });
        $this->app->make('queue.worker');
        $second = floor(Clock::now()) - 30;
        $this->signal('global', '', $second + 10, Carbon::now()->addMinutes(20));

        // The parent started before the signal; this child started after it.
        $this->actAsWorker(ppid: 4000, parentStartedAt: $second, parentCommand: 'php artisan queue:listen', startedAt: $second + 20, controlStartedAt: $second + 20.5);
        self::assertSame(ProcessContext::LISTEN_CHILD, ProcessContext::current()->mode());
        SignalState::flushCache();
        self::assertFalse(WorkerControl::popShouldSkip('database', 'default'), 'a replacement child stopped for a terminate from before its own start');

        // A child that started before the signal stops.
        $this->actAsWorker(ppid: 4000, parentStartedAt: $second, parentCommand: 'php artisan queue:listen', startedAt: $second + 5, controlStartedAt: $second + 5.5);
        SignalState::flushCache();
        self::assertTrue(WorkerControl::popShouldSkip('database', 'default'), 'a child that started before the terminate ignored it');
    }

    /**
     * Rejects: a listen child that compares a terminate with its parent's start (no replacement child works a job while the signal lives), or a held child that ignores a terminate issued after its own start and runs the job itself.
     * Still passes: a child that stops for a terminate from before its own start when no job waits (the test sees only the child that runs the job).
     */
    #[Group('shared-database')]
    public function test_a_real_listen_parent_works_on_after_a_terminate(): void
    {
        $env = $this->sharedEnvironment();
        $barrier = Barrier::forTest();
        $hold = DatabaseQueue::BEFORE_CONTROL;

        // Every child of the listener holds before its control check until the release. The
        // signal cache is 0 s, so the check after the release reads the signals again.
        $listener = WorkerProcess::start(
            ['queue:listen', 'database', '--queue=default', '--sleep=0', '--tries=1'],
            $env,
            $barrier->holdEnvironment($hold) + ['ORRERY_HEARTBEAT_SECONDS' => '0', 'ORRERY_SIGNAL_CACHE_SECONDS' => '0'],
        );
        $barrier->await("{$hold}.reached", 60, $listener->abandonIfExited());

        // The first child holds now. Its control start comes before its first pop(), so it is
        // earlier than the terminate below, and so is the start of the parent.
        $marker = json_decode((string) file_get_contents($barrier->directory()."/{$hold}.reached"), true, 512, JSON_THROW_ON_ERROR);
        $held = (int) $marker['pid'];
        self::assertNotSame($listener->pid(), $held, 'the listen parent itself held, not a child');

        (new SignalState)->terminate('global', '');
        $id = Queue::connection('database')->push(new BasicJob);
        $barrier->arrive("{$hold}.release");

        // The held child reads the terminate and stops without the job; a replacement child,
        // which starts after the terminate, works the job.
        // The job row goes before JobProcessed completes the mirror row, so the test waits for both.
        $listener->waitUntil(fn (): bool => DB::table('jobs')->where('id', $id)->doesntExist() && $this->mirror()->table('orrery_jobs')->value('status') === 'completed', 60, 'a child started after the terminate worked the job');
        $listener->waitUntil(static fn (): bool => ! posix_kill($held, 0), 30, 'the held child stopped');

        $reservedBy = (string) $this->mirror()->table('orrery_jobs')->value('reserved_by');
        self::assertSame(1, preg_match('/^.+:(\d+):[0-9.]+$/', $reservedBy, $match), "the mirror names the worker that reserved the job: {$reservedBy}");
        self::assertNotSame($held, (int) $match[1], 'the child that held across the terminate ran the job');
        self::assertNotSame($listener->pid(), (int) $match[1], 'the listen parent ran the job itself');
        self::assertSame('completed', $this->mirror()->table('orrery_jobs')->value('status'));
        self::assertTrue($listener->isRunning(), 'the terminate stopped the queue:listen parent. '.$listener->describe());
        $listener->stop();
    }

    /**
     * Rejects: a detected control start from ps in whole seconds, or from a time later than the first package registration.
     */
    public function test_the_detected_control_start_is_the_first_package_registration(): void
    {
        $context = ProcessContext::current();
        $start = $context->controlStartedAt();

        self::assertFalse(defined('LARAVEL_START'), 'this PHPUnit process defines LARAVEL_START');
        self::assertGreaterThanOrEqual($context->startedAt(), $start, 'the control start is earlier than the process start');
        self::assertLessThanOrEqual(Clock::now(), $start);
        self::assertSame($start, ProcessContext::current()->controlStartedAt(), 'the control start changed between two reads');
        self::assertNotSame(floor($start), $start, 'the control start has no sub-second part');
    }

    private function signal(string $scope, string $value, float $issuedAt, ?Carbon $expiresAt): void
    {
        $this->mirror()->table('orrery_signals')->insert([
            'signal' => 'terminate',
            'scope' => $scope,
            'scope_value' => $value,
            'issued_at' => Clock::toDecimal($issuedAt),
            'expires_at' => $expiresAt,
        ]);
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
