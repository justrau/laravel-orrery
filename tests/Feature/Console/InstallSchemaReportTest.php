<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\UsesPrivateSkeleton;
use Justrau\Orrery\Tests\Support\UsesSiblingMirror;
use Justrau\Orrery\Tests\TestCase;

/**
 * orrery:install reports the mirror's schema marker against this release's
 * version, with the same hint as `/api/health` `schema`,
 * read on each call. The install writes into a private skeleton
 * copy (Support\UsesPrivateSkeleton).
 */
#[FreshSchema]
final class InstallSchemaReportTest extends TestCase
{
    use UsesPrivateSkeleton;
    use UsesSiblingMirror;

    protected function setUp(): void
    {
        $this->startPrivateSkeleton();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->endPrivateSkeleton();
        }
    }

    /**
     * Rejects: an install that reports no schema state, a hint on a current schema, a marker
     * that the same process keeps after it changed (each call reads it), and on a server mirror a
     * pre-baseline marker without the pre-v0.1 steps or a baseline marker with them.
     */
    public function test_install_reports_the_schema_marker_and_its_hint(): void
    {
        self::assertStringContainsString('The Orrery schema is at version '.PackageSchema::VERSION.', as this release expects.', $this->install());

        if ($this->app->make(PackageSession::class)->mirrorIsSibling()) {
            return;
        }

        try {
            $this->setMarker(PackageSchema::BASELINE - 1);
            $output = $this->install();
            self::assertStringContainsString('The Orrery schema is at version '.(PackageSchema::BASELINE - 1).'; this release expects version '.PackageSchema::VERSION.'. '.PackageSchema::UPGRADE_HINT, $output);

            $next = PackageSchema::VERSION + 1;
            PackageSchema::useUpgradesForTesting([], $next);
            $this->setMarker(PackageSchema::BASELINE);
            $output = $this->install();
            self::assertStringContainsString('The Orrery schema is at version '.PackageSchema::BASELINE."; this release expects version {$next}. ".PackageSchema::MIGRATE_HINT, $output);
            self::assertStringNotContainsString('migrate:refresh', $output);
        } finally {
            PackageSchema::useUpgradesForTesting(null);
            PackageSchema::mark($this->mirror());
        }
    }

    /**
     * Rejects: an install that leaves a complete schema whose marker table holds no row (a
     * database loaded from a schema dump) without its marker, so it keeps calling it a schema
     * from before Orrery 0.1, or that drops or empties a table to fix it.
     */
    public function test_install_writes_the_marker_of_a_loaded_schema_dump(): void
    {
        if ($this->app->make(PackageSession::class)->mirrorIsSibling()) {
            self::markTestSkipped('The run\'s mirror is the sibling file, which installs itself.');
        }

        $this->mirror()->table(PackageSchema::MARKER)->delete();
        $this->mirror()->table('orrery_settings')->insert(['key' => 'install_probe', 'value' => 'kept']);

        $output = $this->install();

        self::assertStringContainsString('Orrery wrote its schema marker (version '.PackageSchema::VERSION.')', $output);
        self::assertStringContainsString('The Orrery schema is at version '.PackageSchema::VERSION.', as this release expects.', $output);
        self::assertSame(PackageSchema::VERSION, PackageSchema::installedVersion($this->mirror()));
        self::assertSame('kept', $this->mirror()->table('orrery_settings')->where('key', 'install_probe')->value('value'));
    }

    /**
     * Rejects: a server mirror without any package table (an install
     * before its first `migrate`) that is sent through the destructive pre-v0.1 path.
     */
    public function test_install_before_the_first_migrate_says_migrate(): void
    {
        if ($this->app->make(PackageSession::class)->mirrorIsSibling()) {
            self::markTestSkipped('The run\'s mirror is the sibling file, which installs itself.');
        }

        $schema = $this->mirror()->getSchemaBuilder();
        PackageSchema::drop($schema);

        try {
            $output = $this->install();
            self::assertStringContainsString('The Orrery schema version is unknown; this release expects version '.PackageSchema::VERSION.'. '.PackageSchema::INSTALL_HINT, $output);
            self::assertStringNotContainsString('orrery:resolve', $output, 'Not the pre-v0.1 path, which drops every package table.');
        } finally {
            PackageSchema::create($schema);
            PackageSchema::mark($this->mirror());
        }
    }

    /**
     * Rejects: a refused sibling that the install does not report,
     * or reports with the server's destructive UPGRADE_STEPS instead of the refusal's message,
     * and an install that reports it but exits 0, so a deploy script goes on.
     */
    public function test_install_reports_a_refused_sibling_with_its_refusal(): void
    {
        $cases = [
            'below the baseline' => [['orrery_jobs', 'orrery_schema'], PackageSchema::BASELINE - 1, PackageSchema::PRE_BASELINE_SIBLING],
            'tables without a marker' => [['orrery_jobs'], null, PackageSchema::PRE_BASELINE_SIBLING],
            'a newer marker' => [['orrery_jobs', 'orrery_schema'], PackageSchema::VERSION + 1, null],
        ];

        foreach ($cases as $case => [$tables, $marker, $reason]) {
            $sibling = $this->useSiblingMirror($tables, $marker);

            try {
                $reason ??= PackageSchema::newerHint($sibling);
                $output = $this->install(1);
                self::assertStringContainsString('The Orrery schema version is unknown; this release expects version '.PackageSchema::VERSION.". Orrery cannot use its SQLite mirror file {$sibling}: {$reason}.", $output, $case);
                self::assertStringNotContainsString('migrate:refresh', $output, $case);
                self::assertSame($marker, $this->siblingMarker($sibling), $case);
            } finally {
                $this->restoreMirror();
            }
        }
    }

    /**
     * Rejects: an install that prints the refusal of the mirror (orrery.database on the SQLite
     * file of a queue connection) and still exits 0, so a deploy script goes on to migrate.
     * Would still pass: an install that exits 1 for another reason with the same text.
     */
    public function test_install_exits_one_on_a_refused_mirror(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'orrery-install-').'.sqlite';
        touch($file);
        $config = $this->app['config'];
        $saved = ['orrery.database' => $config->get('orrery.database'), 'queue.connections' => $config->get('queue.connections')];
        $config->set('database.connections.install_app', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
        $config->set('queue.connections.install_queue', ['driver' => 'database', 'connection' => 'install_app', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $config->set('orrery.database', 'install_app');

        try {
            $refusal = $this->app->make(PackageSession::class)->mirrorRefusal();
            self::assertNotNull($refusal);

            self::assertSame(1, Artisan::call('orrery:install'));
            self::assertStringContainsString($refusal, (string) preg_replace('/\s+/', ' ', Artisan::output()));
        } finally {
            $config->set($saved);
            @unlink($file);
            @unlink(substr($file, 0, -strlen('.sqlite')));
        }
    }

    /** The output of orrery:install, with its line breaks and indents as single spaces. */
    private function install(int $exit = 0): string
    {
        self::assertSame($exit, Artisan::call('orrery:install'));

        return (string) preg_replace('/\s+/', ' ', Artisan::output());
    }

    private function setMarker(int $version): void
    {
        $this->mirror()->table(PackageSchema::MARKER)->delete();
        $this->mirror()->table(PackageSchema::MARKER)->insert(['id' => 1, 'version' => $version]);
    }
}
