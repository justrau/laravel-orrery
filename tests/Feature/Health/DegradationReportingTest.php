<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Health;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Justrau\Orrery\Database\Models\Setting;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\Fixtures\TestCommandsProvider;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\MirrorOutage;
use Justrau\Orrery\Tests\Support\MirrorSession;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/**
 * Degradation reporting: the windows of a web request, a tick,
 * a killed worker, and a healthy worker that never closes another process's
 * window.
 */
#[Group('process')]
final class DegradationReportingTest extends TestCase
{
    private const WORK_ONCE = ['queue:work', 'database', '--once', '--sleep=0', '--tries=1'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->name() === 'test_swap_off_heartbeat_flush_waits_for_the_breaker_close') {
            $app['config']->set('orrery.swap_connector', false);
            $app['config']->set('orrery.workers.heartbeat_seconds', 0);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: no flush in the terminating callback.
     */
    #[FreshSchema]
    public function test_short_producer_outage_in_a_web_request(): void
    {
        $env = $this->testEnvironment();
        // PHPUnit runs in the console: this request stands for a web process.
        ProcessContext::useForTesting(ProcessContext::current()->withKindForTesting('web'));

        Route::get('/orrery-test/outage', function () use ($env) {
            $outage = MirrorOutage::begin($this->app, $env);
            Queue::push(new BasicJob);
            $outage->end();

            // A redirect outage of a server opens the breaker: the read
            // runs after it closed.
            Watcher::advanceMonotonicForTesting(31);
            $this->app->make(Watcher::class)->run('test:read', static fn () => Setting::query()->count());

            return 'ok';
        });

        $this->get('/orrery-test/outage')->assertOk()->assertSeeText('ok');

        self::assertSame(1, DB::connection(TestEnvironment::PRIMARY)->table('jobs')->count(), 'the outage lost the application job');
        $window = $this->onlyWindow(ProcessContext::current()->identity());
        self::assertSame('web', $window->context);
        self::assertGreaterThanOrEqual(1, (int) $window->dropped);
        self::assertNotNull($window->ended_at, 'the recovered window has no end');
    }

    /**
     * Rejects: a heartbeat flush that writes while the breaker is open (each
     * flush of an unreachable mirror waits for its connect timeout),
     * a close of the breaker that does not flush, and a heartbeat
     * write that the open breaker does not skip.
     */
    public function test_swap_off_heartbeat_flush_waits_for_the_breaker_close(): void
    {
        self::assertNotInstanceOf(\Justrau\Orrery\DatabaseQueue::class, $this->app['queue']->connection('database'), 'the connector swap is on');
        $watcher = $this->app->make(Watcher::class);

        // A dependency error of the mirror session opens the breaker. The
        // mirror itself is reachable again (a short outage).
        $watcher->run('test:lost', static function (): never {
            throw new QueryException(MirrorSession::name(), 'select 1', [], new class extends PDOException
            {
                public function __construct()
                {
                    parent::__construct('SQLSTATE[08006]: connection failure');
                    $this->code = '08006';
                    $this->errorInfo = ['08006', 7, 'connection failure'];
                }
            });
        });
        self::assertTrue($watcher->breakerOpen());

        Queue::push(new BasicJob);
        $this->work();

        self::assertSame(0, DB::connection(TestEnvironment::PRIMARY)->table('jobs')->count(), 'the job did not run');
        self::assertTrue($watcher->breakerOpen(), 'the breaker expired during the test');

        // The JobProcessing heartbeat skipped its flush while the breaker was
        // open, and its own write stayed watched (skipped).
        self::assertSame(0, $this->mirror()->table('orrery_health_events')->count(), 'the heartbeat flushed while the breaker was open');
        self::assertSame(0, $this->mirror()->table('orrery_worker_heartbeats')->count(), 'the heartbeat write ran while the breaker was open');

        // The close of the breaker flushes the window.
        Watcher::advanceMonotonicForTesting(30);
        self::assertFalse($watcher->breakerOpen());
        $window = $this->onlyWindow(ProcessContext::current()->identity());
        self::assertSame('test:lost', $window->last_error_operation);
        self::assertNull($window->ended_at);
        self::assertGreaterThanOrEqual(1, (int) $window->dropped);
    }

    /**
     * Rejects: a phase error that never reaches the degradation record.
     */
    #[Group('shared-database')]
    public function test_maintenance_only_failure(): void
    {
        $env = $this->sharedEnvironment();

        $tick = WorkerProcess::start(['orrery:tick'], $env, [
            'ORRERY_TEST_PROVIDERS' => TestCommandsProvider::class,
            TestCommandsProvider::FAILING_PHASE_VARIABLE => 'broken-phase',
        ]);
        self::assertSame(0, $tick->waitForExit(60), $tick->describe());

        $window = $this->onlyWindow($this->identityPattern($tick));
        self::assertSame('tick', $window->context);
        self::assertSame('tick:broken-phase', $window->last_error_operation);
        self::assertSame(0, (int) $window->unverified);
    }

    /**
     * Rejects: closing a window at flush time without a recovery.
     */
    #[Group('shared-database')]
    #[FreshSchema]
    public function test_killed_worker_during_an_outage_keeps_an_open_window(): void
    {
        [$worker, $window] = $this->killWorkerDuringAnOutage($this->sharedEnvironment());

        self::assertSame('worker', $window->context);
        self::assertNull($window->ended_at, 'the window of the killed worker has an end. '.$worker->describe());
        self::assertGreaterThanOrEqual(1, (int) $window->dropped);
    }

    /**
     * Rejects: closing windows by time instead of by process.
     */
    #[Group('shared-database')]
    #[FreshSchema]
    public function test_healthy_worker_does_not_close_another_process_window(): void
    {
        $env = $this->sharedEnvironment();
        [$killed, $before] = $this->killWorkerDuringAnOutage($env);

        // A second worker has its own outage and its own recovery.
        $barrier = Barrier::forTest();
        Queue::push(new BasicJob);
        $this->mirrorSchema()->rename('orrery_jobs', MirrorOutage::RENAMED_PREFIX.'orrery_jobs');

        try {
            $healthy = WorkerProcess::start(self::WORK_ONCE, $env, ['ORRERY_HEARTBEAT_SECONDS' => '0'] + $barrier->holdEnvironment('health.after_flush'));
            $barrier->await('health.after_flush.reached', 60, $healthy->abandonIfExited());
        } finally {
            $this->mirrorSchema()->rename(MirrorOutage::RENAMED_PREFIX.'orrery_jobs', 'orrery_jobs');
        }

        $barrier->arrive('health.after_flush.release');
        self::assertSame(0, $healthy->waitForExit(60), $healthy->describe());

        $recovered = $this->onlyWindow($this->identityPattern($healthy));
        self::assertNotNull($recovered->ended_at, 'the second worker did not report its recovery. '.$healthy->describe());

        $after = $this->onlyWindow($this->identityPattern($killed));
        self::assertEquals($before, $after, "the healthy worker changed the killed worker's window");
        self::assertNull($after->ended_at);
    }

    /**
     * Renames only orrery_jobs, runs a worker that holds after its first
     * flush, kills it there, and restores the table.
     *
     * @return array{0: WorkerProcess, 1: stdClass}
     */
    private function killWorkerDuringAnOutage(TestEnvironment $env): array
    {
        $barrier = Barrier::forTest();
        Queue::push(new BasicJob);
        $this->mirrorSchema()->rename('orrery_jobs', MirrorOutage::RENAMED_PREFIX.'orrery_jobs');

        try {
            $worker = WorkerProcess::start(self::WORK_ONCE, $env, ['ORRERY_HEARTBEAT_SECONDS' => '0'] + $barrier->holdEnvironment('health.after_flush'));
            $barrier->await('health.after_flush.reached', 60, $worker->abandonIfExited());

            // The flush came from a heartbeat (JobProcessing), not from the
            // end of the command: the job is still reserved.
            self::assertSame(1, DB::connection(TestEnvironment::PRIMARY)->table('jobs')->whereNotNull('reserved_at')->count(), 'the first flush was not a heartbeat flush. '.$worker->describe());
            $worker->stop();
        } finally {
            $this->mirrorSchema()->rename(MirrorOutage::RENAMED_PREFIX.'orrery_jobs', 'orrery_jobs');
        }

        return [$worker, $this->onlyWindow($this->identityPattern($worker))];
    }

    private function sharedEnvironment(): TestEnvironment
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        return $env;
    }

    private function identityPattern(WorkerProcess $process): string
    {
        return '%:'.$process->pid().':%';
    }

    private function onlyWindow(string $process): stdClass
    {
        $operator = str_contains($process, '%') ? 'like' : '=';
        $windows = $this->mirror()->table('orrery_health_events')->where('process', $operator, $process)->get();

        self::assertCount(1, $windows, "not exactly one health window of {$process}");

        return $windows[0];
    }
}
