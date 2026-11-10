<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\Fixtures\ReportProcessContextJob;
use Justrau\Orrery\Tests\Support\Fixtures\TestCommandsProvider;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The process context: the kind,
 * the worker evidence from the process's own command line, and the identity
 * from the process start time.
 */
#[Group('process')]
final class ProcessContextTest extends TestCase
{
    /**
     * Rejects: binding argv without the merged application definition (the --env option then fails the binding).
     */
    #[Group('shared-database')]
    public function test_queue_work_command_line_gives_evidence(): void
    {
        $env = $this->sharedEnvironment();
        $path = $this->reportPath('work');
        Queue::push(new ReportProcessContextJob($path));

        $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--tries=7', '--timeout=45', '--env=testing', '--sleep=0'], $env);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $report = $this->report($path, $worker);
        self::assertSame('worker', $report['kind']);
        self::assertTrue($report['is_worker']);
        self::assertSame(['tries' => 7, 'timeout' => 45, 'connection' => 'database', 'queue' => null, 'once' => true], $report['evidence'], $worker->describe());
    }

    /**
     * Rejects: evidence only from WorkerStarting, which a --once child never raises.
     */
    #[Group('shared-database')]
    public function test_queue_listen_child_gives_evidence(): void
    {
        $env = $this->sharedEnvironment();
        $path = $this->reportPath('listen');
        Queue::push(new ReportProcessContextJob($path));

        $listener = WorkerProcess::start(['queue:listen', 'database', '--tries=5', '--timeout=33', '--sleep=0'], $env);
        $listener->waitUntil(static fn (): bool => is_file($path), 60, 'its queue:work child reported its context');
        $listener->stop();

        $report = $this->report($path, $listener);
        self::assertNotSame($listener->pid(), $report['pid'], 'the report does not come from the child');
        self::assertSame('worker', $report['kind']);

        // Laravel's Listener passes --tries to its child, but not --timeout:
        // it enforces its own timeout on the child process. So the child's
        // command line gives its own default, 60 (see the result file).
        self::assertSame(['tries' => 5, 'timeout' => 60, 'connection' => 'database', 'queue' => 'default', 'once' => true], $report['evidence'], $listener->describe());
    }

    /**
     * Rejects: options guessed from the queue:work defaults.
     */
    #[Group('shared-database')]
    public function test_custom_worker_command_gives_unknown_evidence(): void
    {
        $env = $this->sharedEnvironment();
        $path = $this->reportPath('custom');
        Queue::push(new ReportProcessContextJob($path));

        $worker = WorkerProcess::start(['test:custom-worker', 'database'], $env, ['ORRERY_TEST_PROVIDERS' => TestCommandsProvider::class]);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $report = $this->report($path, $worker);
        self::assertSame('cli', $report['kind']);
        self::assertFalse($report['is_worker']);
        self::assertNull($report['evidence'], 'a custom worker command got guessed evidence');
    }

    /**
     * Rejects: a kind taken from runningInConsole() alone, or from the last command that ran in the process.
     */
    public function test_phpunit_process_is_not_a_worker(): void
    {
        $path = $this->reportPath('phpunit');
        Queue::push(new ReportProcessContextJob($path));

        // A queue:work run inside this process does not make it a worker.
        self::assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0, '--memory' => 4096]));

        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('cli', $report['kind']);
        self::assertFalse($report['is_worker']);
        self::assertNull($report['evidence']);

        $context = ProcessContext::current();
        self::assertSame('cli', $context->kind());
        self::assertFalse($context->isWorker());
        self::assertNull($context->workerEvidence());
        self::assertSame(getmypid(), $context->pid());
    }

    /**
     * Rejects: an identity from the first heartbeat time, which differs per
     * report; and on Linux an identity from the whole seconds of ps, which
     * two containers with one host name share when they start in the same
     * second.
     */
    #[Group('shared-database')]
    public function test_identity_uses_the_process_start_time(): void
    {
        $env = $this->sharedEnvironment();
        $first = $this->reportPath('first');
        $second = $this->reportPath('second');
        Queue::push(new ReportProcessContextJob($first));
        Queue::push(new ReportProcessContextJob($second));

        $worker = WorkerProcess::start(['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1'], $env);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $a = $this->report($first, $worker);
        $b = $this->report($second, $worker);
        self::assertSame($worker->pid(), $a['pid']);
        self::assertSame($a['identity'], $b['identity'], 'two reports of one worker carry different identities');
        self::assertSame(['tries' => 1, 'timeout' => 60, 'connection' => 'database', 'queue' => null, 'once' => false], $a['evidence']);

        $start = $worker->startTime();
        self::assertNotNull($start, 'ps gave no start time for the worker');
        $expected = (float) (new DateTimeImmutable($start, new DateTimeZone('UTC')))->getTimestamp();

        if (is_readable('/proc/self/stat')) {
            // /proc first, in clock ticks; ps gives the same second.
            self::assertSame('proc', $a['start_source']);
            self::assertEqualsWithDelta($expected, (float) $a['started_at'], 1.0);
        } else {
            self::assertSame('ps', $a['start_source']);
            self::assertSame($expected, (float) $a['started_at'], 'JSON gives a whole number as an integer');
        }

        self::assertSame(gethostname().':'.$worker->pid().':'.sprintf('%.6F', (float) $a['started_at']), $a['identity']);
    }

    private function sharedEnvironment(): TestEnvironment
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        return $env;
    }

    private function reportPath(string $name): string
    {
        return Barrier::forTest()->directory().'/'.$name.'.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $path, WorkerProcess $worker): array
    {
        self::assertFileExists($path, 'the job wrote no report. '.$worker->describe());

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
