<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Illuminate\Database\Connection;
use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Failed\FailedProviderFixtures;
use Justrau\Orrery\Tests\Feature\Operations\RetryProtocolTestCase;
use Justrau\Orrery\Tests\Feature\Retry\Jobs\RetryableJob;
use Justrau\Orrery\Tests\Support\RecordsFailedRecordDeletes;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use LogicException;
use Throwable;

/**
 * The fixtures of the caller tests of retry and forget (the dashboard
 * routes, the batch retry, the group operations, the commands and
 * reconciliation), on the operation ledger: a real failure
 * that the package's CapturingUuidFailedJobProvider logs and binds, the
 * fence table on the queue's database, and the services
 * (RetryProtocolTestCase).
 *
 * The queue connection `database` and the failer (database-uuids) are on
 * testbench: mode in_tx.
 */
abstract class RetryTestCase extends RetryProtocolTestCase
{
    use FailedProviderFixtures;
    use FenceFixtures;
    use RecordsFailedRecordDeletes;

    /** @var list<Throwable> the errors of log() */
    protected array $logErrors = [];

    private bool $recordingFailures = false;

    /** @var list<\Closure(string): void> */
    private array $hookCallbacks = [];

    private bool $hooksInstalled = false;

    private int $jobCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Every delete on a failed-job table of these tests names id and uuid.
        $this->recordFailedRecordDeletes();
    }

    /**
     * A job that fails at its first $failRuns runs and completes at the
     * next one. While the file throw-in-retry-until exists, its
     * retryUntil() throws (the retry payload preparation fails).
     *
     * @param  list<string>  $tags
     */
    protected function job(array $tags = [], int $failRuns = 1): RetryableJob
    {
        return new RetryableJob(
            runFile: $this->file('retry-runs-'.(++$this->jobCount)),
            failRuns: $failRuns,
            jobTags: $tags,
            throwFile: $this->file('throw-in-retry-until'),
        );
    }

    protected function service(): RetryService
    {
        return $this->app->make(RetryService::class);
    }

    protected function forgetService(): ForgetService
    {
        return $this->app->make(ForgetService::class);
    }

    /**
     * The listener of WorkCommand::logFailedJob(), once per test. An error of
     * log() is recorded in $logErrors, not thrown.
     */
    protected function recordLogFailures(): void
    {
        if ($this->recordingFailures) {
            return;
        }

        $this->recordingFailures = true;

        Event::listen(LaravelJobFailed::class, function (LaravelJobFailed $event): void {
            try {
                $this->app['queue.failer']->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
            } catch (Throwable $error) {
                $this->logErrors[] = $error;
            }
        });
    }

    /**
     * Pushes $job onto the queue connection `database`, and runs it once in
     * this process; the failure is logged and bound.
     *
     * @return array{0: string, 1: int} the uuid and the occurrence id
     */
    protected function failWith(object $job): array
    {
        $this->recordLogFailures();
        $id = $this->app['queue']->connection('database')->push($job);
        $payload = DB::connection(TestEnvironment::PRIMARY)->table('jobs')->where('id', $id)->value('payload');
        self::assertIsString($payload);
        $uuid = self::uuidOf($payload);

        $this->work();

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status, 'The job failed.');

        return [$uuid, (int) $row->occurrence_id];
    }

    /**
     * Runs the next job once and asserts that the mirror row failed again.
     */
    protected function failAgain(string $uuid): int
    {
        $this->recordLogFailures();
        $this->work();
        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status, 'The retried job failed again.');

        return (int) $row->occurrence_id;
    }

    /**
     * Adds a hook callback; the callbacks run in order at every hook point.
     *
     * @param  \Closure(string): void  $callback
     */
    protected function onHook(\Closure $callback): void
    {
        $this->hookCallbacks[] = $callback;

        if ($this->hooksInstalled) {
            return;
        }

        $this->hooksInstalled = true;
        Hooks::$callback = function (string $point): void {
            foreach ($this->hookCallbacks as $callback) {
                $callback($point);
            }
        };
    }

    /**
     * Runs one tick phase as the tick runner does: on the maintenance session.
     */
    protected function runPhase(string $class): void
    {
        /** @var TickPhase $phase */
        $phase = $this->app->make($class);

        $phase->run(new TickBudget(Clock::monotonic() + 30));
    }

    /**
     * Moves the package clock (Clock::now() follows Carbon) by $seconds.
     */
    protected function moveClock(float $seconds): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now->copy()->addMicroseconds((int) round($seconds * 1_000_000)));
    }

    /**
     * The jobs table of a queue connection.
     */
    protected function queueTable(string $queueConnection = 'database'): \Illuminate\Database\Query\Builder
    {
        $config = $this->app['config']->get("queue.connections.{$queueConnection}");

        if (! is_array($config)) {
            throw new LogicException("The queue connection [{$queueConnection}] is not configured.");
        }

        /** @var Connection $connection */
        $connection = $this->app['db']->connection($config['connection'] ?? TestEnvironment::PRIMARY);

        return $connection->table($config['table'] ?? 'jobs');
    }

    /**
     * A consistent copy of a SQLite file, taken now, as a new file of this
     * test (a lagging read replica). The harness files are in WAL mode
     * (D18), so a byte copy of the main file alone can miss committed pages
     * that are still in its -wal file. VACUUM INTO reads through SQLite and
     * writes every committed page.
     */
    protected function sqliteSnapshot(string $live, string $name): string
    {
        $copy = $this->file($name);
        self::assertFileDoesNotExist($copy);
        $pdo = new \PDO('sqlite:'.$live);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('VACUUM INTO '.$pdo->quote($copy));
        $pdo = null;
        self::assertFileExists($copy);

        return $copy;
    }

    protected function providerRecords(string $connection = TestEnvironment::PRIMARY, string $table = 'failed_jobs'): int
    {
        return (int) $this->app['db']->connection($connection)->table($table)->count();
    }

    /**
     * @return list<array<string, mixed>> the cycle summaries of a job, by cycle
     */
    protected function summaries(string $uuid): array
    {
        return $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->orderBy('cycle')->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * The failed record of a uuid on a database connection, or null.
     */
    protected function providerRecord(string $uuid, string $connection = TestEnvironment::PRIMARY, string $table = 'failed_jobs'): ?object
    {
        return $this->app['db']->connection($connection)->table($table)->where('uuid', $uuid)->first();
    }

    /**
     * The id of the failed record of a uuid.
     */
    protected function providerId(string $uuid, string $connection = TestEnvironment::PRIMARY): int
    {
        $id = DB::connection($connection)->table('failed_jobs')->where('uuid', $uuid)->value('id');
        self::assertIsNumeric($id, "the failed record of {$uuid} exists");

        return (int) $id;
    }

    /**
     * The per-job mirror rows of a uuid: tags, failed tags, history and summaries.
     *
     * @return array<string, int>
     */
    protected function jobRows(string $uuid): array
    {
        $counts = [];

        foreach (ForgetService::JOB_TABLES as $table) {
            $counts[$table] = (int) $this->mirror()->table($table)->where('job_uuid', $uuid)->count();
        }

        return $counts;
    }

    /**
     * Removes the mirror row of a uuid with its per-job rows, as retention does.
     */
    protected function trimMirrorRow(string $uuid): void
    {
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->delete();

        foreach (ForgetService::JOB_TABLES as $table) {
            $this->mirror()->table($table)->where('job_uuid', $uuid)->delete();
        }
    }

    /**
     * Removes a queue connection (null) or changes its driver, until the
     * application ends.
     */
    protected function changeQueueDriver(string $queue, ?string $driver): void
    {
        $config = $this->app['config'];
        $original = $config->get('queue.connections');
        $this->beforeApplicationDestroyed(static function () use ($config, $original): void {
            $config->set('queue.connections', $original);
        });

        if ($driver === null) {
            $connections = $config->get('queue.connections');
            unset($connections[$queue]);
            $config->set('queue.connections', $connections);
        } else {
            $config->set("queue.connections.{$queue}.driver", $driver);
        }
    }

    protected static function assertResult(string $result, ?string $code, RetryResult $actual, string $message = ''): void
    {
        self::assertSame([$result, $code], [$actual->result, $actual->code], $message === '' ? 'the retry result' : $message);
    }

    protected static function assertForget(string $result, ?string $code, ForgetResult $actual, string $message = ''): void
    {
        self::assertSame([$result, $code], [$actual->result, $actual->code], $message === '' ? 'the forget result' : $message);
    }
}
