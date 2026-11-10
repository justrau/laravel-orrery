<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\UsesPrivateSkeleton;
use Justrau\Orrery\Tests\TestCase;

/**
 * orrery:install --fence:
 * one self-contained migration per database connection that needs
 * the fence table and does not get it from the main migration. Kind: new
 * behaviour.
 */
#[FreshSchema]
final class FenceInstallTest extends TestCase
{
    use UsesPrivateSkeleton;

    /** @var list<string> */
    private array $sqliteFiles = [];

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
            foreach ($this->sqliteFiles as $file) {
                SqliteFiles::remove($file);
            }

            $this->endPrivateSkeleton();
        }
    }

    /**
     * Rejects: package imports in the migration (R5), a second file for a connection that has one, `migrate` alone
     * (a queue connection added later gets no table: R4), and a table that differs from the main migration's.
     */
    public function test_install_fence_writes_self_contained_migrations(): void
    {
        $config = $this->app['config'];
        $other = $this->otherDatabase();
        $config->set('database.connections.tenant', array_replace((array) $config->get('database.connections.'.TestEnvironment::PRIMARY), $other === null ? [] : ['database' => $other]));
        $config->set('queue.connections.tenant_queue', ['driver' => 'database', 'connection' => 'tenant', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => TestEnvironment::PRIMARY, 'table' => 'failed_jobs']);
        $sibling = $this->app->make(PackageSession::class)->mirrorIsSibling();
        $mirrorShares = ! $sibling && $this->testEnvironment()->packageConnection() === null;

        $this->artisan('orrery:install', ['--fence' => true])->assertExitCode(0);

        $expected = ['tenant'];

        if (! $mirrorShares) {
            // The queue's own database is not the mirror's (a sibling file, or a separate mirror).
            $expected[] = TestEnvironment::PRIMARY;
        }

        sort($expected);
        self::assertSame($expected, $this->fenceConnections());

        foreach ($this->fenceFiles() as $file) {
            $contents = (string) file_get_contents($file);
            preg_match_all('/^use ([^;]+);/m', $contents, $imports);
            self::assertNotSame([], $imports[1]);

            foreach ($imports[1] as $import) {
                self::assertStringStartsWith('Illuminate\\', $import, "{$file} imports only Illuminate classes.");
            }

            self::assertStringNotContainsString('Justrau', $contents);
            self::assertStringContainsString('hasTable', $contents);
        }

        // A second run writes nothing.
        $before = $this->fenceFiles();
        $this->artisan('orrery:install', ['--fence' => true])->expectsOutputToContain('Every fence migration exists')->assertExitCode(0);
        self::assertSame($before, $this->fenceFiles());

        // A queue connection added later gets its file.
        $config->set('database.connections.later', $config->get('database.connections.tenant'));
        $config->set('queue.connections.later_queue', ['driver' => 'database', 'connection' => 'later', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $this->artisan('orrery:install', ['--fence' => true])->assertExitCode(0);
        self::assertContains('later', $this->fenceConnections());
        self::assertCount(count($before) + 1, $this->fenceFiles());

        if ($other === null) {
            // A separate mirror on a server holds the only other database of the run: its fence table stays.
            return;
        }

        // The tenant file creates the table that the main migration creates.
        $tenant = (string) current(array_filter($this->fenceFiles(), static fn (string $file): bool => str_ends_with($file, '_on_tenant.php')));
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->dropIfExists(Fence::TABLE);
        $this->artisan('migrate', ['--path' => $tenant, '--realpath' => true, '--database' => 'tenant'])->assertExitCode(0);
        self::assertTrue($schema->hasTable(Fence::TABLE));
        $this->beforeApplicationDestroyed(static function () use ($schema): void {
            $schema->dropIfExists(Fence::TABLE);
            $schema->dropIfExists('migrations');
        });

        $mirror = $this->mirrorSchema();
        self::assertSame(self::columns($mirror->getColumns(Fence::TABLE)), self::columns($schema->getColumns(Fence::TABLE)));
        self::assertSame(self::indexes($mirror->getIndexes(Fence::TABLE)), self::indexes($schema->getIndexes(Fence::TABLE)));
    }

    /**
     * Rejects: a file slug that maps two connection names to one (`tenant-1` and `tenant_1`), so the second
     * database never gets its fence migration and a second run says every migration exists.
     */
    public function test_connection_names_with_one_plain_slug_get_their_own_files(): void
    {
        $config = $this->app['config'];

        foreach (['tenant-1', 'tenant_1'] as $name) {
            $config->set("database.connections.{$name}", (array) $config->get('database.connections.'.TestEnvironment::PRIMARY));
            $config->set("queue.connections.{$name}_queue", ['driver' => 'database', 'connection' => $name, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        }

        $this->artisan('orrery:install', ['--fence' => true])->assertExitCode(0);

        $files = $this->fenceFiles();
        $named = static fn (string $name): array => array_values(array_filter($files, static fn (string $file): bool => str_contains((string) file_get_contents($file), "'{$name}'")));
        self::assertCount(1, $named('tenant-1'));
        self::assertCount(1, $named('tenant_1'));
        self::assertNotSame($named('tenant-1'), $named('tenant_1'));
        self::assertContains('tenant_1', $this->fenceConnections(), 'A plain name keeps its plain slug.');
    }

    /**
     * Rejects: an orrery:install whose fence migrations could not be written and that still exits 0.
     */
    public function test_install_exits_1_when_a_fence_migration_cannot_be_written(): void
    {
        $config = $this->app['config'];
        $config->set('database.connections.tenant', (array) $config->get('database.connections.'.TestEnvironment::PRIMARY));
        $config->set('queue.connections.tenant_queue', ['driver' => 'database', 'connection' => 'tenant', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        // The files are published first; then the directory is read-only, so the fence migration cannot be written.
        $this->artisan('vendor:publish', ['--tag' => 'orrery-migrations'])->assertExitCode(0);
        $this->artisan('vendor:publish', ['--tag' => 'orrery-config'])->assertExitCode(0);
        $directory = database_path('migrations');
        chmod($directory, 0555);
        $this->beforeApplicationDestroyed(static fn () => chmod($directory, 0755));

        if (is_writable($directory)) {
            self::markTestSkipped('This user writes a read-only directory (root).');
        }

        $this->artisan('orrery:install')->assertExitCode(1);
    }

    /**
     * @return list<string>
     */
    private function fenceFiles(): array
    {
        $files = glob(database_path('migrations').'/*_create_orrery_fences_on_*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function fenceConnections(): array
    {
        $names = array_map(static fn (string $file): string => (string) preg_replace('/^.*_create_orrery_fences_on_(.+)\.php$/', '$1', $file), $this->fenceFiles());
        sort($names);

        return $names;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return list<string>
     */
    private static function columns(array $columns): array
    {
        $names = array_map(static fn (array $column): string => $column['name'].':'.$column['type_name'].':'.($column['nullable'] ? 'null' : 'not null'), $columns);
        sort($names);

        return $names;
    }

    /**
     * @param  list<array<string, mixed>>  $indexes
     * @return list<string>
     */
    private static function indexes(array $indexes): array
    {
        $names = array_map(static fn (array $index): string => implode(',', (array) $index['columns']).($index['primary'] ? ':primary' : ($index['unique'] ? ':unique' : '')), $indexes);
        sort($names);

        return $names;
    }

    private function otherDatabase(): ?string
    {
        $environment = $this->testEnvironment();

        if ($environment->inMemory()) {
            return TestEnvironment::MEMORY;
        }

        if (! $environment->isServer()) {
            return $this->sqliteFiles[] = SqliteFiles::create();
        }

        return $environment->separate() ? null : (string) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.database').'_mirror';
    }
}
