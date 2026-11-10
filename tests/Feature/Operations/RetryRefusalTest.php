<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Failed\CapturingUuidFailedJobProvider;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The refusals of a retry before any write (retry step 1;
 * the parts of the binding, drift and read-only tests). Each refusal
 * claims nothing and deletes nothing. Kind: new behaviour.
 */
final class RetryRefusalTest extends RetryProtocolTestCase
{
    use PooledSchemaDrift;

    /** @var list<string> */
    private array $sqliteFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::releaseAll();

            foreach ($this->sqliteFiles as $file) {
                SqliteFiles::remove($file);
            }
        });
    }

    /**
     * Rejects: a binding guarded by cycle_gen or uuid instead of occurrence_id, and a uuid lookup at the retry: F1's
     * binding failed, a foreign queue:retry republished it (with and without its generation), and a package worker
     * logged R2; the retry of F1 refuses with occurrence_unprovable, and R2 survives.
     */
    #[DataProvider('generations')]
    public function test_binding_names_the_occurrence_of_this_failure(bool $withGeneration): void
    {
        $this->logFailures();
        Queue::push(new FailingJob);
        Watcher::failOperationsForTesting([CapturingUuidFailedJobProvider::OPERATION]);
        $this->work();
        Watcher::failOperationsForTesting([]);

        $uuid = (string) $this->mirror()->table('orrery_jobs')->value('uuid');
        $first = $this->rowOrFail($uuid);
        self::assertNull($first->provider_id);

        $r1 = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        DB::table('failed_jobs')->where('id', $r1->id)->delete();
        $payload = json_decode((string) $r1->payload, true);
        self::assertIsArray($payload);

        if (! $withGeneration) {
            unset($payload['orreryGen']);
        }

        $foreign = new LaravelDatabaseQueue(DB::connection(TestEnvironment::PRIMARY), 'jobs', 'default', 90);
        $foreign->setContainer($this->app);
        $foreign->setConnectionName('database');
        $foreign->pushRaw((string) json_encode($payload), 'default');
        $this->work();

        $r2 = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        self::assertNull($this->rowOrFail($uuid)->provider_id);

        $outcome = $this->retry($uuid, (int) $first->occurrence_id);

        self::assertOutcome(RetryResult::REFUSED, 'occurrence_unprovable', $outcome);
        self::assertSame(RetryService::OCCURRENCE_UNPROVABLE, $outcome->message);
        self::assertSame([], $this->operations());
        self::assertSame([(int) $r2->id], DB::table('failed_jobs')->where('uuid', $uuid)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(), 'R2 survives.');
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function generations(): array
    {
        return ['the same generation' => [true], 'no generation' => [false]];
    }

    /**
     * Rejects: trusting a connection-wide last insert id after another statement: when a host listener replaced R1
     * by R2 during the insert, the retry of F1 never deletes R2 (record_missing where the receipt names R1,
     * occurrence_unprovable where nothing was bound); after an unrelated nested insert the retry deletes only R1.
     */
    #[DataProvider('nestedStatements')]
    public function test_nested_insert_during_log_binds_nothing(string $case): void
    {
        $driver = DB::connection(TestEnvironment::PRIMARY)->getDriverName();
        $this->logFailures();
        $nested = null;

        DB::listen(static function (QueryExecuted $event) use ($case, &$nested): void {
            if ($nested !== null || $event->connectionName !== TestEnvironment::PRIMARY
                || ! str_starts_with(strtolower($event->sql), 'insert') || ! str_contains($event->sql, 'failed_jobs')) {
                return;
            }

            $uuid = $event->bindings[0];
            $nested = 'running';
            $record = ['connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'nested', 'failed_at' => Carbon::now()];

            if ($case === 'replace') {
                $event->connection->table('failed_jobs')->where('uuid', $uuid)->delete();
                $nested = (int) $event->connection->table('failed_jobs')->insertGetId(['uuid' => $uuid] + $record);
            } else {
                $nested = (int) $event->connection->table('failed_jobs')->insertGetId(['uuid' => '11111111-0000-4000-8000-000000000001'] + $record);
            }
        });

        Queue::push(new FailingJob);
        $this->work();
        $uuid = (string) $this->mirror()->table('orrery_jobs')->value('uuid');
        $row = $this->rowOrFail($uuid);
        self::assertIsInt($nested);

        $outcome = $this->retry($uuid, (int) $row->occurrence_id);

        if ($case === 'unrelated') {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                self::assertOutcome(RetryResult::REFUSED, 'occurrence_unprovable', $outcome);
                self::assertSame(2, (int) DB::table('failed_jobs')->count());
            } else {
                self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
                self::assertSame([$nested], DB::table('failed_jobs')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(), 'Only R1 went; the unrelated record stays.');
            }

            return;
        }

        self::assertOutcome(RetryResult::NOT_ELIGIBLE, in_array($driver, ['mysql', 'mariadb'], true) ? null : 'record_missing', $outcome->code === 'occurrence_unprovable' ? new RetryResult(RetryResult::NOT_ELIGIBLE, null) : $outcome);
        self::assertSame([$nested], DB::table('failed_jobs')->where('uuid', $uuid)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(), 'R2 is intact.');
        self::assertSame([], $this->operations());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nestedStatements(): array
    {
        return ['replace R1 by R2' => ['replace'], 'an unrelated insert' => ['unrelated']];
    }

    /**
     * Rejects: filling a missing id later (a uuid lookup at the retry): a dropped binding refuses with
     * occurrence_unprovable and its message, and deletes nothing.
     */
    public function test_failed_binding_is_refused(): void
    {
        $this->logFailures();
        Queue::push(new FailingJob);
        Watcher::failOperationsForTesting([CapturingUuidFailedJobProvider::OPERATION]);
        $this->work();
        Watcher::failOperationsForTesting([]);

        $uuid = (string) $this->mirror()->table('orrery_jobs')->value('uuid');
        $outcome = $this->retry($uuid, (int) $this->rowOrFail($uuid)->occurrence_id);

        self::assertOutcome(RetryResult::REFUSED, 'occurrence_unprovable', $outcome);
        self::assertSame(RetryService::OCCURRENCE_UNPROVABLE, $outcome->message);
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertNull($this->rowOrFail($uuid)->provider_id, 'Nothing fills the id later.');
        self::assertSame([], $this->operations());
    }

    /**
     * Rejects: a target checked only after the claim: between the failure and the retry the failed table, its
     * prefix, its database or (Postgres) its schema changes, with a record of the same id and uuid planted in the
     * new target; the retry refuses with target_changed, and the planted record stays. The host connection is
     * resolved (a cached PDO) before the configuration changes. A change of the server (the `port`) needs a second
     * reachable server: ReachableTargetTest (groups alt and docker) covers it for every action.
     */
    #[DataProvider('drifts')]
    public function test_target_drift_before_the_first_action_is_refused(string $drift, bool $cached = false): void
    {
        $this->useSplitFailedConnection();
        $driver = DB::connection(TestEnvironment::PRIMARY)->getDriverName();

        if ($drift === 'schema' && $driver !== 'pgsql') {
            self::markTestSkipped('The effective schema is a Postgres case; this run uses '.$driver.'.');
        }

        [$uuid, $occurrence] = $this->failedJob();
        $record = (array) DB::connection(self::FAILED_CONNECTION)->table('failed_jobs')->where('uuid', $uuid)->sole();
        DB::connection(self::FAILED_CONNECTION)->getPdo();

        $config = $this->app['config'];
        $key = 'database.connections.'.self::FAILED_CONNECTION;
        $table = 'failed_jobs';

        switch ($drift) {
            case 'table':
                $table = 'failed_jobs_moved';

                break;
            case 'prefix':
                $config->set($key.'.prefix', 'moved_');

                break;
            case 'database':
                $config->set($key.'.database', $this->otherDatabase());

                break;
            case 'schema':
                DB::connection(self::FAILED_CONNECTION)->statement('create schema if not exists orrery_drift');
                $config->set($key.'.search_path', 'orrery_drift');
                $this->beforeApplicationDestroyed(function (): void {
                    DB::connection(TestEnvironment::PRIMARY)->statement('drop schema if exists orrery_drift cascade');
                });

                break;
        }

        $plant = self::FAILED_CONNECTION;

        if ($cached) {
            // The host's connection and the package sessions keep their old PDOs; only the configuration moved.
            $plant = 'testbench_plant';
            $config->set('database.connections.'.$plant, $config->get($key));
        } else {
            DB::purge(self::FAILED_CONNECTION);
            PackageSession::releaseAll();
        }

        $this->createFailedTableOn($plant, $table);
        DB::connection($plant)->table($table)->insert($record);
        $this->useCapturingFailer($table, self::FAILED_CONNECTION);

        $pooled = $this->pooledSchemaDrift($drift);

        if ($pooled) {
            $this->expectPooledRefusal();
        }

        $outcome = $this->retry($uuid, $occurrence);

        if ($pooled) {
            // Pooled mode refuses the drifted search_path itself (PooledSchemaDrift).
            self::assertOutcome(RetryResult::REFUSED, 'record_unreachable', $outcome);
            $this->assertPooledRefusalReported('retry:record_unreachable');
        } else {
            self::assertOutcome(RetryResult::REFUSED, 'target_changed', $outcome);
            self::assertSame(RetryService::TARGET_CHANGED, $outcome->message);
        }

        self::assertSame(1, DB::connection($plant)->table($table)->where('id', $record['id'])->where('uuid', $uuid)->count(), 'The planted record stays.');
        self::assertSame([], $this->operations());
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a null failer that trusts a row bound to a failed record: the
     * configuration changed from database-uuids to null after the failure, so the record still exists; a retry
     * in mode none would publish the mirror payload next to it, and a forget would leave it. The retry, the
     * forget and --all refuse with target_changed, and the record and the row stay.
     * Still passes: a refusal of every row under the null failer (RetryProtocolTest's null-failer retry is the
     * positive control for an unbound row).
     */
    public function test_null_failer_refuses_a_row_with_a_bound_record(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $this->useNullFailer();

        self::assertOutcome(RetryResult::REFUSED, 'target_changed', $this->retry($uuid, $occurrence));
        $forget = $this->app->make(ForgetService::class)->forget($uuid, $occurrence, 'tester', 'cli');
        self::assertSame([ForgetResult::REFUSED, 'target_changed'], [$forget->result, $forget->code]);
        $report = $this->app->make(ForgetAllRecords::class)->run();
        self::assertSame(['target_changed' => 1], $report['skipped']);
        self::assertSame(0, $report['deleted']);

        self::assertSame([], $this->operations());
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
        self::assertSame(1, $this->failedRecords($uuid), 'The record stays.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drifts(): array
    {
        return [
            'the table' => ['table'], 'the prefix' => ['prefix'], 'the database' => ['database'], 'the schema' => ['schema'],
            'the prefix, cached connection' => ['prefix', true], 'the database, cached connection' => ['database', true],
        ];
    }

    /**
     * Rejects: identity by id on a table that can reuse ids: a plain INTEGER PRIMARY KEY table is read-only
     * (ids_may_be_reused), and the stock AUTOINCREMENT table is the positive control.
     */
    public function test_reused_sqlite_ids_are_refused(): void
    {
        if (DB::connection(TestEnvironment::PRIMARY)->getDriverName() !== 'sqlite') {
            self::markTestSkipped('A SQLite table definition; this run uses '.DB::connection(TestEnvironment::PRIMARY)->getDriverName().'.');
        }

        // A raw statement: the table name carries the connection's table prefix itself (same/prefix).
        $prefix = DB::connection(TestEnvironment::PRIMARY)->getTablePrefix();
        DB::connection(TestEnvironment::PRIMARY)->statement('create table '.$prefix.'failed_plain (id integer primary key, uuid varchar not null unique, connection text not null, queue text not null, payload text not null, exception text not null, failed_at datetime not null default CURRENT_TIMESTAMP)');
        $this->beforeApplicationDestroyed(function (): void {
            DB::connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->dropIfExists('failed_plain');
        });
        $this->useCapturingFailer('failed_plain');
        [$uuid, $occurrence] = $this->failedJob();

        $outcome = $this->retry($uuid, $occurrence);

        self::assertOutcome(RetryResult::REFUSED, 'ids_may_be_reused', $outcome);
        self::assertSame(1, (int) DB::table('failed_plain')->where('uuid', $uuid)->count());
        self::assertSame([], $this->operations());

        // The stock table (AUTOINCREMENT) is supported.
        $this->useCapturingFailer();
        [$uuid, $occurrence] = $this->failedJob();
        self::assertOutcome(RetryResult::ACCEPTED, null, $this->retry($uuid, $occurrence));
    }

    /**
     * Rejects: trusting a binding other than the verified one, an instanceof check, and any provider call: an
     * application subclass of Laravel's provider, the legacy database provider, the file provider, a wrapper (Laravel
     * Cloud's shape) and a singleton for another table are refused with provider_read_only, with no provider call
     * and zero statements on a failed table.
     */
    #[DataProvider('readOnlyFailers')]
    public function test_read_only_failers_are_refused_without_a_call(string $kind): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $calls = new \ArrayObject;
        $db = $this->app['db'];
        $failer = match ($kind) {
            'subclass' => new class($db, TestEnvironment::PRIMARY, 'failed_jobs', $calls) extends DatabaseUuidFailedJobProvider
            {
                /** @param \ArrayObject<int, string> $calls */
                public function __construct($db, $database, $table, public \ArrayObject $calls)
                {
                    parent::__construct($db, $database, $table);
                }

                public function find($id)
                {
                    $this->calls[] = 'find';

                    return parent::find($id);
                }

                public function forget($id)
                {
                    $this->calls[] = 'forget';

                    return parent::forget($id);
                }

                public function log($connection, $queue, $payload, $exception)
                {
                    $this->calls[] = 'log';

                    return parent::log($connection, $queue, $payload, $exception);
                }
            },
            'legacy' => new class($db, TestEnvironment::PRIMARY, 'failed_jobs', $calls) extends DatabaseFailedJobProvider
            {
                /** @param \ArrayObject<int, string> $calls */
                public function __construct($db, $database, $table, public \ArrayObject $calls)
                {
                    parent::__construct($db, $database, $table);
                }

                public function find($id)
                {
                    $this->calls[] = 'find';

                    return parent::find($id);
                }

                public function forget($id)
                {
                    $this->calls[] = 'forget';

                    return parent::forget($id);
                }
            },
            'file' => new class($this->file('failed.json'), $calls) extends FileFailedJobProvider
            {
                /** @param \ArrayObject<int, string> $calls */
                public function __construct(string $path, public \ArrayObject $calls)
                {
                    parent::__construct($path);
                }

                public function find($id)
                {
                    $this->calls[] = 'find';

                    return parent::find($id);
                }

                public function forget($id)
                {
                    $this->calls[] = 'forget';

                    return parent::forget($id);
                }
            },
            'wrapper' => new class(new CapturingUuidFailedJobProvider($db, TestEnvironment::PRIMARY, 'failed_jobs'), $calls) implements FailedJobProviderInterface
            {
                /** @param \ArrayObject<int, string> $calls */
                public function __construct(private readonly FailedJobProviderInterface $inner, public \ArrayObject $calls)
                {
                }

                public function log($connection, $queue, $payload, $exception)
                {
                    $this->calls[] = 'log';

                    return $this->inner->log($connection, $queue, $payload, $exception);
                }

                public function ids($queue = null)
                {
                    $this->calls[] = 'ids';

                    return [];
                }

                public function all()
                {
                    $this->calls[] = 'all';

                    return [];
                }

                public function find($id)
                {
                    $this->calls[] = 'find';

                    return null;
                }

                public function forget($id)
                {
                    $this->calls[] = 'forget';

                    return false;
                }

                public function flush($hours = null)
                {
                    $this->calls[] = 'flush';
                }
            },
            default => new DatabaseUuidFailedJobProvider($db, TestEnvironment::PRIMARY, 'tenant_failed'),
        };
        $this->app->instance('queue.failer', $failer);

        $statements = 0;
        DB::listen(static function (QueryExecuted $event) use (&$statements): void {
            if (str_contains($event->sql, 'failed')) {
                $statements++;
            }
        });
        PackageSession::recordForTesting(static function (string $kind, string $sql) use (&$statements): void {
            if ($kind === PackageSession::STATEMENT && str_contains($sql, 'failed_jobs')) {
                $statements++;
            }
        });

        $outcome = $this->retry($uuid, $occurrence);

        self::assertOutcome(RetryResult::REFUSED, 'provider_read_only', $outcome);
        self::assertStringContainsString('Its failures are shown read-only.', (string) $outcome->message);
        self::assertSame([], $calls->getArrayCopy(), 'No provider method was called.');
        self::assertSame(0, $statements, 'No statement touched a failed table.');
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame([], $this->operations());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readOnlyFailers(): array
    {
        return [
            'an application subclass' => ['subclass'],
            'the legacy database provider' => ['legacy'],
            'the file provider' => ['file'],
            'a wrapper' => ['wrapper'],
            'a singleton for another table' => ['singleton'],
        ];
    }

    private function otherDatabase(): string
    {
        $environment = $this->testEnvironment();

        if ($environment->inMemory()) {
            return TestEnvironment::MEMORY;
        }

        if (! $environment->isServer()) {
            return $this->sqliteFiles[] = SqliteFiles::create();
        }

        // bin/test creates <base>_mirror in both mirror modes.
        return $environment->separate() ? $environment->mirrorDatabase() : (string) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.database').'_mirror';
    }

    private function createFailedTableOn(string $connection, string $table): void
    {
        $schema = DB::connection($connection)->getSchemaBuilder();
        $schema->dropIfExists($table);
        $schema->create($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->id();
            $blueprint->string('uuid')->unique('orrery_drift_'.$table.'_uuid');
            $blueprint->text('connection');
            $blueprint->text('queue');
            $blueprint->longText('payload');
            $blueprint->longText('exception');
            $blueprint->timestamp('failed_at')->useCurrent();
        });
        $this->beforeApplicationDestroyed(static function () use ($connection, $table): void {
            try {
                DB::connection($connection)->getSchemaBuilder()->dropIfExists($table);
            } catch (RuntimeException) {
                // The connection is gone with its file.
            }
        });
    }
}
