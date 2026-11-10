<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Storage\PackageSession;
use LogicException;

use function Orchestra\Testbench\default_migration_path;

/**
 * A second server of the run's engine for the tests of a reachable second
 * target. The class that uses
 * this trait extends Tests\TestCase, and each of its tests is in the groups
 * docker and alt. bin/matrix's docker run excludes the group alt; run them
 * with bin/test <mysql|pgsql> --alt --php=<php> -- --group alt --exclude-group
 * orrery-no-exclusion (a command-line --exclude-group replaces the exclusion of
 * the group docker in phpunit.xml.dist, which --group alt alone keeps).
 *
 * useAltServer() defines three database connections:
 * - testbench_alt: the alt server (TestEnvironment::alt()), with the run's own
 *   <base> database there;
 * - testbench_primary: a copy of the primary configuration, for assertions on
 *   the primary server;
 * - testbench_target: another copy of the primary configuration. A test
 *   points a queue connection or queue.failed.database at it, and then moves
 *   it with retargetToAlt() and restorePrimary(). The mirror and the other
 *   connections stay on the primary server.
 *
 * Laravel's QueueManager cannot forget a resolved queue object, so a test
 * never pushes or pops through a queue connection on testbench_target after
 * retargetToAlt(). The operations under test use package sessions and the
 * failer.
 */
trait UsesAltServer
{
    /** @var array<string, mixed>|null the configuration of testbench_target before the first retarget of this test */
    private ?array $altSavedTarget = null;

    /** @var list<array{session: string, port: int|null}> */
    private array $altOpenedSessions = [];

    private bool $altRetargeted = false;

    /**
     * Skips with a visible reason without bin/test --alt. Defines
     * testbench_alt, testbench_primary and testbench_target, and prepares
     * the alt schema.
     */
    protected function useAltServer(): void
    {
        // The alt schema is migrated and dropped per test: only a test with
        // #[FreshSchema] may use it, also where it skips.
        FreshSchema::required('UsesAltServer::useAltServer()');

        $environment = $this->testEnvironment();
        $alt = $environment->alt();

        if ($alt === null) {
            self::markTestSkipped(
                'This test needs a second server of the engine (the Compose profile alt). '
                .'Run it through bin/test mysql --alt or bin/test pgsql --alt, with -- --group alt --exclude-group orrery-no-exclusion.'
            );
        }

        $config = $this->app['config'];
        $primary = $config->get('database.connections.'.TestEnvironment::PRIMARY);

        if (! is_array($primary)) {
            throw new LogicException('The primary connection is not configured.');
        }

        $config->set('database.connections.testbench_alt', $alt);
        $config->set('database.connections.testbench_primary', $primary);
        $config->set('database.connections.testbench_target', $primary);

        $this->beforeApplicationDestroyed(function (): void {
            $this->restorePrimary();
        });

        $altFacts = $this->altServerFacts($this->altDb()->connection('testbench_alt'));
        $primaryFacts = $this->altServerFacts($this->altDb()->connection('testbench_primary'));
        self::assertNotSame($primaryFacts, $altFacts, 'testbench_alt must reach another server than testbench_primary.');
        self::assertSame($environment->token, $this->altMarker($this->altDb()->connection('testbench_alt')), 'The alt server must carry the run token in its orrery_marker database (the endpoint proof).');

        $this->prepareAltSchema();
    }

    /**
     * Points testbench_target at the alt server and asserts the alt server
     * facts on it.
     */
    protected function retargetToAlt(): void
    {
        $config = $this->app['config'];
        $current = $config->get('database.connections.testbench_target');

        if (! is_array($current)) {
            throw new LogicException('Call useAltServer() first.');
        }

        $this->altSavedTarget ??= $current;
        $alt = $this->testEnvironment()->alt();
        $config->set('database.connections.testbench_target', array_replace($current, ['port' => $alt['port'] ?? null]));
        $this->forgetTarget();

        $this->altOpenedSessions = [];
        $opened = &$this->altOpenedSessions;
        PackageSession::usePdoFactoryForTesting(static function (string $session, array $sessionConfig) use (&$opened): null {
            $port = $sessionConfig['port'] ?? null;
            $opened[] = ['session' => $session, 'port' => $port === null ? null : (int) $port];

            return null;
        });
        $this->altRetargeted = true;

        self::assertSame(
            $this->altServerFacts($this->altDb()->connection('testbench_alt')),
            $this->altServerFacts($this->altDb()->connection('testbench_target')),
            'testbench_target reads the alt server after retargetToAlt().',
        );
    }

    /**
     * Points testbench_target back at the primary server.
     * beforeApplicationDestroyed() also runs it.
     */
    protected function restorePrimary(): void
    {
        if ($this->altSavedTarget === null) {
            return;
        }

        $this->app['config']->set('database.connections.testbench_target', $this->altSavedTarget);
        $this->forgetTarget();

        self::assertSame(
            $this->altServerFacts($this->altDb()->connection('testbench_primary')),
            $this->altServerFacts($this->altDb()->connection('testbench_target')),
            'testbench_target reads the primary server after restorePrimary().',
        );

        if ($this->altRetargeted) {
            PackageSession::usePdoFactoryForTesting(null);
            $this->altRetargeted = false;
        }
    }

    /**
     * @return list<array{session: string, port: int|null}> the package sessions that opened since the last retargetToAlt()
     */
    protected function sessionsOpenedSinceRetarget(): array
    {
        return $this->altOpenedSessions;
    }

    /**
     * The facts that tell two servers apart: @@server_uuid on MySQL, the
     * server address and its own port on Postgres.
     *
     * @return array<string, string|null>
     */
    protected function altServerFacts(Connection $connection): array
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => ['server_uuid' => (string) $connection->scalar('select @@server_uuid')],
            'pgsql' => (static function (Connection $connection): array {
                $row = (array) $connection->selectOne("select host(inet_server_addr()) as address, current_setting('port') as port");

                return ['address' => $row['address'] === null ? null : (string) $row['address'], 'port' => (string) $row['port']];
            })($connection),
            default => throw new LogicException('The alt server exists only for MySQL and Postgres.'),
        };
    }

    /**
     * The alt port of the run.
     */
    protected function altPort(): int
    {
        return (int) ($this->testEnvironment()->alt()['port'] ?? 0);
    }

    /**
     * Steps 3-5 of "Retarget": Laravel forgets the resolved connection, the
     * package forgets its target sessions of testbench_target (the mirror
     * session stays), and the failer is built again at its next resolution.
     */
    private function forgetTarget(): void
    {
        $this->altDb()->purge('testbench_target');
        PackageSession::releaseAll();
        $this->app->forgetInstance('queue.failer');
    }

    /**
     * The alt database is the run's own <base> on the alt server, so it holds
     * no other data. Its fence table (orrery_fences) is what
     * orrery:install --fence and migrate give a queue database.
     */
    private function prepareAltSchema(): void
    {
        $primary = $this->altDb()->connection('testbench_primary');
        $alt = $this->altDb()->connection('testbench_alt');

        // 1. The primary row counts.
        $counts = $this->altPrimaryCounts($primary);

        // 2. An empty alt database.
        $alt->getSchemaBuilder()->dropAllTables();

        // 3. The queue tables: this migration names no connection.
        self::assertSame(0, Artisan::call('migrate', [
            '--database' => 'testbench_alt',
            '--realpath' => true,
            '--path' => default_migration_path().'/0001_01_01_000002_testbench_create_jobs_table.php',
        ]), 'The queue tables migrate on testbench_alt.');

        // 4. The fence table.
        $schema = $alt->getSchemaBuilder();
        PackageSchema::create($schema, [Fence::TABLE]);

        // 5. The four alt tables, and an unchanged primary.
        foreach (['jobs', 'job_batches', 'failed_jobs', Fence::TABLE] as $table) {
            self::assertTrue($schema->hasTable($table), "testbench_alt has the table {$table}.");
        }

        self::assertSame($counts, $this->altPrimaryCounts($primary), 'The alt schema changed nothing on the primary server.');
    }

    /**
     * @return array<string, int>
     */
    private function altPrimaryCounts(Connection $primary): array
    {
        $counts = [];

        foreach (['jobs', 'failed_jobs'] as $table) {
            $counts[$table] = (int) $primary->table($table)->count();
        }

        return $counts;
    }

    private function altMarker(Connection $connection): ?string
    {
        $values = $connection->getDriverName() === 'pgsql'
            ? $connection->select("select shobj_description(oid, 'pg_database') as token from pg_database where datname = 'orrery_marker'")
            : $connection->select('select token from orrery_marker.marker');

        return count($values) === 1 ? (string) ((array) $values[0])['token'] : null;
    }

    private function altDb(): DatabaseManager
    {
        return $this->app['db'];
    }
}
