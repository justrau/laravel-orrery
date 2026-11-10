<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\UsesSiblingMirror;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * PackageSchema::upgradeTo(), the call of every upgrade migration.
 * The server path runs on the run's mirror; when that
 * mirror is the sibling file, on a SQLite file of its own named by
 * orrery.database (also a server mirror). The steps are fixture steps
 * above BASELINE (useUpgradesForTesting()). It runs DDL (#[FreshSchema]).
 */
#[FreshSchema]
final class UpgradeToTest extends TestCase
{
    use UsesSiblingMirror;

    private const OWN = 'upgrade_to_own';

    private ?string $ownFile = null;

    /** @var array{0: bool, 1: mixed}|null */
    private ?array $savedDatabase = null;

    protected function tearDown(): void
    {
        $this->endOwnMirror();
        PackageSchema::useUpgradesForTesting(null);

        parent::tearDown();
    }

    /**
     * Rejects: a migration of a version above the code's that writes
     * its marker over a schema without its step (a published vN migration after a rollback of
     * the code), a refusal without its own message, and a refusal that only a server mirror
     * gets (the check comes first, also before the sibling's ensure()).
     */
    public function test_a_migration_newer_than_the_code_is_refused_and_writes_no_marker(): void
    {
        $mirror = $this->serverMirror();
        $above = PackageSchema::currentVersion() + 1;
        $ran = false;
        PackageSchema::useUpgradesForTesting([$above => static function () use (&$ran): void {
            $ran = true;
        }]);

        $this->assertRefused($above, "This Orrery migration (schema version {$above}) is newer than the installed Orrery code (version ".PackageSchema::VERSION.'). Deploy the release that contains it.');
        self::assertFalse($ran);
        self::assertSame(PackageSchema::VERSION, PackageSchema::installedVersion($mirror), 'The refusal wrote no marker.');

        $this->endOwnMirror();
        $sibling = $this->useSiblingMirror(['orrery_jobs', 'orrery_schema'], 1);

        try {
            $this->assertRefused($above, "This Orrery migration (schema version {$above}) is newer than the installed Orrery code (version ".PackageSchema::VERSION.'). Deploy the release that contains it.');
            self::assertSame(1, $this->siblingMarker($sibling), 'The sibling was not touched.');
        } finally {
            $this->restoreMirror();
        }
    }

    /**
     * Rejects: a server upgrade that runs over a schema from before
     * BASELINE (no marker table, or a marker below it) instead of refusing with UPGRADE_STEPS, a
     * refusal that changes the marker or runs a step, a newer marker (the code was rolled
     * back after a `migrate`) that the upgrade overwrites instead of refusing as a downgrade,
     * and a mirror without any package table (a changed orrery.database) that is called a
     * schema from before Orrery 0.1.
     */
    public function test_a_server_mirror_from_before_the_baseline_or_newer_than_the_code_is_refused(): void
    {
        $mirror = $this->serverMirror();
        $next = PackageSchema::BASELINE + 1;
        $ran = false;
        PackageSchema::useUpgradesForTesting([$next => static function () use (&$ran): void {
            $ran = true;
        }], $next);

        foreach ([null, PackageSchema::BASELINE - 1, 1] as $marker) {
            if ($marker === null) {
                // Package tables without the marker table: a build from before the marker.
                $mirror->getSchemaBuilder()->drop(PackageSchema::MARKER);
            } else {
                $this->setMarker($mirror, $marker);
            }

            try {
                PackageSchema::upgradeTo($this->ownName(), $next);
                self::fail('A schema from before the baseline was upgraded.');
            } catch (RuntimeException $refused) {
                self::assertStringContainsString($marker === null ? '(no schema marker)' : "(schema version {$marker})", $refused->getMessage());
                self::assertStringContainsString('is from before Orrery 0.1, so this migration cannot upgrade it. '.PackageSchema::UPGRADE_STEPS, $refused->getMessage());
            }

            self::assertSame($marker, PackageSchema::installedVersion($mirror));
            self::assertFalse($ran, 'No step ran.');

            if ($marker === null) {
                self::assertFalse($mirror->getSchemaBuilder()->hasTable(PackageSchema::MARKER), 'The refusal created no marker table.');
                PackageSchema::create($mirror->getSchemaBuilder(), [PackageSchema::MARKER]);
            }
        }

        $this->setMarker($mirror, $next + 1);
        $this->assertRefused($next, PackageSchema::downgradeHint($next + 1, $next));
        self::assertSame($next + 1, PackageSchema::installedVersion($mirror));
        self::assertFalse($ran);

        // No package table at all (orrery.database changed after the package migration ran elsewhere).
        PackageSchema::drop($mirror->getSchemaBuilder());

        try {
            $this->assertRefused($next, "The Orrery mirror connection {$mirror->getName()} holds no Orrery tables, so this migration has nothing to upgrade: "
                .'the package migration ran on another database before orrery.database changed. '
                .'Run php artisan migrate:refresh --path=database/migrations/2026_09_23_000000_create_orrery_tables.php --realpath to create them on this connection.');
            self::assertFalse(PackageSchema::hasPackageTables($mirror), 'The refusal created nothing.');
            self::assertFalse($ran);
        } finally {
            PackageSchema::create($mirror->getSchemaBuilder());
            PackageSchema::mark($mirror);
        }
    }

    /**
     * Rejects: an upgrade that refuses a complete schema whose marker table holds no row
     * (a database loaded from a schema dump) with the steps that drop every package table, or
     * that skips the steps above BASELINE, or writes another marker than its own version.
     * Still passes: an adoption that also runs a step above the migration's version (the
     * fixture has none above it).
     */
    public function test_a_server_mirror_with_an_empty_marker_table_gets_the_steps_and_its_marker(): void
    {
        $mirror = $this->serverMirror();
        $b = PackageSchema::BASELINE;
        $runs = [];
        PackageSchema::useUpgradesForTesting([$b + 1 => static function () use (&$runs): void {
            $runs[] = 'step';
        }], $b + 1);
        $this->setMarker($mirror, null);
        $mirror->table('orrery_settings')->insert(['key' => 'upgrade_to_probe', 'value' => 'kept']);

        PackageSchema::upgradeTo($this->ownName(), $b + 1);

        self::assertSame(['step'], $runs);
        self::assertSame($b + 1, PackageSchema::installedVersion($mirror));
        self::assertSame('kept', $mirror->table('orrery_settings')->where('key', 'upgrade_to_probe')->value('value'));
    }

    /**
     * Rejects: an upgrade migration under `migrate --pretend`, where every read of the
     * pretending connection answers nothing, that refuses with "holds no Orrery tables" (and
     * sends the operator to migrate:refresh), or that runs a step or writes a marker for real.
     */
    public function test_an_upgrade_under_pretend_does_nothing(): void
    {
        $mirror = $this->serverMirror();
        $b = PackageSchema::BASELINE;
        $runs = [];
        PackageSchema::useUpgradesForTesting([$b + 1 => static function () use (&$runs): void {
            $runs[] = 'step';
        }], $b + 1);
        $this->setMarker($mirror, $b);

        $mirror->pretend(fn () => PackageSchema::upgradeTo($this->ownName(), $b + 1));

        self::assertSame([], $runs);
        self::assertSame($b, PackageSchema::installedVersion($mirror));
    }

    /**
     * Rejects: an upgrade that runs a step at or below the stored
     * marker, or above the migration's own version, that writes the marker of VERSION instead of
     * its own, and a rerun (or an older migration after a newer one) that runs a step again.
     * Still passes: steps that are not idempotent (every one here runs once).
     */
    public function test_a_server_upgrade_runs_only_the_steps_above_the_marker(): void
    {
        $mirror = $this->serverMirror();
        $b = PackageSchema::BASELINE;
        $runs = [];
        $step = static function (int $version) use (&$runs): Closure {
            return static function (Builder $schema) use ($version, &$runs): void {
                $runs[] = $version;
            };
        };
        PackageSchema::useUpgradesForTesting([$b + 3 => $step($b + 3), $b + 1 => $step($b + 1), $b + 2 => $step($b + 2)], $b + 3);

        $this->setMarker($mirror, $b + 1);
        PackageSchema::upgradeTo($this->ownName(), $b + 2);
        self::assertSame([$b + 2], $runs);
        self::assertSame($b + 2, PackageSchema::installedVersion($mirror), 'The marker is the migration\'s own version.');

        PackageSchema::upgradeTo($this->ownName(), $b + 2);
        PackageSchema::upgradeTo($this->ownName(), $b + 1);
        self::assertSame([$b + 2], $runs, 'A marker at or above the migration\'s version changes nothing.');

        PackageSchema::upgradeTo($this->ownName(), $b + 3);
        self::assertSame([$b + 2, $b + 3], $runs);
        self::assertSame($b + 3, PackageSchema::installedVersion($mirror));

        // From the baseline, every step in version order.
        $runs = [];
        $this->setMarker($mirror, $b);
        PackageSchema::upgradeTo($this->ownName(), $b + 3);
        self::assertSame([$b + 1, $b + 2, $b + 3], $runs);
    }

    /**
     * Rejects: an interrupted upgrade that moves the marker past the
     * failed step, one that cannot complete on a rerun, and an upgrade outside one transaction on
     * Postgres and SQLite (their first step stays after the second failed). On MySQL and MariaDB
     * each DDL statement commits, so the first step's column stays, the marker stays, and the
     * rerun runs the guarded steps again.
     */
    public function test_an_interrupted_upgrade_keeps_its_marker_and_completes_on_a_rerun(): void
    {
        $mirror = $this->serverMirror();
        $b = PackageSchema::BASELINE;
        $fail = true;
        $column = static function (string $table, string $name, bool $mayFail) use (&$fail): Closure {
            return static function (Builder $schema) use ($table, $name, $mayFail, &$fail): void {
                if (! $schema->hasColumn($table, $name)) {
                    $schema->table($table, static function (Blueprint $blueprint) use ($name): void {
                        $blueprint->string($name, 191)->nullable();
                    });
                }

                if ($mayFail && $fail) {
                    throw new RuntimeException('The fixture step fails after its DDL.');
                }
            };
        };
        PackageSchema::useUpgradesForTesting([$b + 1 => $column('orrery_signals', 'fixture_first', false), $b + 2 => $column('orrery_markers', 'fixture_second', true)], $b + 2);
        $this->setMarker($mirror, $b);

        try {
            PackageSchema::upgradeTo($this->ownName(), $b + 2);
            self::fail('The failing step did not fail the upgrade.');
        } catch (RuntimeException $error) {
            self::assertSame('The fixture step fails after its DDL.', $error->getMessage());
        }

        $schema = $mirror->getSchemaBuilder();
        $implicitCommits = in_array($mirror->getDriverName(), ['mysql', 'mariadb'], true);
        self::assertSame($b, PackageSchema::installedVersion($mirror), 'The failed upgrade kept the old marker.');
        self::assertSame($implicitCommits, $schema->hasColumn('orrery_signals', 'fixture_first'), $implicitCommits ? 'MySQL commits each DDL statement.' : 'The steps and the marker are one transaction.');
        self::assertSame($implicitCommits, $schema->hasColumn('orrery_markers', 'fixture_second'));

        $fail = false;
        PackageSchema::upgradeTo($this->ownName(), $b + 2);
        self::assertSame($b + 2, PackageSchema::installedVersion($mirror));
        self::assertTrue($schema->hasColumn('orrery_signals', 'fixture_first'));
        self::assertTrue($schema->hasColumn('orrery_markers', 'fixture_second'));
    }

    /**
     * Rejects: an upgrade migration that treats the sibling as
     * a server mirror (it refuses its missing or pre-baseline marker with UPGRADE_STEPS, or
     * runs only the steps above the marker): the sibling runs ensure(), which refuses a
     * pre-baseline file with the delete-and-rebuild hint and brings a baseline file to the
     * migration's version.
     */
    public function test_on_the_sibling_an_upgrade_migration_runs_ensure(): void
    {
        $b = PackageSchema::BASELINE;
        $sibling = $this->useSiblingMirror(['orrery_jobs', 'orrery_schema'], $b - 1);

        try {
            try {
                PackageSchema::upgradeTo(null, $b);
                self::fail('A pre-baseline sibling was upgraded.');
            } catch (SiblingFileUnavailable $refused) {
                self::assertSame("Orrery cannot use its SQLite mirror file {$sibling}: ".PackageSchema::PRE_BASELINE_SIBLING.'.', $refused->getMessage());
                self::assertStringNotContainsString('migrate:refresh', $refused->getMessage());
            }

            self::assertSame($b - 1, $this->siblingMarker($sibling));
        } finally {
            $this->restoreMirror();
        }

        $sibling = $this->useSiblingMirror();

        try {
            // A new sibling of this release; the next version's step adds a column.
            $next = PackageSchema::VERSION + 1;
            $this->app->make(PackageSession::class)->mirror();
            self::assertSame(PackageSchema::VERSION, $this->siblingMarker($sibling));
            PackageSchema::useUpgradesForTesting([...PackageSchema::upgrades(), $next => static function (Builder $schema): void {
                if ($schema->hasTable('orrery_signals') && ! $schema->hasColumn('orrery_signals', 'fixture_sibling')) {
                    $schema->table('orrery_signals', static function (Blueprint $table): void {
                        $table->string('fixture_sibling', 191)->nullable();
                    });
                }
            }], $next);

            PackageSchema::upgradeTo(null, $next);
            self::assertSame($next, $this->siblingMarker($sibling));
            self::assertTrue($this->app->make(PackageSession::class)->mirror()->getSchemaBuilder()->hasColumn('orrery_signals', 'fixture_sibling'));
        } finally {
            PackageSchema::useUpgradesForTesting(null);
            $this->restoreMirror();
        }
    }

    private function assertRefused(int $version, string $message): void
    {
        try {
            PackageSchema::upgradeTo($this->ownName(), $version);
            self::fail('upgradeTo() did not refuse.');
        } catch (RuntimeException $refused) {
            self::assertNotInstanceOf(SiblingFileUnavailable::class, $refused);
            self::assertSame($message, $refused->getMessage());
        }
    }

    /**
     * A server mirror: the run's, or, when the run's mirror is the sibling, a SQLite file of
     * its own named by orrery.database with a fresh schema.
     */
    private function serverMirror(): Connection
    {
        if (! $this->app->make(PackageSession::class)->mirrorIsSibling()) {
            return $this->mirror();
        }

        $config = $this->app['config'];
        $this->ownFile = SqliteFiles::create();
        $this->savedDatabase = [true, $config->get('orrery.database')];
        $config->set('database.connections.'.self::OWN, ['driver' => 'sqlite', 'url' => null, 'database' => $this->ownFile, 'prefix' => '', 'foreign_key_constraints' => false]);
        $config->set('orrery.database', self::OWN);
        PackageSession::releaseAll();
        self::assertFalse($this->app->make(PackageSession::class)->mirrorIsSibling());

        /** @var Connection $own */
        $own = $this->app['db']->connection(self::OWN);
        PackageSchema::create($own->getSchemaBuilder());
        PackageSchema::mark($own);

        return $own;
    }

    /** The mirror's connection name of upgradeTo(), as the migration passes it. */
    private function ownName(): ?string
    {
        /** @var string|null $name */
        $name = $this->app['config']->get('orrery.database');

        return $name;
    }

    private function endOwnMirror(): void
    {
        if ($this->savedDatabase !== null) {
            $this->app['config']->set('orrery.database', $this->savedDatabase[1]);
            $this->savedDatabase = null;
            PackageSession::releaseAll();
            $this->app['db']->purge(self::OWN);
        }

        if ($this->ownFile !== null) {
            SqliteFiles::remove($this->ownFile);
            $this->ownFile = null;
        }
    }

    private function setMarker(Connection $mirror, ?int $marker): void
    {
        $mirror->table(PackageSchema::MARKER)->delete();

        if ($marker !== null) {
            $mirror->table(PackageSchema::MARKER)->insert(['id' => 1, 'version' => $marker]);
        }
    }
}
