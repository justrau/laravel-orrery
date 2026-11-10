<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use LogicException;

/**
 * Helpers for the fence tests (the fence table orrery_fences
 * on a queue's database). The class that uses this trait extends
 * Tests\TestCase.
 *
 * The "other database" is a second database of the run that a copy of the
 * testbench configuration can reach by changing only its database: the
 * run's <base>_mirror on a server, <file>.mirror on a SQLite file. In-memory
 * SQLite has none. Every table that a helper creates there is dropped when
 * the test's application ends, before the harness rolls its migrations back,
 * and every queue connection that a helper changes is restored then.
 */
trait FenceFixtures
{
    /** The table prefix of a bare copy on the mirror's database in separate mode (bareOtherDatabase()). */
    private const BARE_PREFIX = 'bare_';

    private bool $queuesSaved = false;

    protected function fence(): Fence
    {
        return $this->app->make(Fence::class);
    }

    protected function packageSessions(): PackageSession
    {
        return $this->app->make(PackageSession::class);
    }

    protected function skipInMemory(string $why): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped("In-memory SQLite shares one connection for every package session (Spec B §3.1), so {$why} Run it through bin/test sqlite --sqlite-file, bin/test mysql or bin/test pgsql.");
        }
    }

    protected function skipUnlessEngine(string ...$drivers): void
    {
        $driver = $this->testEnvironment()->driver;

        if (! in_array($driver, $drivers, true)) {
            self::markTestSkipped(sprintf('This test needs %s, and this run uses %s.', implode(' or ', $drivers), $driver));
        }
    }

    /**
     * The database of the other database: <base>_mirror or <file>.mirror.
     */
    protected function otherDatabase(): string
    {
        $environment = $this->testEnvironment();

        if ($environment->inMemory()) {
            throw new LogicException('In-memory SQLite has no other database.');
        }

        return $environment->isServer() ? $environment->database.'_mirror' : $environment->database.'.mirror';
    }

    /**
     * The configuration changes of a copy of the testbench connection that
     * reaches the other database and sees no fence table there.
     *
     * In separate mode the other database is the mirror's: it holds every
     * package table, orrery_fences included, under the package prefix
     * (TestEnvironment::mirrorPrefix(), empty in separate/none). A copy with
     * the primary prefix ('' in separate mode) would then find the mirror's
     * fence table. So the copy reads the other database under BARE_PREFIX,
     * which no table of the run has. In same mode the other database is
     * empty, and the copy keeps the primary prefix.
     *
     * @return array<string, mixed>
     */
    protected function bareOtherDatabase(): array
    {
        $environment = $this->testEnvironment();

        if (! $environment->separate()) {
            return ['database' => $this->otherDatabase()];
        }

        if ($environment->mirrorPrefix() === self::BARE_PREFIX) {
            throw new LogicException('The bare prefix is the package prefix of this run.');
        }

        return ['database' => $this->otherDatabase(), 'prefix' => self::BARE_PREFIX];
    }

    /**
     * Defines a copy of the testbench configuration with changes.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function copyConnection(string $name, array $changes = []): void
    {
        $config = $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY);

        if (! is_array($config)) {
            throw new LogicException('The primary connection is not configured.');
        }

        $this->app['config']->set("database.connections.{$name}", array_replace($config, $changes));
    }

    /**
     * Defines $name as the other database, with a jobs table and a fence
     * table.
     */
    protected function defineOtherDatabaseWithFence(string $name = 'testbench_other'): Connection
    {
        $this->copyConnection($name, ['database' => $this->otherDatabase()]);
        /** @var Connection $connection */
        $connection = $this->app['db']->connection($name);
        $this->createQueueTables($connection);

        return $connection;
    }

    /**
     * Creates the jobs table and the fence table on a connection when they
     * are absent, and drops the created ones when the application ends.
     */
    protected function createQueueTables(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        $created = [];

        if (! $schema->hasTable('jobs')) {
            $schema->create('jobs', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('queue');
                $table->longText('payload');
                $table->unsignedSmallInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
            $created[] = 'jobs';
        }

        if (! $schema->hasTable(Fence::TABLE)) {
            PackageSchema::create($schema, [Fence::TABLE]);
            $created[] = Fence::TABLE;
        }

        $app = $this->app;
        $name = (string) $connection->getName();
        // The drop uses the prefix of the creation, also when the test changed the prefix only for a while.
        $prefix = $connection->getTablePrefix();

        $this->beforeApplicationDestroyed(static function () use ($app, $name, $prefix, $created): void {
            // The package sessions first: an open session can hold a lock.
            PackageSession::releaseAll();
            /** @var Connection $owner */
            $owner = $app['db']->connection($name);
            $current = $owner->getTablePrefix();
            $owner->setTablePrefix($prefix);

            try {
                foreach ($created as $table) {
                    $owner->getSchemaBuilder()->dropIfExists($table);
                }
            } finally {
                $owner->setTablePrefix($current);
            }
        });
    }

    /**
     * Sets a database queue connection for this test, and restores the queue
     * connections when the application ends (before the fence migration rolls
     * back, because it reads them).
     */
    protected function useQueue(string $queue, string $database, string $table = 'jobs'): void
    {
        $config = $this->app['config'];

        if (! $this->queuesSaved) {
            // Once per test: the first state is the one to restore.
            $this->queuesSaved = true;
            $original = $config->get('queue.connections');
            $this->beforeApplicationDestroyed(static function () use ($config, $original): void {
                $config->set('queue.connections', $original);
            });
        }

        $config->set("queue.connections.{$queue}", ['driver' => 'database', 'connection' => $database, 'table' => $table, 'queue' => 'default', 'retry_after' => 90]);
    }

}
