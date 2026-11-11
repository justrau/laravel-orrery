<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Justrau\Orrery\Storage\PackageSession;
use LogicException;
use PDO;

/**
 * Helpers for the tests of the package sessions. The class
 * that uses this trait extends Tests\TestCase.
 *
 * The scratch table is not a package table (MirrorOutage does not rename
 * it), and each test drops it when its application ends.
 */
trait PackageSessionHelpers
{
    protected function sessions(): PackageSession
    {
        return $this->app->make(PackageSession::class);
    }

    protected function db(): DatabaseManager
    {
        return $this->app['db'];
    }

    protected function skipOnInMemory(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped(
                'In-memory SQLite cannot be cloned, so every package session is the configured connection (Spec B §3.1). '
                .'This test needs separate sessions: run it through bin/test sqlite --sqlite-file, bin/test mysql or bin/test pgsql.'
            );
        }
    }

    protected function skipUnlessDriver(string ...$drivers): void
    {
        $driver = $this->testEnvironment()->driver;

        if (! in_array($driver, $drivers, true)) {
            self::markTestSkipped(sprintf('This test needs %s, and this run uses %s.', implode(' or ', $drivers), $driver));
        }
    }

    /**
     * The database connection of the package tables: orrery.database, else
     * the default connection, else (the sibling SQLite mirror file) the
     * tests' connection of the sibling.
     */
    protected function mirrorSource(): string
    {
        $configured = $this->app['config']->get('orrery.database');

        if ($this->testEnvironment()->siblingConnection() !== null && ! (is_string($configured) && $configured !== '')) {
            $this->mirror();

            return TestEnvironment::SIBLING;
        }

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) $this->app['config']->get('database.default');
    }

    /**
     * Points orrery.database at another connection for this test. The
     * package migrations roll back on orrery.database after the destroy
     * callbacks, so the original value comes back first.
     */
    protected function usePackageConnection(string $connection): void
    {
        $config = $this->app['config'];
        $original = $config->get('orrery.database');
        $config->set('orrery.database', $connection);

        $this->beforeApplicationDestroyed(static function () use ($config, $original): void {
            $config->set('orrery.database', $original);
        });
    }

    /**
     * Defines a copy of a configured connection under a new name.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function defineConnection(string $name, string $from, array $changes = []): void
    {
        $config = $this->app['config']->get("database.connections.{$from}");

        if (! is_array($config)) {
            throw new LogicException("The connection {$from} is not configured.");
        }

        $this->app['config']->set("database.connections.{$name}", array_replace($config, $changes));
    }

    /**
     * Creates the scratch table on a connection, and drops it when the
     * application of the test ends.
     */
    protected function createScratchTable(Connection $connection, string $table = 'session_scratch'): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->dropIfExists($table);
        $schema->create($table, static function (Blueprint $blueprint): void {
            $blueprint->integer('id')->primary();
            $blueprint->string('value', 64);
        });

        $app = $this->app;
        $this->beforeApplicationDestroyed(static function () use ($connection, $table): void {
            // A package session that a failed test left inside a transaction
            // holds a lock on the table, and MySQL's DROP TABLE would wait for
            // it without a limit. Release the package sessions first.
            PackageSession::releaseAll();
            $connection->getSchemaBuilder()->dropIfExists($table);
        });
    }

    /**
     * The isolation level that a session reports, in the server's spelling:
     * READ-COMMITTED (MySQL), read committed (Postgres), or the value of
     * PRAGMA read_uncommitted (SQLite).
     */
    protected function reportedLevel(Connection $session): string
    {
        return match ($session->getDriverName()) {
            'mysql', 'mariadb' => (string) $session->scalar('select @@transaction_isolation'),
            'pgsql' => (string) $session->scalar("select current_setting('transaction_isolation')"),
            'sqlite' => (string) $session->scalar('pragma read_uncommitted'),
            default => throw new LogicException('Unsupported driver '.$session->getDriverName()),
        };
    }

    /**
     * The level that a package session runs at: READ COMMITTED on
     * Postgres (the package's own setting), the server default on MySQL
     * (REPEATABLE READ here), and PRAGMA read_uncommitted 0 on SQLite.
     */
    protected function expectedLevel(string $connection): string
    {
        return match ($this->testEnvironment()->driver) {
            'mysql', 'mariadb' => 'REPEATABLE-READ',
            'pgsql' => 'read committed',
            default => '0',
        };
    }

    /**
     * The lock wait of a session: MySQL innodb_lock_wait_timeout in seconds,
     * the Postgres lock_timeout text, or the SQLite busy timeout in ms.
     */
    protected function lockWait(Connection $session): string
    {
        return match ($session->getDriverName()) {
            'mysql', 'mariadb' => (string) $session->scalar('select @@innodb_lock_wait_timeout'),
            'pgsql' => (string) $session->scalar('show lock_timeout'),
            'sqlite' => (string) $session->scalar('pragma busy_timeout'),
            default => throw new LogicException('Unsupported driver '.$session->getDriverName()),
        };
    }

    /**
     * The statement timeout of a session: MySQL max_execution_time in ms,
     * or the Postgres statement_timeout text. SQLite has none.
     */
    protected function statementTimeout(Connection $session): string
    {
        return match ($session->getDriverName()) {
            'mysql' => (string) $session->scalar('select @@max_execution_time'),
            // MariaDB's max_statement_time is in seconds.
            'mariadb' => (string) (int) round((float) $session->scalar('select @@max_statement_time') * 1000),
            'pgsql' => (string) $session->scalar('show statement_timeout'),
            default => throw new LogicException('SQLite has no statement timeout.'),
        };
    }

    /**
     * The DSN, user and password of a connection configuration, for a PDO
     * that a test builds without Laravel's connector.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    protected function dsnOf(array $config): array
    {
        $driver = (string) Arr::get($config, 'driver');

        return match ($driver) {
            'sqlite' => ['sqlite:'.$config['database'], null, null],
            'mysql', 'mariadb' => [sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']), $config['username'], $config['password']],
            'pgsql' => [sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']), $config['username'], $config['password']],
            default => throw new LogicException("Unsupported driver {$driver}"),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, mixed>  $options
     */
    protected function rawPdo(array $config, array $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]): PDO
    {
        [$dsn, $user, $password] = $this->dsnOf($config);

        return new PDO($dsn, $user, $password, $options);
    }

    /**
     * A PDO whose isolation level cannot be read: every other statement runs.
     *
     * @param  array<string, mixed>  $config
     */
    protected function levelRefusingPdo(array $config): PDO
    {
        [$dsn, $user, $password] = $this->dsnOf($config);

        return new class($dsn, $user, $password) extends PDO
        {
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                if (preg_match('/transaction_isolation|tx_isolation|read_uncommitted/i', $query) === 1) {
                    throw new \PDOException('The level read is refused by this test PDO.');
                }

                return parent::query($query, $fetchMode, ...$fetchModeArgs);
            }
        };
    }

    /**
     * The id of the server session behind a connection (MySQL, Postgres).
     */
    protected function serverSessionId(Connection $connection): int
    {
        return (int) match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->scalar('select connection_id()'),
            'pgsql' => $connection->scalar('select pg_backend_pid()'),
            default => throw new LogicException('Only MySQL and Postgres have server sessions.'),
        };
    }
}
