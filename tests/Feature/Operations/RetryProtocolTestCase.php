<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\OperationRunner;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Failed\CapturingFailer;
use Justrau\Orrery\Tests\Feature\Hooks\HooksTestCase;
use Justrau\Orrery\Tests\Feature\Operations\Fixtures\OperationCommandsProvider;
use Justrau\Orrery\Tests\Feature\Retry\Jobs\RetryableJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestClock;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use LogicException;
use stdClass;

/**
 * The fixtures of the new retry protocol: a real failure
 * logged by the package's CapturingUuidFailedJobProvider (the package's
 * verifying extender binds it), the fence table on the queue's database, the service, the
 * runner, the operator's resolve, and readers for the ledger, the fence, the
 * queue and the failed records.
 *
 * Every test migrates fresh: a test creates the fence table on the queue's
 * database when the mirror is elsewhere (the sibling SQLite file, or the
 * separate mirror), as orrery:install --fence does in production.
 *
 * On a SQLite file the queue's file is a host file (D17): the runner writes
 * nothing there, so a test that needs such a write asserts the awaiting
 * state first, then resolves as an operator (resolveAsOperator()).
 */
#[FreshSchema]
abstract class RetryProtocolTestCase extends HooksTestCase
{
    use CapturingFailer;

    /** A copy of testbench: a second connection name on the same database (mode before). */
    public const FAILED_CONNECTION = 'testbench_failed';

    /** @var list<string> the connections where this test created the fence table */
    private array $createdFences = [];

    private ?Barrier $files = null;

    private ?Barrier $holdBarrier = null;

    private int $jobs = 0;

    /** @var array<string, true> the connection names whose clock a test moved by name */
    private array $shifted = [];

    /** @var list<string> the hold points that throw in this process */
    private array $kills = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFence(TestEnvironment::PRIMARY);
        $this->useCapturingFailer();
        // The clock file exists from the start, so every worker that the test starts reads it.
        TestClock::shift(TestClock::EVERY, 0.0);

        $this->beforeApplicationDestroyed(function (): void {
            Hooks::$callback = null;

            foreach ($this->createdFences as $connection) {
                $this->app['db']->connection($connection)->getSchemaBuilder()->dropIfExists(Fence::TABLE);
            }

            $this->createdFences = [];
        });
    }

    protected function retries(): RetryService
    {
        return $this->app->make(RetryService::class);
    }

    protected function retry(string $uuid, int $occurrence, string $source = 'cli'): RetryResult
    {
        return $this->retries()->retry($uuid, $occurrence, $source, 'tester');
    }

    protected function runner(): OperationRunner
    {
        return $this->app->make(OperationRunner::class);
    }

    /**
     * @return array{inspected: int, decided: int, deferred: string|null, stopped: string}
     */
    protected function runOnce(): array
    {
        return $this->runner()->runOnce(new TickBudget(Clock::monotonic() + 30));
    }

    /**
     * One operator resolve, through the route ($route) or orrery:resolve.
     *
     * @return array<string, mixed>
     */
    protected function resolveAsOperator(string $id, bool $route = true): array
    {
        if (! $route) {
            Artisan::call('orrery:resolve', ['id' => $id]);
            $operation = $this->operation($id);

            return ['state' => $operation->state, 'error_code' => $operation->error_code, 'awaiting_reason' => $operation->awaiting_reason];
        }

        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
        $response = $this->postJson('/orrery/api/operations/'.$id.'/resolve');
        self::assertContains($response->status(), [200, 409, 503], (string) $response->getContent());

        return (array) $response->json('operation');
    }

    /**
     * Resolves through the operator on a host SQLite file, after asserting
     * that the runner left the operation awaiting; elsewhere one runner run.
     */
    protected function finishByRunnerOrOperator(string $id, bool $route = true): void
    {
        if ($this->queueIsHostFile()) {
            $this->runOnce();
            $operation = $this->operation($id);
            self::assertNotNull($operation->awaiting_since, 'The runner leaves the operation to the operator on a host SQLite file.');
            self::assertContains($operation->awaiting_reason, [Operations::SQLITE_HOST_FILE, Operations::SQLITE_READS_NOT_ALLOWED]);
            $this->resolveAsOperator($id, $route);

            return;
        }

        $this->runOnce();
    }

    protected function queueIsHostFile(): bool
    {
        return $this->app->make(HostFiles::class)->isHost(TestEnvironment::PRIMARY);
    }

    /**
     * The fence table on a database connection's database, as orrery:install --fence creates it.
     */
    protected function ensureFence(string $connection): void
    {
        $schema = $this->app['db']->connection($connection)->getSchemaBuilder();

        if (! $schema->hasTable(Fence::TABLE)) {
            PackageSchema::create($schema, [Fence::TABLE]);
            $this->createdFences[] = $connection;
        }
    }

    /**
     * The failed-job table on a second connection name of the same database: mode before.
     */
    protected function useSplitFailedConnection(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('Two connection names on one database need a database file or a server: an in-memory copy is another, empty database.');
        }

        $this->app['config']->set('database.connections.'.self::FAILED_CONNECTION, $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY));
        $this->useCapturingFailer('failed_jobs', self::FAILED_CONNECTION);
    }

    /**
     * The listener of WorkCommand::logFailedJob(), for TestCase::work().
     */
    protected function logFailures(): void
    {
        $this->app['events']->listen(JobFailed::class, function (JobFailed $event): void {
            $this->app['queue.failer']->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });
    }

    /**
     * The null failer: no failed record exists.
     */
    protected function useNullFailer(): void
    {
        $this->app['config']->set('queue.failed', ['driver' => 'null']);
        $this->app->forgetInstance('queue.failer');
    }

    protected function files(): Barrier
    {
        return $this->files ??= Barrier::forTest();
    }

    protected function file(string $name): string
    {
        return $this->files()->directory().'/'.$name;
    }

    /**
     * A job that fails at its first run and completes at the next one.
     *
     * @param  list<string>  $tags
     */
    protected function job(array $tags = [], int $failRuns = 1): RetryableJob
    {
        return new RetryableJob(runFile: $this->file('runs-'.(++$this->jobs)), failRuns: $failRuns, jobTags: $tags);
    }

    /**
     * Pushes a job and runs it once through queue:work, which logs the
     * failure through the package's provider: the row is `failed` and bound.
     *
     * @return array{0: string, 1: int} the uuid and the occurrence id
     */
    protected function failedJob(?object $job = null, bool $bound = true): array
    {
        /** @var QueueContract $queue */
        $queue = $this->app['queue']->connection('database');
        $id = $queue->push($job ?? $this->job());
        $database = $this->app['config']->get('queue.connections.database.connection');
        $payload = $this->app['db']->connection(is_string($database) ? $database : TestEnvironment::PRIMARY)->table('jobs')->where('id', $id)->value('payload');
        self::assertIsString($payload);
        $uuid = self::uuidOf($payload);

        $this->workAndLogFailures();

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status, 'The job failed.');
        self::assertNotNull($row->occurrence_id);

        if ($bound) {
            self::assertNotNull($row->provider_id, 'The failure is bound to its record.');
        }

        return [$uuid, (int) $row->occurrence_id];
    }

    protected function operation(string $id): stdClass
    {
        $operation = $this->app->make(Operations::class)->find($this->mirror(), $id);
        self::assertNotNull($operation, "The operation {$id} exists.");

        return $operation;
    }

    /**
     * @return list<stdClass>
     */
    protected function operations(): array
    {
        return array_values($this->mirror()->table(Operations::TABLE)->orderBy('created_at')->get()->all());
    }

    protected function fenceRow(string $operationId, string $slot, string $connection = TestEnvironment::PRIMARY): ?stdClass
    {
        $row = $this->app['db']->connection($connection)->table(Fence::TABLE)->where('operation_id', $operationId)->where('slot', $slot)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The jobs rows of a retry: their payload's orreryGen is the operation id.
     */
    protected function jobsOf(string $operationId): int
    {
        return (int) $this->app['db']->connection(TestEnvironment::PRIMARY)->table('jobs')->where('payload', 'like', '%"orreryGen":"'.$operationId.'"%')->count();
    }

    protected function failedRecords(?string $uuid = null): int
    {
        $query = $this->app['db']->connection(TestEnvironment::PRIMARY)->table('failed_jobs');

        if ($uuid !== null) {
            $query->where('uuid', $uuid);
        }

        return (int) $query->count();
    }

    protected function failedRecordId(string $uuid): ?int
    {
        $id = $this->app['db']->connection(TestEnvironment::PRIMARY)->table('failed_jobs')->where('uuid', $uuid)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<string>
     */
    protected function historyEvents(string $uuid): array
    {
        return $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->orderBy('id')->pluck('event')->map(static fn (mixed $event): string => (string) $event)->all();
    }

    /**
     * @return list<string>
     */
    protected function failedTags(string $uuid): array
    {
        return $this->mirror()->table('orrery_failed_tags')->where('job_uuid', $uuid)->orderBy('tag')->pluck('tag')->map(static fn (mixed $tag): string => (string) $tag)->all();
    }

    protected function mirrorNow(): float
    {
        return DatabaseClock::now($this->app->make(PackageSession::class)->mirror());
    }

    /**
     * Moves every database clock (the queue's, the mirror's, the failed
     * records') by $seconds, in this process and in its workers.
     */
    protected function moveDatabaseClocks(float $seconds): void
    {
        TestClock::shift(TestClock::EVERY, $seconds);

        foreach (array_keys($this->shifted) as $name) {
            TestClock::shift($name, $seconds);
        }
    }

    /**
     * Moves the clock of one connection name (as the package session names it, without its prefix); a later
     * moveDatabaseClocks() moves it with the others again.
     */
    protected function shiftClock(string $name, float $seconds): void
    {
        $this->shifted[$name] = true;
        TestClock::shift($name, $seconds);
    }

    /**
     * The clock name of the queue database's session.
     */
    protected function queueClockName(): string
    {
        $name = (string) $this->app->make(PackageSession::class)->forQueue('database')->getName();

        return str_starts_with($name, PackageSession::NAME_PREFIX) ? substr($name, strlen(PackageSession::NAME_PREFIX)) : $name;
    }

    /**
     * Makes this process stop at a hold point: the point throws (a killed
     * publisher, in one process). The hold points are outside every error
     * handler of the service.
     */
    protected function killAt(string ...$points): void
    {
        $this->kills = array_values(array_unique([...$this->kills, ...$points]));
        $kills = &$this->kills;
        Hooks::$callback = static function (string $point) use (&$kills): void {
            if (in_array($point, $kills, true)) {
                throw new KilledAt($point);
            }
        };
    }

    /**
     * Runs $work and expects it to stop at the killed point.
     */
    protected function expectKilled(callable $work, string $point): void
    {
        try {
            $work();
            self::fail("The work did not stop at {$point}.");
        } catch (KilledAt $killed) {
            self::assertSame($point, $killed->point);
        }

        Hooks::$callback = null;
        $this->kills = [];
    }

    protected function needsSharedDatabase(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that it shares with the test: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }
    }

    /**
     * Starts test:retry-operation in a worker process that holds at $holds.
     *
     * @param  list<string>  $holds
     * @param  array<string, string>  $extraEnv
     */
    protected function startRetry(string $uuid, int $occurrence, array $holds, array $extraEnv = []): WorkerProcess
    {
        $env = ['ORRERY_TEST_PROVIDERS' => OperationCommandsProvider::class] + $extraEnv;

        if ($holds !== []) {
            $env += $this->holds()->holdEnvironment(...$holds);
        }

        return WorkerProcess::start(['test:retry-operation', $uuid, (string) $occurrence, '--source=cli'], $this->testEnvironment(), $env);
    }

    /**
     * Starts an orrery:* command (orrery:tick, orrery:resolve) in a worker process that holds at $holds.
     *
     * @param  list<string>  $arguments
     * @param  list<string>  $holds
     * @param  Barrier|null  $barrier  another barrier than holds(), for a second held process
     * @param  array<string, string>  $extraEnv
     */
    protected function startCommand(array $arguments, array $holds, ?Barrier $barrier = null, array $extraEnv = []): WorkerProcess
    {
        $env = ['ORRERY_TEST_PROVIDERS' => OperationCommandsProvider::class] + $extraEnv;

        if ($holds !== []) {
            $env += ($barrier ?? $this->holds())->holdEnvironment(...$holds);
        }

        return WorkerProcess::start($arguments, $this->testEnvironment(), $env);
    }

    protected function holds(): Barrier
    {
        return $this->holdBarrier ??= Barrier::forTest();
    }

    protected function awaitHold(WorkerProcess $worker, string $point): void
    {
        $this->holds()->await($point.'.reached', 60, $worker->abandonIfExited());
    }

    protected function release(string $point): void
    {
        $this->holds()->arrive($point.'.release');
    }

    /**
     * The outcome line of a finished test:retry-operation process.
     *
     * @return array{result: string, code: string|null, operation_id: string|null, job_id: int|null, cycle: int|null, message: string|null}
     */
    protected function childOutcome(WorkerProcess $worker): array
    {
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        foreach (array_reverse(explode("\n", trim($worker->output()))) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['result'])) {
                /** @var array{result: string, code: string|null, operation_id: string|null, job_id: int|null, cycle: int|null, message: string|null} $decoded */
                return $decoded;
            }
        }

        throw new LogicException('test:retry-operation printed no outcome: '.$worker->describe());
    }

    /**
     * The only operation of the ledger.
     */
    protected function onlyOperation(): stdClass
    {
        $operations = $this->operations();
        self::assertCount(1, $operations, 'Exactly one operation exists.');

        return $operations[0];
    }

    protected static function assertOutcome(string $result, ?string $code, RetryResult $outcome, string $message = ''): void
    {
        self::assertSame([$result, $code], [$outcome->result, $outcome->code], $message === '' ? 'the retry outcome' : $message);
    }
}
