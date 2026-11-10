<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use PDO;

/**
 * Makes only the package's storage fail, and restores it.
 *
 * Two methods:
 * - rename: in-memory SQLite (any mode) and same mode (any engine). It
 *   renames every package table (orrery_*, with the prefix) on the
 *   mirror connection, and back on end(). It never purges a connection,
 *   because a purge destroys an in-memory database. A test can also ask for
 *   it in separate mode (begin($app, $env, self::RENAME)): the rename is the
 *   only method that reaches another process, such as a worker, because a
 *   redirect changes only the configuration of this process.
 * - redirect: file and server databases in separate mode. It points
 *   testbench_mirror at 127.0.0.1 port 1 with a 1-second connect timeout
 *   (MySQL, Postgres) or at a file in a directory that does not exist
 *   (SQLite), and purges it. end() restores the original configuration and
 *   purges again. These databases persist, so nothing is lost.
 *   The package's own sessions (Justrau\Orrery\Storage\PackageSession) are clones
 *   with their own PDOs, so begin() and end() also release them
 *   (PackageSession::releaseAll()): the next package session is built from the
 *   configuration of that moment.
 *
 * Renaming is DDL, and MySQL commits an open transaction implicitly. So
 * begin() and end() refuse while any resolved connection has an open
 * transaction, also one opened on its PDO directly or a package transaction
 * (see openTransactions()):
 * install the outage before a business transaction opens, and end it after
 * the transaction closes. The authoritative tables (jobs, failed_jobs,
 * job_batches) are never touched.
 */
final class MirrorOutage
{
    public const RENAME = 'rename';

    public const REDIRECT = 'redirect';

    public const RENAMED_PREFIX = 'outage_';

    private bool $active = true;

    /**
     * @param  list<array{0: string, 1: string}>  $renamed  logical [original, renamed] table names
     * @param  array<string, mixed>|null  $original  the mirror connection before a redirect
     */
    private function __construct(
        private readonly Application $app,
        private readonly TestEnvironment $env,
        public readonly string $method,
        private readonly array $renamed,
        private readonly ?array $original,
    ) {}

    public static function methodFor(TestEnvironment $env): string
    {
        return ($env->inMemory() || ! $env->separate()) ? self::RENAME : self::REDIRECT;
    }

    /**
     * @param  string|null  $method  self::RENAME to rename in every mode; null chooses methodFor($env)
     *
     * @throws HarnessRefusal inside an open transaction, or without package tables
     */
    public static function begin(Application $app, TestEnvironment $env, ?string $method = null): self
    {
        if ($method !== null && $method !== self::RENAME) {
            throw new HarnessRefusal("MirrorOutage::begin() can force only the rename method, not '{$method}'.");
        }

        // A rename is DDL, and a redirect changes the mirror: only a test with
        // #[FreshSchema] may do it (see Support\ReusedSchema).
        FreshSchema::required('MirrorOutage::begin()');

        self::refuseInsideTransaction($app, 'begin');

        $name = $env->mirrorConnectionName();
        $db = self::db($app);

        if (($method ?? self::methodFor($env)) === self::RENAME) {
            $connection = self::mirrorConnection($app, $env);
            $renamed = [];

            foreach (self::packageTables($connection) as $table) {
                $target = self::RENAMED_PREFIX.$table;
                $connection->getSchemaBuilder()->rename($table, $target);
                $renamed[] = [$table, $target];
            }

            if ($renamed === []) {
                throw new HarnessRefusal("The mirror connection {$name} has no package tables, so an outage would change nothing.");
            }

            return new self($app, $env, self::RENAME, $renamed, null);
        }

        $config = $app['config'];
        $original = $config->get("database.connections.{$name}");

        if (! is_array($original)) {
            throw new HarnessRefusal("The mirror connection {$name} is not configured.");
        }

        $outage = $original;

        if ($env->isServer()) {
            $outage['host'] = '127.0.0.1';
            $outage['port'] = 1;
            $outage['options'] = [PDO::ATTR_TIMEOUT => 1] + (is_array($original['options'] ?? null) ? $original['options'] : []);
        } else {
            $missing = dirname((string) $original['database']).'/orrery-outage-missing-'.bin2hex(random_bytes(6));

            if (file_exists($missing)) {
                throw new HarnessRefusal("{$missing} exists, so it cannot stand for a missing directory.");
            }

            $outage['database'] = $missing.'/'.basename((string) $original['database']);
        }

        $config->set("database.connections.{$name}", $outage);
        $db->purge($name);
        self::releasePackageSessions($app);

        return new self($app, $env, self::REDIRECT, [], $original);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Restores the package's storage. Calling it again does nothing.
     *
     * @throws HarnessRefusal inside an open transaction
     */
    public function end(): void
    {
        if (! $this->active) {
            return;
        }

        self::refuseInsideTransaction($this->app, 'end');

        $name = $this->env->mirrorConnectionName();
        $db = self::db($this->app);

        if ($this->method === self::RENAME) {
            $schema = self::mirrorConnection($this->app, $this->env)->getSchemaBuilder();

            foreach (array_reverse($this->renamed) as [$table, $target]) {
                $schema->rename($target, $table);
            }
        } else {
            $this->app['config']->set("database.connections.{$name}", $this->original);
            $db->purge($name);
            self::releasePackageSessions($this->app);
        }

        $this->active = false;
    }

    /**
     * The logical (unprefixed) names of the package tables on a connection,
     * read from the physical names with the connection's prefix.
     *
     * @return list<string>
     */
    public static function packageTables(Connection $connection): array
    {
        $prefix = $connection->getTablePrefix();
        $wanted = $prefix.'orrery_';
        $tables = [];

        foreach (PhysicalTables::of($connection) as $physical) {
            if (str_starts_with($physical, $wanted)) {
                $tables[] = substr($physical, strlen($prefix));
            }
        }

        sort($tables);

        return $tables;
    }

    /**
     * The resolved connections with an open transaction: one that Laravel
     * counts (transactionLevel()), one that was opened on the PDO itself
     * ($connection->getPdo()->beginTransaction()), which Laravel does not
     * count, or a package transaction (PackageTransaction::isOpen(): PHP 8.3
     * does not report a SQLite BEGIN IMMEDIATE through PDO::inTransaction()).
     * The package sessions are resolved connections too. Only PDOs that
     * already exist are asked: the check never opens a connection.
     *
     * @return list<string> connection names
     */
    public static function openTransactions(Application $app): array
    {
        $open = [];

        foreach (self::connections($app) as $name => $connection) {
            if ($connection->transactionLevel() > 0 || self::openPdos($connection) !== [] || PackageTransaction::isOpen($connection)) {
                $open[] = (string) $name;
            }
        }

        return $open;
    }

    /**
     * Rolls back every open transaction of the resolved connections, by the
     * same rule as openTransactions(): Laravel's transactions first, then a
     * transaction that is still open on an existing PDO. The test case uses it
     * before it ends an outage that a test left active.
     */
    public static function rollBackOpenTransactions(Application $app): void
    {
        foreach (self::connections($app) as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            foreach (self::openPdos($connection) as $pdo) {
                $pdo->rollBack();
            }

            // A package transaction that PDO does not report (a SQLite BEGIN
            // IMMEDIATE on PHP 8.3): closing the PDO rolls it back.
            if (PackageTransaction::isOpen($connection)) {
                $connection->disconnect();
            }
        }
    }

    /**
     * Releases every package session, so the next one is built from the
     * current configuration of the mirror connection.
     */
    /**
     * The manager's connections and the package sessions (they live
     * outside the manager), by name.
     *
     * @return array<string, Connection>
     */
    private static function connections(Application $app): array
    {
        $connections = self::db($app)->getConnections();

        foreach (PackageSession::sessionsForTesting() as $entry) {
            $connections[(string) $entry['connection']->getName()] = $entry['connection'];
        }

        return $connections;
    }

    private static function releasePackageSessions(Application $app): void
    {
        PackageSession::releaseAll();
    }

    /**
     * The connection of the package tables: the mirror connection, or the
     * tests' connection of the sibling SQLite mirror file.
     */
    private static function mirrorConnection(Application $app, TestEnvironment $env): Connection
    {
        $sibling = $env->siblingConnection();
        $name = $env->mirrorConnectionName();

        if ($sibling !== null) {
            $app['config']->set('database.connections.'.TestEnvironment::SIBLING, $sibling);
            $app->make(PackageSession::class)->mirror();
            $name = TestEnvironment::SIBLING;
        }

        /** @var Connection $connection */
        $connection = self::db($app)->connection($name);

        return $connection;
    }

    /**
     * The existing PDOs of a connection (write and read) with an open
     * transaction. A PDO that the connection has not created yet is a
     * closure, and it is not resolved here.
     *
     * @return list<PDO>
     */
    private static function openPdos(Connection $connection): array
    {
        $open = [];

        foreach ([$connection->getRawPdo(), $connection->getRawReadPdo()] as $pdo) {
            if ($pdo instanceof PDO && $pdo->inTransaction() && ! in_array($pdo, $open, true)) {
                $open[] = $pdo;
            }
        }

        return $open;
    }

    private static function refuseInsideTransaction(Application $app, string $step): void
    {
        foreach (self::openTransactions($app) as $name) {
            throw new HarnessRefusal(sprintf(
                'MirrorOutage::%s() refuses to run while the connection %s has an open transaction: '
                .'it changes the schema or the connection, and MySQL would commit the transaction. '
                .'Install the outage before the business transaction opens, and end it after the transaction closes.',
                $step,
                $name,
            ));
        }
    }

    private static function db(Application $app): DatabaseManager
    {
        /** @var DatabaseManager $db */
        $db = $app['db'];

        return $db;
    }
}
