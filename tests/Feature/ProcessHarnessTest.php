<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Feature\Jobs\HookedJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\Fixtures\TestCommandsProvider;
use Justrau\Orrery\Tests\Support\Fixtures\ThrowingCommand;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\HarnessRefusal;
use Justrau\Orrery\Tests\Support\PhysicalTables;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WaitTimeout;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

/**
 * The process-helper controls: real `queue:work` processes
 * (Support\WorkerProcess) and barriers (Support\Barrier).
 *
 * A worker needs a database that two processes share: a SQLite file, MySQL or
 * Postgres. On in-memory SQLite (`composer test`) the tests that start a
 * worker are skipped with that reason. They are the group shared-database,
 * and only they call sharedEnvironment() (a control checks both sets). Run
 * them with `bin/test sqlite --sqlite-file --group process`, `bin/test mysql`
 * or `bin/test pgsql`. The other tests (the barrier deadline, the handoff and
 * registration rules, and the group check) start no database worker, so they
 * run everywhere.
 *
 * The assertions use the authoritative tables (jobs, failed_jobs), the exit
 * status and the worker's own evidence, not the package's mirror states, so a
 * package bug that a later spec fixes cannot hide a harness failure. The
 * group process-control is in tests/unconditional.txt.
 */
#[Group('process')]
#[Group('process-control')]
final class ProcessHarnessTest extends TestCase
{
    private const WORK_ONCE = ['queue:work', 'database', '--once', '--sleep=0', '--tries=1'];

    /** Aliases of commands outside the worker allowlist, in the order of preference (see harmlessAlias()). */
    private const HARMLESS_ALIASES = ['queue:table', 'schedule:continue', 'queue:continue'];

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> */
    private array $savedVariables = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        $this->restoreVariables();

        try {
            parent::tearDown();
        } finally {
            foreach ($this->temporaryDirectories as $directory) {
                self::removeDirectory($directory);
            }

            $this->temporaryDirectories = [];
        }
    }

    #[Group('shared-database')]
    public function test_a_worker_processes_the_job_that_the_parent_committed(): void
    {
        $env = $this->sharedEnvironment();
        $id = Queue::push(new BasicJob);
        self::assertSame(1, DB::table('jobs')->where('id', $id)->count());

        $worker = WorkerProcess::start(self::WORK_ONCE, $env);

        // The worker's first heartbeat row (its first poll) is visible here.
        $worker->waitUntil(fn (): bool => $this->mirror()->table('orrery_worker_heartbeats')->where('pid', $worker->pid())->exists(), 60, 'its heartbeat row is visible');
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        // The worker read the parent's committed row, and the parent reads
        // the worker's committed change: the job is gone.
        self::assertSame(0, DB::table('jobs')->count(), $worker->describe());
        self::assertSame(0, DB::table('failed_jobs')->count(), $worker->describe());
        self::assertStringContainsString(BasicJob::class, $worker->output());
    }

    /**
     * Rejects: a worker CLI that exits 0 after an uncaught exception of its command, so that
     * waitForExit() cannot see a failed child.
     */
    #[Group('shared-database')]
    public function test_a_command_that_throws_exits_non_zero(): void
    {
        $env = $this->sharedEnvironment();
        $child = WorkerProcess::start(['test:throw'], $env, ['ORRERY_TEST_PROVIDERS' => TestCommandsProvider::class]);

        self::assertSame(1, $child->waitForExit(60), $child->describe());
        self::assertStringContainsString(ThrowingCommand::MESSAGE, $child->errorOutput());
    }

    #[Group('shared-database')]
    public function test_the_parent_and_a_held_worker_use_independent_database_sessions(): void
    {
        $env = $this->sharedEnvironment();
        $barrier = Barrier::forTest();
        Queue::push(new HookedJob('process.session'));
        $parent = $env->identity($this->app);

        $worker = WorkerProcess::start(self::WORK_ONCE, $env, $barrier->holdEnvironment('process.session'));
        $child = $worker->identity();
        $barrier->await('process.session.reached', 60, $worker->abandonIfExited());

        if ($env->isServer()) {
            $sides = ['primary' => $env->database, 'mirror' => $env->mirrorDatabase()];

            foreach ($sides as $side => $database) {
                $session = $child->get("{$side}.evidence.session");
                self::assertIsInt($session);
                self::assertNotSame($parent->get("{$side}.evidence.session"), $session, "the {$side} connections share a server session");

                // The server itself reports the worker's session, on the intended database.
                self::assertSame($database, $this->serverSessionDatabase($env, $session), "the server has no {$side} session {$session} on {$database}");
            }

            self::assertSame($env->separate(), $child->get('primary.evidence.session') !== $child->get('mirror.evidence.session'));
        } else {
            foreach (['primary', 'mirror'] as $side) {
                self::assertSame('sqlite-file', $child->get("{$side}.evidence.kind"));
                self::assertSame($worker->pid(), $child->get("{$side}.evidence.pid"));
                self::assertSame(getmypid(), $parent->get("{$side}.evidence.pid"));
                self::assertSame($parent->get("{$side}.database"), $child->get("{$side}.database"), "the {$side} files differ");
            }

            self::assertNotSame(getmypid(), $worker->pid());
        }

        $barrier->arrive('process.session.release');
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->count());
    }

    #[Group('shared-database')]
    public function test_a_failing_job_run_by_a_real_worker_lands_in_failed_jobs(): void
    {
        $env = $this->sharedEnvironment();
        $id = Queue::push(new FailingJob);
        $uuid = $this->uuidOf($id);

        $worker = WorkerProcess::start(self::WORK_ONCE, $env);

        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->count(), $worker->describe());

        $failed = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($failed, 'the failed job is not in failed_jobs. '.$worker->describe());
        self::assertSame('database', $failed->connection);
        self::assertSame('default', $failed->queue);
        self::assertStringContainsString('test failure', (string) $failed->exception);
    }

    #[Group('shared-database')]
    public function test_a_worker_with_stop_when_empty_exits_zero_after_the_last_job(): void
    {
        $env = $this->sharedEnvironment();

        foreach (range(1, 3) as $ignored) {
            Queue::push(new BasicJob);
        }

        $worker = WorkerProcess::start(['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1'], $env);

        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->count(), $worker->describe());
        self::assertSame(0, DB::table('failed_jobs')->count(), $worker->describe());
        // One worker ran all three: WorkCommand prints RUNNING when a job starts.
        $started = array_filter(explode("\n", $worker->output()), static fn (string $line): bool => str_contains($line, BasicJob::class) && str_contains($line, 'RUNNING'));
        self::assertCount(3, $started, $worker->describe());

        // A wait for a condition that the exited worker can no longer make
        // true ends at once, not at its deadline.
        $waited = microtime(true);

        try {
            $worker->waitUntil(static fn (): bool => false, 30, 'a condition that never holds');
            self::fail('waitUntil() returned for a condition that never holds');
        } catch (WaitTimeout $timeout) {
            self::assertLessThan(5.0, microtime(true) - $waited);
            self::assertStringContainsString('exited before a condition that never holds', $timeout->getMessage());
        }
    }

    #[Group('shared-database')]
    public function test_stop_ends_only_its_own_worker_and_checks_the_recorded_start_first(): void
    {
        $env = $this->sharedEnvironment();

        // A daemon worker: it polls an empty queue until it is stopped.
        $worker = WorkerProcess::start(['queue:work', 'database', '--sleep=1'], $env);
        $worker->waitUntil(fn (): bool => $this->mirror()->table('orrery_worker_heartbeats')->where('pid', $worker->pid())->exists(), 60, 'its heartbeat row is visible');
        $recorded = (string) $worker->startTime();
        self::assertSame($recorded, WorkerProcess::processStart($worker->pid()));

        // A PID whose start differs from the record is another process: no signal.
        $record = new \ReflectionProperty(WorkerProcess::class, 'startTime');
        $record->setValue($worker, 'Thu Jan  1 00:00:00 1970');

        try {
            $worker->stop(1);
            self::fail('stop() signalled a PID with another start time');
        } catch (HarnessRefusal $refusal) {
            self::assertStringContainsString('It is not signalled', $refusal->getMessage());
        }

        self::assertTrue($worker->isRunning(), 'the worker was signalled');
        $record->setValue($worker, $recorded);

        $status = $worker->stop(10);

        self::assertFalse($worker->isRunning());
        self::assertSame(0, $status, 'the worker did not stop gracefully on SIGTERM. '.$worker->describe());
        self::assertNotSame($recorded, WorkerProcess::processStart($worker->pid()));
    }

    #[Group('shared-database')]
    #[FreshSchema]
    public function test_a_failed_worker_stop_keeps_the_worker_barriers_and_schema_until_a_verified_stop(): void
    {
        $env = $this->sharedEnvironment();
        $barrier = Barrier::forTest();
        $id = Queue::push(new HookedJob('process.fault'));
        $this->mirror()->table('orrery_settings')->insert(['key' => 'process-fault', 'value' => 'kept']);

        $worker = WorkerProcess::start(self::WORK_ONCE, $env, $barrier->holdEnvironment('process.fault'));
        $barrier->await('process.fault.reached', 60, $worker->abandonIfExited());

        // Kept outside the application, so they still answer after it is torn down.
        $primaryPdo = DB::connection(TestEnvironment::PRIMARY)->getPdo();
        $mirrorPdo = $this->mirror()->getPdo();
        $primaryTables = PhysicalTables::onPdo($primaryPdo, $env->driver);
        $mirrorTables = PhysicalTables::onPdo($mirrorPdo, $env->driver);
        self::assertNotSame([], array_filter($mirrorTables, static fn (string $table): bool => str_contains($table, 'orrery_')));

        // The fault: the worker's PID no longer has the recorded start, so
        // every stop refuses to signal it, as after a failed inspection.
        $recorded = (string) $worker->startTime();
        $startTime = new \ReflectionProperty(WorkerProcess::class, 'startTime');
        $startTime->setValue($worker, 'Thu Jan  1 00:00:00 1970');

        try {
            try {
                $this->tearDown();
                self::fail('tearDown() went on although the worker is not proved stopped');
            } catch (HarnessRefusal $refusal) {
                self::assertStringContainsString('not proved stopped', $refusal->getMessage());
                self::assertStringContainsString("PID {$worker->pid()} (3 attempts)", $refusal->getMessage());
            }

            // Nothing was cleaned up: the worker, its record, the barrier and the schema are all still there.
            self::assertTrue($worker->isRunning(), 'the worker was signalled');
            self::assertSame([$worker], WorkerProcess::tracked());
            self::assertTrue($barrier->arrived('process.fault.reached'), 'the barrier was removed');
            self::assertNotNull($this->app, 'the application was torn down');
            self::assertSame($primaryTables, PhysicalTables::onPdo($primaryPdo, $env->driver), 'the schema was rolled back');
            self::assertSame($mirrorTables, PhysicalTables::onPdo($mirrorPdo, $env->driver), 'the schema was rolled back');
            self::assertSame('kept', $this->mirror()->table('orrery_settings')->where('key', 'process-fault')->value('value'));
            self::assertNotNull(DB::table('jobs')->where('id', $id)->value('reserved_at'), 'the held job is not reserved');
            self::assertNotNull(self::failedTeardown());

            // No later test of this process uses the schema.
            try {
                $this->setUp();
                self::fail('setUp() started a test after a teardown that could not stop its worker');
            } catch (HarnessRefusal $refusal) {
                self::assertStringContainsString('could not prove that its workers stopped', $refusal->getMessage());
            }
        } finally {
            // The fault is removed.
            $startTime->setValue($worker, $recorded);
        }

        // The verified cleanup: the stop succeeds, then the barrier and the schema go.
        $this->tearDown();

        self::assertFalse($worker->isRunning());
        self::assertNotSame($recorded, WorkerProcess::processStart($worker->pid()), 'the worker still runs');
        self::assertSame([], WorkerProcess::tracked());
        self::assertDirectoryDoesNotExist($barrier->directory());
        self::assertNull($this->app);
        if ($env->siblingDatabase() !== null) {
            // The fresh teardown removes the sibling SQLite mirror file.
            self::assertFileDoesNotExist($env->siblingDatabase(), 'the sibling mirror file was not removed');
        } else {
            self::assertSame([], array_values(array_filter(PhysicalTables::onPdo($mirrorPdo, $env->driver), static fn (string $table): bool => str_contains($table, 'orrery_'))), 'the schema was not rolled back');
        }

        self::assertNull(self::failedTeardown(), 'later tests are still refused');
    }

    #[Group('shared-database')]
    #[FreshSchema]
    public function test_the_worker_cli_refuses_every_command_outside_the_allowlist_by_its_resolved_name(): void
    {
        $env = $this->sharedEnvironment();

        // A sentinel that the parent owns: a table with a row on testbench, and a package row.
        Schema::connection(TestEnvironment::PRIMARY)->create('process_sentinel', static function (Blueprint $table): void {
            $table->string('value');
        });

        try {
            DB::connection(TestEnvironment::PRIMARY)->table('process_sentinel')->insert(['value' => 'kept']);
            $this->mirror()->table('orrery_settings')->insert(['key' => 'process-sentinel', 'value' => 'kept']);
            $primaryTables = PhysicalTables::of(DB::connection(TestEnvironment::PRIMARY));
            $mirrorTables = PhysicalTables::of($this->mirror());
            [$alias, $aliased] = $this->harmlessAlias();

            // The harmless cases first, so a guard that lets names through fails
            // before a destructive one runs. A harmless case that passed a broken
            // guard would only print help: the alias runs with --help.
            $cases = [
                'an alias' => [[$alias, '--help'], "resolves to {$aliased}"],
                'a command outside the list' => [['about'], 'resolves to about'],
                'list before an allowed name' => [['list', 'queue:work'], 'resolves to list'],
                'help before an allowed name' => [['help', 'queue:work'], 'resolves to help'],
                'an option value that names an allowed command' => [['--env', 'queue:work', $alias, '--help'], "resolves to {$aliased}"],
                'an abbreviation of orrery:install' => [['orrery:ins'], 'resolves to orrery:install'],
                'a case variant of db:wipe' => [['Db:Wipe', '--force'], 'resolves to db:wipe'],
                'an abbreviation of db:wipe' => [['db:w', '--force'], 'resolves to db:wipe'],
                'a leading option with its value after =' => [['--env=x', 'db:w', '--force'], 'resolves to db:wipe'],
                'a leading option with a separate value' => [['--env', 'x', 'db:w', '--force'], 'resolves to db:wipe'],
                'a leading option whose value is an allowed command' => [['--env', 'queue:work', 'db:w', '--force'], 'resolves to db:wipe'],
                'an abbreviation of migrate:fresh' => [['mig:f', '--force'], 'resolves to migrate:fresh'],
                'an ambiguous abbreviation' => [['db:', '--force'], 'is not exactly one command'],
            ];

            foreach ($cases as $case => [$arguments, $reason]) {
                $refusal = $this->startRefusal($env, [], $arguments);

                self::assertStringContainsString('(exit 64)', $refusal, $case);
                self::assertStringContainsString($reason, $refusal, $case);
                self::assertSame($primaryTables, PhysicalTables::of(DB::connection(TestEnvironment::PRIMARY)), "{$case}: the schema changed");
                self::assertSame($mirrorTables, PhysicalTables::of($this->mirror()), "{$case}: the schema changed");
                self::assertSame('kept', DB::connection(TestEnvironment::PRIMARY)->table('process_sentinel')->value('value'), "{$case}: the sentinel row is gone");
                self::assertSame('kept', $this->mirror()->table('orrery_settings')->where('key', 'process-sentinel')->value('value'), "{$case}: the package row is gone");
            }

            // The decision uses the resolved name both ways: an abbreviation of
            // an allowed command, after a leading option, runs it.
            Queue::push(new BasicJob);
            $worker = WorkerProcess::start(['--env=x', 'queue:w', 'database', '--once', '--sleep=0', '--tries=1'], $env);
            self::assertSame(0, $worker->waitForExit(60), $worker->describe());
            self::assertSame(0, DB::table('jobs')->count(), $worker->describe());
        } finally {
            Schema::connection(TestEnvironment::PRIMARY)->dropIfExists('process_sentinel');
        }
    }

    #[Group('shared-database')]
    public function test_a_worker_held_at_a_hook_point_keeps_the_job_reserved_until_the_release(): void
    {
        $env = $this->sharedEnvironment();
        $barrier = Barrier::forTest();
        $id = Queue::push(new HookedJob('process.hold'));

        $worker = WorkerProcess::start(self::WORK_ONCE, $env, $barrier->holdEnvironment('process.hold'));
        $barrier->await('process.hold.reached', 60, $worker->abandonIfExited());

        // At the hook point the job runs: it is reserved, and nothing else happened.
        self::assertTrue($barrier->arrived('process.hold.reached'));
        $job = DB::table('jobs')->where('id', $id)->first();
        self::assertNotNull($job, 'the held job was deleted');
        self::assertNotNull($job->reserved_at, 'the held job is not reserved');
        self::assertSame(1, (int) $job->attempts);
        self::assertTrue($worker->isRunning());

        // The worker stays at the point.
        $this->assertWorkerWaits($worker, static fn (): bool => DB::table('jobs')->where('id', $id)->whereNotNull('reserved_at')->count() === 1, 'the job stays reserved');
        self::assertFalse($barrier->arrived('process.hold.release'));

        $barrier->arrive('process.hold.release');

        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->count(), 'the job did not complete after the release. '.$worker->describe());
        self::assertSame(0, DB::table('failed_jobs')->count(), $worker->describe());
    }

    #[Group('shared-database')]
    public function test_a_launched_worker_runs_nothing_before_its_identity_is_accepted(): void
    {
        $env = $this->sharedEnvironment();
        $id = Queue::push(new BasicJob);

        $worker = WorkerProcess::launch(self::WORK_ONCE, $env);
        self::assertStringStartsWith('ORRERY-IDENTITY ', $worker->identityLine());

        // The worker waits at its identity gate: it printed only its identity.
        $this->assertWorkerWaits($worker, static fn (): bool => DB::table('jobs')->where('id', $id)->whereNull('reserved_at')->count() === 1, 'the job stays unreserved');
        self::assertSame($worker->identityLine(), trim($worker->output()), 'the worker printed more than its identity line');

        $worker->accept($env);

        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->count(), $worker->describe());
    }

    public function test_await_fails_after_its_deadline_when_the_marker_never_arrives(): void
    {
        $barrier = Barrier::forTest();
        $started = microtime(true);

        try {
            $barrier->await('never', 1);
            self::fail('await() returned, but nothing arrived at "never"');
        } catch (WaitTimeout $timeout) {
            $elapsed = microtime(true) - $started;
            self::assertGreaterThanOrEqual(1.0, $elapsed);
            self::assertLessThan(5.0, $elapsed);
            self::assertStringContainsString("'never'", $timeout->getMessage());
            self::assertStringContainsString($barrier->directory(), $timeout->getMessage());
            self::assertStringContainsString('within 1 seconds', $timeout->getMessage());
        }

        // An abandon reason ends the wait before the deadline.
        $started = microtime(true);

        try {
            $barrier->await('never', 30, static fn (): string => 'the other process is gone');
            self::fail('await() returned, but nothing arrived at "never"');
        } catch (WaitTimeout $timeout) {
            self::assertLessThan(5.0, microtime(true) - $started);
            self::assertStringContainsString('the other process is gone', $timeout->getMessage());
        }

        // A marker that exists ends the wait at once.
        $barrier->arrive('now');
        $started = microtime(true);
        $barrier->await('now', 30);
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertTrue($barrier->arrived('now'));
        self::assertFalse($barrier->arrived('never'));
    }

    #[Group('shared-database')]
    public function test_a_worker_gets_the_intended_configuration_from_a_poisoned_parent(): void
    {
        $env = $this->sharedEnvironment();
        $id = Queue::push(new BasicJob);

        // Every harness and application variable of this process is wrong
        // while the worker starts. $env was read before.
        $this->setVariables(self::poisonFor($env));

        try {
            $worker = WorkerProcess::start(self::WORK_ONCE, $env);
        } finally {
            $this->restoreVariables();
        }

        $child = $worker->identity();
        $child->assertMatches($env);
        self::assertSame($env->driver, $child->get('primary.driver'));
        self::assertSame($env->database, $child->get('primary.database'));
        self::assertSame($env->mirrorDatabase(), $child->get('mirror.database'));
        self::assertSame($env->mirrorPrefix(), $child->get('mirror.prefix'));

        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->where('id', $id)->count(), $worker->describe());
    }

    #[Group('shared-database')]
    public function test_a_wrong_php_version_or_prefix_stops_the_worker_before_it_reserves_a_job(): void
    {
        $env = $this->sharedEnvironment();
        $id = Queue::push(new BasicJob);

        // A worker on another PHP version refuses its own identity (exit 78).
        $refusal = $this->startRefusal($env, ['ORRERY_EXPECT_PHP' => '7.4']);
        self::assertStringContainsString('php: expected 7.4 (ORRERY_EXPECT_PHP)', $refusal);
        self::assertStringContainsString('(exit 78)', $refusal);
        $this->assertNotReserved($id);

        // A wrong prefix is consistent inside the worker, so only this
        // process's check finds it. The identity gate never opens.
        $wrong = $env->prefix === 'wrong_' ? 'other_' : 'wrong_';
        $refusal = $this->startRefusal($env, ['ORRERY_TEST_PREFIX' => $wrong]);
        self::assertStringContainsString('mirror.prefix: expected "'.$env->mirrorPrefix().'", got "'.$wrong.'"', $refusal);
        self::assertNotNull(WorkerProcess::last());
        self::assertTrue(WorkerProcess::last()->startGateOpened());
        self::assertFalse(WorkerProcess::last()->isRunning());
        self::assertSame(1, substr_count(WorkerProcess::last()->output(), 'ORRERY-IDENTITY '), 'the worker printed more than its identity line');
        $this->assertNotReserved($id);
    }

    #[Group('shared-database')]
    public function test_a_worker_is_recorded_in_its_run_before_its_start_gate_opens(): void
    {
        $env = $this->sharedEnvironment();

        if ($env->isServer() && ! $env->ci) {
            $worker = WorkerProcess::start(['queue:work', 'database', '--stop-when-empty', '--sleep=0'], $env);
            $status = self::registryStatus();
            $children = $status['leases'][$env->runId]['children'] ?? [];
            $entries = array_values(array_filter($children, static fn (array $child): bool => ($child['pid'] ?? null) === $worker->pid()));

            self::assertCount(1, $entries, 'the lease of '.$env->runId.' does not list the worker: '.json_encode($children));
            self::assertSame($worker->startTime(), $entries[0]['start']);
            self::assertFalse($entries[0]['group'] ?? false);
            self::assertTrue($worker->startGateOpened());
            self::assertSame(0, $worker->waitForExit(60), $worker->describe());

            return;
        }

        // Without a run (SQLite, CI) there is no registry: a registry
        // variable that would fail any registry call changes nothing. (A
        // registry call would create the directory; tearDown removes it.)
        $registry = $this->temporaryDirectories[] = (string) realpath(sys_get_temp_dir()).'/orrery-no-registry-'.bin2hex(random_bytes(8));
        $worker = WorkerProcess::start(['queue:work', 'database', '--stop-when-empty', '--sleep=0'], $env, [
            'ORRERY_REGISTRY_TEST' => '1',
            'ORRERY_REGISTRY_DIR' => $registry,
            'ORRERY_OPS' => 'fake',
        ]);

        self::assertTrue($worker->startGateOpened());
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertFileDoesNotExist($registry);
    }

    public function test_a_worker_whose_registration_fails_never_passes_its_start_gate(): void
    {
        // A run id without a lease, in an empty test registry: add-child
        // fails (66). The configuration names a server that the worker never
        // reaches, because it stops before it loads any code.
        $registry = $this->temporaryDirectory('orrery-registry-');
        $env = TestEnvironment::fromArray([
            'ORRERY_TEST_DRIVER' => 'mysql',
            'ORRERY_TEST_DATABASE' => 'orrery_unregistered',
            'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
            'ORRERY_TEST_RUN_ID' => 'run_1_'.bin2hex(random_bytes(4)),
            'ORRERY_TEST_MYSQL_PORT' => '1024',
        ]);

        $refusal = $this->startRefusal($env, [
            'ORRERY_REGISTRY_TEST' => '1',
            'ORRERY_REGISTRY_DIR' => $registry,
            'ORRERY_OPS' => 'fake',
        ]);

        self::assertStringContainsString('add-child '.$env->runId, $refusal);
        self::assertStringContainsString('(exit 66)', $refusal);

        $worker = WorkerProcess::last();
        self::assertNotNull($worker);
        self::assertFalse($worker->startGateOpened());
        self::assertFalse($worker->isRunning());
        self::assertSame('', $worker->output(), 'the worker ran before its start gate opened');
        self::assertSame('', $worker->errorOutput(), 'the worker ran before its start gate opened');
        self::assertNotNull($worker->startTime());
        self::assertNotSame($worker->startTime(), WorkerProcess::processStart($worker->pid()), 'the worker still runs');
    }

    public function test_exactly_the_tests_that_need_a_shared_database_are_in_the_group_shared_database(): void
    {
        // The known-failure check excuses an in-memory skip only for the group
        // shared-database, so the group must equal the set of tests that call
        // sharedEnvironment(), the one place that skips.
        $needle = '$this->'.'sharedEnvironment()';
        $class = new \ReflectionClass(self::class);
        $lines = file((string) $class->getFileName()) ?: [];
        $tests = [];
        $calling = [];
        $grouped = [];

        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== self::class || ! str_starts_with($method->getName(), 'test_')) {
                continue;
            }

            $tests[] = $method->getName();
            $body = implode('', array_slice($lines, (int) $method->getStartLine(), (int) $method->getEndLine() - (int) $method->getStartLine()));

            if (str_contains($body, $needle)) {
                $calling[] = $method->getName();
            }

            foreach ($method->getAttributes(Group::class) as $attribute) {
                if ($attribute->newInstance()->name() === 'shared-database') {
                    $grouped[] = $method->getName();
                }
            }
        }

        sort($calling);
        sort($grouped);

        self::assertNotSame([], $calling);
        self::assertSame($calling, $grouped, 'the group shared-database differs from the tests that call sharedEnvironment()');

        // The tests outside the group run everywhere, in-memory SQLite too.
        self::assertContains(__FUNCTION__, array_diff($tests, $grouped));
        self::assertContains('test_await_fails_after_its_deadline_when_the_marker_never_arrives', array_diff($tests, $grouped));
    }

    public function test_the_child_environment_unsets_inherited_database_variables_and_lets_extra_values_win(): void
    {
        $env = TestEnvironment::fromArray([
            'ORRERY_TEST_DRIVER' => 'pgsql',
            'ORRERY_TEST_DATABASE' => 'orrery_handoff',
            'ORRERY_TEST_TOKEN' => str_repeat('b', 32),
            'ORRERY_TEST_CI' => '1',
            'ORRERY_TEST_MIRROR' => 'separate',
        ]);
        $this->setVariables([
            'DB_URL' => 'mysql://intruder@127.0.0.1:1/production',
            'DATABASE_URL' => 'mysql://intruder@127.0.0.1:1/production',
            'DB_HOST' => '127.0.0.1',
            'ORRERY_TEST_BARRIER_DIR' => '/nonexistent',
            'ORRERY_TEST_PREFIX' => 'poison_',
            'ORRERY_IDENTITY_LOG' => '/nonexistent/identity.log',
        ]);

        $child = WorkerProcess::environmentFor($env, ['ORRERY_TEST_HOLD_AT' => 'a.point', 'ORRERY_TEST_MIRROR' => 'same', 'EXTRA' => 'x']);

        foreach (['DB_URL', 'DATABASE_URL', 'DB_HOST', 'ORRERY_TEST_BARRIER_DIR', 'ORRERY_IDENTITY_LOG', 'ORRERY_TEST_PREFIX'] as $name) {
            self::assertArrayHasKey($name, $child);
            self::assertFalse($child[$name], "{$name} reaches the child");
        }

        foreach ($env->toProcessEnv() as $name => $value) {
            self::assertArrayHasKey($name, $child);

            if ($name !== 'ORRERY_TEST_MIRROR') {
                self::assertSame($value, $child[$name], "{$name} is not the handoff value");
            }
        }

        // The extra values win, also over the handoff.
        self::assertSame('same', $child['ORRERY_TEST_MIRROR']);
        self::assertSame('a.point', $child['ORRERY_TEST_HOLD_AT']);
        self::assertSame('x', $child['EXTRA']);
        self::assertFalse($child[WorkerProcess::START_GATE]);
        self::assertFalse($child[WorkerProcess::IDENTITY_GATE]);

        foreach ([WorkerProcess::START_GATE, WorkerProcess::IDENTITY_GATE, 'ORRERY_START_GATE_TIMEOUT'] as $gate) {
            try {
                WorkerProcess::environmentFor($env, [$gate => '/tmp/open']);
                self::fail("environmentFor() accepted {$gate}");
            } catch (LogicException $exception) {
                self::assertStringContainsString($gate, $exception->getMessage());
            }
        }
    }

    /**
     * The environment of this run, or a skip on in-memory SQLite.
     */
    private function sharedEnvironment(): TestEnvironment
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share. Run the process tests through bin/test sqlite --sqlite-file, bin/test mysql or bin/test pgsql.');
        }

        return $env;
    }

    /**
     * Asserts that a waiting worker stays where it is for 0.3 seconds: it
     * runs, and $unchanged stays true. The window orders nothing (the gates
     * and markers do). It catches a worker that does not wait at all.
     *
     * @param  callable(): bool  $unchanged
     */
    private function assertWorkerWaits(WorkerProcess $worker, callable $unchanged, string $state): void
    {
        $until = microtime(true) + 0.3;
        $moved = null;

        while ($moved === null && microtime(true) < $until) {
            if (! $worker->isRunning()) {
                $moved = 'the worker exited';
            } elseif (! $unchanged()) {
                $moved = 'the worker moved on';
            }

            usleep(20_000);
        }

        self::assertNull($moved, "Expected that {$state} while the worker waits, but {$moved}. ".$worker->describe());
    }

    /**
     * An alias of a command outside the allowlist, read from this
     * application's Artisan, which has the same framework commands as the
     * worker's. The control runs it with --help, so it would only print help
     * if the guard let it through.
     *
     * The first alias of HARMLESS_ALIASES that this framework registers is
     * used. queue:table (make:queue-table) exists from Laravel 12.0.1 through
     * Laravel 13; the others are the fallbacks.
     *
     * @return array{0: string, 1: string} the alias and its canonical name
     */
    private function harmlessAlias(): array
    {
        $aliases = [];

        foreach (Artisan::all() as $command) {
            foreach ($command->getAliases() as $alias) {
                $aliases[$alias] = (string) $command->getName();
            }
        }

        foreach (self::HARMLESS_ALIASES as $alias) {
            if (isset($aliases[$alias])) {
                return [$alias, $aliases[$alias]];
            }
        }

        self::fail('This framework version registers none of the aliases '.implode(', ', self::HARMLESS_ALIASES).'. It has: '.json_encode($aliases));
    }

    /**
     * The message of the HarnessRefusal of a worker that must not start.
     *
     * @param  array<string, string|false>  $extraEnv
     * @param  list<string>  $arguments
     */
    private function startRefusal(TestEnvironment $env, array $extraEnv, array $arguments = self::WORK_ONCE): string
    {
        try {
            $worker = WorkerProcess::start($arguments, $env, $extraEnv);
        } catch (HarnessRefusal $refusal) {
            return $refusal->getMessage();
        }

        self::fail('The worker started. '.$worker->describe());
    }

    private function assertNotReserved(mixed $id): void
    {
        $job = DB::table('jobs')->where('id', $id)->first();
        self::assertNotNull($job, 'the job is gone');
        self::assertNull($job->reserved_at, 'the job was reserved');
        self::assertSame(0, (int) $job->attempts, 'the job was attempted');
    }

    private function uuidOf(mixed $id): string
    {
        $payload = json_decode((string) DB::table('jobs')->where('id', $id)->value('payload'), true);

        return (string) ($payload['uuid'] ?? '');
    }

    /**
     * The database of a live server session, or null when there is none.
     */
    private function serverSessionDatabase(TestEnvironment $env, int $session): ?string
    {
        $sql = $env->isMysqlFamily()
            ? 'SELECT DB AS name FROM information_schema.PROCESSLIST WHERE ID = ?'
            : 'SELECT datname AS name FROM pg_stat_activity WHERE pid = ?';
        $row = DB::connection(TestEnvironment::PRIMARY)->selectOne($sql, [$session]);

        return $row === null ? null : (string) $row->name;
    }

    /**
     * @return array<string, mixed>
     */
    private static function registryStatus(): array
    {
        $script = dirname(__DIR__, 2).'/bin/lib/testdb.php';
        $output = [];
        exec(escapeshellarg(PHP_BINARY).' -n '.escapeshellarg($script).' status --no-services 2>/dev/null', $output, $exit);
        $status = json_decode(implode("\n", $output), true);
        self::assertSame(0, $exit, 'testdb.php status failed');
        self::assertIsArray($status);

        return $status;
    }

    /**
     * Wrong values for every variable that could configure a worker.
     *
     * @return array<string, string>
     */
    private static function poisonFor(TestEnvironment $env): array
    {
        return [
            'ORRERY_TEST_DRIVER' => $env->isMysqlFamily() ? 'pgsql' : 'mysql',
            'ORRERY_TEST_DATABASE' => 'orrery_poison',
            'ORRERY_TEST_SQLITE_FILE' => '/nonexistent/orrery-sqlite-poison/primary.sqlite',
            'ORRERY_TEST_MIRROR' => $env->separate() ? 'same' : 'separate',
            'ORRERY_TEST_PREFIX' => $env->prefix === 'poison_' ? 'venom_' : 'poison_',
            'ORRERY_TEST_MYSQL_PORT' => '1024',
            'ORRERY_TEST_PGSQL_PORT' => '1024',
            'ORRERY_TEST_MARIADB_PORT' => '1024',
            'ORRERY_TEST_PASSWORD' => 'poison',
            'ORRERY_TEST_TOKEN' => str_repeat('0', 32),
            'ORRERY_TEST_RUN_ID' => 'run_1_00000000',
            'ORRERY_EXPECT_DRIVER' => $env->isMysqlFamily() ? 'pgsql' : 'mysql',
            'ORRERY_TEST_BARRIER_DIR' => '/nonexistent/orrery-barrier-0000000000000000',
            'ORRERY_TEST_HOLD_AT' => 'poison.point',
            'DB_URL' => 'mysql://intruder:secret@127.0.0.1:1/production',
            'DATABASE_URL' => 'pgsql://intruder:secret@127.0.0.1:1/production',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_DATABASE' => 'production',
            'DB_USERNAME' => 'intruder',
            'DB_PASSWORD' => 'secret',
        ];
    }

    private function temporaryDirectory(string $prefix): string
    {
        $directory = (string) realpath(sys_get_temp_dir()).'/'.$prefix.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        return $this->temporaryDirectories[] = $directory;
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) && ! is_link($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }

    /**
     * Sets process variables everywhere PHP and Laravel read them, and
     * remembers the old values for restoreVariables().
     *
     * @param  array<string, string>  $variables
     */
    private function setVariables(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $this->savedVariables[$name] ??= [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function restoreVariables(): void
    {
        foreach ($this->savedVariables as $name => [$env, $superEnv, $server]) {
            putenv($env === false ? $name : "{$name}={$env}");

            if ($superEnv === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $superEnv;
            }

            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }

        $this->savedVariables = [];
    }
}
