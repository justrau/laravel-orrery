<?php

namespace Justrau\Orrery\Tests\Feature\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Fluent;
use Justrau\Orrery\Database\Schema\InlineKeys;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The DDL statements of PackageSchema::create() (task S-F): on MySQL and
 * MariaDB each table is one `create table` with its keys inside; on the other
 * engines the statements are Laravel's, unchanged. The connections never
 * connect: `pretend()` only collects the statements, so this runs in the fast
 * suite on every engine. SchemaTest proves on a real server that the two
 * forms give the same tables.
 */
final class PackageSchemaStatementsTest extends TestCase
{
    /**
     * Rejects: a MySQL create that keeps one `alter table … add index` per key, that drops or
     * renames a key, turns a unique key into an index or the other way round, changes a key's
     * column order, or changes any column or table option of Laravel's `create table`.
     * Still passes: a key clause that MySQL would build differently from the same text in an
     * `alter table` (SchemaTest compares the built tables on a real server).
     */
    #[DataProvider('mysqlFamily')]
    public function test_mysql_creates_each_table_with_its_keys_in_one_statement(string $driver, string $prefix): void
    {
        $connection = self::fakeConnection($driver, $prefix);
        $separate = self::statements($connection, true);
        $inline = self::statements($connection, false);

        $this->assertCount(count(PackageSchema::TABLES), $inline, 'one statement per table');

        $byTable = [];

        foreach ($separate as $sql) {
            $this->assertSame(1, preg_match('/^(create table|alter table) (`[^`]+`) (.*)$/s', $sql, $match), $sql);
            $byTable[$match[2]][] = $match[1] === 'create table' ? $sql : $match[3];
        }

        $this->assertCount(count(PackageSchema::TABLES), $byTable);
        $keys = 0;

        foreach (array_values($byTable) as $position => $parts) {
            $create = array_shift($parts);
            $clauses = array_map(static function (string $clause): string {
                self::assertSame(1, preg_match('/^add ((?:index|unique) `[^`]+`\(.+\))$/s', $clause, $match), "not a plain key: {$clause}");

                return $match[1];
            }, $parts);
            $keys += count($clauses);
            $statement = $inline[$position];

            $this->assertStringStartsWith('create table ', $statement);

            if ($clauses === []) {
                $this->assertSame($create, $statement);

                continue;
            }

            $list = ', '.implode(', ', $clauses);
            $this->assertSame(1, substr_count($statement, $list), "the keys of {$create}");
            // The keys close the column list: Laravel writes the table options after it.
            $this->assertStringContainsString($list.') default character set ', $statement);
            $this->assertSame($create, str_replace($list, '', $statement), 'the table without its keys');
        }

        // The 46 named keys of SchemaTest::INDEXES that are not primary keys (38, the three deferred indexes,
        // the two keys of step 8: orrery_operations `bulk` and orrery_bulk_runs `last_run`, the key of step 9:
        // orrery_incidents `due`, and the keys of step 10: orrery_incidents `notify_due` and orrery_notify_cooldowns `expiry`).
        $this->assertSame(46, $keys);
    }

    /**
     * Rejects: an inline create that builds its Blueprint with `new` instead of from the
     * container (as Builder::create() does), so a host's binding of Blueprint, here one that
     * sets the MyISAM engine, applies to the separate statements but not to the inline ones.
     */
    public function test_the_inline_create_uses_the_bound_blueprint(): void
    {
        $this->app->bind(Blueprint::class, MyIsamBlueprint::class);
        $connection = self::fakeConnection('mysql', 'pfx_');
        $inline = self::statements($connection, false);

        $this->assertCount(count(PackageSchema::TABLES), $inline);

        foreach ($inline as $statement) {
            $this->assertStringEndsWith(' engine = MyISAM', $statement);
        }

        $this->assertSame(
            self::statements($connection, true)[0],
            preg_replace('/, (?:index|unique) `[^`]+`\([^)]*\)/', '', $inline[0]),
            'the inline create of orrery_jobs is the separate one with its keys',
        );
    }

    /**
     * Rejects: a key clause rebuilt by hand instead of taken from the grammar: a grammar whose
     * index statement has a form that cannot go inside `create table` (here a trailing lock
     * option) keeps that statement of its own, while its unique keys go inside with the
     * grammar's own clause (here with a prefix length, which a hand-built clause loses).
     * Still passes: a wrong clause that the grammar itself writes in the plain form.
     */
    public function test_a_key_whose_statement_is_not_plain_stays_separate(): void
    {
        $connection = self::fakeConnection('mysql', 'pfx_');
        $connection->setSchemaGrammar(new LockingIndexGrammar($connection));
        $inline = self::statements($connection, false);
        $creates = array_values(array_filter($inline, static fn (string $sql): bool => str_starts_with($sql, 'create table ')));
        $alters = array_values(array_filter($inline, static fn (string $sql): bool => str_starts_with($sql, 'alter table ')));

        $this->assertCount(count(PackageSchema::TABLES), $creates);
        $this->assertCount(count($inline) - count($creates), $alters);
        // 46 named keys: 5 unique keys inside, 41 indexes left as statements of their own.
        $this->assertCount(41, $alters);

        foreach ($alters as $alter) {
            $this->assertMatchesRegularExpression('/^alter table `[^`]+` add index `[^`]+`\(.+\), lock=none$/s', $alter);
        }

        // The unique clauses inside are the grammar's own, with its prefix length.
        $this->assertSame(5, preg_match_all('/, unique `[^`]+`\([^)]*\(10\)\)/', implode("\n", $creates)));
        $this->assertStringNotContainsString(', index `', implode("\n", $creates));
    }

    /**
     * Rejects: an InlineKeys::create() that, for a `create table` statement of an unknown form
     * (here a temporary table), returns without creating the table instead of running
     * Laravel's own statements.
     */
    public function test_an_unknown_create_statement_gives_laravels_statements(): void
    {
        $connection = self::fakeConnection('mysql', 'pfx_');
        $definition = static function (Blueprint $table): void {
            $table->temporary();
            $table->bigIncrements('id');
            $table->string('name', 64);
            $table->index('name', 'pfx_probe_name');
        };
        $run = static fn (\Closure $create): array => array_column($connection->pretend(static function () use ($create, $connection): void {
            $create($connection->getSchemaBuilder());
        }), 'query');

        $laravel = $run(static fn (Builder $schema) => $schema->create('probe', $definition));

        $this->assertCount(2, $laravel);
        $this->assertStringStartsWith('create temporary table ', $laravel[0]);
        $this->assertSame($laravel, $run(static fn (Builder $schema) => InlineKeys::create($schema, 'probe', $definition)));
    }

    /**
     * Rejects: the inline form on an engine that has no inline index (Postgres builds
     * `create index`, SQLite too), or any other change to their statements.
     */
    #[DataProvider('otherEngines')]
    public function test_other_engines_keep_laravels_statements(string $driver, string $prefix): void
    {
        $connection = self::fakeConnection($driver, $prefix);

        $this->assertSame(self::statements($connection, true), self::statements($connection, false));
        $this->assertGreaterThan(count(PackageSchema::TABLES), count(self::statements($connection, false)));
    }

    /** @return array<string, array{string, string}> */
    public static function mysqlFamily(): array
    {
        return ['mysql' => ['mysql', ''], 'mysql with a prefix' => ['mysql', 'pfx_'], 'mariadb' => ['mariadb', 'pfx_']];
    }

    /** @return array<string, array{string, string}> */
    public static function otherEngines(): array
    {
        return ['pgsql' => ['pgsql', 'pfx_'], 'sqlite' => ['sqlite', '']];
    }

    /**
     * Rejects: an edit of create() or mark() that changes only the MySQL or the
     * Postgres statements. The frozen fixtures of those engines are compared with a build only in
     * a server run (SchemaBaselineTest) and only hashed in the group `scripts`, so such an edit
     * passed `composer test` and `composer test:all`. Here the fake connections of the test
     * harness's configuration (prefix `pfx_`, as the fixtures were captured) pretend create() and
     * mark(), and the statements must be the fixture's, line for line.
     * Still passes: a change that the harness's server configuration would give but these fake
     * connections do not (a server-side collation lookup; a pretending MySQL connection names
     * utf8mb4_0900_bin, as MySQL 8.4 does).
     */
    #[DataProvider('serverEngines')]
    public function test_the_server_fixtures_are_the_statements_of_create_and_mark(string $driver): void
    {
        if (PackageSchema::VERSION !== PackageSchema::BASELINE) {
            self::markTestSkipped('The fixtures hold the baseline only; after the first schema step, SchemaBaselineTest checks the baseline and the upgrade migrations on each engine.');
        }

        $prefix = 'pfx_';
        $pdo = static fn () => throw new \LogicException('The fake connection never connects.');
        $connection = match ($driver) {
            'mysql' => new MySqlConnection($pdo, 'orrery_fake', $prefix, ['driver' => 'mysql', 'prefix' => $prefix, 'prefix_indexes' => true, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true, 'engine' => null, 'name' => 'fake_mysql']),
            default => new PostgresConnection($pdo, 'orrery_fake', $prefix, ['driver' => 'pgsql', 'prefix' => $prefix, 'prefix_indexes' => true, 'charset' => 'utf8', 'search_path' => 'public', 'name' => 'fake_pgsql']),
        };
        $queries = $connection->pretend(static function () use ($connection): void {
            PackageSchema::create($connection->getSchemaBuilder());
            PackageSchema::mark($connection);
        });
        $built = [];

        foreach ($queries as $query) {
            $sql = $query['bindings'] === [] ? $query['query'] : $connection->getQueryGrammar()->substituteBindingsIntoRawSql($query['query'], $connection->prepareBindings($query['bindings']));
            $built[] = str_replace($prefix, '{prefix}', $sql).';';
        }

        $fixture = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/schema-baseline/'.$driver.'.sql');
        $lines = array_values(array_filter(explode("\n", $fixture), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '--')));

        self::assertSame($lines, $built, "create() and mark() on {$driver} differ from the frozen fixture.");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serverEngines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    /** @return list<string> */
    private static function statements(Connection $connection, bool $separate): array
    {
        PackageSchema::useSeparateKeysForTesting($separate);

        try {
            return array_column($connection->pretend(static function () use ($connection): void {
                PackageSchema::create($connection->getSchemaBuilder());
            }), 'query');
        } finally {
            PackageSchema::useSeparateKeysForTesting(false);
        }
    }

    private static function fakeConnection(string $driver, string $prefix): Connection
    {
        // The PDO resolver is never called: pretend() runs no statement.
        $pdo = static fn () => throw new \LogicException('The fake connection never connects.');
        $config = ['driver' => $driver, 'prefix' => $prefix, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'name' => 'fake_'.$driver];

        return match ($driver) {
            'mysql' => new MySqlConnection($pdo, 'orrery_fake', $prefix, $config),
            'mariadb' => new MariaDbConnection($pdo, 'orrery_fake', $prefix, $config),
            'pgsql' => new PostgresConnection($pdo, 'orrery_fake', $prefix, ['driver' => 'pgsql', 'prefix' => $prefix, 'charset' => 'utf8', 'name' => 'fake_pgsql']),
            'sqlite' => new SQLiteConnection($pdo, ':memory:', $prefix, ['driver' => 'sqlite', 'prefix' => $prefix, 'name' => 'fake_sqlite']),
        };
    }
}

/** A host's Blueprint binding: every table it creates uses the MyISAM engine. */
final class MyIsamBlueprint extends Blueprint
{
    public function __construct(Connection $connection, $table, ?\Closure $callback = null)
    {
        parent::__construct($connection, $table, $callback);
        $this->engine = 'MyISAM';
    }
}

/**
 * A grammar whose index statement ends with a lock option, which has no place inside
 * `create table`, and whose unique statement gives its last column a prefix length (a plain clause).
 */
final class LockingIndexGrammar extends MySqlGrammar
{
    /** @param  Fluent<string, mixed>  $command */
    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        // A prefix length on the last column: "… add unique `n`(`a`, `b`(10))".
        return substr(parent::compileUnique($blueprint, $command), 0, -1).'(10))';
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        return parent::compileIndex($blueprint, $command).', lock=none';
    }
}
