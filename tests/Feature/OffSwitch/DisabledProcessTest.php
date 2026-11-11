<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\OffSwitch;

use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Fixtures\OffSwitch\DispatchProvider;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * A real dispatch and a real worker with ORRERY_ENABLED=false, then the
 * package on again in this process: the tick and the dashboard work on a
 * state that the package did not observe.
 */
#[Group('process')]
#[Group('shared-database')]
final class DisabledProcessTest extends ControllerTest
{
    private const DISABLED = ['ORRERY_ENABLED' => 'false'];

    /**
     * Rejects: a disabled dispatcher or worker process that writes a mirror row, a heartbeat or a health window
     * (a listener, the swapped queue's pop() or a terminating flush that still runs), a disabled worker that stops
     * writing Laravel's own failed_jobs record, and a tick or dashboard read that fails on the unobserved state
     * after the switch is on again.
     * Still passes: a disabled process that opens a package connection and sends no write (the in-process test
     * counts the statements).
     */
    public function test_disabled_processes_write_nothing_and_the_enabled_package_reads_their_state(): void
    {
        $env = $this->sharedEnvironment();

        // Observed while on: a waiting job that a disabled worker then runs.
        Queue::push(new BasicJob);
        self::assertSame('pending', $this->mirror()->table('orrery_jobs')->value('status'));

        $dispatch = WorkerProcess::start(['test:dispatch-pair'], $env, self::DISABLED + ['ORRERY_TEST_PROVIDERS' => DispatchProvider::class]);
        self::assertSame(0, $dispatch->waitForExit(60), $dispatch->describe());
        self::assertStringContainsString(LaravelDatabaseQueue::class, $dispatch->output(), $dispatch->describe());
        self::assertSame(3, DB::table('jobs')->count());

        $worker = WorkerProcess::start(['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1'], $env, self::DISABLED);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        self::assertSame(0, DB::table('jobs')->count(), $worker->describe());
        self::assertSame(1, DB::table('failed_jobs')->count(), 'Laravel still records the failure. '.$worker->describe());
        self::assertSame(1, $this->mirror()->table('orrery_jobs')->count(), 'only the job pushed while on has a row');
        self::assertSame('pending', $this->mirror()->table('orrery_jobs')->value('status'), 'nobody observed its run');
        self::assertSame(0, $this->mirror()->table('orrery_worker_heartbeats')->count(), $worker->describe());
        self::assertSame(0, $this->mirror()->table('orrery_health_events')->count(), $worker->describe());

        // On again (this process): the tick and the main reads.
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertSame([], $this->mirror()->table('orrery_health_events')->whereNotNull('last_error_operation')->pluck('last_error_operation')->all());

        foreach (['/orrery/api/workload', '/orrery/api/workers', '/orrery/api/counts', '/orrery/api/health', '/orrery/api/jobs?status=pending', '/orrery/api/jobs?status=failed', '/orrery/api/failures/groups?'.http_build_query(['from' => time() - 3600, 'to' => time() + 60]), '/orrery/api/throughput?minutes=60', '/orrery/api/classes?minutes=60'] as $uri) {
            $response = $this->getJson($uri);
            self::assertSame(200, $response->status(), $uri.': '.$response->getContent());
        }
    }

    private function sharedEnvironment(): TestEnvironment
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share. Run the process tests through bin/test sqlite --sqlite-file, bin/test mysql or bin/test pgsql.');
        }

        return $env;
    }
}
