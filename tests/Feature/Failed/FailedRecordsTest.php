<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Failed;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider as LaravelLegacyProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider as LaravelUuidProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Failed\CapturingUuidFailedJobProvider;
use Justrau\Orrery\Failed\FailedRecord;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\FailerProvenance;
use Justrau\Orrery\Failed\NoFailedRecords;
use Justrau\Orrery\Failed\ProviderRegistrar;
use Justrau\Orrery\Failed\ReadOnlyFailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The failed-record adapters:
 * the verdict of FailedRecords::for(), the provenance guard, the verifying
 * extender, the modes and the SQL adapter's exact reads, deletes and
 * restores. These are the first halves; other tests add the refusals of the actions.
 * Kind: new behaviour.
 */
#[FreshSchema]
final class FailedRecordsTest extends TestCase
{
    use CapturingFailer;
    use FailedProviderFixtures;

    /**
     * Rejects: a mode decided by the physical database instead of the connection names, and
     * a null failer that gets a SQL mode.
     */
    #[DataProvider('modes')]
    public function test_mode_follows_the_connection_names(?string $failedConnection, ?string $queueConnection, string $expected): void
    {
        $config = $this->app['config'];
        $primary = $config->get('database.connections.'.TestEnvironment::PRIMARY);
        // Two more names: one for the same database (an alias) and one for "another" database.
        $config->set('database.connections.testbench_alias', $primary);
        $config->set('database.connections.testbench_other', $primary);
        $config->set('queue.connections.database.connection', $queueConnection);

        foreach (['testbench_alias', 'testbench_other'] as $name) {
            // In-memory SQLite gives each name its own database, which needs its own table.
            if (! DB::connection($name)->getSchemaBuilder()->hasTable('failed_jobs')) {
                $this->createFailedTable('failed_jobs', true, $name);
            }
        }

        $this->useCapturingFailer('failed_jobs', $failedConnection);
        $records = FailedRecords::for($this->app, $config);

        self::assertInstanceOf(SqlFailedRecords::class, $records);
        self::assertSame($expected, $records->mode('database'));

        $config->set('queue.failed', ['driver' => 'null']);
        $this->app->forgetInstance('queue.failer');
        self::assertSame(Operations::NONE, FailedRecords::for($this->app, $config)->mode('database'));
    }

    /**
     * @return array<string, array{string|null, string|null, string}>
     */
    public static function modes(): array
    {
        return [
            'both on database.default' => [null, null, Operations::IN_TX],
            'the same name' => [TestEnvironment::PRIMARY, TestEnvironment::PRIMARY, Operations::IN_TX],
            'another name' => ['testbench_other', TestEnvironment::PRIMARY, Operations::BEFORE],
            'two names, one database' => ['testbench_alias', TestEnvironment::PRIMARY, Operations::BEFORE],
        ];
    }

    /**
     * Rejects: the old failer swap left registered, a replacement by class
     * alone, and loose `==` (test_bound_failer_must_target_the_configuration
     * through the registrar): with no extender of the test, the package's
     * registered extender gives CapturingUuidFailedJobProvider for Laravel's
     * own binding, and an application extend() that runs after the
     * package's and returns another target (a table '01' while the
     * configuration says '1', a connection '01' against '1') or a wrapper is
     * read-only: retry and forget refuse provider_read_only with zero
     * statements on either table. An application binding of Laravel's own
     * provider class for another table reaches the registered extender, which
     * keeps it (it replaces only Laravel's binding of the configured target),
     * so a verify() by class alone, which would retarget it, fails here.
     */
    public function test_bound_failer_must_target_the_configuration_through_the_registrar(): void
    {
        $config = $this->app['config'];
        $db = $this->app['db'];
        $config->set('database.connections.1', $config->get('database.connections.'.TestEnvironment::PRIMARY));
        $config->set('database.connections.01', $config->get('database.connections.'.TestEnvironment::PRIMARY));
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => '1', 'table' => '1']);
        $this->app->forgetInstance('queue.failer');
        self::assertSame(CapturingUuidFailedJobProvider::class, $this->app['queue.failer']::class, "Laravel's own binding after the registered extender.");
        self::assertTrue(FailerProvenance::targetsConfiguration($this->app, $this->app['queue.failer']));

        $uuid = '0c5d0000-0000-4000-8000-000000000001';
        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'status' => 'failed', 'occurrence_id' => 1,
            'provider_id' => 1, 'provider_target' => str_repeat('a', 64), 'payload' => '{}', 'payload_bytes' => 2,
            'pushed_at' => 1, 'failed_at' => 1, 'finished_at' => 1, 'updated_at' => 1,
        ]);
        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            if (preg_match('/["`]0?1["`]/', $query->sql) === 1) {
                $statements[] = $query->sql;
            }
        });
        PackageSession::recordForTesting(static function (string $kind, string $sql) use (&$statements): void {
            if (preg_match('/["`]0?1["`]/', $sql) === 1) {
                $statements[] = $sql;
            }
        });

        $wrapper = new class implements FailedJobProviderInterface
        {
            public function log($connection, $queue, $payload, $exception)
            {
                return null;
            }

            public function ids($queue = null)
            {
                return [];
            }

            public function all()
            {
                return [];
            }

            public function find($id)
            {
                return null;
            }

            public function forget($id)
            {
                return false;
            }

            public function flush($hours = null) {}
        };

        try {
            foreach ([
                "a table '01' against '1'" => static fn () => new CapturingUuidFailedJobProvider($db, '1', '01'),
                "a connection '01' against '1'" => static fn () => new CapturingUuidFailedJobProvider($db, '01', '1'),
                "Laravel's provider for another table" => static fn () => new LaravelUuidProvider($db, '1', '01'),
                'a wrapper' => static fn () => $wrapper,
            ] as $case => $make) {
                // The application's extend() runs after the package's (registered first). An extend() of a resolved
                // instance changes only that instance, so the instance goes first, as before a real resolution.
                $this->app->forgetInstance('queue.failer');
                $this->app->extend('queue.failer', static fn () => $make());

                self::assertSame(FailedRecords::READ_ONLY, FailedRecords::for($this->app, $config)->kind(), $case);
                $retry = $this->app->make(RetryService::class)->retry($uuid, 1, 'cli', 'cli');
                self::assertSame(['refused', FailedRecords::PROVIDER_READ_ONLY], [$retry->result, $retry->code], $case);
                $forget = $this->app->make(ForgetService::class)->forget($uuid, 1, 'cli', 'cli');
                self::assertSame(['refused', FailedRecords::PROVIDER_READ_ONLY], [$forget->result, $forget->code], $case);

                // Back to the package's extender alone (registered before any resolution, as at boot).
                $this->app->forgetExtenders('queue.failer');
                $this->app->forgetInstance('queue.failer');
                ProviderRegistrar::register($this->app);
            }

            // An application binding (not an extend) of Laravel's own class for another table: the package's
            // extender sees it, and keeps it.
            $laravel = $this->app->getBindings()['queue.failer'];
            $this->app->forgetInstance('queue.failer');
            $this->app->singleton('queue.failer', static fn () => new LaravelUuidProvider($db, '1', '01'));
            self::assertSame(LaravelUuidProvider::class, $this->app['queue.failer']::class, 'the extender keeps a binding of another target');
            self::assertSame(FailedRecords::READ_ONLY, FailedRecords::for($this->app, $config)->kind());
            $retry = $this->app->make(RetryService::class)->retry($uuid, 1, 'cli', 'cli');
            self::assertSame(['refused', FailedRecords::PROVIDER_READ_ONLY], [$retry->result, $retry->code], 'a binding of another target');
            $this->app->forgetInstance('queue.failer');
            $this->app->bind('queue.failer', $laravel['concrete'], $laravel['shared']);
        } finally {
            PackageSession::recordForTesting(null);
        }

        self::assertSame([], $statements, 'no statement on either table');
        self::assertSame('failed', $this->mirrorRow($uuid)->status);
        $this->app->forgetInstance('queue.failer');
        self::assertSame(CapturingUuidFailedJobProvider::class, $this->app['queue.failer']::class);
    }

    /**
     * Rejects: a class check alone, and a loose `==` (the strings '01' and '1'), so an application extend() that
     * runs after the package's and returns a provider for another table or connection would be trusted.
     */
    public function test_bound_failer_must_target_the_configuration(): void
    {
        $config = $this->app['config'];
        $db = $this->app['db'];
        $this->useCapturingFailer('1', TestEnvironment::PRIMARY);
        self::assertTrue(FailerProvenance::targetsConfiguration($this->app, $this->app['queue.failer']), "Laravel's binding after the verifying extender.");

        self::assertFalse(FailerProvenance::targetsConfiguration($this->app, new CapturingUuidFailedJobProvider($db, TestEnvironment::PRIMARY, '01')), "A table '01' while the configuration says '1'.");

        $config->set('database.connections.1', $config->get('database.connections.'.TestEnvironment::PRIMARY));
        $config->set('database.connections.01', $config->get('database.connections.'.TestEnvironment::PRIMARY));
        $config->set('queue.failed.database', '1');
        self::assertFalse(FailerProvenance::targetsConfiguration($this->app, new CapturingUuidFailedJobProvider($db, '01', '1')), "A connection '01' against '1'.");
        self::assertTrue(FailerProvenance::targetsConfiguration($this->app, new CapturingUuidFailedJobProvider($db, '1', '1')));

        // A subclass with no property of its own: the same (array) cast, so only the exact class check refuses it.
        $subclass = new class($db, TestEnvironment::PRIMARY, '1') extends CapturingUuidFailedJobProvider {};
        $config->set('queue.failed.database', TestEnvironment::PRIMARY);
        self::assertFalse(FailerProvenance::targetsConfiguration($this->app, $subclass), 'A subclass of the package provider.');
        $config->set('queue.failed.database', '1');

        $wrapper = new class implements FailedJobProviderInterface
        {
            public function log($connection, $queue, $payload, $exception)
            {
                return null;
            }

            public function ids($queue = null)
            {
                return [];
            }

            public function all()
            {
                return [];
            }

            public function find($id)
            {
                return null;
            }

            public function forget($id)
            {
                return false;
            }

            public function flush($hours = null) {}
        };
        self::assertFalse(FailerProvenance::targetsConfiguration($this->app, $wrapper));

        $config->set('queue.failed', ['driver' => 'null']);
        self::assertTrue(FailerProvenance::targetsConfiguration($this->app, new NullFailedJobProvider));
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => TestEnvironment::PRIMARY, 'table' => 'failed_jobs']);
        self::assertFalse(FailerProvenance::targetsConfiguration($this->app, new NullFailedJobProvider), 'A null failer under another configured driver.');
    }

    /**
     * Rejects: a replacement by class alone, by properties alone, and provenance checked only after the
     * replacement: the host's failures stay in the host's
     * table, its own failer instance stays bound, and the verdict reads neither table.
     *
     * @param  'other_table'|'other_connection'|'strings'|'subclass'  $case
     */
    #[DataProvider('foreignBindings')]
    public function test_extender_never_moves_the_hosts_failed_table(string $case): void
    {
        $config = $this->app['config'];
        $this->createFailedTable('tenant_failed', true);
        foreach (['01', '1'] as $name) {
            $config->set('database.connections.'.$name, $config->get('database.connections.'.TestEnvironment::PRIMARY));

            if (! DB::connection($name)->getSchemaBuilder()->hasTable('failed_jobs')) {
                $this->createFailedTable('failed_jobs', true, $name);
            }
        }

        // The configured target: the strings '1' where the application's own failer says '01'.
        if ($case === 'table_01') {
            $this->createFailedTable('1', true);
            $this->createFailedTable('01', true);
            $this->useCapturingFailer('1');
        } elseif ($case === 'database_01') {
            $this->useCapturingFailer('failed_jobs', '1');
        } else {
            $this->useCapturingFailer();
        }

        $db = $this->app['db'];

        $own = match ($case) {
            'other_table' => new LaravelUuidProvider($db, TestEnvironment::PRIMARY, 'tenant_failed'),
            'other_connection' => new LaravelUuidProvider($db, '01', 'failed_jobs'),
            // The configured name against null (database.default): the same table, another construction.
            'strings' => new LaravelUuidProvider($db, null, 'failed_jobs'),
            'table_01' => new LaravelUuidProvider($db, TestEnvironment::PRIMARY, '01'),
            'database_01' => new LaravelUuidProvider($db, '01', 'failed_jobs'),
            'subclass' => new class($db, TestEnvironment::PRIMARY, 'failed_jobs') extends LaravelUuidProvider
            {
                public function log($connection, $queue, $payload, $exception)
                {
                    return parent::log($connection, $queue, $payload, $exception);
                }
            },
        };
        $this->app->singleton('queue.failer', static fn () => $own);
        $this->app->forgetInstance('queue.failer');

        self::assertSame($own, $this->app['queue.failer'], "The resolved failer is the application's own instance.");

        $this->logFailures();
        Queue::push(new FailingJob);
        $this->work();
        $table = match ($case) {
            'other_table' => 'tenant_failed',
            'table_01' => '01',
            default => 'failed_jobs',
        };
        $connection = in_array($case, ['other_connection', 'database_01'], true) ? '01' : TestEnvironment::PRIMARY;
        self::assertSame(1, DB::connection($connection)->table($table)->count(), "The failure landed in the host's own table.");

        $statements = $this->statementsDuring(fn () => self::assertSame(
            FailedRecords::PROVIDER_READ_ONLY,
            FailedRecords::for($this->app, $config)->refusal(),
        ));
        self::assertSame([], $statements, 'The verdict reads no table.');

        // A forget and --all are refused before any statement, and the host's record stays.
        $row = $this->mirror()->table('orrery_jobs')->sole();
        $statements = $this->statementsDuring(function () use ($row): void {
            $result = $this->app->make(ForgetService::class)->forget((string) $row->uuid, (int) $row->occurrence_id, null);
            self::assertSame(['refused', FailedRecords::PROVIDER_READ_ONLY], [$result->result, $result->code]);
            $report = $this->app->make(ForgetAllRecords::class)->run();
            self::assertSame(['refused', FailedRecords::PROVIDER_READ_ONLY], [$report['result'], $report['code']]);
        });
        self::assertSame([], $statements, 'Zero statements on either failed table (and none elsewhere).');
        self::assertSame(1, DB::connection($connection)->table($table)->count(), "The host's record stays.");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignBindings(): array
    {
        return [
            'a singleton for tenant_failed' => ['other_table'],
            'a factory for another connection' => ['other_connection'],
            'null for the configured name' => ['strings'],
            'a subclass that overrides log()' => ['subclass'],
            "the table '01' against '1'" => ['table_01'],
            "the connection '01' against '1'" => ['database_01'],
        ];
    }

    /**
     * Rejects: a verifying extender that leaves an application singleton of exactly the configured target alone
     * (it is Laravel's provider, so it is supported).
     */
    public function test_singleton_of_the_configured_target_is_replaced(): void
    {
        $this->useCapturingFailer();
        $db = $this->app['db'];
        $this->app->singleton('queue.failer', static fn () => new LaravelUuidProvider($db, TestEnvironment::PRIMARY, 'failed_jobs'));
        $this->app->forgetInstance('queue.failer');

        self::assertInstanceOf(CapturingUuidFailedJobProvider::class, $this->app['queue.failer']);
        self::assertInstanceOf(SqlFailedRecords::class, FailedRecords::for($this->app, $this->app['config']));
    }

    /**
     * Rejects: an instanceof check (a subclass of Laravel's provider passes it), and any provider call: a spy
     * records none.
     *
     * @param  'file'|'legacy'|'subclass'|'wrapper'  $kind
     */
    #[DataProvider('readOnlyFailers')]
    public function test_read_only_failers_are_refused_without_a_call(string $kind): void
    {
        $calls = [];
        $db = $this->app['db'];
        $failer = match ($kind) {
            'file' => new class(sys_get_temp_dir().'/orrery-no-failures', $calls) extends FileFailedJobProvider
            {
                /** @param  list<string>  $calls */
                public function __construct(string $path, private array &$calls)
                {
                    parent::__construct($path);
                }

                public function __call(string $method, array $arguments): mixed
                {
                    $this->calls[] = $method;

                    return null;
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
            },
            'legacy' => new LaravelLegacyProvider($db, TestEnvironment::PRIMARY, 'failed_jobs'),
            'subclass' => new class($db, TestEnvironment::PRIMARY, 'failed_jobs', $calls) extends CapturingUuidFailedJobProvider
            {
                /** @param  list<string>  $calls */
                public function __construct($db, $database, $table, private array &$calls)
                {
                    parent::__construct($db, $database, $table);
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
            },
            'wrapper' => new NullFailedJobProvider,
        };

        if ($kind === 'wrapper') {
            // A null failer under the database-uuids configuration is not the configured failer.
            $this->app['config']->set('queue.failed', ['driver' => 'database-uuids', 'database' => TestEnvironment::PRIMARY, 'table' => 'failed_jobs']);
        }

        $this->app->instance('queue.failer', $failer);
        $records = FailedRecords::for($this->app, $this->app['config']);

        self::assertInstanceOf(ReadOnlyFailedRecords::class, $records);
        self::assertSame(FailedRecords::PROVIDER_READ_ONLY, $records->refusal());
        self::assertStringContainsString($failer::class, (string) $records->message());
        self::assertNull($records->mode('database'));
        self::assertSame([], $calls, 'No provider method was called.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readOnlyFailers(): array
    {
        return ['the file driver' => ['file'], 'the legacy database driver' => ['legacy'], 'a subclass of the package provider' => ['subclass'], 'a null failer that is not configured' => ['wrapper']];
    }

    /**
     * Rejects: identity by id on a table that can reuse ids: a plain INTEGER PRIMARY KEY hands
     * out a deleted id again.
     */
    public function test_reused_sqlite_ids_are_refused(): void
    {
        if (DB::connection(TestEnvironment::PRIMARY)->getDriverName() !== 'sqlite') {
            self::markTestSkipped('The id reuse of a plain INTEGER PRIMARY KEY is a SQLite rule; this run uses '.DB::connection(TestEnvironment::PRIMARY)->getDriverName().'.');
        }

        $schema = DB::connection(TestEnvironment::PRIMARY)->getSchemaBuilder();
        $schema->create('reusing_failed', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $this->beforeApplicationDestroyed(fn () => $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->dropIfExists('reusing_failed'));

        // F1 gets id 1 and is bound; a native retry removes it; the re-failure F2 gets id 1 again.
        $this->useCapturingFailer('reusing_failed');
        $this->logFailures();
        Queue::push(new FailingJob);
        $this->work();
        $uuid = (string) $this->mirror()->table('orrery_jobs')->value('uuid');
        self::assertSame(1, (int) $this->mirrorRow($uuid)->provider_id);
        $record = DB::table('reusing_failed')->sole();
        DB::table('reusing_failed')->delete();
        DB::table('reusing_failed')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $record->payload, 'exception' => 'F2', 'failed_at' => Carbon::now()]);
        self::assertSame(1, (int) DB::table('reusing_failed')->value('id'), 'The table reused the id.');

        $records = FailedRecords::for($this->app, $this->app['config']);
        self::assertInstanceOf(ReadOnlyFailedRecords::class, $records);
        self::assertSame(FailedRecords::IDS_MAY_BE_REUSED, $records->refusal());
        self::assertSame('F2', DB::table('reusing_failed')->value('exception'), 'F2 is intact.');

        // Laravel's stock table (AUTOINCREMENT) is the positive control.
        $this->useCapturingFailer('failed_jobs');
        self::assertInstanceOf(SqlFailedRecords::class, FailedRecords::for($this->app, $this->app['config']));
    }

    /**
     * Rejects: a read or a delete by uuid alone, a restore that keeps the old id, a saved record that loses
     * a binary column, and a log-time target that differs from the configured one.
     */
    public function test_sql_records_read_delete_and_restore_by_the_exact_identity(): void
    {
        $this->useCapturingFailer();
        $records = FailedRecords::for($this->app, $this->app['config']);
        self::assertInstanceOf(SqlFailedRecords::class, $records);
        $uuid = '0f5a3c1e-6b1d-4e57-9a3e-3f1b2c4d5e6f';
        // SQLite keeps any bytes in a text column; MySQL and Postgres accept only valid UTF-8 there.
        $exception = DB::connection(TestEnvironment::PRIMARY)->getDriverName() === 'sqlite' ? "trace \xff\xfe" : 'trace ą';
        $id = (int) DB::table('failed_jobs')->insertGetId(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{"uuid":"'.$uuid.'"}', 'exception' => $exception, 'failed_at' => Carbon::now()]);
        $binary = new FailedRecord(1, $uuid, ['id' => 1, 'exception' => "\xff\xfe\x00", 'attempts' => 3, 'note' => null]);
        self::assertSame($binary->columns, SqlFailedRecords::decode(SqlFailedRecords::encode($binary)), 'A binary column survives the JSON.');

        self::assertNull($records->read($id + 1, $uuid), 'Another id is another record.');
        self::assertNull($records->read($id, '11111111-0000-4000-8000-000000000000'), 'Another uuid is another record.');
        $record = $records->read($id, $uuid);
        self::assertInstanceOf(FailedRecord::class, $record);

        $saved = SqlFailedRecords::encode($record);
        self::assertSame($record->columns, SqlFailedRecords::decode($saved), 'Every column survives the JSON, a binary one included.');

        // The writes are operator actions: a host SQLite file takes no automatic write (D17).
        $sessions = $this->app->make(PackageSession::class);
        $restored = $sessions->operator(static function () use ($sessions, $records, $id, $uuid, $saved): int {
            $session = $sessions->forAction(TestEnvironment::PRIMARY);
            self::assertSame(0, $records->delete($session, $id, '11111111-0000-4000-8000-000000000000'));
            self::assertSame(1, $records->delete($session, $id, $uuid));
            self::assertSame(0, DB::table('failed_jobs')->count());

            return $records->restore($session, SqlFailedRecords::decode($saved));
        });
        self::assertNotSame($id, $restored, 'A restore gets a new id.');
        $row = DB::table('failed_jobs')->where('id', $restored)->sole();
        self::assertSame($exception, $row->exception);

        $host = DB::connection(TestEnvironment::PRIMARY);
        self::assertSame($records->target(), SqlFailedRecords::targetOf(PackageSession::writerConfigOf($host->getConfig()), 'failed_jobs'), 'The log-time target of the connection object equals the configured one.');
        self::assertNotSame($records->target(), SqlFailedRecords::targetOf(PackageSession::writerConfigOf($host->getConfig()), 'tenant_failed'));
    }

    /**
     * Rejects: an AUTOINCREMENT proof that a comment or another column satisfies: the `id` column
     * itself must be INTEGER PRIMARY KEY AUTOINCREMENT.
     */
    #[DataProvider('autoincrementTables')]
    public function test_autoincrement_proof_reads_the_id_column(string $definition, bool $proved): void
    {
        self::assertSame($proved, SqlFailedRecords::idAutoincrements($definition));

        if (DB::connection(TestEnvironment::PRIMARY)->getDriverName() !== 'sqlite' || $definition === '') {
            return;
        }

        $host = DB::connection(TestEnvironment::PRIMARY);
        $host->statement(sprintf($definition, $host->getQueryGrammar()->wrapTable('proof_failed')));
        $this->beforeApplicationDestroyed(fn () => $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->dropIfExists('proof_failed'));
        $this->useCapturingFailer('proof_failed');

        $records = FailedRecords::for($this->app, $this->app['config']);
        self::assertSame($proved ? null : FailedRecords::IDS_MAY_BE_REUSED, $records->refusal());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function autoincrementTables(): array
    {
        $columns = 'uuid varchar(255) not null unique, connection text, queue text, payload text, exception text, failed_at datetime';

        return [
            'Laravel\'s stock table' => ['create table %s ("id" integer primary key autoincrement not null, '.$columns.')', true],
            'AUTOINCREMENT only in a comment' => ["create table %s (\"id\" integer primary key not null, -- not AUTOINCREMENT\n".$columns.')', false],
            'AUTOINCREMENT in a block comment' => ['create table %s ("id" integer primary key /* autoincrement */ not null, '.$columns.')', false],
            'AUTOINCREMENT on another column' => ['create table %s (seq integer primary key autoincrement, id integer, '.$columns.')', false],
        ];
    }

    /**
     * Rejects: a null failer that is read-only, and a verdict that calls the provider.
     */
    public function test_null_failer_has_no_records(): void
    {
        $this->app['config']->set('queue.failed', ['driver' => 'null']);
        $this->app->forgetInstance('queue.failer');

        $records = FailedRecords::for($this->app, $this->app['config']);

        self::assertInstanceOf(NoFailedRecords::class, $records);
        self::assertSame(FailedRecords::NONE, $records->kind());
        self::assertNull($records->refusal());
    }

    /**
     * The statements on the host connections and the package sessions while $work runs.
     *
     * @return list<string>
     */
    private function statementsDuring(\Closure $work): array
    {
        $host = [];
        DB::listen(static function (QueryExecuted $event) use (&$host): void {
            $host[] = $event->sql;
        });
        $package = PackageStatements::record($work);

        return [...$host, ...array_map(static fn (array $entry): string => $entry['sql'], $package)];
    }
}
