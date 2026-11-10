<?php

namespace Justrau\Orrery\Tests\Feature\Console;

use Justrau\Orrery\Tests\Support\ApplicationSkeleton;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\UsesPrivateSkeleton;
use Justrau\Orrery\Tests\TestCase;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * orrery:install publishes files into the application and edits its
 * bootstrap/providers.php. Each test gets a private copy of Testbench's
 * application skeleton (Support\UsesPrivateSkeleton), so two suites of one
 * checkout that run at the same time never touch the files of each other,
 * and the shared skeleton under vendor/ never changes.
 */
#[FreshSchema]
class InstallCommandTest extends TestCase
{
    use UsesPrivateSkeleton;

    protected string $providerPath = '';

    protected string $configPath = '';

    protected function setUp(): void
    {
        $this->startPrivateSkeleton();

        parent::setUp();

        $this->providerPath = app_path('Providers/OrreryServiceProvider.php');
        $this->configPath = config_path('orrery.php');
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
     * The files that orrery:install publishes into the skeleton copy: the provider,
     * the config and a copy of each package migration.
     *
     * @return list<string>
     */
    protected function publishedPaths(): array
    {
        $migrations = array_map(
            static fn (string $file): string => database_path('migrations/'.basename($file)),
            glob(dirname(__DIR__, 3).'/database/migrations/*.php') ?: [],
        );

        return [$this->providerPath, $this->configPath, ...$migrations];
    }

    public function test_install_publishes_the_provider_stub_and_config(): void
    {
        $this->artisan('orrery:install')->assertExitCode(0);

        $this->assertFileExists($this->providerPath);
        $this->assertFileExists($this->configPath);

        $providerContents = (string) file_get_contents($this->providerPath);
        $this->assertStringContainsString('class OrreryServiceProvider', $providerContents);
        $this->assertStringContainsString('OrreryApplicationServiceProvider', $providerContents);
        $this->assertStringContainsString("Gate::define('viewOrrery'", $providerContents);
        $this->assertStringContainsString("Gate::define('operateOrrery'", $providerContents);

        foreach (array_slice($this->publishedPaths(), 2) as $migration) {
            $this->assertFileExists($migration);
        }
    }

    /**
     * Rejects: an install test that publishes into, edits or deletes from Testbench's shared
     * skeleton under vendor/, where a concurrent suite of the same checkout uses the same files.
     */
    public function test_install_writes_only_into_a_private_skeleton_copy(): void
    {
        $shared = (string) realpath(default_skeleton_path());
        $base = (string) realpath(base_path());

        // Checked before the install, so a failure here never writes into the shared skeleton.
        $this->assertNotSame($shared, $base);
        $this->assertSame((string) realpath((string) self::privateSkeleton()), $base);
        $this->assertStringStartsWith(base_path().DIRECTORY_SEPARATOR, $this->providerPath);

        // The install never writes the runtime directories, and another suite of the checkout can.
        $runtime = ['storage', 'bootstrap/cache'];
        $before = ApplicationSkeleton::fingerprint($shared, $runtime);

        $this->artisan('orrery:install')->assertExitCode(0);

        $this->assertFileExists($this->providerPath);
        $this->assertStringContainsString(
            'OrreryServiceProvider',
            (string) file_get_contents(base_path('bootstrap/providers.php')),
        );
        $this->assertSame($before, ApplicationSkeleton::fingerprint($shared, $runtime));
    }
}
