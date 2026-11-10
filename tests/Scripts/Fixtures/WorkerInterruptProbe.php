<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts\Fixtures;

use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Jobs\HookedJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;

/**
 * A probe for WorkerInterruptDockerTest. It is in no test suite: the test
 * runs it by path, `bin/test mysql tests/Scripts/Fixtures/WorkerInterruptProbe.php`.
 *
 * It starts a real daemon worker through WorkerProcess. The worker holds a
 * HookedJob at the point "interrupt.hold" of the barrier directory in
 * ORRERY_WORKER_PROBE_BARRIER. When the worker is at the point, the probe
 * writes ORRERY_WORKER_PROBE_OUT/probe.json: the identities (PID and start) of
 * PHPUnit and of the worker, the run id and the database. Then PHPUnit waits
 * for ORRERY_WORKER_PROBE_OUT/phpunit.release (at most 120 seconds), so the
 * test can interrupt the run while PHPUnit and the worker both run.
 */
final class WorkerInterruptProbe extends TestCase
{
    public function test_probe(): void
    {
        $out = (string) getenv('ORRERY_WORKER_PROBE_OUT');
        $barrier = Barrier::open((string) getenv('ORRERY_WORKER_PROBE_BARRIER'));
        $env = $this->testEnvironment();

        Queue::push(new HookedJob('interrupt.hold'));

        // A daemon worker: SIGTERM only makes it stop after its current job.
        $worker = WorkerProcess::start(['queue:work', 'database', '--sleep=0', '--tries=1'], $env, $barrier->holdEnvironment('interrupt.hold'));
        $barrier->await('interrupt.hold.reached', 60, $worker->abandonIfExited());

        $record = [
            'phpunit' => ['pid' => (int) getmypid(), 'start' => WorkerProcess::processStart((int) getmypid())],
            'worker' => ['pid' => $worker->pid(), 'start' => $worker->startTime()],
            'run_id' => $env->runId,
            'database' => $env->database,
        ];
        file_put_contents($out.'/probe.json.tmp', json_encode($record, JSON_UNESCAPED_SLASHES));
        rename($out.'/probe.json.tmp', $out.'/probe.json');

        $deadline = microtime(true) + 120;

        while (! file_exists($out.'/phpunit.release')) {
            if (microtime(true) >= $deadline) {
                self::fail('the probe was neither released nor interrupted within 120 seconds');
            }

            usleep(10_000);
            clearstatcache();
        }

        // Released without an interrupt: the worker finishes its job, and
        // tearDown stops it.
        $barrier->arrive('interrupt.hold.release');
        $this->addToAssertionCount(1);
    }
}
