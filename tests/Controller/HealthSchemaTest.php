<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\UsesSiblingMirror;

/**
 * GET /api/health `schema`: the
 * mirror's installed schema version against PackageSchema::VERSION, with
 * the hint of the installed marker, read on each request.
 */
final class HealthSchemaTest extends ControllerTest
{
    use UsesSiblingMirror;

    /**
     * Rejects: a schema field that reports the release's own version instead of the mirror's
     * marker, a hint on a current schema, and a marker that a long-lived process keeps after a
     * `migrate` changed it (every request reads it).
     */
    public function test_a_current_server_schema_has_no_hint_and_every_request_reads_the_marker(): void
    {
        if ($this->app->make(PackageSession::class)->mirrorIsSibling()) {
            self::assertSame(['installed' => PackageSchema::VERSION, 'expected' => PackageSchema::VERSION, 'hint' => null], $this->schema());

            return;
        }

        self::assertSame(['installed' => PackageSchema::VERSION, 'expected' => PackageSchema::VERSION, 'hint' => null], $this->schema());

        try {
            $this->setMarker(PackageSchema::BASELINE - 1);
            self::assertSame(PackageSchema::BASELINE - 1, $this->schema()['installed'], 'The same process reads the changed marker.');
        } finally {
            PackageSchema::mark($this->mirror());
        }

        self::assertSame(['installed' => PackageSchema::VERSION, 'expected' => PackageSchema::VERSION, 'hint' => null], $this->schema(), 'After `migrate` the next request is current.');
    }

    /**
     * Rejects: on a server mirror, a pre-baseline marker or a
     * missing marker table without the pre-v0.1 steps (UPGRADE_STEPS), a marker table without a
     * row (a database loaded from a schema dump) that gets those destructive steps instead of
     * UNMARKED_HINT, such a dump of a newer release without the downgrade hint, a marker from
     * BASELINE up to below VERSION that gets the destructive steps instead of
     * `php artisan migrate`, a newer marker (a code rollback after `migrate`) without the
     * downgrade hint.
     */
    #[FreshSchema]
    public function test_the_server_hint_depends_on_the_marker(): void
    {
        if ($this->app->make(PackageSession::class)->mirrorIsSibling()) {
            self::markTestSkipped('The run\'s mirror is the sibling file; test_a_refused_sibling_gives_its_refusal_as_the_hint covers the sibling.');
        }

        $next = PackageSchema::VERSION + 1;

        try {
            $this->setMarker(PackageSchema::BASELINE - 1);
            $older = $this->schema();
            self::assertSame([PackageSchema::BASELINE - 1, PackageSchema::VERSION, PackageSchema::UPGRADE_HINT], [$older['installed'], $older['expected'], $older['hint']]);
            self::assertStringContainsString(PackageSchema::UPGRADE_STEPS, (string) $older['hint']);
            self::assertStringContainsString('vendor:publish --tag=orrery-migrations --force', (string) $older['hint']);

            $this->setMarker(null);
            self::assertSame(['installed' => null, 'expected' => PackageSchema::VERSION, 'hint' => PackageSchema::UNMARKED_HINT], $this->schema(), 'A marker table without a row is a loaded schema dump.');
            self::assertStringNotContainsString('migrate:refresh', PackageSchema::UNMARKED_HINT);
            self::assertStringContainsString('php artisan orrery:install', PackageSchema::UNMARKED_HINT);

            // A loaded dump of a newer release: its migrations rows name a newer upgrade migration.
            $newer = PackageSchema::VERSION + 1;
            $this->mirror()->table('migrations')->insert(['migration' => "2027_01_01_000000_upgrade_orrery_schema_to_v{$newer}", 'batch' => 99]);
            self::assertSame(['installed' => null, 'expected' => PackageSchema::VERSION, 'hint' => PackageSchema::downgradeHint($newer, PackageSchema::VERSION)], $this->schema());
            $this->mirror()->table('migrations')->where('batch', 99)->delete();

            $this->mirror()->getSchemaBuilder()->drop(PackageSchema::MARKER);
            self::assertSame(['installed' => null, 'expected' => PackageSchema::VERSION, 'hint' => PackageSchema::UPGRADE_HINT], $this->schema(), 'Without a marker table the schema predates the marker.');
            PackageSchema::create($this->mirror()->getSchemaBuilder(), [PackageSchema::MARKER]);

            // A release one version later: the baseline marker needs only `migrate`.
            PackageSchema::useUpgradesForTesting([], $next);
            $this->setMarker(PackageSchema::BASELINE);
            self::assertSame(['installed' => PackageSchema::BASELINE, 'expected' => $next, 'hint' => PackageSchema::MIGRATE_HINT], $this->schema());
            self::assertStringNotContainsString('migrate:refresh', PackageSchema::MIGRATE_HINT);
            PackageSchema::useUpgradesForTesting(null);

            // The code was rolled back after a `migrate`.
            $this->setMarker($next);
            self::assertSame(['installed' => $next, 'expected' => PackageSchema::VERSION, 'hint' => PackageSchema::downgradeHint($next, PackageSchema::VERSION)], $this->schema());
            self::assertStringContainsString('deploy the release that matches the installed schema', PackageSchema::downgradeHint($next, PackageSchema::VERSION));
        } finally {
            PackageSchema::useUpgradesForTesting(null);
            PackageSchema::mark($this->mirror());
        }
    }

    /**
     * Rejects: a server mirror without any package table (before the
     * first `migrate`, or after a change of orrery.database) that answers 500 because the health
     * reads tables that do not exist, or that is told to drop its tables (UPGRADE_STEPS) instead
     * of INSTALL_HINT; its mirror fields are unknown.
     */
    #[FreshSchema]
    public function test_a_server_mirror_without_package_tables_gets_the_install_hint(): void
    {
        if ($this->app->make(PackageSession::class)->mirrorIsSibling()) {
            self::markTestSkipped('The run\'s mirror is the sibling file, which installs itself.');
        }

        $schema = $this->mirror()->getSchemaBuilder();
        PackageSchema::drop($schema);

        try {
            $health = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
            self::assertSame(['installed' => null, 'expected' => PackageSchema::VERSION, 'hint' => PackageSchema::INSTALL_HINT], $health->json('schema'));
            self::assertNull($health->json('windows'));
            self::assertNull($health->json('tick.observer_tick_at'));
            self::assertSame(0, Degradation::record()['dropped'] ?? 0, 'The health tried no read of a missing package table.');
            self::assertStringNotContainsString(PackageSchema::UPGRADE_STEPS, PackageSchema::INSTALL_HINT);
        } finally {
            PackageSchema::create($schema);
            PackageSchema::mark($this->mirror());
        }
    }

    /**
     * Rejects: a failed read of the degradation windows or of the tick time that answers 500
     * instead of unknown (null) fields: once through the watcher's test failure, once with the
     * package storage down (every mirror read fails).
     */
    #[FreshSchema]
    public function test_a_failed_window_or_tick_read_is_unknown(): void
    {
        // A stored tick time, so a read that the watcher does not run would show it.
        $this->mirror()->table('orrery_settings')->insert(['key' => 'observer_tick_at', 'value' => '1790000000.5']);
        self::assertSame(['observer_tick_at' => 1790000000.5, 'state' => 'ok'], $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('tick'));
        Watcher::failOperationsForTesting(['health:windows', 'health:tick']);

        $health = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertNull($health->json('windows'));
        self::assertNull($health->json('truncated'));
        self::assertSame(['observer_tick_at' => null, 'state' => 'unreadable'], $health->json('tick'));
        self::assertSame(PackageSchema::VERSION, $health->json('schema.installed'));

        Watcher::failOperationsForTesting([]);
        $this->app->forgetInstance(Watcher::class);
        $outage = $this->mirrorOutage();

        try {
            $health = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
            self::assertNull($health->json('windows'));
            self::assertSame(['observer_tick_at' => null, 'state' => 'unreadable'], $health->json('tick'));
        } finally {
            $outage->end();
        }
    }

    /**
     * The tick state tells a missing tick from a tick that could not be read.
     *
     * Rejects: one null for both cases (the footer says "No tick yet" while
     * the mirror is down), and a state that does not follow the stored row.
     */
    #[FreshSchema]
    public function test_the_tick_state_tells_none_from_unreadable(): void
    {
        self::assertSame(['observer_tick_at' => null, 'state' => 'none'], $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('tick'));

        $this->mirror()->table('orrery_settings')->insert(['key' => 'observer_tick_at', 'value' => 'not a time']);
        self::assertSame(['observer_tick_at' => null, 'state' => 'unreadable'], $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('tick'));
    }

    /**
     * Rejects: a refused sibling that gives
     * `schema` null or a whole response 500 (the refusal reached only the host's error report), a
     * sibling hint that names the server's destructive UPGRADE_STEPS, and a refusal that an open
     * breaker hides (the refusal opens the breaker). The cases: a marker below BASELINE,
     * package tables without a marker, and a marker above VERSION.
     */
    public function test_a_refused_sibling_gives_its_refusal_as_the_hint(): void
    {
        $cases = [
            'below the baseline' => [['orrery_jobs', 'orrery_schema'], PackageSchema::BASELINE - 1, PackageSchema::PRE_BASELINE_SIBLING],
            'tables without a marker' => [['orrery_jobs', 'orrery_settings'], null, PackageSchema::PRE_BASELINE_SIBLING],
            'a newer marker' => [['orrery_jobs', 'orrery_schema'], PackageSchema::VERSION + 1, null],
        ];

        foreach ($cases as $case => [$tables, $marker, $reason]) {
            $sibling = $this->useSiblingMirror($tables, $marker);

            try {
                // A watched open of the mirror meets the refusal first: the breaker opens.
                Watcher::instance()->run('test:open', fn (): mixed => $this->app->make(PackageSession::class)->mirror());
                self::assertTrue(Watcher::instance()->breakerOpen(), "{$case}: the refusal opens the breaker.");

                $health = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
                self::assertNull($health->json('windows'), "{$case}: the mirror fields are unknown.");
                self::assertNull($health->json('sqlite_shared_mirror'), "{$case}: not a shared mirror.");
                $schema = $health->json('schema');
                self::assertIsArray($schema);
                $reason ??= PackageSchema::newerHint($sibling);
                self::assertSame(['installed' => null, 'expected' => PackageSchema::VERSION, 'hint' => "Orrery cannot use its SQLite mirror file {$sibling}: {$reason}."], $schema, $case);
                self::assertStringNotContainsString('migrate:refresh', (string) $schema['hint'], $case);
                self::assertSame($marker, $this->siblingMarker($sibling), "{$case}: the refused sibling is unchanged.");
            } finally {
                $this->restoreMirror();
                $this->app->forgetInstance(Watcher::class);
            }
        }
    }

    /**
     * Rejects: a failed read reported as a current or an older schema.
     */
    public function test_a_failed_read_is_null(): void
    {
        Watcher::failOperationsForTesting(['health:schema']);

        $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->assertJsonPath('schema', null);
    }

    private function setMarker(?int $version): void
    {
        $this->mirror()->table(PackageSchema::MARKER)->delete();

        if ($version !== null) {
            $this->mirror()->table(PackageSchema::MARKER)->insert(['id' => 1, 'version' => $version]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $schema = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('schema');
        self::assertIsArray($schema);

        return $schema;
    }
}
