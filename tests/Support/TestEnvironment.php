<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Storage\PackageSession;
use LogicException;
use PDO;

/**
 * One complete, validated database configuration for the tests.
 *
 * The parent test case (tests/TestCase.php) and every child process that
 * touches a test database read it from the ORRERY_TEST_* variables and
 * apply it with apply(). The connections that it defines are complete: no
 * value comes from the application's own DB_* variables, so an inherited
 * DB_URL, DB_HOST or ORRERY_CONNECTION can never redirect a test.
 *
 * Connections:
 * - testbench: the authoritative tables (jobs, failed_jobs, job_batches) and,
 *   in same mode, the package tables too. In same mode the table prefix is on
 *   this connection, so every table is prefixed, as in a real prefixed
 *   application.
 * - testbench_mirror: only in separate mode. The package tables, with the
 *   prefix: a second in-memory database, <file>.mirror, or <base>_mirror.
 *
 * The configuration never falls back to SQLite silently: a server variable
 * with the sqlite driver, or a server driver without its variables, is
 * refused.
 *
 * The alt server (bin/test <mysql|pgsql> --alt): with
 * ORRERY_TEST_ALT=1, alt() gives the configuration of the second server of the
 * engine (the Compose profile alt). It is the testbench configuration with
 * only the port changed. Without the flag it is null, whatever the alt port
 * variables say.
 */
final class TestEnvironment
{
    public const DRIVERS = ['sqlite', 'mysql', 'pgsql', 'mariadb'];

    public const PRIMARY = 'testbench';

    public const MIRROR = 'testbench_mirror';

    /** The tests' own connection of the sibling SQLite mirror file. */
    public const SIBLING = 'testbench_sibling';

    public const MEMORY = ':memory:';

    public const HOST = '127.0.0.1';

    public const MARKER = 'orrery_marker';

    public const BASE_PATTERN = '/^orrery_[a-z0-9_]{1,40}$/D';

    public const MIRROR_PATTERN = '/^orrery_[a-z0-9_]{1,47}$/D';

    public const PREFIX_PATTERN = '/^[a-z0-9_]{1,16}$/D';

    public const TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

    public const RUN_ID_PATTERN = '/^run_[0-9]{1,10}_[0-9a-f]{8}$/D';

    public const SYSTEM_DATABASES = ['mysql', 'information_schema', 'performance_schema', 'sys', 'postgres', 'template0', 'template1'];

    public const DEFAULT_PORTS = ['mysql' => 33106, 'pgsql' => 54336, 'mariadb' => 33108];

    public const PORT_VARIABLES = ['mysql' => 'ORRERY_TEST_MYSQL_PORT', 'pgsql' => 'ORRERY_TEST_PGSQL_PORT', 'mariadb' => 'ORRERY_TEST_MARIADB_PORT'];

    /** The published ports of the alt services (docker/testing/compose.yaml, profile alt). MariaDB has no alt server. */
    public const DEFAULT_ALT_PORTS = ['mysql' => 33107, 'pgsql' => 54337];

    public const ALT_PORT_VARIABLES = ['mysql' => 'ORRERY_TEST_MYSQL_ALT_PORT', 'pgsql' => 'ORRERY_TEST_PGSQL_ALT_PORT'];

    public const USERNAMES = ['mysql' => 'root', 'pgsql' => 'postgres', 'mariadb' => 'root'];

    public const DEFAULT_PASSWORD = 'orrery';

    /** The server version that each engine of docker/testing/compose.yaml (and CI) runs: the default of the identity check. */
    public const SERVER_VERSIONS = ['mysql' => '8.4.', 'pgsql' => '18.', 'mariadb' => '12.3.'];

    /**
     * The releases that ORRERY_TEST_SERVER_VERSION may select per engine, the
     * default first. MariaDB 11.8 (the previous LTS release) runs only in its
     * own CI job (.github/workflows/tests.yml, mariadb-previous-lts).
     */
    public const SERVER_VERSION_CHOICES = ['mysql' => ['8.4'], 'pgsql' => ['18'], 'mariadb' => ['12.3', '11.8']];

    /** Seconds before a connection attempt to a server gives up. */
    public const CONNECT_TIMEOUT = 5;

    /**
     * Every variable that fromArray() reads. toProcessEnv() sets or unsets
     * each one, so a child gets exactly this configuration.
     */
    public const VARIABLES = [
        'ORRERY_TEST_DRIVER',
        'ORRERY_TEST_DATABASE',
        'ORRERY_TEST_SQLITE_FILE',
        'ORRERY_TEST_MIRROR',
        'ORRERY_TEST_PREFIX',
        'ORRERY_TEST_MYSQL_PORT',
        'ORRERY_TEST_PGSQL_PORT',
        'ORRERY_TEST_MARIADB_PORT',
        'ORRERY_TEST_PASSWORD',
        'ORRERY_TEST_TOKEN',
        'ORRERY_TEST_RUN_ID',
        'ORRERY_TEST_CI',
        'ORRERY_TEST_ALT',
        'ORRERY_TEST_MYSQL_ALT_PORT',
        'ORRERY_TEST_PGSQL_ALT_PORT',
        'ORRERY_TEST_SQLITE_JOURNAL',
        'ORRERY_TEST_PG_POOLED',
        'ORRERY_TEST_SERVER_VERSION',
        SharedCache::VARIABLE,
        'ORRERY_EXPECT_DRIVER',
        'ORRERY_EXPECT_PHP',
    ];

    /** The journal modes of the configured SQLite files (D18): WAL by default, rollback with ORRERY_TEST_SQLITE_JOURNAL=delete. */
    public const SQLITE_JOURNALS = ['wal', 'delete'];

    /** The busy timeout of every configured SQLite file, in milliseconds (D18). */
    public const SQLITE_BUSY_TIMEOUT_MS = 5000;

    /**
     * @param  string  $driver  sqlite, mysql, pgsql or mariadb
     * @param  string  $database  the base name, ':memory:', or the canonical SQLite file
     * @param  string  $mirror  same or separate
     * @param  string  $prefix  '' or a prefix that matches PREFIX_PATTERN
     * @param  string|null  $serverVersion  the release prefix that the identity check expects (such as '12.3.'); null for SQLite
     */
    private function __construct(
        public readonly string $driver,
        public readonly string $database,
        public readonly string $mirror,
        public readonly string $prefix,
        public readonly ?int $port,
        public readonly ?string $password,
        public readonly ?string $token,
        public readonly ?string $runId,
        public readonly bool $ci,
        public readonly ?string $expectDriver,
        public readonly ?string $expectPhp,
        public readonly ?int $altPort = null,
        public readonly string $sqliteJournal = 'wal',
        public readonly bool $pooled = false,
        public readonly ?string $sharedCache = null,
        public readonly ?string $serverVersion = null,
    ) {}

    /**
     * This configuration with the shared `file` cache store of a test
     * (SharedCache): apply() makes it the default store, and
     * toProcessEnv() hands its directory to each child.
     */
    public function withSharedCache(string $directory): self
    {
        return new self(
            $this->driver, $this->database, $this->mirror, $this->prefix, $this->port, $this->password, $this->token,
            $this->runId, $this->ci, $this->expectDriver, $this->expectPhp, $this->altPort, $this->sqliteJournal, $this->pooled,
            SharedCache::validate($directory), $this->serverVersion,
        );
    }

    public static function fromEnv(): self
    {
        return self::fromArray(getenv());
    }

    /**
     * @param  array<string, mixed>  $env
     *
     * @throws HarnessRefusal
     */
    public static function fromArray(array $env): self
    {
        $value = static function (string $name) use ($env): ?string {
            $raw = $env[$name] ?? null;

            return is_string($raw) && $raw !== '' ? $raw : null;
        };

        $driver = $value('ORRERY_TEST_DRIVER') ?? 'sqlite';

        if (! in_array($driver, self::DRIVERS, true)) {
            throw new HarnessRefusal("ORRERY_TEST_DRIVER must be sqlite, mysql, pgsql or mariadb, not '{$driver}'.");
        }

        $mirror = $value('ORRERY_TEST_MIRROR') ?? 'same';

        if (! in_array($mirror, ['same', 'separate'], true)) {
            throw new HarnessRefusal("ORRERY_TEST_MIRROR must be same or separate, not '{$mirror}'.");
        }

        $prefix = $value('ORRERY_TEST_PREFIX') ?? '';

        if ($prefix !== '' && preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new HarnessRefusal("ORRERY_TEST_PREFIX must match ^[a-z0-9_]{1,16}$, not '{$prefix}'.");
        }

        $expectDriver = $value('ORRERY_EXPECT_DRIVER');

        if ($expectDriver !== null && ! in_array($expectDriver, self::DRIVERS, true)) {
            throw new HarnessRefusal("ORRERY_EXPECT_DRIVER must be sqlite, mysql, pgsql or mariadb, not '{$expectDriver}'.");
        }

        $expectPhp = $value('ORRERY_EXPECT_PHP');

        if ($expectPhp !== null && preg_match('/^[0-9]+(\.[0-9]+){0,2}$/D', $expectPhp) !== 1) {
            throw new HarnessRefusal("ORRERY_EXPECT_PHP must be a PHP version such as 8.4 or 8.4.18, not '{$expectPhp}'.");
        }

        $altValue = $value('ORRERY_TEST_ALT');

        if ($altValue !== null && $altValue !== '1') {
            throw new HarnessRefusal("ORRERY_TEST_ALT must be 1 or unset, not '{$altValue}'.");
        }

        if ($altValue !== null && $driver === 'mariadb') {
            throw new HarnessRefusal('ORRERY_TEST_ALT is set, but MariaDB has no alt server. Unset it.');
        }

        $journal = $value('ORRERY_TEST_SQLITE_JOURNAL') ?? 'wal';

        if (! in_array($journal, self::SQLITE_JOURNALS, true)) {
            throw new HarnessRefusal("ORRERY_TEST_SQLITE_JOURNAL must be wal or delete, not '{$journal}'.");
        }

        $pooledValue = $value('ORRERY_TEST_PG_POOLED');

        if ($pooledValue !== null && ($pooledValue !== '1' || $driver !== 'pgsql')) {
            throw new HarnessRefusal("ORRERY_TEST_PG_POOLED must be 1 with the pgsql driver, or unset, not '{$pooledValue}' with {$driver}.");
        }

        $pooled = $pooledValue === '1';
        $sharedCache = $value(SharedCache::VARIABLE);
        $sharedCache = $sharedCache === null ? null : SharedCache::validate($sharedCache);

        if ($driver === 'sqlite') {
            foreach (['ORRERY_TEST_DATABASE', 'ORRERY_TEST_TOKEN', 'ORRERY_TEST_RUN_ID', 'ORRERY_TEST_CI', 'ORRERY_TEST_ALT', 'ORRERY_TEST_SERVER_VERSION'] as $name) {
                if ($value($name) !== null) {
                    throw new HarnessRefusal(
                        "{$name} is set, but the driver is sqlite. The harness never falls back to SQLite silently: "
                        .'set ORRERY_TEST_DRIVER (bin/test mysql or bin/test pgsql does this), or unset the server variables.'
                    );
                }
            }

            $file = $value('ORRERY_TEST_SQLITE_FILE');
            $database = $file === null ? self::MEMORY : SqliteFiles::validate($file, $mirror === 'separate');

            return new self('sqlite', $database, $mirror, $prefix, null, null, null, null, false, $expectDriver, $expectPhp, null, $journal, false, $sharedCache);
        }

        if ($value('ORRERY_TEST_SQLITE_FILE') !== null) {
            throw new HarnessRefusal("ORRERY_TEST_SQLITE_FILE is set, but the driver is {$driver}. Unset one of them.");
        }

        $ciValue = $value('ORRERY_TEST_CI');

        if ($ciValue !== null && $ciValue !== '1') {
            throw new HarnessRefusal("ORRERY_TEST_CI must be 1 or unset, not '{$ciValue}'.");
        }

        $ci = $ciValue === '1';
        $database = $value('ORRERY_TEST_DATABASE');
        $token = $value('ORRERY_TEST_TOKEN');
        $runId = $value('ORRERY_TEST_RUN_ID');

        if ($database === null || $token === null || ($runId === null && ! $ci)) {
            throw new HarnessRefusal(
                "ORRERY_TEST_DRIVER={$driver} needs an admitted run: ORRERY_TEST_DATABASE, ORRERY_TEST_TOKEN "
                ."and ORRERY_TEST_RUN_ID. Run the suite through bin/test {$driver}, which takes the lease and the name lock "
                .'and sets them (CI uses bin/test --no-provision).'
            );
        }

        if ($ci && $runId !== null) {
            throw new HarnessRefusal('ORRERY_TEST_CI=1 and ORRERY_TEST_RUN_ID are both set. bin/test --no-provision unsets the run id; set only one of them.');
        }

        self::validateBaseName($database);

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new HarnessRefusal('ORRERY_TEST_TOKEN must be 32 lowercase hex characters.');
        }

        if ($runId !== null && preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            throw new HarnessRefusal("ORRERY_TEST_RUN_ID must match run_<pid>_<8 hex>, not '{$runId}'.");
        }

        $port = self::port($value(self::PORT_VARIABLES[$driver]), self::PORT_VARIABLES[$driver]) ?? self::DEFAULT_PORTS[$driver];
        // Only the flag gives an alt server: a leftover port variable alone never does.
        $altPort = $altValue === null
            ? null
            : self::port($value(self::ALT_PORT_VARIABLES[$driver]), self::ALT_PORT_VARIABLES[$driver]) ?? self::DEFAULT_ALT_PORTS[$driver];

        if ($altPort !== null && $altPort === $port) {
            throw new HarnessRefusal(self::ALT_PORT_VARIABLES[$driver]." is the primary port {$port}: the alt server must be another server.");
        }

        $password = $value('ORRERY_TEST_PASSWORD') ?? self::DEFAULT_PASSWORD;
        $release = $value('ORRERY_TEST_SERVER_VERSION');

        if ($release !== null && ! in_array($release, self::SERVER_VERSION_CHOICES[$driver], true)) {
            throw new HarnessRefusal(sprintf(
                "ORRERY_TEST_SERVER_VERSION must be one of %s with the %s driver, or unset, not '%s'.",
                implode(', ', self::SERVER_VERSION_CHOICES[$driver]), $driver, $release,
            ));
        }

        $serverVersion = $release === null ? self::SERVER_VERSIONS[$driver] : $release.'.';

        return new self($driver, $database, $mirror, $prefix, $port, $password, $token, $runId, $ci, $expectDriver, $expectPhp, $altPort, $journal, $pooled, $sharedCache, $serverVersion);
    }

    /**
     * @throws HarnessRefusal
     */
    private static function port(?string $value, string $variable): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^[0-9]{4,5}$/D', $value) !== 1 || (int) $value < 1024 || (int) $value > 65535) {
            throw new HarnessRefusal($variable.' must be a port number from 1024 to 65535.');
        }

        return (int) $value;
    }

    /**
     * The name rules for a base name and its derived mirror name.
     *
     * @throws HarnessRefusal
     */
    public static function validateBaseName(string $name): void
    {
        $shown = json_encode($name, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if (preg_match(self::BASE_PATTERN, $name) !== 1) {
            throw new HarnessRefusal("Invalid test database name {$shown}: a base name must match ^orrery_[a-z0-9_]{1,40}$.");
        }

        if ($name === self::MARKER || str_ends_with($name, '_mirror') || in_array($name, self::SYSTEM_DATABASES, true)) {
            throw new HarnessRefusal("Invalid test database name {$shown}: it is reserved, or it ends in _mirror.");
        }

        if (preg_match(self::MIRROR_PATTERN, $name.'_mirror') !== 1) {
            throw new HarnessRefusal("Invalid test database name {$shown}: its mirror name is too long.");
        }
    }

    /**
     * MySQL or MariaDB: one SQL dialect, one PDO driver (pdo_mysql) and one
     * client protocol.
     */
    public function isMysqlFamily(): bool
    {
        return $this->driver === 'mysql' || $this->driver === 'mariadb';
    }

    public function isServer(): bool
    {
        return $this->driver !== 'sqlite';
    }

    public function inMemory(): bool
    {
        return $this->driver === 'sqlite' && $this->database === self::MEMORY;
    }

    public function separate(): bool
    {
        return $this->mirror === 'separate';
    }

    /**
     * The database that holds the package tables: the primary database in
     * same mode, else a second in-memory database, <file>.mirror or <base>_mirror.
     */
    public function mirrorDatabase(): string
    {
        if (! $this->separate()) {
            return $this->database;
        }

        if ($this->isServer()) {
            return $this->database.'_mirror';
        }

        return $this->inMemory() ? self::MEMORY : $this->database.'.mirror';
    }

    /**
     * The sibling SQLite mirror file: in `same` mode on a SQLite file
     * orrery.database stays empty, so the package keeps its tables in
     * `<name>.orrery.sqlite` next to the queue's file. Null otherwise.
     */
    public function siblingDatabase(): ?string
    {
        if ($this->driver !== 'sqlite' || $this->inMemory() || $this->separate()) {
            return null;
        }

        return PackageSession::siblingPathOf($this->database);
    }

    /**
     * A connection configuration of the sibling file, for the harness and
     * the tests' own reads; null without a sibling.
     *
     * @return array<string, mixed>|null
     */
    public function siblingConnection(): ?array
    {
        $sibling = $this->siblingDatabase();

        return $sibling === null ? null : array_replace($this->connection($sibling, $this->mirrorPrefix()), ['journal_mode' => 'wal']);
    }

    public function mirrorConnectionName(): string
    {
        return $this->separate() ? self::MIRROR : self::PRIMARY;
    }

    /**
     * The value of orrery.database: null in same mode (the package then
     * uses the default connection, as in an application that does not set
     * ORRERY_CONNECTION), else the mirror connection.
     */
    public function packageConnection(): ?string
    {
        return $this->separate() ? self::MIRROR : null;
    }

    /**
     * The table prefix of the testbench connection: the prefix in same mode.
     */
    public function primaryPrefix(): string
    {
        return $this->separate() ? '' : $this->prefix;
    }

    /**
     * The table prefix of the package tables, on either connection.
     */
    public function mirrorPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * The complete testbench connection. 'url' => null keeps an inherited
     * DB_URL away even if a later change merges this array into another one.
     *
     * @return array<string, mixed>
     */
    public function queueConnection(): array
    {
        return $this->connection($this->database, $this->primaryPrefix());
    }

    /**
     * The alt server of the engine (bin/test <mysql|pgsql> --alt): the
     * testbench configuration with only the port changed to the alt port.
     * Null without ORRERY_TEST_ALT=1.
     *
     * @return array<string, mixed>|null
     */
    public function alt(): ?array
    {
        if ($this->altPort === null) {
            return null;
        }

        return array_replace($this->queueConnection(), ['port' => $this->altPort]);
    }

    /**
     * The complete testbench_mirror connection, only in separate mode.
     *
     * @return array<string, mixed>|null
     */
    public function mirrorConnection(): ?array
    {
        return $this->separate() ? $this->connection($this->mirrorDatabase(), $this->mirrorPrefix()) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(string $database, string $prefix): array
    {
        if ($this->driver === 'sqlite') {
            // A SQLite file in WAL with a busy timeout, so the package may read
            // it automatically (D18); ORRERY_TEST_SQLITE_JOURNAL=delete gives
            // rollback mode. In-memory databases take no journal setting.
            $file = $database !== self::MEMORY;

            return [
                'driver' => 'sqlite',
                'url' => null,
                'database' => $database,
                'prefix' => $prefix,
                'prefix_indexes' => null,
                'foreign_key_constraints' => true,
                'busy_timeout' => $file ? self::SQLITE_BUSY_TIMEOUT_MS : null,
                'journal_mode' => $file ? $this->sqliteJournal : null,
                'synchronous' => null,
            ];
        }

        $connection = [
            'driver' => $this->driver,
            'url' => null,
            'host' => self::HOST,
            'port' => $this->port,
            'database' => $database,
            'username' => self::USERNAMES[$this->driver],
            'password' => $this->password,
            'prefix' => $prefix,
            'prefix_indexes' => true,
            'options' => [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT],
        ];

        if ($this->isMysqlFamily()) {
            return $connection + [
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
                'engine' => null,
            ];
        }

        // Pooled mode supports only the server's default search path, so the
        // pooled harness leaves the key out.
        return $connection + ($this->pooled ? [] : ['search_path' => 'public']) + [
            'charset' => 'utf8',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * Sets every connection that the tests use, and every storage consumer
     * that could reach a database: the default connection, the queue, the
     * failed-job and batch stores, the package connection, the cache and the
     * session.
     */
    public function apply(Repository $config): void
    {
        $config->set('database.default', self::PRIMARY);
        $config->set('database.connections.'.self::PRIMARY, $this->queueConnection());
        $config->set('database.connections.'.self::MIRROR, $this->mirrorConnection());

        $config->set('queue.default', 'database');
        $config->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => self::PRIMARY,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $config->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => self::PRIMARY,
            'table' => 'failed_jobs',
        ]);
        $config->set('queue.batching', [
            'database' => self::PRIMARY,
            'table' => 'job_batches',
        ]);

        $config->set('orrery.database', $this->packageConnection());
        $config->set('orrery.sessions.pooled', $this->pooled);

        $config->set('cache.default', 'array');
        $config->set('session.driver', 'array');

        if ($this->sharedCache !== null) {
            $config->set('cache.stores.'.SharedCache::STORE, SharedCache::store($this->sharedCache));
            $config->set('cache.default', SharedCache::STORE);
        }

        self::withoutOpenOperationGuard($config);
    }

    /**
     * Turns PackageSchema::drop()'s open-operation guard off in the
     * application that is being configured: the fresh
     * schema mode rolls the package migration back after every test, also
     * after tests that leave open operations. apply() runs while the
     * application is built, so it is the container's instance; the guard is
     * set when PackageSchema is first resolved. One test turns it on again.
     */
    private static function withoutOpenOperationGuard(Repository $config): void
    {
        $app = Container::getInstance();

        if (! $app instanceof Application || ! $app->bound('config') || $app->make('config') !== $config) {
            return;
        }

        $app->afterResolving(PackageSchema::class, static function (PackageSchema $schema): void {
            $schema->guardOpenOperationsForTesting(false);
        });
    }

    /**
     * The identity of the connections that $app resolves.
     */
    public function identity(Application $app): IdentityRecord
    {
        return IdentityRecord::capture($app);
    }

    /**
     * The complete harness environment for a child process. Every variable of
     * VARIABLES is present: a string, or false (unset). A child applies it
     * with fromEnv() and gets exactly this configuration.
     *
     * @return array<string, string|false>
     */
    public function toProcessEnv(): array
    {
        if ($this->inMemory()) {
            throw new LogicException('An in-memory SQLite database cannot be shared with a child process. Use bin/test sqlite --sqlite-file.');
        }

        $env = array_fill_keys(self::VARIABLES, false);
        $env['ORRERY_TEST_DRIVER'] = $this->driver;
        $env['ORRERY_TEST_MIRROR'] = $this->mirror;
        $env['ORRERY_TEST_PREFIX'] = $this->prefix === '' ? false : $this->prefix;
        $env['ORRERY_EXPECT_DRIVER'] = $this->expectDriver ?? false;
        $env['ORRERY_EXPECT_PHP'] = $this->expectPhp ?? false;
        $env['ORRERY_TEST_SQLITE_JOURNAL'] = $this->sqliteJournal === 'wal' ? false : $this->sqliteJournal;
        $env['ORRERY_TEST_PG_POOLED'] = $this->pooled ? '1' : false;
        $env[SharedCache::VARIABLE] = $this->sharedCache ?? false;

        if ($this->driver === 'sqlite') {
            $env['ORRERY_TEST_SQLITE_FILE'] = $this->database;

            return $env;
        }

        $env['ORRERY_TEST_DATABASE'] = $this->database;
        $env[self::PORT_VARIABLES[$this->driver]] = (string) $this->port;
        $env['ORRERY_TEST_PASSWORD'] = (string) $this->password;
        $env['ORRERY_TEST_TOKEN'] = (string) $this->token;
        $env['ORRERY_TEST_RUN_ID'] = $this->runId ?? false;
        $env['ORRERY_TEST_CI'] = $this->ci ? '1' : false;
        $env['ORRERY_TEST_SERVER_VERSION'] = $this->serverVersion === self::SERVER_VERSIONS[$this->driver] ? false : rtrim((string) $this->serverVersion, '.');

        if ($this->altPort !== null) {
            $env['ORRERY_TEST_ALT'] = '1';
            $env[self::ALT_PORT_VARIABLES[$this->driver]] = (string) $this->altPort;
        }

        return $env;
    }

    /**
     * A short description for messages, without secrets.
     */
    public function describe(): string
    {
        $where = $this->isServer()
            ? "{$this->driver} {$this->database} on ".self::HOST.":{$this->port}"
            : 'sqlite '.$this->database;

        return sprintf('%s, mirror %s, prefix %s%s', $where, $this->mirror, $this->prefix === '' ? '(none)' : $this->prefix, $this->pooled ? ', pooled' : '');
    }
}
