<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Database\Schema\PackageSchema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The migration freeze. The main
 * migration file and the four baseline fixtures (the schema of
 * PackageSchema::BASELINE per engine, SchemaBaselineTest) are recorded by
 * their sha256, captured at task 5b's commit (the MariaDB fixture later,
 * from the same version 10 create(): it differs from the MySQL fixture only
 * in MariaDB's byte-exact collation). Until the tag v0.1.0 a schema
 * change captures the fixtures again and records their new hashes here in
 * the same commit; after the tag they never change, and every schema change
 * raises VERSION with one upgrade migration per version. The test reads
 * files and constants only.
 */
#[Group('scripts')]
final class MigrationFreezeTest extends TestCase
{
    /** The schema version of the tag v0.1.0. */
    private const BASELINE = 10;

    /** The frozen files and their sha256. */
    private const HASHES = [
        'database/migrations/2026_09_23_000000_create_orrery_tables.php' => '628fffa6921b41d8f15189f2056bbdfd4ab16b1845ded285bc6befbdbed9db3b',
        'tests/Fixtures/schema-baseline/mariadb.sql' => '1481431687e7b080669aefbd522abdbf79565d5e782681bd8c5874e86e2afff2',
        'tests/Fixtures/schema-baseline/mysql.sql' => '0fff0f877b380aab4418454a6bd1fb25b49c142b7b690a40ace5b78843d6e8d0',
        'tests/Fixtures/schema-baseline/pgsql.sql' => 'fc6b0b22aa6d249726dfa122ccc4bb951d560f0c7e6ed631a85d63d934141c2e',
        'tests/Fixtures/schema-baseline/sqlite.sql' => '8cb6a61c72f58d086ed62ef0dee2ea2ba10f8e0237d4df852a590e49a8fd01f3',
    ];

    /**
     * The main migration: the wrapper whose getConnection() is
     * PackageSchema::migrationConnection(), whose up() is install() and whose
     * down() is uninstall(), never edited again.
     */
    private const MAIN = '2026_09_23_000000_create_orrery_tables.php';

    /**
     * Rejects: an edit of the main migration file (it stays the wrapper of
     * PackageSchema::migrationConnection(), install() and uninstall(), so the install policy
     * changes in src/ only), and an edit of a baseline fixture (the schema of the tag v0.1.0 per
     * engine).
     * Still passes: an edit that keeps the bytes (none can), and a create() edit (the
     * SchemaBaselineTest guard).
     */
    public function test_the_main_migration_and_the_baseline_fixtures_are_unchanged(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (self::HASHES as $path => $hash) {
            self::assertFileExists($root.'/'.$path);
            self::assertSame($hash, hash_file('sha256', $root.'/'.$path), "{$path} changed: the main migration and the baseline fixtures are frozen (Spec D section 3).");
        }
    }

    /**
     * Rejects: a changed BASELINE (it is the schema version of the tag v0.1.0), and a VERSION
     * below it.
     */
    public function test_the_baseline_is_the_version_of_v0_1_0(): void
    {
        self::assertSame(self::BASELINE, PackageSchema::BASELINE);
        self::assertGreaterThanOrEqual(PackageSchema::BASELINE, PackageSchema::VERSION);
    }

    /**
     * Rejects: a VERSION above BASELINE without one
     * database/migrations/*_upgrade_orrery_schema_to_v<N>.php for each N from BASELINE + 1 to
     * VERSION, two migrations of one version, an upgrade migration of a version at or below
     * BASELINE or above VERSION, and any other package migration besides the main one.
     * Still passes: an upgrade migration whose up() does not call upgradeTo() (SchemaBaselineTest
     * runs them).
     */
    public function test_every_version_above_the_baseline_has_one_upgrade_migration(): void
    {
        $files = array_map('basename', glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: []);

        self::assertSame([], self::upgradeProblems(PackageSchema::BASELINE, PackageSchema::VERSION, $files));
    }

    /**
     * Rejects: a rule of the upgrade migrations that accepts a missing, a doubled or a stray
     * migration (the rule itself, with fixed inputs).
     */
    public function test_the_upgrade_migration_rule(): void
    {
        $main = self::MAIN;

        self::assertSame([], self::upgradeProblems(10, 10, [$main]));
        self::assertSame(['No upgrade migration for schema version 11.'], self::upgradeProblems(10, 11, [$main]));
        self::assertSame([], self::upgradeProblems(10, 12, [$main, '2027_01_01_000000_upgrade_orrery_schema_to_v11.php', '2027_02_01_000000_upgrade_orrery_schema_to_v12.php']));
        self::assertSame(['2 upgrade migrations for schema version 11.'], self::upgradeProblems(10, 11, [$main, '2027_01_01_000000_upgrade_orrery_schema_to_v11.php', '2027_01_02_000000_upgrade_orrery_schema_to_v11.php']));
        self::assertSame(['The upgrade migration 2027_01_01_000000_upgrade_orrery_schema_to_v10.php is for a version outside 11 to 10.'], self::upgradeProblems(10, 10, [$main, '2027_01_01_000000_upgrade_orrery_schema_to_v10.php']));
        self::assertSame(['The upgrade migration 2027_01_01_000000_upgrade_orrery_schema_to_v12.php is for a version outside 11 to 11.'], self::upgradeProblems(10, 11, [$main, '2027_01_01_000000_upgrade_orrery_schema_to_v11.php', '2027_01_01_000000_upgrade_orrery_schema_to_v12.php']));
        self::assertSame(['The package migration 2027_01_01_000000_add_orrery_column.php is neither the main migration nor an upgrade migration.'], self::upgradeProblems(10, 10, [$main, '2027_01_01_000000_add_orrery_column.php']));
        self::assertSame(['The main migration '.self::MAIN.' is missing.'], self::upgradeProblems(10, 10, []));
    }

    /**
     * The problems of the package migrations for a baseline and a version.
     *
     * @param  list<string>  $files  the file names in database/migrations
     * @return list<string>
     */
    private static function upgradeProblems(int $baseline, int $version, array $files): array
    {
        $problems = [];
        $versions = [];

        if (! in_array(self::MAIN, $files, true)) {
            $problems[] = 'The main migration '.self::MAIN.' is missing.';
        }

        foreach ($files as $file) {
            if ($file === self::MAIN) {
                continue;
            }

            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_upgrade_orrery_schema_to_v(\d+)\.php$/D', $file, $match) !== 1) {
                $problems[] = "The package migration {$file} is neither the main migration nor an upgrade migration.";

                continue;
            }

            $target = (int) $match[1];

            if ($target <= $baseline || $target > $version) {
                $problems[] = "The upgrade migration {$file} is for a version outside ".($baseline + 1)." to {$version}.";

                continue;
            }

            $versions[$target] = ($versions[$target] ?? 0) + 1;
        }

        for ($target = $baseline + 1; $target <= $version; $target++) {
            $count = $versions[$target] ?? 0;

            if ($count === 0) {
                $problems[] = "No upgrade migration for schema version {$target}.";
            } elseif ($count > 1) {
                $problems[] = "{$count} upgrade migrations for schema version {$target}.";
            }
        }

        return $problems;
    }
}
