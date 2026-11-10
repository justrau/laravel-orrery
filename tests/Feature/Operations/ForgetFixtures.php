<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\OperationResolver;
use Justrau\Orrery\Retry\OperationRunner;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Failed\CapturingFailer;
use Justrau\Orrery\Tests\Feature\Failed\FailedProviderFixtures;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestClock;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use RuntimeException;
use stdClass;

/**
 * The fixtures of the forget tests: the package's
 * CapturingUuidFailedJobProvider bound through the verifying extender, the
 * logger of WorkCommand, the fence table in the failed-record database, and
 * a recorder that checks every delete on the failed-job table.
 *
 * Until the install puts the fence on the queue database, a test whose mirror is
 * not in that database (a SQLite file, the separate mirror) creates the
 * table there; the class carries #[FreshSchema] for that DDL.
 */
trait ForgetFixtures
{
    use CapturingFailer;
    use FailedProviderFixtures;

    private bool $createdRecordFence = false;

    /** @var list<array{sql: string, bindings: array<mixed>}> the deletes on the failed-job table */
    private array $failedDeletes = [];

    protected function setUpForgets(): void
    {
        $this->ensureRecordFence();
        $this->useCapturingFailer();
        $this->logFailures();
        $this->recordFailedDeletes();
    }

    protected function ensureRecordFence(string $connection = TestEnvironment::PRIMARY): void
    {
        $schema = $this->app['db']->connection($connection)->getSchemaBuilder();

        if ($schema->hasTable(Fence::TABLE)) {
            return;
        }

        PackageSchema::create($schema, [Fence::TABLE]);
        $this->createdRecordFence = true;
        $this->beforeApplicationDestroyed(function () use ($connection): void {
            if ($this->createdRecordFence) {
                $this->app['db']->connection($connection)->getSchemaBuilder()->dropIfExists(Fence::TABLE);
            }
        });
    }

    /**
     * Every statement on the package session of the failed-record database,
     * and of the application's connection on in-memory SQLite, that deletes
     * from the failed-job table (test_every_failed_record_delete_names_id_and_uuid).
     */
    protected function recordFailedDeletes(string $connection = TestEnvironment::PRIMARY, string $table = 'failed_jobs'): void
    {
        PackageStatements::beforeEach($this->app, $connection, function (string $sql, array $bindings) use ($table): void {
            $lower = strtolower($sql);

            if (str_starts_with(ltrim($lower), 'delete') && str_contains($lower, $table)) {
                $this->failedDeletes[] = ['sql' => $sql, 'bindings' => $bindings];
            }
        });
    }

    /**
     * Switches to the null failer, and unbinds the rows of $uuids as a
     * failure logged under the null failer leaves them (no failed record is
     * bound: provider_id and provider_target null). A bound row under the
     * null failer is refused with target_changed; the records in
     * failed_jobs stay, so a test still proves that the null failer never
     * touches them.
     */
    protected function useNullFailerFor(string ...$uuids): void
    {
        $this->app['config']->set('queue.failed', ['driver' => 'null']);
        $this->app->forgetInstance('queue.failer');
        $this->mirror()->table('orrery_jobs')->whereIn('uuid', $uuids)->update(['provider_id' => null, 'provider_target' => null]);
    }

    /**
     * Asserts that each recorded delete on the failed-job table names exactly
     * `id` and `uuid`, and returns how many ran.
     */
    protected function assertFailedDeletesNameIdAndUuid(): int
    {
        foreach ($this->failedDeletes as $delete) {
            $sql = strtolower(str_replace(['"', '`'], '', $delete['sql']));
            self::assertMatchesRegularExpression('/^delete from [a-z0-9_.]*failed[a-z0-9_]* where id = \? and uuid = \?$/', $sql, 'Every failed-record delete names id and uuid.');
            self::assertCount(2, $delete['bindings']);
            self::assertIsInt($delete['bindings'][0] + 0);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $delete['bindings'][1]);
        }

        return count($this->failedDeletes);
    }

    /**
     * A job that failed once through the worker: its uuid, its occurrence
     * and the id of its failed record (bound to the row).
     *
     * @return array{0: string, 1: int, 2: int}
     */
    protected function failedOccurrence(string $queue = 'default'): array
    {
        $before = $this->mirror()->table('orrery_jobs')->max('id');
        Queue::push(new FailingJob, '', $queue);
        $this->work(1, $queue);

        $row = $this->mirror()->table('orrery_jobs')->where('id', '>', (int) $before)->orderByDesc('id')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('failed', $row->status);
        self::assertNotNull($row->provider_id, 'The failure bound its record.');

        return [(string) $row->uuid, (int) $row->occurrence_id, (int) $row->provider_id];
    }

    /** The failed-record connection of the drift cases: a copy of testbench. */
    protected const DRIFT_CONNECTION = 'failed_target';

    /** @var array{0: string, 1: int, 2: int, 3: string, 4: string} uuid, occurrence, record id, connection, table of the planted record */
    protected array $drifted;

    /**
     * The failure of a drift case, logged to the target that the case then
     * changes; skips the cases that the engine cannot run.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    protected function failedOccurrenceFor(string $change): array
    {
        $env = $this->testEnvironment();

        if ($env->inMemory() && ! in_array($change, ['table', 'cached', 'failed_table'], true)) {
            self::markTestSkipped("The {$change} drift needs a second connection to the same database; in-memory SQLite gives every connection its own database.");
        }

        if (in_array($change, ['schema', 'connect_via'], true) && $env->driver !== 'pgsql') {
            self::markTestSkipped("The {$change} drift is a Postgres setting; this run uses {$env->driver}.");
        }

        if (! $env->inMemory() && ! in_array($change, ['table', 'failed_table'], true)) {
            $config = $this->app['config'];
            $config->set('database.connections.'.self::DRIFT_CONNECTION, $config->get('database.connections.'.TestEnvironment::PRIMARY));
            $this->useCapturingFailer('failed_jobs', self::DRIFT_CONNECTION);
        }

        return $this->failedOccurrence();
    }

    /**
     * Changes the failed-record target of a case and plants a record with
     * the same id and uuid there.
     */
    protected function plantDriftedTarget(string $change, string $uuid, int $occurrence, int $recordId): void
    {
        $record = (array) DB::table('failed_jobs')->where('id', $recordId)->sole();
        $config = $this->app['config'];
        $env = $this->testEnvironment();

        if ($change === 'failed_table') {
            // Only queue.failed.table changes (the failer instance stays): the claimed operation must wait.
            $this->createFailedTable('planted_failed', true);
            DB::table('planted_failed')->insert($record);
            $config->set('queue.failed.table', 'planted_failed');
            $this->restoreDrift = static fn () => $config->set('queue.failed.table', 'failed_jobs');
            $this->drifted = [$uuid, $occurrence, $recordId, TestEnvironment::PRIMARY, 'planted_failed'];

            return;
        }

        if ($change === 'table') {
            $this->createFailedTable('planted_failed', true);
            DB::table('planted_failed')->insert($record);
            $this->useCapturingFailer('planted_failed');
            $this->drifted = [$uuid, $occurrence, $recordId, TestEnvironment::PRIMARY, 'planted_failed'];

            return;
        }

        $name = $env->inMemory() ? TestEnvironment::PRIMARY : self::DRIFT_CONNECTION;
        $key = "database.connections.{$name}";
        $original = (array) $config->get($key);
        $this->restoreDrift = static function () use ($config, $key, $original, $name, $env): void {
            $config->set($key, $original);

            if (! $env->inMemory()) {
                DB::purge($name);
            }
        };
        $this->beforeApplicationDestroyed(fn () => $this->restoreDriftedTarget());

        if ($change === 'prefix' || $change === 'cached') {
            // The new prefix names a table that holds the planted record.
            $this->createFailedTable('planted_failed_jobs', true);
            DB::table('planted_failed_jobs')->insert($record);
            $config->set("{$key}.prefix", ((string) ($original['prefix'] ?? '')).'planted_');

            if ($change === 'prefix') {
                DB::purge($name);
            }

            $this->drifted = [$uuid, $occurrence, $recordId, TestEnvironment::PRIMARY, 'planted_failed_jobs'];

            return;
        }

        if ($change === 'database' || $change === 'connect_via') {
            // bin/test creates <base>_mirror on a server in both mirror modes. On a SQLite file the
            // target moves to a new file of its own: in separate mode <file>.mirror is the file of
            // orrery.database, and the package refuses a mirror on a used connection's file (D17).
            $other = $env->isServer() ? $env->database.'_mirror' : $this->newDriftFile();
            $config->set($change === 'database' ? "{$key}.database" : "{$key}.connect_via_database", $other);
            DB::purge($name);
        } else {
            DB::connection(TestEnvironment::PRIMARY)->statement('create schema if not exists orrery_drift');
            $this->beforeApplicationDestroyed(static fn () => DB::connection(TestEnvironment::PRIMARY)->statement('drop schema if exists orrery_drift cascade'));
            $config->set("{$key}.search_path", 'orrery_drift');
            DB::purge($name);
        }

        $this->createFailedTable('failed_jobs', true, $name);
        DB::connection($name)->table('failed_jobs')->insert($record);
        $this->drifted = [$uuid, $occurrence, $recordId, $name, 'failed_jobs'];

        if ($env->isServer() && ($change === 'database' || $change === 'connect_via')) {
            // The planted table is in <base>_mirror, a test database: drop it
            // through the drifted configuration (the test restores $name
            // before its teardown), else the fresh guard finds it.
            $cleanup = self::DRIFT_CONNECTION.'_cleanup';
            $config->set("database.connections.{$cleanup}", $config->get($key));
            $this->beforeApplicationDestroyed(static function () use ($cleanup): void {
                DB::connection($cleanup)->getSchemaBuilder()->dropIfExists('failed_jobs');
                DB::purge($cleanup);
            });
        }
    }

    /**
     * A new SQLite file for the `database` drift, removed when the test's
     * application ends (after every session on it is released).
     */
    private function newDriftFile(): string
    {
        $file = SqliteFiles::create();
        $this->beforeApplicationDestroyed(function () use ($file): void {
            PackageSession::releaseAll();
            DB::purge(self::DRIFT_CONNECTION);
            SqliteFiles::remove($file);
        });

        return $file;
    }

    /** @var (\Closure(): void)|null */
    private ?\Closure $restoreDrift = null;

    /**
     * Restores the configuration that plantDriftedTarget() changed.
     */
    protected function restoreDriftedTarget(): void
    {
        if ($this->restoreDrift !== null) {
            ($this->restoreDrift)();
            $this->restoreDrift = null;
        }
    }

    protected function assertPlantedRecordStays(): void
    {
        [$uuid, , $recordId, $connection, $table] = $this->drifted;
        self::assertSame(1, DB::connection($connection)->table($table)->where('id', $recordId)->where('uuid', $uuid)->count(), 'The planted record stays.');
    }

    protected function work(int $times = 1, string $queue = 'default'): void
    {
        $worker = $this->app->make('queue.worker');

        for ($i = 0; $i < $times; $i++) {
            $worker->runNextJob('database', $queue, $this->workerOptions());
        }
    }

    protected function record(int $id, string $uuid, string $connection = TestEnvironment::PRIMARY, string $table = 'failed_jobs'): ?stdClass
    {
        $row = DB::connection($connection)->table($table)->where('id', $id)->where('uuid', $uuid)->first();

        return $row instanceof stdClass ? $row : null;
    }

    protected function row(string $uuid): ?stdClass
    {
        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The only operation of an occurrence.
     */
    protected function operationOf(string $uuid): stdClass
    {
        $row = $this->mirror()->table(Operations::TABLE)->where('job_uuid', $uuid)->sole();
        self::assertInstanceOf(stdClass::class, $row);

        return $row;
    }

    /**
     * The operator's resolve of one operation (OperationResolver, the path of
     * the resolve route and orrery:resolve): the step's kind and code.
     *
     * @return array{step: string|null, code: string|null}
     */
    protected function resolveOperation(string $id): array
    {
        $result = $this->app->make(OperationResolver::class)->resolve($id);

        return ['step' => $result['step'] ?? null, 'code' => $result['code'] ?? null];
    }

    protected function forgets(): ForgetService
    {
        return $this->app->make(ForgetService::class);
    }

    protected function forgetAll(): ForgetAllRecords
    {
        return $this->app->make(ForgetAllRecords::class);
    }

    /**
     * @return array{inspected: int, decided: int, deferred: string|null, stopped: string}
     */
    protected function runOperations(): array
    {
        return $this->app->make(OperationRunner::class)->runOnce(new TickBudget(Clock::monotonic() + 30));
    }

    protected function mirrorNow(): float
    {
        return DatabaseClock::now($this->app->make(PackageSession::class)->mirror());
    }

    /**
     * Moves every database clock (the mirror's and the failed-record database's) by $seconds.
     */
    protected function shiftClocks(float $seconds): void
    {
        TestClock::shift(TestClock::EVERY, $seconds);
    }

    /**
     * Makes an operation due now, in the mirror's clock.
     */
    protected function makeDue(string $operationId): void
    {
        $this->mirror()->table(Operations::TABLE)->where('id', $operationId)->update(['next_check_at' => Clock::toDecimal($this->mirrorNow() - 1)]);
    }

    /**
     * Whether the failed-record database is a host SQLite file, where the
     * runner waits for an operator before a write (D17).
     */
    protected function recordsOnHostFile(): bool
    {
        return $this->app->make(HostFiles::class)->isHost(TestEnvironment::PRIMARY);
    }

    protected function primary(): Connection
    {
        return DB::connection(TestEnvironment::PRIMARY);
    }

    /**
     * Throws at a hook point of this process: the in-process stand-in for a
     * crash at that point.
     */
    protected function crashAt(string $point): void
    {
        Hooks::$callback = static function (string $reached) use ($point): void {
            if ($reached === $point) {
                throw new RuntimeException("crash at {$point}");
            }
        };
    }
}
