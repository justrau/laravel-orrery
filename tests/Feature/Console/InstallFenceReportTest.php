<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\FenceTargets;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Retry\FenceFixtures;
use Justrau\Orrery\Tests\Support\DirectAlias;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\UsesPrivateSkeleton;
use Justrau\Orrery\Tests\TestCase;

/**
 * orrery:install reports every database queue connection whose database has
 * no fence table (FenceTargets::report()). The install writes into a private
 * skeleton copy (Support\UsesPrivateSkeleton), never into the shared one.
 */
#[FreshSchema]
final class InstallFenceReportTest extends TestCase
{
    use FenceFixtures;
    use UsesPrivateSkeleton;

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
     * Rejects: a report that checks only the default connection.
     */
    public function test_lists_connections_without_a_fence_table(): void
    {
        // The install writes into this test's private copy only.
        self::assertSame(realpath((string) self::privateSkeleton()), realpath(base_path()));
        $this->copyConnection('testbench_bare', $this->bareDatabase());
        $this->useQueue('reports-bare', 'testbench_bare');
        $this->useQueue('reports-same', TestEnvironment::PRIMARY);
        $this->app['config']->set('queue.connections.reports-sync', ['driver' => 'sync']);
        $this->app['config']->set('orrery.retry.fence_retention_days', 12);
        // The primary database has its fence table, as orrery:install --fence and migrate give it.
        $this->createQueueTables($this->app['db']->connection(TestEnvironment::PRIMARY));

        // On a SQLite file the bare database is not in WAL yet, so the automatic report does not
        // read it (D18): unknown, never "has a fence". orrery:install reads it as an operator.
        $report = $this->report();
        self::assertSame(['reports-bare'], [...$report['missing'], ...$report['unknown']]);

        self::assertSame(0, Artisan::call('orrery:install'));
        $output = Artisan::output();

        self::assertStringContainsString(Fence::TABLE, $output);
        // The migrate step too (a review nit): the fence command only writes the migration.
        self::assertStringContainsString('php artisan orrery:install --fence", then "php artisan migrate"', (string) preg_replace('/\s+/', ' ', $output));
        self::assertMatchesRegularExpression('/^\s*⇂ reports-bare\s*$/mu', $output);
        self::assertDoesNotMatchRegularExpression('/^\s*⇂ (database|reports-same|reports-sync)\s*$/mu', $output);
        self::assertMatchesRegularExpression('/kept.*12 days/', $output);
    }

    /**
     * Rejects: a report that looks up the raw queue value `x::write` (or `x::direct` on Laravel versions that support it), which lists a queue whose database has the fence table and tells the user to migrate again.
     */
    public function test_an_aliased_queue_with_a_fence_table_is_not_listed(): void
    {
        $this->createQueueTables($this->app['db']->connection(TestEnvironment::PRIMARY));
        $this->useQueue('reports-write', TestEnvironment::PRIMARY.'::write');
        $this->useQueue('reports-read', TestEnvironment::PRIMARY.'::read');

        if (DirectAlias::supported($this->app['db'])) {
            // Only some Laravel versions parse `::direct` as an alias. Others read it as a part of the name.
            $this->useQueue('reports-direct', TestEnvironment::PRIMARY.'::direct');
        }

        self::assertSame([], $this->report()['missing']);

        self::assertSame(0, Artisan::call('orrery:install'));
        $output = Artisan::output();

        self::assertStringContainsString('Every connection that a retry needs has its fence table', $output);
        self::assertStringNotContainsString('have no fence table', $output);
        self::assertDoesNotMatchRegularExpression('/^\s*⇂ (database|reports-write|reports-read|reports-direct)\s*$/mu', $output);
    }

    /**
     * The database of testbench_bare: a second in-memory database, on a
     * server <base>_mirror (FenceFixtures::bareOtherDatabase()), and on a
     * SQLite file a new file of its own. Never <file>.mirror: in separate
     * mode that is the mirror's file, and the package refuses a mirror
     * that shares the file of a queue connection, so orrery:install would
     * fail.
     *
     * @return array<string, mixed>
     */
    private function bareDatabase(): array
    {
        $environment = $this->testEnvironment();

        if ($environment->inMemory()) {
            return ['database' => TestEnvironment::MEMORY];
        }

        if ($environment->isServer()) {
            return $this->bareOtherDatabase();
        }

        $file = SqliteFiles::create();
        $this->beforeApplicationDestroyed(function () use ($file): void {
            PackageSession::releaseAll();
            $this->app['db']->purge('testbench_bare');
            SqliteFiles::remove($file);
        });

        return ['database' => $file];
    }

    /**
     * @return array{missing: list<string>, unknown: list<string>}
     */
    private function report(): array
    {
        return $this->app->make(FenceTargets::class)->report($this->fence(), $this->app->make(HostFiles::class));
    }
}
