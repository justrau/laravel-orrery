<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Operations\Jobs\ClockProbeJob;
use Justrau\Orrery\Tests\Support\TestClock;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A worker reads the parent's test clock file at every database clock read.
 * Kind: new behaviour.
 */
#[Group('process')]
final class DatabaseClockFileTest extends TestCase
{
    /**
     * Rejects: a child that reads the clock file once at its start (a held publisher would then never see
     * a moved deadline), and a handoff that drops the file, so the child keeps the real clock.
     */
    #[Group('shared-database')]
    public function test_a_running_worker_sees_each_new_offset(): void
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share. Run the process tests through bin/test sqlite --sqlite-file, bin/test mysql or bin/test pgsql.');
        }

        $directory = sys_get_temp_dir().'/orrery-clock-probe-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $first = $directory.'/first.json';
        $second = $directory.'/second.json';

        try {
            TestClock::shift(TestEnvironment::PRIMARY, 0.0);
            Queue::push(new ClockProbeJob($first));
            $worker = WorkerProcess::start(['queue:work', 'database', '--sleep=0', '--tries=1', '--max-jobs=2'], $env);
            $worker->waitUntil(static fn (): bool => is_file($first), 60, 'the first probe ran');

            // The worker keeps running; only the file changes.
            TestClock::shift(TestEnvironment::PRIMARY, 7200.0);
            Queue::push(new ClockProbeJob($second));
            $worker->waitUntil(static fn (): bool => is_file($second), 60, 'the second probe ran');
            self::assertSame(0, $worker->waitForExit(60), $worker->describe());

            $a = json_decode((string) file_get_contents($first), true);
            $b = json_decode((string) file_get_contents($second), true);

            self::assertEqualsWithDelta(7200, $b['queue'] - $a['queue'], 60, 'The queue database clock moved by the new offset.');
            // The mirror moves with it only when it is the queue database's own session.
            $sessions = $this->app->make(PackageSession::class);
            $shared = $sessions->mirror()->getName() === $sessions->forQueue('database')->getName();
            self::assertEqualsWithDelta($shared ? 7200 : 0, $b['mirror'] - $a['mirror'], 60, 'The mirror clock moved only with its own connection.');
        } finally {
            foreach ([$first, $second] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }
}
