<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Performance;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\OrreryServiceProvider;
use Justrau\Orrery\Registration\Modules\MonitoringModule;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The optional index of the application's jobs table:
 * published under `orrery-jobs-index`, never run by the package.
 */
#[FreshSchema]
final class OptionalJobsIndexTest extends TestCase
{
    /**
     * Rejects: a migration that the package loads and runs by itself.
     */
    public function test_the_index_is_only_published_and_never_run(): void
    {
        $target = $this->app->databasePath('migrations/'.MonitoringModule::MIGRATION);

        self::assertFileExists(MonitoringModule::source());
        self::assertSame([MonitoringModule::source() => $target], ServiceProvider::pathsToPublish(null, MonitoringModule::PUBLISH_TAG));
        self::assertSame($target, ServiceProvider::pathsToPublish(OrreryServiceProvider::class)[MonitoringModule::source()] ?? null);

        foreach ($this->app['migrator']->paths() as $path) {
            self::assertStringNotContainsString('database/optional', (string) $path, 'the optional migration is not loaded');
        }

        self::assertFalse($this->hasBacklogIndex(), 'the package migrations do not add the index');
    }

    /**
     * Rejects: a copy published under the fixed 2026-09-23 name: a fresh `migrate` ran it before
     * the application's own jobs table migration, so it built nothing and was recorded as run. The source is
     * among the paths that `vendor:publish` names by the time of the publish, also when the application's
     * `database.migrations.update_date_on_publish` is off.
     * Still passes: a rename that `vendor:publish` skips for a reason this test does not reach (the copy itself
     * is not run here).
     */
    public function test_the_published_copy_is_named_by_the_time_of_the_publish(): void
    {
        $this->app['config']->set('database.migrations.update_date_on_publish', false);

        self::assertContains(MonitoringModule::source(), ServiceProvider::publishableMigrationPaths());
    }

    /**
     * Rejects: an index on other columns, a second run that fails, or a down() that drops another index.
     */
    public function test_up_adds_the_backlog_index_once_and_down_removes_it(): void
    {
        // Two queue connections on one database and one table: one DDL.
        $this->app['config']->set('queue.connections.second', ['driver' => 'database', 'connection' => TestEnvironment::PRIMARY, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $before = $this->indexNames();

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $index = $this->backlogIndex();
        self::assertNotNull($index, 'the index exists after up()');
        self::assertSame(['queue', 'reserved_at', 'available_at'], $index['columns']);
        self::assertFalse((bool) $index['unique']);

        $migration->down();
        $migration->down();

        self::assertFalse($this->hasBacklogIndex());
        self::assertSame($before, $this->indexNames(), 'down() drops only the backlog index');
    }

    /**
     * Rejects: an index migration whose `migrate --pretend` builds the index for real, or
     * whose pretend rollback drops it for real, on a jobs table of another endpoint than the
     * migration connection (the migrator pretends only on its own connection).
     * Still passes: a pretend run that also skips the jobs table of the migration connection
     * (nothing is built there either way).
     */
    public function test_pretend_builds_and_drops_nothing_on_another_endpoint(): void
    {
        $file = SqliteFiles::create();

        try {
            $this->app['config']->set('database.connections.pretend_queue', ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
            $this->app['config']->set('queue.connections.pretend_queue', ['driver' => 'database', 'connection' => 'pretend_queue', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
            $queue = DB::connection('pretend_queue');
            $queue->statement('create table jobs (id integer primary key autoincrement, queue varchar not null, payload text not null, attempts integer not null, reserved_at integer, available_at integer not null, created_at integer not null)');
            $index = static fn (): bool => $queue->selectOne("select name from sqlite_master where type = 'index' and name = 'jobs_orrery_backlog'") !== null;
            $migrationConnection = DB::connection();

            $migrationConnection->pretend(fn () => $this->migration()->up());
            self::assertFalse($index(), 'migrate --pretend built no index.');

            $this->migration()->up();
            self::assertTrue($index());

            $migrationConnection->pretend(fn () => $this->migration()->down());
            self::assertTrue($index(), 'The pretend rollback dropped no index.');

            $this->migration()->down();
            self::assertFalse($index());
        } finally {
            DB::purge('pretend_queue');
            SqliteFiles::remove($file);
        }
    }

    /**
     * Rejects: a qualified index name (`tenant`.`jobs_orrery_backlog`) for a qualified jobs table, or an index on the unqualified table.
     * Still passes: an index on another qualified table of the same name in a third schema (not configured).
     *
     * @param  'mysql'|'pgsql'  $engine
     */
    #[DataProvider('serverEngines')]
    public function test_a_qualified_jobs_table_with_a_prefix_gets_an_unqualified_index(string $engine): void
    {
        $this->serverEngine($engine);
        $primary = DB::connection(TestEnvironment::PRIMARY);
        $schema = $engine === 'pgsql' ? 'b22_tenant' : $this->testEnvironment()->database.'_mirror';

        if ($engine === 'pgsql') {
            $primary->statement('create schema if not exists b22_tenant');
            $this->beforeApplicationDestroyed(fn () => DB::connection(TestEnvironment::PRIMARY)->statement('drop schema if exists b22_tenant cascade'));
        } else {
            $this->beforeApplicationDestroyed(fn () => DB::connection(TestEnvironment::PRIMARY)->statement("drop table if exists `{$schema}`.`pq_jobs`"));
        }

        $this->createJobsTable($engine, $schema, 'pq_jobs');
        $this->copyConnection('testbench_pq', ['prefix' => 'pq_']);
        $this->app['config']->set('queue.connections.qualified', ['driver' => 'database', 'connection' => 'testbench_pq', 'table' => $schema.'.jobs', 'queue' => 'default', 'retry_after' => 90]);

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        self::assertSame(['queue', 'reserved_at', 'available_at'], $this->indexColumns($engine, $schema, 'pq_jobs', 'pq_jobs_orrery_backlog'));

        $migration->down();
        $migration->down();

        self::assertNull($this->indexColumns($engine, $schema, 'pq_jobs', 'pq_jobs_orrery_backlog'));
    }

    /**
     * Rejects: a resolution pinned to current_schema(), which skips a jobs table that exists only in a later schema of the search path.
     * Still passes: a resolution that ignores a table in an earlier schema that the queue never reaches (not configured here).
     */
    public function test_a_jobs_table_in_a_later_search_path_schema_gets_the_index(): void
    {
        $this->serverEngine('pgsql');
        $primary = DB::connection(TestEnvironment::PRIMARY);
        $primary->statement('create schema if not exists b22_later');
        $this->beforeApplicationDestroyed(fn () => DB::connection(TestEnvironment::PRIMARY)->statement('drop schema if exists b22_later cascade'));
        $this->createJobsTable('pgsql', 'b22_later', 'later_jobs');
        $this->copyConnection('testbench_later', ['prefix' => '', 'search_path' => 'public,b22_later']);
        $this->app['config']->set('queue.connections.later', ['driver' => 'database', 'connection' => 'testbench_later', 'table' => 'later_jobs', 'queue' => 'default', 'retry_after' => 90]);

        $this->migration()->up();

        self::assertSame(['queue', 'reserved_at', 'available_at'], $this->indexColumns('pgsql', 'b22_later', 'later_jobs', 'later_jobs_orrery_backlog'));

        $this->migration()->down();
        self::assertNull($this->indexColumns('pgsql', 'b22_later', 'later_jobs', 'later_jobs_orrery_backlog'));
    }

    /**
     * Rejects: a grouping by the connection endpoint alone, which gives two aliases whose search paths reach different jobs relations only one index.
     * Still passes: a grouping that runs the DDL twice for one relation (hasIndex() makes it idempotent).
     */
    public function test_aliases_whose_jobs_relations_differ_each_get_the_index(): void
    {
        $this->serverEngine('pgsql');
        $primary = DB::connection(TestEnvironment::PRIMARY);

        foreach (['b22_a', 'b22_b'] as $schema) {
            $primary->statement("create schema if not exists {$schema}");
            $this->beforeApplicationDestroyed(fn () => DB::connection(TestEnvironment::PRIMARY)->statement("drop schema if exists {$schema} cascade"));
            $this->createJobsTable('pgsql', $schema, 'alias_jobs');
            $this->copyConnection('testbench_'.$schema, ['prefix' => '', 'search_path' => $schema]);
            $this->app['config']->set('queue.connections.'.$schema, ['driver' => 'database', 'connection' => 'testbench_'.$schema, 'table' => 'alias_jobs', 'queue' => 'default', 'retry_after' => 90]);
        }

        $this->migration()->up();

        foreach (['b22_a', 'b22_b'] as $schema) {
            self::assertSame(['queue', 'reserved_at', 'available_at'], $this->indexColumns('pgsql', $schema, 'alias_jobs', 'alias_jobs_orrery_backlog'), $schema);
        }

        $this->migration()->down();

        foreach (['b22_a', 'b22_b'] as $schema) {
            self::assertNull($this->indexColumns('pgsql', $schema, 'alias_jobs', 'alias_jobs_orrery_backlog'), $schema);
        }
    }

    /**
     * Rejects: a plain CREATE INDEX on Postgres, which blocks every dispatch and reservation for the whole build,
     * a migration inside a transaction (CONCURRENTLY cannot run there), and an up() that keeps the INVALID index
     * of a failed concurrent build.
     */
    public function test_postgres_builds_and_drops_the_index_concurrently(): void
    {
        $this->serverEngine('pgsql');
        $statements = [];
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $migration = $this->migration();
        self::assertFalse($migration->withinTransaction);

        $migration->up();
        self::assertCount(1, preg_grep('/^create index concurrently /', $statements) ?: []);

        // A failed concurrent build leaves the index INVALID.
        $valid = fn (): ?bool => ($row = DB::connection(TestEnvironment::PRIMARY)->selectOne('select i.indisvalid as valid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = ? and c.relnamespace = current_schema()::regnamespace', [$this->indexName()])) === null ? null : (bool) $row->valid;
        DB::connection(TestEnvironment::PRIMARY)->update('update pg_index set indisvalid = false where indexrelid = (select c.oid from pg_class c where c.relname = ? and c.relnamespace = current_schema()::regnamespace)', [$this->indexName()]);
        self::assertFalse($valid());

        $migration->up();
        self::assertTrue($valid(), 'up() built the invalid index again.');

        $migration->down();
        self::assertNotEmpty(preg_grep('/^drop index concurrently /', $statements));
        self::assertNull($valid());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serverEngines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function serverEngine(string $engine): void
    {
        if ($this->testEnvironment()->driver !== $engine) {
            self::markTestSkipped("This case needs {$engine}: run it with bin/test {$engine}.");
        }
    }

    private function createJobsTable(string $engine, string $schema, string $table): void
    {
        $primary = DB::connection(TestEnvironment::PRIMARY);

        if ($engine === 'pgsql') {
            $primary->statement("create table \"{$schema}\".\"{$table}\" (id bigserial primary key, queue varchar(255) not null, payload text not null, attempts smallint not null, reserved_at integer, available_at integer not null, created_at integer not null)");
        } else {
            $primary->statement("create table `{$schema}`.`{$table}` (id bigint unsigned auto_increment primary key, queue varchar(255) not null, payload longtext not null, attempts tinyint unsigned not null, reserved_at int unsigned null, available_at int unsigned not null, created_at int unsigned not null)");
        }
    }

    /**
     * @return list<string>|null the columns of the index, or null when it does not exist
     */
    private function indexColumns(string $engine, string $schema, string $table, string $index): ?array
    {
        $primary = DB::connection(TestEnvironment::PRIMARY);

        $columns = $engine === 'pgsql'
            ? $primary->select('select a.attname as name from pg_index i join pg_class ic on ic.oid = i.indexrelid join pg_namespace n on n.oid = ic.relnamespace join pg_class t on t.oid = i.indrelid join lateral unnest(i.indkey) with ordinality k(attnum, ord) on true join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum where n.nspname = ? and t.relname = ? and ic.relname = ? order by k.ord', [$schema, $table, $index])
            : $primary->select('select column_name as name from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? order by seq_in_index', [$schema, $table, $index]);

        return $columns === [] ? null : array_map(static fn (object $row): string => (string) $row->name, $columns);
    }

    private function copyConnection(string $name, array $changes): void
    {
        $this->app['config']->set("database.connections.{$name}", array_replace((array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY), $changes));
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require MonitoringModule::source();

        return $migration;
    }

    private function indexName(): string
    {
        return IndexName::for(DB::connection(TestEnvironment::PRIMARY), 'jobs', 'orrery_backlog');
    }

    private function hasBacklogIndex(): bool
    {
        return DB::connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->hasIndex('jobs', $this->indexName());
    }

    /**
     * @return array{name: string, columns: list<string>, unique: bool}|null
     */
    private function backlogIndex(): ?array
    {
        foreach (DB::connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->getIndexes('jobs') as $index) {
            if (strtolower((string) $index['name']) === strtolower($this->indexName())) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function indexNames(): array
    {
        $names = array_map(static fn (array $index): string => strtolower((string) $index['name']), DB::connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->getIndexes('jobs'));
        sort($names);

        return $names;
    }
}
