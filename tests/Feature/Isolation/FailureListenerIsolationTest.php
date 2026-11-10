<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Isolation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\MirrorOutage;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Isolation of the failure path: a
 * mirror outage never stops Laravel's failed-job logging.
 */
#[Group('process')]
#[FreshSchema]
final class FailureListenerIsolationTest extends TestCase
{
    /**
     * Rejects: a failure listener whose mirror error stops Laravel's failed-job logging.
     */
    #[Group('shared-database')]
    public function test_failed_job_is_logged_while_the_mirror_is_down(): void
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        Queue::push(new FailingJob);
        $barrier = Barrier::forTest();

        // The rename method in every mirror mode: a redirect changes only the
        // configuration of this process, and the worker has its own.
        $outage = MirrorOutage::begin($this->app, $env, MirrorOutage::RENAME);

        try {
            $worker = WorkerProcess::start(
                ['queue:work', 'database', '--once', '--sleep=0', '--tries=1'],
                $env,
                $barrier->holdEnvironment('health.before_flush'),
            );

            // The worker holds before its first flush of the degradation
            // record (the terminating callback), or it exits.
            $worker->waitUntil(static fn (): bool => ! $worker->isRunning() || $barrier->arrived('health.before_flush.reached'), 60, 'it failed the job');

            self::assertSame(1, DB::connection(TestEnvironment::PRIMARY)->table('failed_jobs')->count(), 'the mirror outage stopped the failed-job logging. '.$worker->describe());
            self::assertSame(0, DB::connection(TestEnvironment::PRIMARY)->table('jobs')->count(), $worker->describe());
            self::assertTrue($barrier->arrived('health.before_flush.reached'), 'the worker never tried to flush its degradation record. '.$worker->describe());
        } finally {
            $outage->end();
        }

        $barrier->arrive('health.before_flush.release');
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $windows = $this->mirror()->table('orrery_health_events')
            ->where('process', 'like', '%:'.$worker->pid().':%')
            ->get();

        self::assertCount(1, $windows, 'the worker reported no health window after the outage. '.$worker->describe());
        self::assertSame('worker', $windows[0]->context);
        self::assertGreaterThanOrEqual(1, (int) $windows[0]->dropped);
    }
}
