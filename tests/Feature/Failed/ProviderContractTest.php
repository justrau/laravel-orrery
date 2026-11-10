<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Failed;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider as LaravelUuidProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Failed\CapturingUuidFailedJobProvider;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * The package's CapturingUuidFailedJobProvider stores what Laravel's
 * DatabaseUuidFailedJobProvider stores and returns what it returns, while it
 * binds the inserted id (test_provider_binds_the_inserted_id:
 * a contract test, also on the Laravel 12 and 13 lowest and latest legs).
 * The provider classes differ between Laravel 12.0.x, 12.x and 13.x, so the
 * version legs run this test on the lowest and the latest of both majors.
 */
#[FreshSchema]
final class ProviderContractTest extends TestCase
{
    use CapturingFailer;
    use FailedProviderFixtures;

    private const UUID = '0f5a3c1e-6b1d-4e57-9a3e-3f1b2c4d5e6f';

    /**
     * Rejects: `insert()` plus a lookup, a RETURNING insert that stores other columns or values than Laravel's,
     * a receipt without recordsHaveBeenModified(), and a provider that returns the id instead of the uuid.
     */
    public function test_provider_binds_the_inserted_id(): void
    {
        $this->createFailedTable('contract_parent', true);
        $this->useCapturingFailer('failed_jobs');
        $this->seedFailedRecords(5);
        $occurrence = $this->mirroredFailure(self::UUID);
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 10, 11, 12));

        try {
            $payload = (string) json_encode(['uuid' => self::UUID, 'displayName' => 'Contract', 'data' => ['text' => 'ąčę']], JSON_UNESCAPED_UNICODE);
            $exception = new RuntimeException("contract failure \u{00e9}");
            $expected = (new LaravelUuidProvider($this->app['db'], TestEnvironment::PRIMARY, 'contract_parent'))->log('database', 'default', $payload, $exception);
            $this->slot()->put(self::UUID, $occurrence);
            // Laravel's own log() above modified the connection: start the capturing log() from a clean state.
            DB::connection(TestEnvironment::PRIMARY)->forgetRecordModificationState();
            $actual = $this->app['queue.failer']->log('database', 'default', $payload, $exception);
        } finally {
            Carbon::setTestNow();
        }

        self::assertSame($expected, $actual, 'log() returns the uuid, as Laravel does.');
        $stored = static fn (string $table): array => array_map(
            static function (object $row): array {
                $row = (array) $row;
                unset($row['id']);

                return $row;
            },
            DB::table($table)->where('uuid', self::UUID)->get()->all(),
        );
        self::assertSame($stored('contract_parent'), $stored('failed_jobs'), 'The same row as Laravel\'s.');

        $record = DB::table('failed_jobs')->where('uuid', self::UUID)->sole();
        self::assertSame((int) $record->id, (int) $this->mirrorRow(self::UUID)->provider_id, 'Exactly the inserted id is bound.');
        self::assertTrue(DB::connection(TestEnvironment::PRIMARY)->hasModifiedRecords(), 'The receipt marked the connection as modified.');
    }

    /**
     * Rejects: a quick-refailure branch or any other handling of the unique-uuid conflict: Laravel's error comes
     * through unchanged, and nothing is bound.
     */
    public function test_unique_uuid_conflict_comes_through_unchanged(): void
    {
        $this->createFailedTable('contract_parent', true);
        $this->useCapturingFailer('failed_jobs');
        $occurrence = $this->mirroredFailure(self::UUID);
        $payload = (string) json_encode(['uuid' => self::UUID]);
        $laravel = new LaravelUuidProvider($this->app['db'], TestEnvironment::PRIMARY, 'contract_parent');
        $laravel->log('database', 'default', $payload, new RuntimeException('first'));
        DB::table('failed_jobs')->insert(['uuid' => self::UUID, 'connection' => 'database', 'queue' => 'default', 'payload' => $payload, 'exception' => 'first', 'failed_at' => Carbon::now()]);

        $expected = null;

        try {
            $laravel->log('database', 'default', $payload, new RuntimeException('second'));
        } catch (Throwable $error) {
            $expected = $error::class;
        }

        $this->slot()->put(self::UUID, $occurrence);

        try {
            $this->app['queue.failer']->log('database', 'default', $payload, new RuntimeException('second'));
            self::fail('The conflict comes through.');
        } catch (Throwable $error) {
            self::assertSame($expected, $error::class);
            self::assertInstanceOf(UniqueConstraintViolationException::class, $error);
        }

        self::assertNull($this->mirrorRow(self::UUID)->provider_id);
        self::assertSame(1, DB::table('failed_jobs')->where('uuid', self::UUID)->count());
    }

    /**
     * Rejects: a RETURNING insert on a SQLite library without RETURNING (below 3.35), and a binding there.
     */
    public function test_sqlite_below_3_35_logs_as_laravel_and_binds_nothing(): void
    {
        if (DB::connection(TestEnvironment::PRIMARY)->getDriverName() !== 'sqlite') {
            self::markTestSkipped('The SQLite library version applies to SQLite; this run uses '.DB::connection(TestEnvironment::PRIMARY)->getDriverName().'.');
        }

        $this->useCapturingFailer('failed_jobs');
        $occurrence = $this->mirroredFailure(self::UUID);
        CapturingUuidFailedJobProvider::useSqliteVersionForTesting(static fn (): string => '3.34.1');
        $statements = [];
        DB::listen(static function (QueryExecuted $event) use (&$statements): void {
            if ($event->connectionName === TestEnvironment::PRIMARY) {
                $statements[] = $event->sql;
            }
        });

        $this->slot()->put(self::UUID, $occurrence);
        $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => self::UUID]), new RuntimeException('old library'));

        self::assertSame(1, DB::table('failed_jobs')->where('uuid', self::UUID)->count());
        self::assertNull($this->mirrorRow(self::UUID)->provider_id);
        self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains(strtolower($sql), 'returning'))));
    }

    /**
     * Rejects: a receipt that is not validated: on a table without an `id` column SQLite reads "id" as a string
     * literal and returns the text `id`, which must bind nothing.
     */
    public function test_receipt_without_an_id_binds_nothing(): void
    {
        $host = DB::connection(TestEnvironment::PRIMARY);

        if ($host->getDriverName() !== 'sqlite') {
            self::markTestSkipped('The string receipt of a missing column is a SQLite behaviour; this run uses '.$host->getDriverName().'.');
        }

        $host->getSchemaBuilder()->create('noid_failed', static function (Blueprint $blueprint): void {
            $blueprint->string('uuid')->unique();
            $blueprint->text('connection');
            $blueprint->text('queue');
            $blueprint->longText('payload');
            $blueprint->longText('exception');
            $blueprint->timestamp('failed_at')->useCurrent();
        });
        $this->beforeApplicationDestroyed(fn () => $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->dropIfExists('noid_failed'));
        $this->useCapturingFailer('noid_failed');
        $occurrence = $this->mirroredFailure(self::UUID);

        $this->slot()->put(self::UUID, $occurrence);
        $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => self::UUID]), new RuntimeException('no id'));

        self::assertSame(1, DB::table('noid_failed')->count(), 'The record is logged.');
        self::assertNull($this->mirrorRow(self::UUID)->provider_id);
        self::assertNull(CapturingUuidFailedJobProvider::receipt('id'));
        self::assertNull(CapturingUuidFailedJobProvider::receipt(0));
        self::assertNull(CapturingUuidFailedJobProvider::receipt(null));
        self::assertSame(7, CapturingUuidFailedJobProvider::receipt('7'));
    }

    /**
     * Rejects: a receipt read that skips recordsHaveBeenModified(), so a sticky connection keeps reading a stale
     * replica after the failure was logged, on the receipt path and on the plain path.
     */
    public function test_sticky_connection_reads_the_writer_after_log(): void
    {
        $config = $this->app['config'];
        $config->set('database.connections.sticky', array_merge($config->get('database.connections.'.TestEnvironment::PRIMARY), ['sticky' => true]));

        if (! DB::connection('sticky')->getSchemaBuilder()->hasTable('failed_jobs')) {
            $this->createFailedTable('failed_jobs', true, 'sticky');
        }

        $this->useCapturingFailer('failed_jobs', 'sticky');
        $occurrence = $this->mirroredFailure(self::UUID);

        foreach (['receipt' => true, 'plain' => false] as $path => $withSlot) {
            $connection = DB::connection('sticky');
            // A previously unmodified connection.
            $connection->forgetRecordModificationState();
            $stale = new PDO('sqlite::memory:');
            $connection->setReadPdo($stale);
            self::assertSame($stale, $connection->getReadPdo(), "{$path}: the connection reads the replica before log().");

            if ($withSlot) {
                $this->slot()->put(self::UUID, $occurrence);
            }

            $this->app->forgetInstance('queue.failer');
            $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => self::UUID]), new RuntimeException($path));

            self::assertSame($connection->getPdo(), $connection->getReadPdo(), "{$path}: after log() the sticky connection reads the writer.");
            $connection->table('failed_jobs')->where('uuid', self::UUID)->delete();
        }
    }

    /**
     * Rejects: RETURNING without a proven SELECT on `id`: an insert-only role and a
     * role whose row-security policy hides the new row log exactly as Laravel does, and nothing is bound.
     */
    #[DataProvider('postgresRoles')]
    public function test_postgres_role_without_a_readable_id_logs_as_laravel(string $role): void
    {
        $host = DB::connection(TestEnvironment::PRIMARY);

        if ($host->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Roles and row security are Postgres features; this run uses '.$host->getDriverName().'.');
        }

        $this->useCapturingFailer('failed_jobs');
        $occurrence = $this->mirroredFailure(self::UUID);
        $name = $this->postgresRole($role);
        // Every attempted statement, also one that fails (a failed statement fires no QueryExecuted).
        $attempted = [];
        $host->beforeExecuting(static function (string $sql) use (&$attempted): void {
            $attempted[] = $sql;
        });

        try {
            $host->statement('SET ROLE '.$name);
            $this->slot()->put(self::UUID, $occurrence);
            $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => self::UUID]), new RuntimeException($role));
        } finally {
            $host->statement('RESET ROLE');
        }

        self::assertSame(1, DB::table('failed_jobs')->where('uuid', self::UUID)->count(), 'The record is logged.');
        self::assertNull($this->mirrorRow(self::UUID)->provider_id, 'Nothing is bound.');
        self::assertSame([], array_values(array_filter($attempted, static fn (string $sql): bool => preg_match('/\breturning\s+"id"/i', $sql) === 1)), 'The capability read stopped the RETURNING insert before it ran.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function postgresRoles(): array
    {
        return ['an insert-only role' => ['insert_only'], 'a SELECT policy that hides the row' => ['row_security']];
    }

    /**
     * Rejects: a RETURNING insert whose 0A000 error (a DO INSTEAD rule without RETURNING) comes out of log(), and
     * a second fallback insert: Laravel's insert runs once, through the rule, and nothing is bound.
     */
    public function test_postgres_rule_without_returning_logs_once(): void
    {
        $host = DB::connection(TestEnvironment::PRIMARY);

        if ($host->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Rules are a Postgres feature; this run uses '.$host->getDriverName().'.');
        }

        $this->useCapturingFailer('failed_jobs');
        $occurrence = $this->mirroredFailure(self::UUID);
        $table = $host->getQueryGrammar()->wrapTable('failed_jobs');
        $shadow = $host->getQueryGrammar()->wrapTable('failed_jobs_shadow');
        $host->statement("CREATE TABLE {$shadow} (uuid varchar(255), payload text)");
        $host->statement("CREATE RULE orrery_c5a_instead AS ON INSERT TO {$table} DO INSTEAD INSERT INTO {$shadow} (uuid, payload) VALUES (NEW.uuid, NEW.payload)");
        $this->beforeApplicationDestroyed(function () use ($table, $shadow): void {
            $host = $this->app['db']->connection(TestEnvironment::PRIMARY);
            $host->statement("DROP RULE IF EXISTS orrery_c5a_instead ON {$table}");
            $host->statement("DROP TABLE IF EXISTS {$shadow}");
        });

        $this->slot()->put(self::UUID, $occurrence);
        $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => self::UUID]), new RuntimeException('rule'));

        self::assertSame(1, (int) $host->table('failed_jobs_shadow')->where('uuid', self::UUID)->count(), 'One insert, through the rule.');
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertNull($this->mirrorRow(self::UUID)->provider_id);
    }

    /**
     * Rejects: a capability read whose error comes out of log() or still leads to RETURNING.
     */
    public function test_postgres_failing_capability_read_logs_as_laravel(): void
    {
        $host = DB::connection(TestEnvironment::PRIMARY);

        if ($host->getDriverName() !== 'pgsql') {
            self::markTestSkipped('The capability read is a Postgres rule; this run uses '.$host->getDriverName().'.');
        }

        $this->useCapturingFailer('failed_jobs');
        $occurrence = $this->mirroredFailure(self::UUID);
        $attempted = [];
        $host->beforeExecuting(static function (string $sql) use (&$attempted): void {
            $attempted[] = $sql;

            if (str_contains($sql, 'orrery_returning')) {
                throw new RuntimeException('the capability read fails');
            }
        });

        $this->slot()->put(self::UUID, $occurrence);
        $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => self::UUID]), new RuntimeException('read fails'));

        self::assertSame(1, DB::table('failed_jobs')->where('uuid', self::UUID)->count());
        self::assertNull($this->mirrorRow(self::UUID)->provider_id);
        self::assertSame([], array_values(array_filter($attempted, static fn (string $sql): bool => preg_match('/\breturning\s+"id"/i', $sql) === 1)));
    }

    /**
     * Rejects: any extra statement inside a host transaction of the failed-record connection on Postgres:
     * a failing capability read or RETURNING would abort the host's transaction.
     */
    #[DataProvider('postgresTransactionCases')]
    public function test_postgres_host_transaction_gets_laravels_insert_alone(string $case, bool $commit): void
    {
        $host = DB::connection(TestEnvironment::PRIMARY);

        if ($host->getDriverName() !== 'pgsql') {
            self::markTestSkipped('The capability read is a Postgres rule; this run uses '.$host->getDriverName().'.');
        }

        $table = 'failed_jobs';

        if ($case === 'no_id') {
            $table = 'noid_failed';
            $host->getSchemaBuilder()->create($table, static function (Blueprint $blueprint): void {
                $blueprint->string('uuid')->unique();
                $blueprint->text('connection');
                $blueprint->text('queue');
                $blueprint->longText('payload');
                $blueprint->longText('exception');
                $blueprint->timestamp('failed_at')->useCurrent();
            });
            $this->beforeApplicationDestroyed(fn () => $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->dropIfExists('noid_failed'));
        }

        $this->useCapturingFailer($table);
        $occurrence = $this->mirroredFailure(self::UUID);
        $name = $case === 'revoked' ? $this->postgresRole('insert_only') : null;
        $statements = [];
        DB::listen(static function (QueryExecuted $event) use (&$statements): void {
            if ($event->connectionName === TestEnvironment::PRIMARY) {
                $statements[] = $event->sql;
            }
        });

        $host->beginTransaction();

        try {
            $host->table('job_batches')->insert(['id' => 'unrelated', 'name' => 'n', 'total_jobs' => 0, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => 1]);

            if ($name !== null) {
                $host->statement('SET LOCAL ROLE '.$name);
            }

            $statements = [];
            $this->slot()->put(self::UUID, $occurrence);
            $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => self::UUID]), new RuntimeException($case));
            $inside = $statements;
        } finally {
            $commit ? $host->commit() : $host->rollBack();
        }

        self::assertCount(1, $inside, 'Only Laravel\'s insert ran: '.implode(' | ', $inside));
        self::assertStringNotContainsString('returning', strtolower($inside[0]));
        self::assertSame($commit ? 1 : 0, DB::table('job_batches')->where('id', 'unrelated')->count(), 'The host transaction ended as the host intended.');
        self::assertSame($commit ? 1 : 0, DB::table($table)->where('uuid', self::UUID)->count());
        self::assertNull($this->mirrorRow(self::UUID)->provider_id);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function postgresTransactionCases(): array
    {
        return [
            'SELECT on id revoked, commit' => ['revoked', true],
            'a table without id, commit' => ['no_id', true],
            'the stock table, rollback' => ['stock', false],
        ];
    }

    /**
     * A mirrored failed occurrence of $uuid without a bound record id (no failed record exists): its occurrence.
     */
    private function mirroredFailure(string $uuid): int
    {
        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => $uuid, 'cycle' => 1, 'occurrence_id' => 41, 'connection' => 'database', 'queue' => 'default',
            'status' => 'failed', 'payload' => (string) json_encode(['uuid' => $uuid]), 'payload_bytes' => 2,
            'pushed_at' => '1790000000.000000', 'failed_at' => '1790000001.000000', 'updated_at' => '1790000001.000000',
        ]);

        return 41;
    }

    /**
     * A Postgres role for this test, dropped at its end: `insert_only` may insert into failed_jobs and use its
     * sequence but not SELECT; `row_security` may SELECT and INSERT, but a SELECT policy hides every row.
     */
    private function postgresRole(string $kind): string
    {
        $host = DB::connection(TestEnvironment::PRIMARY);
        $name = 'orrery_c5a_'.bin2hex(random_bytes(4));
        $table = $host->getQueryGrammar()->wrapTable('failed_jobs');
        $sequence = $host->getTablePrefix().'failed_jobs_id_seq';
        $host->statement("CREATE ROLE {$name} NOLOGIN");
        $host->statement("GRANT USAGE ON SCHEMA public TO {$name}");
        $host->statement("GRANT INSERT ON {$table} TO {$name}");
        $host->statement("GRANT USAGE ON SEQUENCE {$sequence} TO {$name}");

        if ($kind === 'row_security') {
            $host->statement("GRANT SELECT ON {$table} TO {$name}");
            $host->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            $host->statement("CREATE POLICY orrery_c5a_insert ON {$table} FOR INSERT TO {$name} WITH CHECK (true)");
            $host->statement("CREATE POLICY orrery_c5a_select ON {$table} FOR SELECT TO {$name} USING (false)");
        }

        $this->beforeApplicationDestroyed(function () use ($name, $table, $kind): void {
            $host = $this->app['db']->connection(TestEnvironment::PRIMARY);

            if ($kind === 'row_security') {
                $host->statement("DROP POLICY IF EXISTS orrery_c5a_insert ON {$table}");
                $host->statement("DROP POLICY IF EXISTS orrery_c5a_select ON {$table}");
                $host->statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }

            $host->statement("DROP OWNED BY {$name}");
            $host->statement("DROP ROLE IF EXISTS {$name}");
        });

        return $name;
    }
}
