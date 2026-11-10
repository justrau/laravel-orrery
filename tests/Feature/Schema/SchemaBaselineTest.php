<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Database\Schema\SchemaOutdated;
use Justrau\Orrery\Database\Schema\SchemaStatus;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\DescribesPackageTables;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;

/**
 * The frozen baseline: the schema
 * that create() and mark() built at PackageSchema::BASELINE, recorded per
 * engine as buildable statements in tests/Fixtures/schema-baseline/, with
 * the placeholder {prefix} before every table and index name. The baseline
 * plus every real upgrade migration must give the schema of a fresh
 * create(): an edit of create() without a version step and an upgrade
 * migration fails here. The fixture version (a create() seam, a step
 * VERSION + 1 and a fixture upgrade migration) proves the mechanism.
 *
 * It runs DDL, so it has its own schema (#[FreshSchema]).
 *
 * Until the tag v0.1.0 a schema change edits create() in place and
 * captures the three fixtures again: run this class once per engine with
 * ORRERY_CAPTURE_SCHEMA_BASELINE=1 and ORRERY_TEST_PREFIX=pfx_ (for example
 * `ORRERY_CAPTURE_SCHEMA_BASELINE=1 ORRERY_TEST_PREFIX=pfx_ bin/test mysql
 * -- --filter test_the_baseline_and_the_upgrade_migrations`; that test
 * writes the fixture before it builds it), then record the new hashes in
 * MigrationFreezeTest in the same commit. After the tag the fixtures never
 * change.
 */
#[FreshSchema]
final class SchemaBaselineTest extends TestCase
{
    use DescribesPackageTables;

    /** The placeholder of the table prefix in the fixtures. */
    private const PREFIX = '{prefix}';

    /** The fixture table of the fixture version (the create() seam and its step). */
    private const FIXTURE_TABLE = 'orrery_fixture_notes';

    /** The engine that the fixture of a driver was captured on. */
    private const ENGINES = ['sqlite' => 'sqlite', 'mysql' => 'mysql', 'pgsql' => 'pgsql'];

    /**
     * Rejects: a baseline whose names the placeholder replacement
     * cannot reproduce. IndexName hashes a name longer than 63 bytes with the prefix inside, so
     * `{prefix}` replaced by a longer prefix is no longer the name that create() builds.
     * Still passes: a prefix that MySQL refuses for another reason (a table name over 64 bytes
     * fails there with its own error).
     */
    public function test_no_table_or_index_name_passes_63_bytes_for_the_run_prefix(): void
    {
        $prefix = $this->mirror()->getTablePrefix();
        $names = self::fixtureNames($this->fixture($this->mirror()));

        self::assertNotSame([], $names);
        self::assertContains('orrery_jobs', $names, 'The fixture names the package tables.');

        foreach ($names as $name) {
            $length = strlen($prefix.$name);
            self::assertLessThanOrEqual(IndexName::MAX_LENGTH, $length, "{$prefix}{$name} has {$length} bytes, more than IndexName::MAX_LENGTH: the baseline cannot be replayed with this prefix.");
        }
    }

    /**
     * Rejects: a forward step at or below BASELINE (the pre-v0.1
     * steps 2 to 10 are gone), and a VERSION below BASELINE.
     */
    public function test_upgrades_hold_no_step_at_or_below_the_baseline(): void
    {
        self::assertGreaterThanOrEqual(PackageSchema::BASELINE, PackageSchema::VERSION);
        self::assertSame([], self::stepProblems(), 'upgrades() holds a step outside BASELINE + 1 to VERSION.');

        // The rule itself: a step at BASELINE and one above VERSION are named.
        $step = static function (Builder $schema): void {};

        try {
            PackageSchema::useUpgradesForTesting([PackageSchema::BASELINE => $step, PackageSchema::VERSION + 1 => $step]);
            self::assertSame([
                'upgrades() holds the step '.PackageSchema::BASELINE.', at or below BASELINE.',
                'upgrades() holds the step '.(PackageSchema::VERSION + 1).', above VERSION.',
            ], self::stepProblems());
        } finally {
            PackageSchema::useUpgradesForTesting(null);
        }
    }

    /**
     * The steps of upgrades() outside BASELINE + 1 to VERSION.
     *
     * @return list<string>
     */
    private static function stepProblems(): array
    {
        $problems = [];

        foreach (array_keys(PackageSchema::upgrades()) as $version) {
            if ($version <= PackageSchema::BASELINE) {
                $problems[] = "upgrades() holds the step {$version}, at or below BASELINE.";
            } elseif ($version > PackageSchema::VERSION) {
                $problems[] = "upgrades() holds the step {$version}, above VERSION.";
            }
        }

        return $problems;
    }

    /**
     * Rejects: an edit of create() without a version step
     * and an upgrade migration (the baseline plus the upgrade migrations then differ from a fresh
     * create()), a fixture that no longer builds on its engine or with the run's prefix, and a
     * baseline whose marker is not BASELINE.
     * Still passes: a difference that the catalog fingerprint does not capture (a MySQL table
     * engine that the connection sets, a view or a trigger outside the package tables).
     */
    public function test_the_baseline_and_the_upgrade_migrations_build_the_schema_of_a_fresh_create(): void
    {
        $mirror = $this->mirror();
        $fresh = $this->describeTables($mirror);
        self::assertSame(PackageSchema::VERSION, PackageSchema::installedVersion($mirror));

        if (getenv('ORRERY_CAPTURE_SCHEMA_BASELINE') === '1') {
            file_put_contents($this->fixturePath($mirror), self::capture($mirror));
        }

        $this->buildBaseline($mirror);
        self::assertSame(PackageSchema::BASELINE, PackageSchema::installedVersion($mirror), 'The baseline writes the marker BASELINE.');

        foreach ($this->upgradeMigrations() as $migration) {
            $migration->up();
        }

        self::assertSame(PackageSchema::VERSION, PackageSchema::installedVersion($mirror));
        self::assertSame($fresh, $this->describeTables($mirror), 'The baseline plus the upgrade migrations differ from a fresh create(): a schema change needs a version step and an upgrade migration.');
    }

    /**
     * Rejects: an upgrade mechanism that does not bring
     * a baseline schema to the schema of a fresh create() of the next version, a reader of a new
     * object that fails with a raw query error (or reads nothing) before the migration, a
     * `schema_outdated` message that names the destructive migrate:refresh at or above BASELINE,
     * a `schema` status without `php artisan migrate` for a marker from BASELINE, and a reader
     * that still answers `schema_outdated` after the migration. On a sibling mirror the package
     * runs the step itself when it opens the file (ensure()), so the reader works at once.
     * Still passes: a step that is correct only for this fixture table.
     */
    public function test_the_fixture_version_upgrades_the_baseline_to_a_fresh_create(): void
    {
        $mirror = $this->mirror();
        $next = PackageSchema::VERSION + 1;
        $sibling = $this->app->make(PackageSession::class)->mirrorIsSibling();

        try {
            // The next version: create() builds the fixture table, and its step adds it.
            PackageSchema::useCreateForTesting(static function (Builder $schema, ?array $only): void {
                if ($only === null || in_array(self::FIXTURE_TABLE, $only, true)) {
                    self::createFixtureTable($schema);
                }
            });
            PackageSchema::useUpgradesForTesting([...PackageSchema::upgrades(), $next => static function (Builder $schema): void {
                if ($schema->hasTable('orrery_settings') && ! $schema->hasTable(self::FIXTURE_TABLE)) {
                    PackageSchema::create($schema, [self::FIXTURE_TABLE]);
                }
            }], $next);

            // A fresh install of the next version.
            PackageSchema::drop($mirror->getSchemaBuilder());
            PackageSchema::create($mirror->getSchemaBuilder());
            PackageSchema::mark($mirror);
            $fresh = $this->describeTables($mirror, [self::FIXTURE_TABLE]);
            self::assertSame($next, PackageSchema::installedVersion($mirror));

            // The baseline, as the deploy of the next version finds it before `migrate`.
            $mirror->getSchemaBuilder()->drop(self::FIXTURE_TABLE);
            $this->buildBaseline($mirror);

            foreach ($this->upgradeMigrations() as $migration) {
                $migration->up();
            }

            if (! $sibling) {
                self::assertSame(PackageSchema::VERSION, PackageSchema::installedVersion($mirror));

                try {
                    $this->readFixture($mirror);
                    self::fail('The fixture reader read a table that is missing.');
                } catch (SchemaOutdated $outdated) {
                    self::assertStringStartsWith('schema_outdated: the table '.self::FIXTURE_TABLE.' is missing. ', $outdated->getMessage());
                    self::assertStringContainsString('php artisan migrate;', $outdated->getMessage());
                    self::assertStringContainsString('/orrery/api/health', $outdated->getMessage());
                    self::assertStringNotContainsString('migrate:refresh', $outdated->getMessage());
                }

                self::assertSame(
                    ['installed' => PackageSchema::VERSION, 'expected' => $next, 'hint' => PackageSchema::MIGRATE_HINT],
                    SchemaStatus::read($this->app->make(PackageSession::class)),
                );
            }

            // The fixture upgrade migration of the next version.
            $this->fixtureMigration($next)->up();

            self::assertSame($next, PackageSchema::installedVersion($mirror));
            self::assertSame(0, $this->readFixture($mirror), 'The reader reads after the migration.');
            self::assertSame($fresh, $this->describeTables($mirror, [self::FIXTURE_TABLE]), 'The upgraded baseline differs from a fresh create() of the next version.');
            self::assertSame(['installed' => $next, 'expected' => $next, 'hint' => null], SchemaStatus::read($this->app->make(PackageSession::class)));
        } finally {
            PackageSchema::useUpgradesForTesting(null);
            PackageSchema::useCreateForTesting(null);
            $mirror->getSchemaBuilder()->dropIfExists(self::FIXTURE_TABLE);
        }
    }

    /**
     * Rejects: a hot-path writer that probes the schema, a failed
     * write of a column that a later step adds which reaches the host's job (it must still
     * complete), and a failed write that the watcher does not count as dropped. The column is
     * orrery_jobs.runtime_ms, which every completion writes: the test removes it, as a mirror
     * would lack a column of the next version before `migrate`, and the fixture step of that
     * version adds it again.
     */
    public function test_a_hot_path_write_of_a_column_before_its_migration_is_dropped_and_the_job_completes(): void
    {
        $mirror = $this->mirror();
        $next = PackageSchema::VERSION + 1;
        $mirror->getSchemaBuilder()->table('orrery_jobs', static function (Blueprint $table): void {
            $table->dropColumn('runtime_ms');
        });

        Queue::connection('database')->push(new BasicJob);
        $this->work();

        self::assertSame(0, $this->app['db']->connection()->table('jobs')->count(), 'The host job completed.');
        self::assertSame(1, Degradation::record()['dropped'] ?? null, 'The failed completion counts as one dropped observation.');
        self::assertNotNull(Degradation::record()['last_error_operation'] ?? null, 'The watcher recorded the error.');
        self::assertSame('reserved', $mirror->table('orrery_jobs')->value('status'), 'The completion was not written.');

        try {
            PackageSchema::useUpgradesForTesting([...PackageSchema::upgrades(), $next => static function (Builder $schema): void {
                if ($schema->hasTable('orrery_jobs') && ! $schema->hasColumn('orrery_jobs', 'runtime_ms')) {
                    $schema->table('orrery_jobs', static function (Blueprint $table): void {
                        $table->unsignedInteger('runtime_ms')->nullable();
                    });
                }
            }], $next);
            $this->fixtureMigration($next)->up();

            Queue::connection('database')->push(new BasicJob);
            $this->work();

            self::assertSame(1, Degradation::record()['dropped'] ?? null, 'After the migration the completion is written.');
            self::assertSame(1, $mirror->table('orrery_jobs')->where('status', 'completed')->whereNotNull('runtime_ms')->count());
        } finally {
            PackageSchema::useUpgradesForTesting(null);
        }
    }

    /**
     * The fixture of a reader of an object added after the baseline:
     * it probes its table and answers `schema_outdated` while the table is missing.
     */
    private function readFixture(Connection $mirror): int
    {
        if (! $mirror->getSchemaBuilder()->hasTable(self::FIXTURE_TABLE)) {
            throw SchemaOutdated::table(self::FIXTURE_TABLE);
        }

        return $mirror->table(self::FIXTURE_TABLE)->count();
    }

    private static function createFixtureTable(Builder $schema): void
    {
        $connection = $schema->getConnection();
        $schema->create(self::FIXTURE_TABLE, static function (Blueprint $table) use ($connection): void {
            $table->bigIncrements('id');
            $table->string('note', 191);
            $table->decimal('created_at', 16, 6);
            $table->index(['created_at', 'id'], IndexName::for($connection, self::FIXTURE_TABLE, 'created'));
        });
    }

    /**
     * Replaces every package table with the baseline of the connection's engine.
     */
    private function buildBaseline(Connection $mirror): void
    {
        PackageSchema::drop($mirror->getSchemaBuilder());
        self::assertSame([], array_values(array_filter(PackageSchema::TABLES, static fn (string $table): bool => $mirror->getSchemaBuilder()->hasTable($table))));

        foreach (self::statements($this->fixture($mirror), $mirror->getTablePrefix()) as $statement) {
            $mirror->statement($statement);
        }
    }

    /**
     * The fixture text of the connection's engine.
     */
    private function fixture(Connection $mirror): string
    {
        $path = $this->fixturePath($mirror);
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function fixturePath(Connection $mirror): string
    {
        $driver = $mirror->getDriverName();
        self::assertArrayHasKey($driver, self::ENGINES, "No baseline fixture for the driver {$driver}.");

        return dirname(__DIR__, 2).'/Fixtures/schema-baseline/'.self::ENGINES[$driver].'.sql';
    }

    /**
     * The statements of create() and mark() on the connection, with the bindings in the text and
     * the prefix replaced by the placeholder.
     */
    private static function capture(Connection $mirror): string
    {
        $prefix = $mirror->getTablePrefix();
        self::assertNotSame('', $prefix, 'Capture the baseline with a table prefix (ORRERY_TEST_PREFIX=pfx_), so that the placeholder can replace it.');
        self::assertSame(PackageSchema::BASELINE, PackageSchema::VERSION, 'The baseline is captured only while VERSION is BASELINE (before the tag v0.1.0).');
        self::assertSame(PackageSchema::BASELINE, PackageSchema::currentVersion(), 'No test version while the baseline is captured.');

        $queries = $mirror->pretend(static function () use ($mirror): void {
            PackageSchema::create($mirror->getSchemaBuilder());
            PackageSchema::mark($mirror);
        });
        $grammar = $mirror->getQueryGrammar();
        $lines = [
            '-- The Orrery schema of version '.PackageSchema::BASELINE.' (PackageSchema::BASELINE, the tag v0.1.0) on '.self::ENGINES[$mirror->getDriverName()].':',
            '-- the statements of PackageSchema::create() and mark(), one per line. {prefix} is the table prefix.',
            '-- Frozen: never edit this file (SchemaBaselineTest, MigrationFreezeTest).',
        ];

        foreach ($queries as $query) {
            $sql = $query['bindings'] === [] ? $query['query'] : $grammar->substituteBindingsIntoRawSql($query['query'], $mirror->prepareBindings($query['bindings']));
            self::assertStringNotContainsString("\n", $sql, 'One statement per line.');
            $lines[] = str_replace($prefix, self::PREFIX, $sql).';';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The statements of a fixture with the prefix in place of the placeholder.
     *
     * @return list<string>
     */
    private static function statements(string $fixture, string $prefix): array
    {
        $statements = [];

        foreach (explode("\n", $fixture) as $line) {
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }

            self::assertStringEndsWith(';', $line);
            $statements[] = str_replace(self::PREFIX, $prefix, substr($line, 0, -1));
        }

        self::assertNotSame([], $statements);

        return $statements;
    }

    /**
     * The table and index names of a fixture: every name that follows the placeholder.
     *
     * @return list<string>
     */
    private static function fixtureNames(string $fixture): array
    {
        preg_match_all('/'.preg_quote(self::PREFIX, '/').'([A-Za-z0-9_]+)/', $fixture, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * The real upgrade migrations, in the order in which `migrate` runs them.
     *
     * @return list<Migration>
     */
    private function upgradeMigrations(): array
    {
        $files = glob(dirname(__DIR__, 3).'/database/migrations/*_upgrade_orrery_schema_to_v*.php') ?: [];
        sort($files);

        return array_map(static fn (string $file): Migration => require $file, $files);
    }

    /**
     * An upgrade migration of the documented form for the
     * fixture version: up() calls upgradeTo() on the mirror connection, down() does nothing.
     */
    private function fixtureMigration(int $version): Migration
    {
        return new class($version) extends Migration
        {
            public function __construct(private readonly int $version) {}

            public function up(): void
            {
                /** @var string|null $connection */
                $connection = config('orrery.database');
                PackageSchema::upgradeTo($connection, $this->version);
            }

            public function down(): void {}
        };
    }
}
