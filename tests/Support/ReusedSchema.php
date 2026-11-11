<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Justrau\Orrery\Database\Schema\PackageSchema;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Throwable;

/**
 * The schema of the reuse mode (ORRERY_TEST_SCHEMA=reuse, bin/test --schema=reuse).
 *
 * The fresh mode migrates before every test and rolls back after it. The
 * reuse mode migrates once per PHPUnit process and keeps the schema. After
 * each test it:
 *
 * 1. compares a fingerprint of the schema (tables, views, columns, indexes,
 *    on SQLite also the triggers, and on Postgres also the schemas and the
 *    sequences) with the fingerprint of the migrated state. If it differs,
 *    it drops every table, forgets the schema (the next test migrates
 *    again) and fails the test with a message that names #[FreshSchema];
 * 2. empties every table of the test databases except the migrations table,
 *    and resets the auto-increment counters: DELETE and ALTER TABLE ...
 *    AUTO_INCREMENT = 1 on MySQL, DELETE and ALTER SEQUENCE ... RESTART in
 *    one transaction on Postgres (TRUNCATE ... CASCADE when a delete fails),
 *    DELETE and sqlite_sequence in one transaction on SQLite. Only the
 *    tables that have rows or a used counter are reset;
 * 3. asserts that every table is empty and that no counter is used.
 *
 * The cost of one check is one PDO and one statement per test database: the
 * statement reads the fingerprint and, for each table of the migrated state,
 * whether it has rows, and the used counters. Only when that read finds rows
 * or a used counter does the reset run, followed by one more read of the
 * rows and the counters for the assertion. The values of a read are used
 * only inside the check that made it, and only after its fingerprint
 * matched. Across tests, the class keeps only the migrated fingerprint, the
 * quoted table names of the migrated state and the SQL text built from them.
 * When the statement fails because a table of the migrated state is gone,
 * the fingerprint alone is read again and names the change.
 *
 * The test databases are the database of testbench and, in separate mode,
 * the database of testbench_mirror: every table in them, whatever its prefix.
 * The class works through its own PDOs, built from the TestEnvironment
 * configuration (the endpoints that the harness proved for the test), so a
 * test's own connections, fake PDOs or redirects do not reach it. Each
 * operation opens one PDO per test database and closes them at its end.
 *
 * The schema also depends on the configuration at migration time: the
 * publication fence migration creates one table for each database queue
 * connection, and the package migration uses orrery.database. So the test
 * case gives prepare() a key of that configuration, and a test with another
 * key gets a new migration.
 *
 * In-memory SQLite cannot keep a schema across tests (each test has a new
 * database), so it runs only the fresh mode.
 *
 * The limit of the guard:
 * Reuse compares the captured schema properties after each test. It does not
 * trace DDL or prove that no DDL ran. Every test that changes schema or
 * database settings must use FreshSchema, including changes that the
 * fingerprint does not capture (for example, a MySQL table engine or default
 * collation, a Postgres column collation, a view body, MySQL and Postgres
 * triggers, functions, database settings). A temporary change that is fully
 * restored can pass the fingerprint.
 */
final class ReusedSchema
{
    use PdoFromConfig;

    public const VARIABLE = 'ORRERY_TEST_SCHEMA';

    public const FRESH = 'fresh';

    public const REUSE = 'reuse';

    /** Seconds that one statement of this class waits for a lock before it fails. */
    public const LOCK_WAIT_SECONDS = 30;

    /** The number of values that each kind of fingerprint row has, per driver. */
    private const ARITY = [
        'mysql' => ['table' => 2, 'column' => 8, 'index' => 6],
        'pgsql' => ['schema' => 1, 'relation' => 3, 'column' => 7, 'index' => 4],
        'sqlite' => ['object' => 4],
    ];

    /** The value columns of one statement row, per driver (the widest kind). */
    private const WIDTH = ['mysql' => 8, 'pgsql' => 7, 'sqlite' => 4];

    private static ?self $instance = null;

    /** The configuration key of the migrated schema, or null when no schema is kept. */
    private ?string $key = null;

    /** @var list<string>|null the fingerprint of the migrated state */
    private ?array $fingerprint = null;

    /**
     * Per database label: the quoted tables of the migrated state that the
     * reset empties, and the SQL text of the state read (the rows of those
     * tables and the used counters). Built when the schema is migrated.
     *
     * @var array<string, array{tables: list<string>, sql: string}>
     */
    private array $plans = [];

    private function __construct(private readonly TestEnvironment $env) {}

    /**
     * The schema mode: fresh (the default) or reuse.
     *
     * @throws HarnessRefusal
     */
    public static function mode(TestEnvironment $env): string
    {
        $value = getenv(self::VARIABLE);
        $mode = is_string($value) && $value !== '' ? $value : self::FRESH;

        if (! in_array($mode, [self::FRESH, self::REUSE], true)) {
            throw new HarnessRefusal(self::VARIABLE." must be fresh or reuse, not '{$mode}'.");
        }

        if ($mode === self::REUSE && $env->inMemory()) {
            throw new HarnessRefusal(
                self::VARIABLE.'=reuse needs a database that lives longer than one test: a SQLite file (bin/test sqlite --sqlite-file), MySQL or Postgres. '
                .'In-memory SQLite runs only the fresh mode.'
            );
        }

        return $mode;
    }

    /**
     * The one kept schema of this process.
     *
     * @throws HarnessRefusal when the configuration of the process changed
     */
    public static function for(TestEnvironment $env): self
    {
        if (self::$instance !== null && self::$instance->env->describe() !== $env->describe()) {
            throw new HarnessRefusal('The test configuration changed inside one PHPUnit process ('.self::$instance->env->describe().' before, '.$env->describe().' now). The reuse mode keeps one schema per process.');
        }

        return self::$instance ??= new self($env);
    }

    /**
     * A separate instance for the tests of this class. It never replaces the
     * kept schema of the process.
     */
    public static function forTesting(TestEnvironment $env): self
    {
        return new self($env);
    }

    /**
     * Whether the kept schema was migrated with this configuration key.
     */
    public function matches(string $key): bool
    {
        return $this->key === $key && $this->fingerprint !== null;
    }

    public function kept(): bool
    {
        return $this->key !== null;
    }

    /**
     * Makes a kept schema ready for a test: migrates when no schema is kept
     * or when the key differs. $migrate runs the migrations on empty
     * databases.
     *
     * @param  callable(): void  $migrate
     */
    public function prepare(string $key, callable $migrate): void
    {
        if ($this->matches($key)) {
            return;
        }

        $this->discard();

        try {
            $migrate();
            $this->withPdos(function (array $pdos): void {
                $lines = [];

                foreach ($this->databases() as $database) {
                    $pdo = $pdos[$database['label']];
                    $rows = $this->rows($pdo, $this->fingerprintSql());
                    array_push($lines, ...$this->lines($database['label'], $rows));
                    $this->plans[$database['label']] = $this->plan($pdo, $database['keep'], $rows);
                }

                sort($lines);
                $this->fingerprint = $lines;

                // A migration that writes rows would lose them at the first reset.
                foreach ($this->databases() as $database) {
                    $this->assertClean($database, $this->state($pdos[$database['label']], $database['label']), 'after the migration');
                }
            });
            $this->key = $key;
        } catch (Throwable $failure) {
            $this->forget();

            throw $failure;
        }
    }

    /**
     * Drops every table, view and (on Postgres) every schema other than
     * public in the test databases, and forgets the kept schema. The fresh
     * path then starts from empty databases.
     */
    public function discard(): void
    {
        $this->forget();

        $this->withPdos(function (array $pdos): void {
            foreach ($pdos as $pdo) {
                $this->dropAll($pdo);
            }
        });
    }

    /**
     * The guard of the fresh mode after a test (on a SQLite file or a
     * server; in-memory databases end with their test). The rollback must
     * leave each test database as the migration found it: no table, view,
     * sequence or schema other than the empty migrations table. A test whose
     * rollback missed a database (for example, it changed orrery.database,
     * so the package migration's down() ran on another connection) would
     * make the migration of every later test fail with "table already
     * exists". So the guard drops every object of the test databases and
     * fails this test with the objects that it found. The next test then
     * starts from empty databases.
     *
     * @throws AssertionFailedError when the test left objects (they are dropped)
     */
    public static function afterFreshTest(TestEnvironment $env, string $test): void
    {
        $left = (new self($env))->dropLeftovers();

        if ($left !== []) {
            throw new AssertionFailedError(sprintf(
                "The fresh schema rollback after %s left objects in the test databases, so the harness dropped them and the next test starts from empty databases.\n"
                ."A test that changes the configuration that the migrations read (orrery.database, the connections) must restore it before its teardown.\n"
                .'Left: %s',
                $test,
                self::summary($left),
            ));
        }
    }

    /**
     * The check of the fresh mode before a migration, when no guard proved
     * the databases empty after the previous test (the first test of the
     * process, or a teardown that did not finish). Objects that are there
     * are dropped, and this test refuses with them.
     *
     * @throws HarnessRefusal when the databases were not empty (they are dropped)
     */
    public static function beforeFreshTest(TestEnvironment $env, string $test): void
    {
        $left = (new self($env))->dropLeftovers();

        if ($left !== []) {
            throw new HarnessRefusal(sprintf(
                'The test databases were not empty before the fresh migration of %s (an earlier test or run left them, and no guard dropped them). '
                .'The harness dropped them, so the next test starts from empty databases. Left: %s',
                $test,
                self::summary($left),
            ));
        }
    }

    /**
     * The objects of the test databases other than the empty migrations
     * table, as "<label> <kind> <name>" lines. When there are any, every
     * object of the test databases is dropped.
     *
     * @return list<string>
     */
    private function dropLeftovers(): array
    {
        if ($this->env->inMemory()) {
            return [];
        }

        return $this->withPdos(function (array $pdos): array {
            $left = [];

            foreach ($this->databases(false) as $database) {
                array_push($left, ...array_map(
                    static fn (string $object): string => $database['label'].' '.$object,
                    $this->leftovers($pdos[$database['label']], $database['keep']),
                ));
            }

            if ($left !== []) {
                foreach ($pdos as $pdo) {
                    $this->dropAll($pdo);
                }
            }

            return $left;
        }, false);
    }

    /**
     * The objects of one database other than the migrations table $keep (on
     * Postgres also its id sequence and the public schema), and a row of
     * $keep when it has one.
     *
     * @return list<string>
     */
    private function leftovers(PDO $pdo, string $keep): array
    {
        $objects = match ($this->dialect()) {
            'mysql' => $pdo->query('SELECT TABLE_TYPE, TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_NUM),
            'pgsql' => $pdo->query("SELECT 'schema'::text, nspname::text FROM pg_namespace WHERE nspname <> 'public' AND ".self::postgresSchemas('nspname')
                ." UNION ALL SELECT 'relation '::text || c.relkind::text, n.nspname::text || '.' || c.relname::text FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace"
                ." WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S') AND ".self::postgresSchemas('n.nspname'))->fetchAll(PDO::FETCH_NUM),
            default => $pdo->query("SELECT type, name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'")->fetchAll(PDO::FETCH_NUM),
        };
        $allowed = $this->dialect() === 'pgsql' ? ['public.'.$keep, 'public.'.$keep.'_id_seq'] : [$keep];
        $left = [];
        $hasKeep = false;

        foreach ($objects as [$kind, $name]) {
            if (in_array((string) $name, $allowed, true)) {
                $hasKeep = $hasKeep || (string) $name === $allowed[0];

                continue;
            }

            $left[] = strtolower((string) $kind).' '.$name;
        }

        if ($hasKeep && $pdo->query('SELECT 1 FROM '.$this->quote($keep).' LIMIT 1')->fetchColumn() !== false) {
            $left[] = 'rows in '.$keep;
        }

        sort($left);

        return $left;
    }

    /**
     * The guard and the reset after a test that used the kept schema (see
     * the class docblock). Every failure forgets the kept schema, so the
     * next test migrates again.
     *
     * @throws AssertionFailedError when the schema changed or a table is not empty after the reset
     */
    public function afterTest(string $test): void
    {
        if ($this->fingerprint === null) {
            return;
        }

        try {
            $changed = $this->withPdos(fn (array $pdos): ?array => $this->checkAndReset($pdos));
        } catch (Throwable $failure) {
            $this->forget();

            throw $failure;
        }

        if ($changed !== null) {
            [$removed, $added] = $changed;
            $this->discard();

            throw new AssertionFailedError(sprintf(
                "The schema after %s differs from the migrated state, so the harness dropped it and the next test migrates again.\n"
                ."A test that runs DDL or changes the schema needs the attribute #[%s] on its method or class; it then gets a fresh migration in both schema modes.\n"
                ."Removed: %s\nAdded: %s",
                $test,
                FreshSchema::class,
                self::summary($removed),
                self::summary($added),
            ));
        }
    }

    /**
     * One read per database (the fingerprint and the state), then the reset
     * and its assertion where the state is not clean.
     *
     * @param  array<string, PDO>  $pdos
     * @return array{0: list<string>, 1: list<string>}|null the removed and added fingerprint lines, or null when the schema matched
     */
    private function checkAndReset(array $pdos): ?array
    {
        $lines = [];
        $states = [];
        $errors = [];

        foreach ($this->databases() as $database) {
            $label = $database['label'];
            $pdo = $pdos[$label];
            $plan = $this->plans[$label] ?? null;
            $combined = $plan !== null && $plan['sql'] !== '';
            $sql = $combined ? $this->fingerprintSql().' UNION ALL '.$plan['sql'] : $this->fingerprintSql();

            try {
                $rows = HarnessTiming::measure('reuse.fingerprint', fn (): array => $this->rows($pdo, $sql));
            } catch (Throwable $failure) {
                // A table of the migrated state is gone: the fingerprint
                // alone names the change. Else the failure is the error.
                $rows = $this->rows($pdo, $this->fingerprintSql());
                $errors[] = $failure;
            }

            array_push($lines, ...$this->lines($label, $rows));
            $states[$label] = $combined ? $this->stateOf($rows, $plan) : null;
        }

        sort($lines);
        $migrated = $this->fingerprint ?? [];

        if ($lines !== $migrated) {
            return [array_values(array_diff($migrated, $lines)), array_values(array_diff($lines, $migrated))];
        }

        if ($errors !== []) {
            throw $errors[0];
        }

        foreach ($this->databases() as $database) {
            $label = $database['label'];
            $state = $states[$label] ?? HarnessTiming::measure('reuse.state', fn (): array => $this->state($pdos[$label], $label));

            if ($state['filled'] === [] && $state['counters'] === []) {
                // The read of this check found the database clean.
                continue;
            }

            HarnessTiming::measure('reuse.reset', fn () => $this->reset($pdos[$label], $state));
            HarnessTiming::measure('reuse.assert_empty', fn () => $this->assertClean($database, $this->state($pdos[$label], $label), 'after the reset'));
        }

        return null;
    }

    private function forget(): void
    {
        $this->key = null;
        $this->fingerprint = null;
        $this->plans = [];
    }

    /**
     * @param  list<string>  $lines
     */
    private static function summary(array $lines): string
    {
        if ($lines === []) {
            return '(nothing)';
        }

        $shown = array_slice($lines, 0, 12);
        $more = count($lines) - count($shown);

        return implode('; ', $shown).($more > 0 ? "; and {$more} more" : '');
    }

    /**
     * The test databases: the testbench database and, in separate mode, the
     * mirror database, each with the name of its migrations table.
     *
     * @return list<array{label: string, config: array<string, mixed>, keep: string}>
     */
    private function databases(bool $sibling = true): array
    {
        $databases = [['label' => 'primary', 'config' => $this->env->queueConnection(), 'keep' => $this->env->primaryPrefix().'migrations']];
        $mirror = $this->env->mirrorConnection();

        if ($mirror !== null) {
            $databases[] = ['label' => 'mirror', 'config' => $mirror, 'keep' => $this->env->mirrorPrefix().'migrations'];
        }

        // The sibling SQLite mirror file in same mode on a SQLite file:
        // a third database, reset and fingerprinted like the others. It has
        // no migrations table.
        // The fresh guard leaves it out: the teardown deletes the file.
        $siblingConnection = $sibling ? $this->env->siblingConnection() : null;

        if ($siblingConnection !== null) {
            if (! is_file((string) $siblingConnection['database'])) {
                touch((string) $siblingConnection['database']);
            }

            $databases[] = ['label' => 'sibling', 'config' => $siblingConnection, 'keep' => $this->env->mirrorPrefix().'migrations'];
        }

        return $databases;
    }

    /**
     * Runs $work with one new PDO per test database, by label, and closes
     * them at its end.
     *
     * @template T
     *
     * @param  callable(array<string, PDO>): T  $work
     * @param  bool  $sibling  whether the sibling mirror file is one of the databases
     * @return T
     */
    private function withPdos(callable $work, bool $sibling = true): mixed
    {
        $pdos = [];

        try {
            foreach ($this->databases($sibling) as $database) {
                $pdos[$database['label']] = HarnessTiming::measure('reuse.connect', fn (): PDO => $this->connect($database['config']));
            }

            return $work($pdos);
        } finally {
            $pdos = [];
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    /**
     * The SQL dialect of the engine: MariaDB reads and resets as MySQL.
     */
    private function dialect(): string
    {
        return $this->env->isMysqlFamily() ? 'mysql' : $this->env->driver;
    }

    private function connect(array $config): PDO
    {
        [$dsn, $username, $password, $options] = self::pdoArguments($config);
        $options[PDO::ATTR_TIMEOUT] = $this->env->isServer() ? TestEnvironment::CONNECT_TIMEOUT : self::LOCK_WAIT_SECONDS;
        $pdo = new PDO($dsn, $username, $password, $options);

        match ($this->dialect()) {
            // The counters of information_schema.TABLES come from the storage
            // engine, not from the statistics cache. (A fingerprint read with
            // the cached statistics and a second read for the counters took
            // as long as this one statement: 8.3 against 8.7 ms per check,
            // MySQL 8.4, load 9-10, 2026-11-08.)
            // MariaDB has no statistics cache to turn off.
            'mysql' => $pdo->exec('SET '.($this->env->driver === 'mariadb' ? '' : 'SESSION information_schema_stats_expiry = 0, ')
                .'SESSION lock_wait_timeout = '.self::LOCK_WAIT_SECONDS
                .', SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_SECONDS
                .', SESSION foreign_key_checks = 0'),
            'pgsql' => $pdo->exec("SET lock_timeout = '".self::LOCK_WAIT_SECONDS."s'"),
            default => $pdo->exec('PRAGMA foreign_keys = OFF'),
        };

        return $pdo;
    }

    /**
     * @return list<list<string|null>> each row: the kind, then the values
     */
    private function rows(PDO $pdo, string $sql): array
    {
        $rows = [];

        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as $row) {
            $rows[] = array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, array_values($row));
        }

        return $rows;
    }

    /**
     * The one statement that reads the fingerprint of a database: one row
     * per table and view, column and index (on SQLite also each trigger; on
     * Postgres also each schema and sequence).
     */
    private function fingerprintSql(): string
    {
        return match ($this->dialect()) {
            'mysql' => self::mysqlFingerprintSql(),
            'pgsql' => self::postgresFingerprintSql(),
            default => "SELECT 'object', type, name, tbl_name, COALESCE(sql, '') FROM sqlite_master WHERE name NOT LIKE 'sqlite\\_%' ESCAPE '\\'",
        };
    }

    private static function mysqlFingerprintSql(): string
    {
        // One character set and collation for every value, so the parts of
        // the union combine (information_schema mixes collations).
        $text = static fn (string ...$columns): string => implode(', ', array_map(
            static fn (string $column): string => $column === 'NULL' ? 'NULL' : "CONVERT({$column} USING utf8mb4) COLLATE utf8mb4_bin",
            $columns,
        ));

        return "SELECT 'table', ".$text('TABLE_NAME', 'TABLE_TYPE', 'NULL', 'NULL', 'NULL', 'NULL', 'NULL', 'NULL').' FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            ." UNION ALL SELECT 'column', ".$text('TABLE_NAME', 'ORDINAL_POSITION', 'COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA', 'COLLATION_NAME').' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            ." UNION ALL SELECT 'index', ".$text('TABLE_NAME', 'INDEX_NAME', 'SEQ_IN_INDEX', 'NON_UNIQUE', 'COLUMN_NAME', 'SUB_PART', 'NULL', 'NULL').' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()';
    }

    private static function postgresFingerprintSql(): string
    {
        $null = static fn (int $count): string => str_repeat(', NULL::text', $count);

        return "SELECT 'schema'::text, nspname::text".$null(6).' FROM pg_namespace WHERE '.self::postgresSchemas('nspname')
            ." UNION ALL SELECT 'relation', n.nspname::text, c.relname::text, c.relkind::text".$null(4)
            .' FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            ."WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S') AND ".self::postgresSchemas('n.nspname')
            ." UNION ALL SELECT 'column', n.nspname::text, c.relname::text, a.attnum::text, a.attname::text, format_type(a.atttypid, a.atttypmod), a.attnotnull::text, pg_get_expr(d.adbin, d.adrelid)"
            .' FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace '
            .'LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum '
            ."WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f') AND a.attnum > 0 AND NOT a.attisdropped AND ".self::postgresSchemas('n.nspname')
            ." UNION ALL SELECT 'index', schemaname::text, tablename::text, indexname::text, indexdef".$null(3).' FROM pg_indexes WHERE '.self::postgresSchemas('schemaname');
    }

    private static function postgresSchemas(string $column): string
    {
        return "{$column} NOT IN ('pg_catalog', 'information_schema', 'pg_toast') AND {$column} NOT LIKE 'pg\\_temp\\_%' AND {$column} NOT LIKE 'pg\\_toast\\_temp\\_%'";
    }

    /**
     * The fingerprint lines of the rows of one database (the state rows are
     * left out).
     *
     * @param  list<list<string|null>>  $rows
     * @return list<string>
     */
    private function lines(string $label, array $rows): array
    {
        $arity = self::ARITY[$this->dialect()];
        $lines = [];

        foreach ($rows as $row) {
            $kind = (string) $row[0];

            if (! isset($arity[$kind])) {
                continue;
            }

            $values = array_slice($row, 1, $arity[$kind]);
            $lines[] = $label.' '.$kind.' '.implode(' | ', array_map(static fn (?string $value): string => $value ?? 'NULL', $values));
        }

        return $lines;
    }

    /**
     * The reset plan of one database from the rows of its migrated
     * fingerprint: its base tables without the migrations table and the
     * package's schema marker (both keep their rows), and the SQL text of
     * the state read.
     *
     * @param  list<list<string|null>>  $rows
     * @return array{tables: list<string>, sql: string}
     */
    private function plan(PDO $pdo, string $keep, array $rows): array
    {
        $driver = $this->dialect();
        $marker = $this->env->mirrorPrefix().PackageSchema::MARKER;
        $tables = [];

        foreach ($rows as $row) {
            $table = match ($driver) {
                'mysql' => $row[0] === 'table' && $row[2] === 'BASE TABLE' && ! in_array($row[1], [$keep, $marker], true) ? $this->quote((string) $row[1]) : null,
                'pgsql' => $row[0] === 'relation' && in_array($row[3], ['r', 'p'], true) && ! ($row[1] === 'public' && in_array($row[2], [$keep, $marker], true))
                    ? $this->quote((string) $row[1]).'.'.$this->quote((string) $row[2])
                    : null,
                default => $row[0] === 'object' && $row[1] === 'table' && ! in_array($row[2], [$keep, $marker], true) ? $this->quote((string) $row[2]) : null,
            };

            if ($table !== null) {
                $tables[] = $table;
            }
        }

        sort($tables);
        $width = self::WIDTH[$driver];
        $pad = static fn (int $used): string => str_repeat($driver === 'pgsql' ? ', NULL::text' : ', NULL', $width - $used);
        $literal = static fn (string $value): string => "'".str_replace("'", "''", $value)."'";
        $from = $driver === 'mysql' ? ' FROM DUAL' : '';
        $parts = [];

        foreach ($tables as $index => $table) {
            $parts[] = "SELECT 'filled', '{$index}'".$pad(1)."{$from} WHERE EXISTS (SELECT 1 FROM {$table})";
        }

        // The used counters, without the counter of the migrations table.
        $counters = match ($driver) {
            'mysql' => "SELECT 'counter', CONVERT(TABLE_NAME USING utf8mb4) COLLATE utf8mb4_bin".$pad(1)
                ." FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND AUTO_INCREMENT > 1 AND TABLE_NAME <> ".$literal($keep),
            'pgsql' => "SELECT 'counter', schemaname::text, sequencename::text".$pad(2)
                .' FROM pg_sequences WHERE last_value IS NOT NULL AND '.self::postgresSchemas('schemaname')
                ." AND NOT (schemaname = 'public' AND sequencename = ".$literal($keep.'_id_seq').')',
            default => $this->sqliteSequenceExists($pdo)
                ? "SELECT 'counter', name".$pad(1).' FROM sqlite_sequence WHERE seq > 0 AND name <> '.$literal($keep)
                : null,
        };

        if ($counters !== null) {
            $parts[] = $counters;
        }

        return ['tables' => $tables, 'sql' => implode(' UNION ALL ', $parts)];
    }

    /**
     * The tables with rows (quoted) and the used counters (quoted: a MySQL
     * table, a Postgres schema.sequence or a SQLite table) in the rows of a
     * read.
     *
     * @param  list<list<string|null>>  $rows
     * @param  array{tables: list<string>, sql: string}|null  $plan
     * @return array{filled: list<string>, counters: list<string>}
     */
    private function stateOf(array $rows, ?array $plan): array
    {
        $state = ['filled' => [], 'counters' => []];

        foreach ($rows as $row) {
            if ($row[0] === 'filled') {
                $state['filled'][] = $plan['tables'][(int) $row[1]] ?? (string) $row[1];
            } elseif ($row[0] === 'counter') {
                $state['counters'][] = $this->dialect() === 'pgsql'
                    ? $this->quote((string) $row[1]).'.'.$this->quote((string) $row[2])
                    : $this->quote((string) $row[1]);
            }
        }

        return $state;
    }

    /**
     * A new read of the state of one database.
     *
     * @return array{filled: list<string>, counters: list<string>}
     */
    private function state(PDO $pdo, string $label): array
    {
        $plan = $this->plans[$label] ?? null;

        if ($plan === null || $plan['sql'] === '') {
            return ['filled' => [], 'counters' => []];
        }

        return $this->stateOf($this->rows($pdo, $plan['sql']), $plan);
    }

    private function quote(string $name): string
    {
        return $this->dialect() === 'mysql'
            ? '`'.str_replace('`', '``', $name).'`'
            : '"'.str_replace('"', '""', $name).'"';
    }

    private function sqliteSequenceExists(PDO $pdo): bool
    {
        return $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->fetchColumn() > 0;
    }

    /**
     * Empties the tables with rows and resets the used counters of one
     * database.
     *
     * @param  array{filled: list<string>, counters: list<string>}  $state
     */
    private function reset(PDO $pdo, array $state): void
    {
        match ($this->dialect()) {
            'mysql' => $this->resetMysql($pdo, $state['filled'], $state['counters']),
            'pgsql' => $this->resetPostgres($pdo, $state['filled'], $state['counters']),
            default => $this->resetSqlite($pdo, $state['filled'], $state['counters']),
        };
    }

    /**
     * DELETE in one transaction, then ALTER TABLE ... AUTO_INCREMENT = 1 for
     * each used counter (measured on MySQL 8.4 on 2026-11-07: about 35% less
     * time than a TRUNCATE, which is DDL, of each table).
     *
     * @param  list<string>  $filled
     * @param  list<string>  $counters  quoted table names
     */
    private function resetMysql(PDO $pdo, array $filled, array $counters): void
    {
        if ($filled !== []) {
            $pdo->beginTransaction();

            try {
                foreach ($filled as $table) {
                    $pdo->exec("DELETE FROM {$table}");
                }

                $pdo->commit();
            } catch (Throwable $failure) {
                $pdo->rollBack();

                throw $failure;
            }
        }

        // A used counter of an empty table restarts at 1 (InnoDB sets it to
        // the largest id plus 1). A table that the delete emptied keeps its
        // counter, and the read found that counter used too.
        foreach ($counters as $table) {
            $pdo->exec("ALTER TABLE {$table} AUTO_INCREMENT = 1");
        }
    }

    /**
     * DELETE and ALTER SEQUENCE ... RESTART in one transaction (measured on
     * Postgres 18 on 2026-11-07: about a third of the time of a TRUNCATE ...
     * RESTART IDENTITY of the same tables).
     * When a delete fails (a foreign key between the tables), the
     * transaction rolls back and TRUNCATE ... RESTART IDENTITY CASCADE runs.
     *
     * @param  list<string>  $filled
     * @param  list<string>  $counters  quoted schema.sequence names
     */
    private function resetPostgres(PDO $pdo, array $filled, array $counters): void
    {
        $run = static function (PDO $pdo, callable $empty) use ($counters): void {
            $pdo->beginTransaction();

            try {
                $empty();

                // The sequences that the read found used, also those of a
                // table that the test emptied itself.
                foreach ($counters as $sequence) {
                    $pdo->exec("ALTER SEQUENCE {$sequence} RESTART");
                }

                $pdo->commit();
            } catch (Throwable $failure) {
                $pdo->rollBack();

                throw $failure;
            }
        };

        try {
            $run($pdo, static function () use ($pdo, $filled): void {
                foreach ($filled as $table) {
                    $pdo->exec("DELETE FROM {$table}");
                }
            });
        } catch (Throwable $failure) {
            if ($filled === []) {
                throw $failure;
            }

            $run($pdo, static fn () => $pdo->exec('TRUNCATE TABLE '.implode(', ', $filled).' RESTART IDENTITY CASCADE'));
        }
    }

    /**
     * @param  list<string>  $filled
     * @param  list<string>  $counters  quoted table names
     */
    private function resetSqlite(PDO $pdo, array $filled, array $counters): void
    {
        // One write transaction for all deletes: one commit instead of one per table.
        $pdo->exec('BEGIN IMMEDIATE');

        try {
            foreach ($filled as $table) {
                $pdo->exec("DELETE FROM {$table}");
            }

            if ($counters !== []) {
                $names = array_map(static fn (string $quoted): string => "'".str_replace("'", "''", str_replace('""', '"', substr($quoted, 1, -1)))."'", $counters);
                $pdo->exec('DELETE FROM sqlite_sequence WHERE name IN ('.implode(', ', $names).')');
            }

            $pdo->exec('COMMIT');
        } catch (Throwable $failure) {
            $pdo->exec('ROLLBACK');

            throw $failure;
        }
    }

    /**
     * @param  array{label: string, config: array<string, mixed>, keep: string}  $database
     * @param  array{filled: list<string>, counters: list<string>}  $state
     *
     * @throws RuntimeException when a table has rows or a counter is used
     */
    private function assertClean(array $database, array $state, string $when): void
    {
        if ($state['filled'] !== [] || $state['counters'] !== []) {
            throw new RuntimeException(sprintf(
                'The reuse schema is not clean %s in the %s database: tables with rows [%s], used counters [%s].',
                $when,
                $database['label'],
                implode(', ', $state['filled']),
                implode(', ', $state['counters']),
            ));
        }
    }

    /**
     * Drops every table and view, and on Postgres every schema other than
     * public (with its objects) and every sequence left in public.
     */
    private function dropAll(PDO $pdo): void
    {
        if ($this->dialect() === 'mysql') {
            $kinds = ['VIEW' => [], 'TABLE' => []];

            foreach ($pdo->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
                $kinds[$type === 'VIEW' ? 'VIEW' : 'TABLE'][] = $this->quote((string) $name);
            }

            foreach ($kinds as $keyword => $names) {
                if ($names !== []) {
                    $pdo->exec("DROP {$keyword} IF EXISTS ".implode(', ', $names));
                }
            }

            return;
        }

        if ($this->dialect() === 'pgsql') {
            $schemas = $pdo->query("SELECT nspname FROM pg_namespace WHERE nspname <> 'public' AND ".self::postgresSchemas('nspname'))->fetchAll(PDO::FETCH_COLUMN);

            if ($schemas !== []) {
                $pdo->exec('DROP SCHEMA '.implode(', ', array_map(fn (mixed $schema): string => $this->quote((string) $schema), $schemas)).' CASCADE');
            }

            $keywords = ['v' => 'VIEW', 'm' => 'MATERIALIZED VIEW', 'r' => 'TABLE', 'p' => 'TABLE', 'f' => 'FOREIGN TABLE', 'S' => 'SEQUENCE'];
            $kinds = [];

            foreach ($pdo->query("SELECT c.relkind, c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('v', 'm', 'r', 'p', 'f', 'S')")->fetchAll(PDO::FETCH_NUM) as [$kind, $name]) {
                $kinds[$keywords[(string) $kind]][] = $this->quote('public').'.'.$this->quote((string) $name);
            }

            // Views first, the sequences last: CASCADE can already have
            // dropped an object of a later kind, so each drop has IF EXISTS.
            foreach (array_unique(array_values($keywords)) as $keyword) {
                if (($kinds[$keyword] ?? []) !== []) {
                    $pdo->exec("DROP {$keyword} IF EXISTS ".implode(', ', $kinds[$keyword]).' CASCADE');
                }
            }

            return;
        }

        foreach ($pdo->query("SELECT type, name FROM sqlite_master WHERE type IN ('view', 'table') AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY type DESC")->fetchAll(PDO::FETCH_NUM) as [$type, $name]) {
            $pdo->exec(($type === 'view' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ').$this->quote((string) $name));
        }

        if ($this->sqliteSequenceExists($pdo)) {
            $pdo->exec('DELETE FROM sqlite_sequence');
        }
    }
}
