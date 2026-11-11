<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Models\Setting;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Tags;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\HarnessRefusal;
use Justrau\Orrery\Tests\Support\IdentityRecord;
use Justrau\Orrery\Tests\Support\LeaseProof;
use Justrau\Orrery\Tests\Support\MirrorOutage;
use Justrau\Orrery\Tests\Support\PhysicalTables;
use Justrau\Orrery\Tests\Support\SharedCache;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * The harness controls. They must pass in every configuration:
 * SQLite in memory or in a file, MySQL, Postgres, with the mirror on the same
 * or a separate connection, with or without a prefix. They are in
 * tests/unconditional.txt, so no known-failure entry can excuse them.
 *
 * Checks that need a server (the real registry, the real marker) run on every
 * server run. The same decisions are also checked on synthetic input, so a
 * SQLite run still tests them.
 */
#[Group('harness')]
final class HarnessIdentityTest extends TestCase
{
    /** The package tables that the migrations of this package create on the mirror connection. */
    private const PACKAGE_TABLES = [
        'orrery_jobs',
        'orrery_job_tags',
        'orrery_failed_tags',
        'orrery_job_history',
        'orrery_cycle_summaries',
        'orrery_monitored_tags',
        'orrery_settings',
        'orrery_worker_heartbeats',
        'orrery_health_events',
        'orrery_signals',
        'orrery_markers',
        'orrery_samples',
        'orrery_operations',
        self::FENCE_TABLE,
    ];

    /**
     * The fence table: the main migration creates it on the
     * mirror; another queue database gets it from orrery:install --fence.
     */
    private const FENCE_TABLE = 'orrery_fences';

    private const AUTHORITATIVE_TABLES = ['jobs', 'failed_jobs', 'job_batches'];

    /** The synthetic caller, its process group, and the recorded group entry of the lease proof data sets. */
    private const CALLER = 4241;

    private const GROUP = 4242;

    private const LEADER_START = 'Wed Sep 23 10:00:01 2026';

    private const GROUP_TOKEN = 'cccccccccccccccccccccccccccccccc';

    /**
     * Variables of the application that must never reach a test connection.
     * The hostile test sets them before its application is created.
     */
    private const HOSTILE = [
        'DB_URL' => 'mysql://intruder:secret@127.0.0.1:1/production',
        'DATABASE_URL' => 'pgsql://intruder:secret@127.0.0.1:1/production',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1',
        'DB_DATABASE' => 'production',
        'DB_USERNAME' => 'intruder',
        'DB_PASSWORD' => 'secret',
        'DB_SOCKET' => '/tmp/orrery-intruder.sock',
        'ORRERY_CONNECTION' => 'mysql',
        'DB_QUEUE_CONNECTION' => 'mysql',
        'QUEUE_CONNECTION' => 'sync',
        'QUEUE_FAILED_DRIVER' => 'file',
        'CACHE_STORE' => 'database',
        'DB_CACHE_CONNECTION' => 'mysql',
        'SESSION_DRIVER' => 'database',
    ];

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> */
    private array $savedVariables = [];

    /** @var list<string> */
    private array $sqliteFiles = [];

    protected function setUp(): void
    {
        // The hostile test builds its whole application, including the
        // identity check and the migrations, with the hostile variables set.
        if ($this->name() === 'test_hostile_application_variables_never_reach_a_connection') {
            $this->setVariables(self::HOSTILE);
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->restoreVariables();

            foreach ($this->sqliteFiles as $file) {
                SqliteFiles::remove($file);
            }

            $this->sqliteFiles = [];
        }
    }

    public function test_the_resolved_identity_equals_the_environment(): void
    {
        $env = $this->testEnvironment();
        $record = $env->identity($this->app);
        $record->assertMatches($env);

        // Also against the raw variables, not only against TestEnvironment's reading of them.
        $driver = getenv('ORRERY_TEST_DRIVER') ?: 'sqlite';
        self::assertSame($driver, $record->get('primary.driver'));
        self::assertSame($driver, $record->get('mirror.driver'));
        self::assertSame(getenv('ORRERY_TEST_PREFIX') ?: '', $record->get('mirror.prefix'));

        $expectDriver = getenv('ORRERY_EXPECT_DRIVER');

        if (is_string($expectDriver) && $expectDriver !== '') {
            self::assertSame($expectDriver, $record->get('primary.driver'));
        }

        $expectPhp = getenv('ORRERY_EXPECT_PHP');

        if (is_string($expectPhp) && $expectPhp !== '') {
            self::assertTrue($expectPhp === PHP_VERSION || str_starts_with(PHP_VERSION, $expectPhp.'.'), 'PHP '.PHP_VERSION." is not {$expectPhp}");
        }

        $separate = getenv('ORRERY_TEST_MIRROR') === 'separate';

        if ($driver === 'sqlite') {
            $file = getenv('ORRERY_TEST_SQLITE_FILE') ?: null;
            self::assertSame($file ?? ':memory:', $record->get('primary.database'));
            self::assertSame($file === null ? ':memory:' : ($separate ? $file.'.mirror' : $file), $record->get('mirror.database'));
        } else {
            $base = (string) getenv('ORRERY_TEST_DATABASE');
            self::assertSame($base, $record->get('primary.database'));
            self::assertSame($separate ? $base.'_mirror' : $base, $record->get('mirror.database'));
            self::assertSame(getenv('ORRERY_TEST_TOKEN'), $record->get('primary.evidence.marker'));
            self::assertSame(getenv('ORRERY_TEST_TOKEN'), $record->get('mirror.evidence.marker'));
        }

        self::assertSame($record->toArray(), IdentityRecord::fromLine($record->line())->toArray());

        // The matrix runner reads the identity of each leg from this log.
        $log = getenv('ORRERY_IDENTITY_LOG');

        if (is_string($log) && $log !== '') {
            file_put_contents($log, $record->line()."\n", FILE_APPEND | LOCK_EX);
        }
    }

    public function test_a_wrong_expected_php_or_driver_fails_the_identity(): void
    {
        $env = $this->testEnvironment();
        $record = $env->identity($this->app);
        $otherDriver = $env->driver === 'mysql' ? 'pgsql' : 'mysql';

        foreach (['ORRERY_EXPECT_PHP' => '7.4', 'ORRERY_EXPECT_DRIVER' => $otherDriver] as $variable => $wrong) {
            $expecting = TestEnvironment::fromArray(array_merge(getenv(), [$variable => $wrong]));

            self::assertStringContainsString("({$variable})", $this->refusalOf(static fn () => $record->assertMatches($expecting)));
        }
    }

    public function test_package_writes_reach_only_the_mirror_database(): void
    {
        $env = $this->testEnvironment();
        /** @var Connection $primary */
        $primary = DB::connection(TestEnvironment::PRIMARY);
        $mirror = $this->mirror();
        $tag = 'harness-'.bin2hex(random_bytes(4));

        $this->app->make(MonitoringVersion::class)->monitor($tag);

        // Counted through the mirror's own PDO, with the physical table name.
        $table = $env->mirrorPrefix().'orrery_monitored_tags';
        $count = $mirror->getPdo()->prepare('SELECT COUNT(*) FROM '.self::quote($env->driver, $table).' WHERE tag = ?');
        $count->execute([$tag]);
        self::assertSame(1, (int) $count->fetchColumn());

        if ($env->siblingDatabase() !== null) {
            // The sibling SQLite mirror file: the package tables are not in the queue's file.
            self::assertSame(TestEnvironment::SIBLING, $mirror->getName());
            self::assertSame([], array_values(array_filter(PhysicalTables::of($primary), static fn (string $name): bool => str_contains($name, 'orrery_'))), 'a package table exists in the queue\'s file');

            return;
        }

        if (! $env->separate()) {
            self::assertSame(TestEnvironment::PRIMARY, $mirror->getName());
            self::assertSame($primary->getPdo(), $mirror->getPdo());

            return;
        }

        self::assertSame(TestEnvironment::MIRROR, $mirror->getName());
        self::assertNotSame($primary->getPdo(), $mirror->getPdo(), 'separate mode shares the PDO of testbench');

        $primaryTables = PhysicalTables::of($primary);
        self::assertSame([], array_values(array_filter($primaryTables, static fn (string $name): bool => str_contains($name, 'orrery_'))), 'a package table exists on testbench');
        self::assertContains($env->mirrorPrefix().self::FENCE_TABLE, PhysicalTables::of($mirror), 'the main migration creates the fence table on the mirror');

        foreach (self::AUTHORITATIVE_TABLES as $authoritative) {
            self::assertNotContains($env->mirrorPrefix().$authoritative, PhysicalTables::of($mirror), "{$authoritative} exists on the mirror");
            self::assertContains($authoritative, $primaryTables);
        }
    }

    public function test_physical_table_names_carry_the_prefix(): void
    {
        $env = $this->testEnvironment();
        $primaryTables = PhysicalTables::of(DB::connection(TestEnvironment::PRIMARY));
        $mirrorTables = PhysicalTables::of($this->mirror());

        foreach (self::AUTHORITATIVE_TABLES as $table) {
            self::assertContains($env->primaryPrefix().$table, $primaryTables);
        }

        foreach (self::PACKAGE_TABLES as $table) {
            self::assertContains($env->mirrorPrefix().$table, $mirrorTables);
        }

        if (! $env->separate() && $env->siblingDatabase() === null) {
            // One database holds the Laravel and the package tables.
            self::assertSame($primaryTables, $mirrorTables);
        }

        if ($env->prefix !== '') {
            // Every table of the prefixed connection carries the prefix, as
            // in a real prefixed application (the migrations table too).
            $prefixed = $env->separate() ? $mirrorTables : $primaryTables;
            self::assertSame([], array_values(array_filter($prefixed, static fn (string $name): bool => ! str_starts_with($name, $env->prefix))));

            if ($env->separate()) {
                self::assertSame([], array_values(array_filter($primaryTables, static fn (string $name): bool => str_starts_with($name, $env->prefix))));
            }
        }
    }

    public function test_hostile_application_variables_never_reach_a_connection(): void
    {
        $env = $this->testEnvironment();
        $config = $this->app['config'];

        // setUp already built this application with the hostile variables
        // set, proved its identity and migrated. The variables are still set.
        self::assertSame(self::HOSTILE['DB_URL'], getenv('DB_URL'));
        self::assertSame($env->queueConnection(), $config->get('database.connections.testbench'));
        self::assertSame($env->mirrorConnection(), $config->get('database.connections.testbench_mirror'));
        self::assertNull(DB::connection(TestEnvironment::PRIMARY)->getConfig('url'));
        $env->identity($this->app)->assertMatches($env);

        // The real queue path writes the job to testbench and the mirror row to the mirror.
        Queue::push(new BasicJob);
        self::assertSame(1, DB::connection(TestEnvironment::PRIMARY)->table('jobs')->count());
        self::assertSame(1, $this->mirror()->table('orrery_jobs')->count());
    }

    #[FreshSchema]
    public function test_a_mirror_outage_refuses_to_begin_inside_a_transaction(): void
    {
        $env = $this->testEnvironment();

        foreach (array_unique([TestEnvironment::PRIMARY, $env->mirrorConnectionName()]) as $name) {
            /** @var Connection $connection */
            $connection = DB::connection($name);
            $before = PhysicalTables::of($this->mirror());
            [$table, $row, $column] = self::businessRow($name);

            $connection->beginTransaction();
            $connection->table($table)->insert($row);
            $refused = null;

            try {
                $this->mirrorOutage();
            } catch (HarnessRefusal $refusal) {
                $refused = $refusal;
            }

            $connection->rollBack();

            // MySQL commits an open transaction at the first DDL statement. A
            // begin() that renamed tables here would have committed the row.
            self::assertSame(0, $connection->table($table)->where($column, 'harness-business')->count(), "the business row on {$name} was committed");
            self::assertNotNull($refused, "MirrorOutage::begin() ran inside an open transaction on {$name}");
            self::assertStringContainsString('open transaction', $refused->getMessage());
            self::assertSame($before, PhysicalTables::of($this->mirror()), 'the refused begin() changed the package tables');
        }
    }

    #[FreshSchema]
    public function test_a_mirror_outage_refuses_to_begin_inside_a_transaction_opened_on_the_pdo(): void
    {
        $env = $this->testEnvironment();

        foreach (array_unique([TestEnvironment::PRIMARY, $env->mirrorConnectionName()]) as $name) {
            /** @var Connection $connection */
            $connection = DB::connection($name);
            $pdo = $connection->getPdo();
            $before = PhysicalTables::of($this->mirror());
            [$table, $row, $column] = self::businessRow($name);

            $pdo->beginTransaction();
            $connection->table($table)->insert($row);
            // Laravel does not count this transaction.
            self::assertSame(0, $connection->transactionLevel());
            $refused = null;

            try {
                $this->mirrorOutage();
            } catch (HarnessRefusal $refusal) {
                $refused = $refusal;
            }

            // After an implicit commit (MySQL DDL), the PDO has no transaction left.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            self::assertSame(0, $connection->table($table)->where($column, 'harness-business')->count(), "the business row on {$name} was committed");
            self::assertNotNull($refused, "MirrorOutage::begin() ran inside a transaction opened on the PDO of {$name}");
            self::assertStringContainsString('open transaction', $refused->getMessage());
            self::assertSame($before, PhysicalTables::of($this->mirror()), 'the refused begin() changed the package tables');
        }
    }

    #[FreshSchema]
    public function test_a_mirror_outage_refuses_to_end_inside_a_transaction_opened_on_the_pdo(): void
    {
        $before = PhysicalTables::of($this->mirror());
        $outage = $this->mirrorOutage();
        /** @var Connection $connection */
        $connection = DB::connection(TestEnvironment::PRIMARY);
        $pdo = $connection->getPdo();
        [$table, $row, $column] = self::businessRow(TestEnvironment::PRIMARY);

        $pdo->beginTransaction();
        $connection->table($table)->insert($row);
        $refused = null;

        try {
            $outage->end();
        } catch (HarnessRefusal $refusal) {
            $refused = $refusal;
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        self::assertSame(0, $connection->table($table)->where($column, 'harness-business')->count(), 'the business row was committed');
        self::assertNotNull($refused, 'MirrorOutage::end() ran inside a transaction opened on the PDO');
        self::assertStringContainsString('open transaction', $refused->getMessage());
        self::assertTrue($outage->isActive(), 'the refused end() ended the outage');

        $outage->end();
        self::assertFalse($outage->isActive());
        self::assertSame($before, PhysicalTables::of($this->mirror()));
    }

    #[FreshSchema]
    public function test_the_teardown_rollback_covers_a_transaction_opened_on_the_pdo(): void
    {
        $before = PhysicalTables::of($this->mirror());
        $outage = $this->mirrorOutage();
        /** @var Connection $connection */
        $connection = DB::connection(TestEnvironment::PRIMARY);
        $pdo = $connection->getPdo();
        [$table, $row, $column] = self::businessRow(TestEnvironment::PRIMARY);

        $pdo->beginTransaction();
        $connection->table($table)->insert($row);
        self::assertSame([TestEnvironment::PRIMARY], MirrorOutage::openTransactions($this->app));

        // What TestCase::tearDown() does before it ends an active outage.
        MirrorOutage::rollBackOpenTransactions($this->app);

        self::assertFalse($pdo->inTransaction(), 'the rollback left the PDO transaction open');
        self::assertSame([], MirrorOutage::openTransactions($this->app));
        self::assertSame(0, $connection->table($table)->where($column, 'harness-business')->count(), 'the rollback kept the business row');

        $outage->end();
        self::assertSame($before, PhysicalTables::of($this->mirror()));
    }

    #[FreshSchema]
    public function test_a_mirror_outage_fails_only_the_package_storage_and_ends_without_loss(): void
    {
        $env = $this->testEnvironment();
        $sentinel = bin2hex(random_bytes(8));
        $jobId = DB::connection(TestEnvironment::PRIMARY)->table('jobs')->insertGetId(
            ['queue' => 'harness-sentinel', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
        );
        $this->mirror()->table('orrery_settings')->insert(['key' => 'harness-sentinel', 'value' => $sentinel]);
        $primaryTables = PhysicalTables::of(DB::connection(TestEnvironment::PRIMARY));
        $mirrorTables = PhysicalTables::of($this->mirror());

        $outage = $this->mirrorOutage();
        self::assertSame(MirrorOutage::methodFor($env), $outage->method);

        $failure = null;

        try {
            Setting::query()->where('key', 'harness-sentinel')->value('value');
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertNotNull($failure, 'the package storage still worked during the outage');
        self::assertSame(1, DB::connection(TestEnvironment::PRIMARY)->table('jobs')->where('id', $jobId)->count(), 'the outage broke the authoritative storage');

        $outage->end();

        // Both sentinels and both schemas survived begin() and end().
        self::assertSame(1, DB::connection(TestEnvironment::PRIMARY)->table('jobs')->where('id', $jobId)->count());
        self::assertSame($sentinel, $this->mirror()->table('orrery_settings')->where('key', 'harness-sentinel')->value('value'));
        self::assertSame($primaryTables, PhysicalTables::of(DB::connection(TestEnvironment::PRIMARY)));
        self::assertSame($mirrorTables, PhysicalTables::of($this->mirror()));
        self::assertSame($sentinel, Setting::query()->where('key', 'harness-sentinel')->value('value'), 'the package storage did not come back');
    }

    /**
     * Rejects: an outage that changes only the configured mirror connection, while the package's own mirror session keeps its PDO.
     */
    #[FreshSchema]
    public function test_a_mirror_outage_reaches_the_package_sessions(): void
    {
        $sentinel = bin2hex(random_bytes(8));
        $this->mirror()->table('orrery_settings')->insert(['key' => 'harness-session', 'value' => $sentinel]);
        $sessions = $this->app->make(PackageSession::class);

        // The package session exists and is connected before the outage.
        self::assertSame($sentinel, $sessions->mirror()->table('orrery_settings')->where('key', 'harness-session')->value('value'));

        $outage = $this->mirrorOutage();
        $failure = null;

        try {
            $sessions->mirror()->table('orrery_settings')->where('key', 'harness-session')->value('value');
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertNotNull($failure, "the package's mirror session still worked during the outage ({$outage->method})");

        $outage->end();

        self::assertSame($sentinel, $sessions->mirror()->table('orrery_settings')->where('key', 'harness-session')->value('value'), "the package's mirror session did not come back");
    }

    /**
     * Rejects: a transaction check that misses a package transaction, which PHP 8.3 does not report through PDO::inTransaction() after a SQLite BEGIN IMMEDIATE.
     */
    #[FreshSchema]
    public function test_a_mirror_outage_refuses_to_begin_inside_a_package_transaction(): void
    {
        $before = PhysicalTables::of($this->mirror());
        $session = $this->app->make(PackageSession::class)->mirror();
        $refused = null;

        try {
            $this->app->make(PackageTransaction::class)->run($session, function (Connection $session): void {
                $session->table('orrery_settings')->insert(['key' => 'harness-package', 'value' => 'x']);
                self::assertContains((string) $session->getName(), MirrorOutage::openTransactions($this->app));

                $this->mirrorOutage();
            });
        } catch (HarnessRefusal $refusal) {
            $refused = $refusal;
        }

        self::assertNotNull($refused, 'MirrorOutage::begin() ran inside a package transaction');
        self::assertStringContainsString('open transaction', $refused->getMessage());
        self::assertSame($before, PhysicalTables::of($this->mirror()), 'the refused begin() changed the package tables');
        self::assertSame(0, $this->mirror()->table('orrery_settings')->where('key', 'harness-package')->count(), 'the package transaction was committed');
        self::assertSame([], MirrorOutage::openTransactions($this->app));
    }

    public function test_the_lease_proof_accepts_an_owned_member_of_the_live_lease(): void
    {
        $env = self::serverEnvironment();
        $calls = [];
        LeaseProof::evaluate(self::registryStatus($env, self::GROUP), $env, self::CALLER, self::GROUP, self::members(self::ownedMembers(), $calls));

        // The proof asked the rules of testdb.php about the recorded group:
        // its number, the recorded leader start and the group token.
        self::assertSame([[self::GROUP, self::LEADER_START, self::GROUP_TOKEN]], $calls);

        foreach ([
            'a closing lease' => ['closing' => true],
            'another member is unverified' => ['alive' => ['supervisor' => true, 'children' => [self::GROUP], 'unverified' => [99]]],
        ] as $change) {
            $status = self::registryStatus($env, self::GROUP);
            $status['leases'][$env->runId] = array_merge($status['leases'][$env->runId], $change);
            LeaseProof::evaluate($status, $env, self::CALLER, self::GROUP, self::members(self::ownedMembers(), $calls));
            $this->addToAssertionCount(1);
        }

        // The real registry and the real process inspection, on a server run with a lease.
        $real = $this->testEnvironment();

        if ($real->isServer() && ! $real->ci) {
            LeaseProof::verify($real);
            self::assertSame('lease', LeaseProof::ensure($real));
        } else {
            self::assertSame($real->isServer() ? 'ci' : 'none', LeaseProof::ensure($real));
        }
    }

    /**
     * @param  callable(array<string, mixed>, TestEnvironment): array<string, mixed>  $change
     * @param  array{owned: array<int, string>, unverified: array<int, string>}|null  $members
     */
    #[DataProvider('refusedLeases')]
    public function test_the_lease_proof_refuses_a_run_that_does_not_hold_its_database(callable $change, ?array $members, string $message): void
    {
        $env = self::serverEnvironment();
        $calls = [];

        self::assertStringContainsString($message, $this->refusalOf(static fn () => LeaseProof::evaluate(
            $change(self::registryStatus($env, self::GROUP), $env),
            $env,
            self::CALLER,
            self::GROUP,
            self::members($members, $calls),
        )));
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>, TestEnvironment): array<string, mixed>, 1: array{owned: array<int, string>, unverified: array<int, string>}|null, 2: string}>
     */
    public static function refusedLeases(): iterable
    {
        $owned = self::ownedMembers();
        $same = static fn (array $status): array => $status;

        yield 'no lease for the run' => [static function (array $status, TestEnvironment $env): array {
            unset($status['leases'][$env->runId]);

            return $status;
        }, $owned, 'holds no lease'];
        yield 'the mirror name is not held' => [static function (array $status, TestEnvironment $env): array {
            $status['leases'][$env->runId]['names'] = [$env->database];
            unset($status['names'][$env->database.'_mirror']);

            return $status;
        }, $owned, 'does not hold the database name orrery_harness_mirror'];
        yield 'another run holds the name' => [static function (array $status, TestEnvironment $env): array {
            $status['names'][$env->database] = 'run_2_0000000b';

            return $status;
        }, $owned, 'does not hold the database name orrery_harness'];
        yield 'the registry token differs' => [static function (array $status): array {
            $status['token'] = str_repeat('b', 32);

            return $status;
        }, $owned, 'differs from the registry token'];
        yield 'another engine' => [static function (array $status, TestEnvironment $env): array {
            $status['leases'][$env->runId]['engine'] = 'pgsql';

            return $status;
        }, $owned, 'is for the engine "pgsql"'];
        yield 'not live' => [static function (array $status, TestEnvironment $env): array {
            $status['leases'][$env->runId]['live'] = false;

            return $status;
        }, $owned, 'is not live'];
        yield 'another process group' => [static function (array $status, TestEnvironment $env): array {
            $status['leases'][$env->runId]['children'][0]['pid'] = self::GROUP + 1;

            return $status;
        }, $owned, 'does not list the process group 4242'];
        yield 'a plain child with the group id' => [static function (array $status, TestEnvironment $env): array {
            $status['leases'][$env->runId]['children'][0]['group'] = false;

            return $status;
        }, $owned, 'does not list the process group 4242'];
        yield 'the registry does not count the group as owned' => [static function (array $status, TestEnvironment $env): array {
            // Live only through an unverified process: the raw entry is still there.
            $status['leases'][$env->runId]['alive'] = ['supervisor' => false, 'children' => [], 'unverified' => [99]];

            return $status;
        }, $owned, 'does not count the recorded process group 4242'];
        yield 'this process is unverified' => [static function (array $status, TestEnvironment $env): array {
            $status['leases'][$env->runId]['alive']['unverified'] = [self::CALLER];

            return $status;
        }, $owned, 'is unverified in the lease'];
        yield 'a reused group number (the leader has another start)' => [$same, null, 'is not an owned member of the recorded process group 4242'];
        yield 'this process is not an owned member' => [$same, ['owned' => [self::GROUP => self::LEADER_START], 'unverified' => [self::CALLER => self::LEADER_START]], 'is not an owned member of the recorded process group 4242'];
        yield 'an unreadable status' => [static function (array $status): array {
            $status['ok'] = false;

            return $status;
        }, $owned, 'not readable'];
    }

    public function test_a_cached_proof_does_not_cover_another_token(): void
    {
        // A synthetic registry and process inspection, in this process: the
        // lease lists this process group and owns this process. A new run id
        // keeps earlier proofs of this process out of the way.
        $variables = [
            'ORRERY_TEST_DRIVER' => 'mysql',
            'ORRERY_TEST_DATABASE' => 'orrery_harness',
            'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
            'ORRERY_TEST_RUN_ID' => 'run_1_'.bin2hex(random_bytes(4)),
        ];
        $env = TestEnvironment::fromArray($variables);
        $reads = 0;
        $status = static function () use (&$reads, $env): array {
            $reads++;

            return self::registryStatus($env, LeaseProof::processGroup());
        };
        $members = static fn (int $group, string $start, ?string $token): array => ['owned' => [(int) getmypid() => $start], 'unverified' => []];
        $verifications = LeaseProof::verifications();

        self::assertSame('lease', LeaseProof::ensure($env, $status, $members));
        self::assertSame('lease', LeaseProof::ensure($env, $status, $members));
        self::assertSame(1, $reads, 'a second proof of the same context read the registry again');

        $changed = TestEnvironment::fromArray(array_merge($variables, ['ORRERY_TEST_TOKEN' => str_repeat('b', 32)]));
        self::assertStringContainsString('differs from the registry token', $this->refusalOf(static fn () => LeaseProof::ensure($changed, $status, $members)));
        self::assertSame(2, $reads, 'a proof with another token came from the cache');
        self::assertSame($verifications + 2, LeaseProof::verifications());

        // The real registry, on a server run with a lease: tests/bootstrap.php
        // proved this run; another token needs a new proof, and it fails.
        $real = $this->testEnvironment();

        if (! $real->isServer() || $real->ci) {
            return;
        }

        $before = LeaseProof::verifications();
        self::assertSame('lease', LeaseProof::ensure($real));
        self::assertSame($before, LeaseProof::verifications(), 'the proof of this run was not cached');

        $wrongToken = str_repeat('0', 32) === $real->token ? str_repeat('1', 32) : str_repeat('0', 32);
        $wrong = TestEnvironment::fromArray(array_merge(getenv(), ['ORRERY_TEST_TOKEN' => $wrongToken]));
        self::assertStringContainsString('differs from the registry token', $this->refusalOf(static fn () => LeaseProof::ensure($wrong)));
        self::assertSame($before + 1, LeaseProof::verifications());
    }

    public function test_the_real_registry_refuses_a_run_id_or_a_name_that_this_run_does_not_hold(): void
    {
        $real = $this->testEnvironment();

        if (! $real->isServer() || $real->ci) {
            // Without an admitted run there is no lease to read. The decisions
            // are covered on synthetic input above; the environment itself
            // must still refuse a server driver without a run.
            $message = $this->refusalOf(static fn () => TestEnvironment::fromArray([
                'ORRERY_TEST_DRIVER' => 'mysql',
                'ORRERY_TEST_DATABASE' => 'orrery_harness',
                'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
            ]));
            self::assertStringContainsString('Run the suite through bin/test mysql', $message);

            return;
        }

        $otherRun = TestEnvironment::fromArray(array_merge(getenv(), ['ORRERY_TEST_RUN_ID' => 'run_1_'.bin2hex(random_bytes(4))]));
        self::assertStringContainsString('holds no lease', $this->refusalOf(static fn () => LeaseProof::verify($otherRun)));

        $otherName = TestEnvironment::fromArray(array_merge(getenv(), ['ORRERY_TEST_DATABASE' => 'orrery_harness_'.bin2hex(random_bytes(4))]));
        self::assertStringContainsString('does not hold the database name', $this->refusalOf(static fn () => LeaseProof::verify($otherName)));
    }

    public function test_a_wrong_token_fails_the_identity(): void
    {
        // Synthetic: a record that matches a server environment, then the same
        // record with another marker.
        $env = self::serverEnvironment();
        $record = self::matchingServerRecord($env);
        IdentityRecord::fromArray($record)->assertMatches($env);

        $record['mirror']['evidence']['marker'] = str_repeat('b', 32);
        self::assertStringContainsString('mirror.evidence.marker: expected "'.str_repeat('a', 32).'", got "'.str_repeat('b', 32).'"', $this->refusalOf(static fn () => IdentityRecord::fromArray($record)->assertMatches($env)));

        // Real: the resolved connections of this server run against another token.
        $real = $this->testEnvironment();

        if (! $real->isServer()) {
            return;
        }

        $wrongToken = str_repeat('0', 32) === $real->token ? str_repeat('1', 32) : str_repeat('0', 32);
        $wrong = TestEnvironment::fromArray(array_merge(getenv(), ['ORRERY_TEST_TOKEN' => $wrongToken]));
        $message = $this->refusalOf(fn () => $real->identity($this->app)->assertMatches($wrong));
        self::assertStringContainsString("primary.evidence.marker: expected \"{$wrongToken}\"", $message);
        self::assertStringContainsString("mirror.evidence.marker: expected \"{$wrongToken}\"", $message);
    }

    /**
     * @param  array<string, string>  $variables
     */
    #[DataProvider('refusedConfigurations')]
    public function test_an_invalid_configuration_is_refused(array $variables, string $message): void
    {
        self::assertStringContainsString($message, $this->refusalOf(static fn () => TestEnvironment::fromArray($variables)));
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function refusedConfigurations(): iterable
    {
        $server = ['ORRERY_TEST_DRIVER' => 'mysql', 'ORRERY_TEST_DATABASE' => 'orrery_harness', 'ORRERY_TEST_TOKEN' => str_repeat('a', 32), 'ORRERY_TEST_RUN_ID' => 'run_1_0000000a'];

        yield 'unknown driver' => [['ORRERY_TEST_DRIVER' => 'sqlsrv'], 'must be sqlite, mysql, pgsql or mariadb'];
        yield 'unknown mirror mode' => [['ORRERY_TEST_MIRROR' => 'both'], 'must be same or separate'];
        yield 'invalid prefix' => [['ORRERY_TEST_PREFIX' => 'Pfx-'], 'ORRERY_TEST_PREFIX must match'];
        yield 'prefix too long' => [['ORRERY_TEST_PREFIX' => str_repeat('p', 17)], 'ORRERY_TEST_PREFIX must match'];
        yield 'server name with sqlite' => [['ORRERY_TEST_DATABASE' => 'orrery_harness'], 'never falls back to SQLite silently'];
        yield 'server without a run' => [array_diff_key($server, ['ORRERY_TEST_RUN_ID' => 1]), 'Run the suite through bin/test mysql'];
        yield 'server without a token' => [array_diff_key($server, ['ORRERY_TEST_TOKEN' => 1]), 'Run the suite through bin/test mysql'];
        yield 'ci with a run id' => [$server + ['ORRERY_TEST_CI' => '1'], 'are both set'];
        yield 'a name with a 41-character suffix' => [array_merge($server, ['ORRERY_TEST_DATABASE' => 'orrery_'.str_repeat('a', 41)]), 'must match ^orrery_[a-z0-9_]{1,40}$'];
        yield 'a name that ends in _mirror' => [array_merge($server, ['ORRERY_TEST_DATABASE' => 'orrery_a_mirror']), 'ends in _mirror'];
        yield 'the marker database' => [array_merge($server, ['ORRERY_TEST_DATABASE' => 'orrery_marker']), 'reserved'];
        yield 'an injected name' => [array_merge($server, ['ORRERY_TEST_DATABASE' => 'orrery_a;drop']), 'must match'];
        yield 'an invalid token' => [array_merge($server, ['ORRERY_TEST_TOKEN' => 'secret']), '32 lowercase hex'];
        yield 'an invalid port' => [$server + ['ORRERY_TEST_MYSQL_PORT' => '1'], 'must be a port number'];
        yield 'a sqlite file with a server' => [$server + ['ORRERY_TEST_SQLITE_FILE' => '/tmp/orrery-sqlite-x/primary.sqlite'], 'Unset one of them'];
        yield 'a relative sqlite file' => [['ORRERY_TEST_SQLITE_FILE' => 'primary.sqlite'], 'must be an absolute path'];
        yield 'a missing sqlite file' => [['ORRERY_TEST_SQLITE_FILE' => '/nonexistent/orrery-sqlite-x/primary.sqlite'], 'does not exist'];
        yield 'an invalid expected PHP' => [['ORRERY_EXPECT_PHP' => 'eight'], 'ORRERY_EXPECT_PHP must be'];
    }

    public function test_a_valid_configuration_is_accepted_at_the_name_limits(): void
    {
        $env = TestEnvironment::fromArray([
            'ORRERY_TEST_DRIVER' => 'pgsql',
            'ORRERY_TEST_DATABASE' => 'orrery_'.str_repeat('a', 40),
            'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
            'ORRERY_TEST_CI' => '1',
            'ORRERY_TEST_MIRROR' => 'separate',
            'ORRERY_TEST_PREFIX' => str_repeat('p', 16),
            'DB_URL' => 'mysql://intruder@127.0.0.1:1/production',
        ]);

        self::assertSame('orrery_'.str_repeat('a', 40).'_mirror', $env->mirrorDatabase());
        self::assertSame(54336, $env->port);
        self::assertNull($env->queueConnection()['url']);
        self::assertSame('127.0.0.1', $env->queueConnection()['host']);
        self::assertSame('', $env->queueConnection()['prefix']);
        self::assertSame(str_repeat('p', 16), $env->mirrorConnection()['prefix'] ?? null);
        self::assertSame('orrery_'.str_repeat('a', 40).'_mirror', $env->mirrorConnection()['database'] ?? null);
    }

    public function test_the_child_environment_reproduces_this_configuration(): void
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            $this->assertInMemoryCannotBeShared($env);

            // A file configuration with the same mirror mode and prefix stands in.
            $file = $this->sqliteFiles[] = SqliteFiles::create();
            $env = TestEnvironment::fromArray(array_merge(getenv(), ['ORRERY_TEST_SQLITE_FILE' => $file]));
        }

        $child = $env->toProcessEnv();
        self::assertSame(TestEnvironment::VARIABLES, array_keys($child));

        // Poisoned parent values are replaced, because every variable is present.
        $poisoned = array_fill_keys(TestEnvironment::VARIABLES, 'poison');
        $applied = array_filter(array_merge($poisoned, $child), static fn (mixed $value): bool => $value !== false);
        self::assertEquals($env, TestEnvironment::fromArray($applied));
    }

    /**
     * Rejects: a child on the harness's array store, or on another shared directory, accepted for a test that opted into the shared file cache.
     */
    public function test_the_shared_cache_is_part_of_the_identity(): void
    {
        $plain = $this->testEnvironment();
        $env = $this->useSharedCache();
        self::assertNotNull($env->sharedCache);
        self::assertSame(SharedCache::STORE, $this->app['config']->get('cache.default'));

        $record = $env->identity($this->app);
        $record->assertMatches($env);
        self::assertSame($env->sharedCache, $record->get('cache_path'));
        self::assertStringContainsString('cache: expected "'.SharedCache::STORE.'", got "array"', $this->refusalOf(static fn () => IdentityRecord::fromArray(['cache' => 'array', 'cache_path' => null] + $record->toArray())->assertMatches($env)));

        $other = SharedCache::create();
        self::assertStringContainsString('cache_path: expected', $this->refusalOf(static fn () => IdentityRecord::fromArray(['cache_path' => $other] + $record->toArray())->assertMatches($env)));
        self::assertStringContainsString('cache: expected "array"', $this->refusalOf(static fn () => $record->assertMatches($plain)));

        if (! $plain->inMemory()) {
            $child = $env->toProcessEnv();
            self::assertSame($env->sharedCache, $child[SharedCache::VARIABLE]);
            self::assertFalse($plain->toProcessEnv()[SharedCache::VARIABLE]);
        }
    }

    public function test_sqlite_files_must_be_owned_canonical_and_private(): void
    {
        $file = $this->sqliteFiles[] = SqliteFiles::create();
        $directory = dirname($file);

        self::assertSame($file, SqliteFiles::validate($file, true));
        self::assertMatchesRegularExpression(SqliteFiles::DIRECTORY_PATTERN, basename($directory));
        self::assertFileExists($file.'.mirror');

        $link = $directory.'/link.sqlite';
        symlink($file, $link);
        self::assertStringContainsString('is a symlink', $this->refusalOf(static fn () => SqliteFiles::validate($link, false)));
        unlink($link);

        chmod($directory, 0750);
        self::assertStringContainsString('must be private', $this->refusalOf(static fn () => SqliteFiles::validate($file, false)));
        chmod($directory, 0700);

        rename($file.'.mirror', $file.'.moved');
        self::assertStringContainsString('does not exist', $this->refusalOf(static fn () => SqliteFiles::validate($file, true)));
        self::assertSame($file, SqliteFiles::validate($file, false));
        rename($file.'.moved', $file.'.mirror');

        $outside = sys_get_temp_dir().'/orrery-harness-'.bin2hex(random_bytes(4)).'.sqlite';
        touch($outside);

        try {
            self::assertStringContainsString('orrery-sqlite-', $this->refusalOf(static fn () => SqliteFiles::validate((string) realpath($outside), false)));
        } finally {
            unlink($outside);
        }

        file_put_contents($directory.'/notes.txt', 'x');
        self::assertStringContainsString('not a SQLite test file', $this->refusalOf(static fn () => SqliteFiles::remove($file)));
        unlink($directory.'/notes.txt');

        SqliteFiles::remove($file);
        $this->sqliteFiles = [];
        self::assertDirectoryDoesNotExist($directory);
    }

    public function test_every_test_starts_with_clean_package_state(): void
    {
        // The Controller and control-plane tests set the gate callback and the
        // caches; TestCase resets them after each test and before the next one.
        self::assertSame(self::cleanState(), self::packageState());
    }

    public function test_the_reset_hook_clears_every_static_state_of_the_package(): void
    {
        Orrery::auth(static fn (): bool => true);
        Orrery::routeMailNotificationsTo('ops@example.test');
        Orrery::routeSlackNotificationsTo('https://hooks.example.test/x', '#ops');
        Orrery::routeSmsNotificationsTo('+10000000000');
        Closure::bind(static function (): void {
            Tags::$event = new \stdClass;
        }, null, Tags::class)();
        Hooks::$callback = static function (string $point): void {};
        self::assertNotSame(self::cleanState(), self::packageState());

        self::resetPackageState();

        self::assertSame(self::cleanState(), self::packageState());
    }

    /**
     * @return array<string, mixed>
     */
    private static function packageState(): array
    {
        $read = static fn (string $class, string $property): mixed => Closure::bind(static fn () => $class::$$property, null, $class)();

        return [
            'auth' => Orrery::$authUsing,
            'email' => Orrery::$email,
            'slack' => Orrery::$slackWebhookUrl,
            'slack channel' => Orrery::$slackChannel,
            'sms' => Orrery::$smsNumber,
            'tags event' => $read(Tags::class, 'event'),
            'hook callback' => Hooks::$callback,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cleanState(): array
    {
        return [
            'auth' => null,
            'email' => null,
            'slack' => null,
            'slack channel' => null,
            'sms' => null,
            'tags event' => null,
            'hook callback' => null,
        ];
    }

    private function assertInMemoryCannotBeShared(TestEnvironment $env): void
    {
        try {
            $env->toProcessEnv();
            self::fail('toProcessEnv() handed an in-memory database to a child');
        } catch (LogicException $exception) {
            self::assertStringContainsString('in-memory', $exception->getMessage());
        }
    }

    /**
     * The message of the HarnessRefusal that $callback throws. It fails when
     * the callback throws nothing or anything else.
     */
    private function refusalOf(callable $callback): string
    {
        try {
            $callback();
        } catch (HarnessRefusal $refusal) {
            return $refusal->getMessage();
        }

        self::fail('The harness did not refuse.');
    }

    /**
     * The members of the synthetic group as testdb.php's rules would give
     * them: the leader and the calling process are owned.
     *
     * @return array{owned: array<int, string>, unverified: array<int, string>}
     */
    private static function ownedMembers(): array
    {
        return ['owned' => [self::CALLER => self::LEADER_START, self::GROUP => self::LEADER_START], 'unverified' => []];
    }

    /**
     * A stand-in for the process inspection of LeaseProof: it records each
     * question (group, leader start, token) and gives $result.
     *
     * @param  array{owned: array<int, string>, unverified: array<int, string>}|null  $result
     * @param  list<array{0: int, 1: string, 2: string|null}>  $calls
     */
    private static function members(?array $result, array &$calls): callable
    {
        return static function (int $group, string $start, ?string $token) use ($result, &$calls): ?array {
            $calls[] = [$group, $start, $token];

            return $result;
        };
    }

    /**
     * A business row for a transaction control: a job on testbench, or a
     * setting on the mirror connection.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: string} table, row, marker column
     */
    private static function businessRow(string $connection): array
    {
        return $connection === TestEnvironment::PRIMARY
            ? ['jobs', ['queue' => 'harness-business', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()], 'queue']
            : ['orrery_settings', ['key' => 'harness-business', 'value' => 'x'], 'key'];
    }

    private static function serverEnvironment(): TestEnvironment
    {
        return TestEnvironment::fromArray([
            'ORRERY_TEST_DRIVER' => 'mysql',
            'ORRERY_TEST_DATABASE' => 'orrery_harness',
            'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
            'ORRERY_TEST_RUN_ID' => 'run_1_0000000a',
            'ORRERY_TEST_MIRROR' => 'separate',
        ]);
    }

    /**
     * A registry status (`testdb.php status --no-services`) in which the run
     * of $env holds both names and owns the recorded process group $group.
     *
     * @return array<string, mixed>
     */
    private static function registryStatus(TestEnvironment $env, int $group): array
    {
        return [
            'ok' => true,
            'registry' => '/tmp/orrery-testing-0',
            'token' => $env->token,
            'leases' => [
                (string) $env->runId => [
                    'supervisor' => ['pid' => 100, 'start' => 'Wed Sep 23 10:00:00 2026'],
                    'children' => [['pid' => $group, 'start' => self::LEADER_START, 'group' => true, 'run' => $env->runId, 'token' => self::GROUP_TOKEN]],
                    'names' => [$env->database, $env->database.'_mirror'],
                    'base' => $env->database,
                    'engine' => $env->driver,
                    'generated' => false,
                    'closing' => false,
                    'live' => true,
                    'alive' => ['supervisor' => true, 'children' => [$group], 'unverified' => []],
                ],
            ],
            'names' => [$env->database => $env->runId, $env->database.'_mirror' => $env->runId],
            'orphans' => [],
        ];
    }

    /**
     * An identity record that matches a server environment in separate mode.
     *
     * @return array<string, mixed>
     */
    private static function matchingServerRecord(TestEnvironment $env): array
    {
        $side = static fn (string $connection, string $database, int $pdo): array => [
            'connection' => $connection,
            'driver' => $env->driver,
            'database' => $database,
            'prefix' => '',
            'server_version' => TestEnvironment::SERVER_VERSIONS[$env->driver].'0',
            'pdo' => $pdo,
            'evidence' => ['kind' => 'endpoint-token', 'marker' => $env->token, 'host' => TestEnvironment::HOST, 'port' => $env->port, 'session' => $pdo],
        ];

        return [
            'php' => PHP_VERSION,
            'default' => 'testbench',
            'queue' => ['default' => 'database', 'driver' => 'database', 'connection' => 'testbench', 'table' => 'jobs'],
            'package' => ['setting' => 'testbench_mirror', 'connection' => 'testbench_mirror'],
            'primary' => $side('testbench', $env->database, 1),
            'mirror' => $side('testbench_mirror', $env->mirrorDatabase(), 2),
            'failed' => ['driver' => 'database-uuids', 'database' => 'testbench', 'table' => 'failed_jobs'],
            'batching' => ['database' => 'testbench', 'table' => 'job_batches'],
            'cache' => 'array',
            'session' => 'array',
        ];
    }

    private static function quote(string $driver, string $name): string
    {
        return $driver === 'mysql' || $driver === 'mariadb' ? '`'.$name.'`' : '"'.$name.'"';
    }

    /**
     * Sets process variables everywhere Laravel reads them, and remembers the
     * old values for restoreVariables().
     *
     * @param  array<string, string>  $variables
     */
    private function setVariables(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $this->savedVariables[$name] ??= [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function restoreVariables(): void
    {
        foreach ($this->savedVariables as $name => [$env, $superEnv, $server]) {
            putenv($env === false ? $name : "{$name}={$env}");

            if ($superEnv === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $superEnv;
            }

            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }

        $this->savedVariables = [];
    }
}
