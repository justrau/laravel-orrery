<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Workbench\App\Providers\WorkbenchServiceProvider;
use Workbench\App\Support\WorkbenchBootstrapCache;

/**
 * The refusal of foreign bootstrap cache paths, without the Workbench CLI.
 *
 * The checker cases pass a test-owned directory, so they never read the Workbench's own files.
 * The provider cases build a bare Laravel application in this process, set the five variables in
 * $_SERVER, and give it a package manifest with a chosen path. They cover the case that only an
 * application that captured its manifest path earlier shows: resolvers that name the owned files
 * and a manifest in use elsewhere. They restore $_SERVER and the container instance afterwards.
 * These tests read and write no shared file, so they are not in the `workbench` group.
 */
final class WorkbenchBootstrapCacheRefusalTest extends TestCase
{
    private string $directory;

    private string $owned;

    private string $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orrery-bootstrap-cache-'.bin2hex(random_bytes(6));
        $this->owned = $this->directory.DIRECTORY_SEPARATOR.'owned';
        $this->foreign = $this->directory.DIRECTORY_SEPARATOR.'foreign';
        mkdir($this->owned, 0700, true);
        mkdir($this->foreign, 0700);
    }

    protected function tearDown(): void
    {
        foreach ([$this->owned, $this->foreign, $this->directory] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function test_owned_paths_pass_in_absolute_and_relative_form(): void
    {
        $resolved = WorkbenchBootstrapCache::paths($this->owned);

        // The web server child resolves the testbench.yaml values against the skeleton, so its
        // paths contain `..` segments.
        $resolved['APP_CONFIG_CACHE'] = $this->foreign.'/../owned/config.php';
        $resolved['APP_EVENTS_CACHE'] = $this->owned.'/./events.php';

        WorkbenchBootstrapCache::refuseForeign($resolved, $this->foreign.'/../owned/packages.php', $this->owned);

        $this->assertTrue(WorkbenchBootstrapCache::isOwned('APP_ROUTES_CACHE', $this->owned.'/routes-v7.php', $this->owned));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function variables(): array
    {
        return array_combine(array_keys(WorkbenchBootstrapCache::FILES), array_map(static fn (string $variable): array => [$variable], array_keys(WorkbenchBootstrapCache::FILES)));
    }

    #[DataProvider('variables')]
    public function test_a_foreign_path_is_refused_for_each_variable(string $variable): void
    {
        $resolved = WorkbenchBootstrapCache::paths($this->owned);
        $resolved[$variable] = $this->foreign.DIRECTORY_SEPARATOR.WorkbenchBootstrapCache::FILES[$variable];

        $this->assertRefused($variable, $resolved[$variable], fn () => WorkbenchBootstrapCache::refuseForeign($resolved, $this->owned.'/packages.php', $this->owned));
    }

    public function test_a_package_manifest_in_use_elsewhere_is_refused(): void
    {
        $manifest = $this->foreign.'/packages.php';

        $this->assertRefused('APP_PACKAGES_CACHE', $manifest, fn () => WorkbenchBootstrapCache::refuseForeign(WorkbenchBootstrapCache::paths($this->owned), $manifest, $this->owned));
    }

    public function test_a_wrong_file_name_a_missing_variable_and_a_missing_directory_are_refused(): void
    {
        $resolved = WorkbenchBootstrapCache::paths($this->owned);
        $resolved['APP_PACKAGES_CACHE'] = $this->owned.'/services.php';
        $this->assertRefused('APP_PACKAGES_CACHE', $resolved['APP_PACKAGES_CACHE'], fn () => WorkbenchBootstrapCache::refuseForeign($resolved, $this->owned.'/packages.php', $this->owned));

        $resolved = WorkbenchBootstrapCache::paths($this->owned);
        unset($resolved['APP_ROUTES_CACHE']);
        $this->assertRefused('APP_ROUTES_CACHE', '', fn () => WorkbenchBootstrapCache::refuseForeign($resolved, $this->owned.'/packages.php', $this->owned));

        $missing = $this->directory.'/missing';
        $this->assertRefused('APP_SERVICES_CACHE', $missing.'/services.php', fn () => WorkbenchBootstrapCache::refuseForeign(WorkbenchBootstrapCache::paths($missing), $missing.'/packages.php', $missing));
    }

    #[DataProvider('variables')]
    public function test_the_provider_refuses_a_foreign_resolver(string $variable): void
    {
        $paths = WorkbenchBootstrapCache::paths();
        $paths[$variable] = $this->foreign.DIRECTORY_SEPARATOR.WorkbenchBootstrapCache::FILES[$variable];

        $this->assertRefused($variable, $paths[$variable], fn () => $this->registerProvider($paths, WorkbenchBootstrapCache::paths()['APP_PACKAGES_CACHE']));
    }

    public function test_the_provider_refuses_a_package_manifest_captured_elsewhere(): void
    {
        // Every resolver names the owned file now, but the manifest kept the path that it
        // captured earlier. Only the check of PackageManifest::$manifestPath sees this.
        $manifest = $this->foreign.'/packages.php';

        $this->assertRefused('APP_PACKAGES_CACHE', $manifest, fn () => $this->registerProvider(WorkbenchBootstrapCache::paths(), $manifest));
    }

    /**
     * Register the Workbench provider on a bare application whose five variables are $paths, and
     * whose package manifest uses $manifest.
     *
     * @param  array<string, string>  $paths
     */
    private function registerProvider(array $paths, string $manifest): void
    {
        WorkbenchBootstrapCache::ensureDirectory();

        $saved = [];
        foreach ($paths as $variable => $path) {
            $saved[$variable] = array_key_exists($variable, $_SERVER) ? $_SERVER[$variable] : null;
            $_SERVER[$variable] = $path;
        }

        $previous = Container::getInstance();

        try {
            $app = new Application($this->directory);
            $app->instance(PackageManifest::class, new PackageManifest(new Filesystem, $this->directory, $manifest));

            (new WorkbenchServiceProvider($app))->register();
        } finally {
            foreach ($saved as $variable => $value) {
                if ($value === null) {
                    unset($_SERVER[$variable]);
                } else {
                    $_SERVER[$variable] = $value;
                }
            }

            Container::setInstance($previous);
        }
    }

    /**
     * The provider cases bind no configuration, so a provider that does not refuse goes on and
     * throws a later error. That error must not count as the refusal.
     */
    private function assertRefused(string $variable, string $path, callable $call): void
    {
        try {
            $call();
        } catch (Throwable $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception, sprintf('The refusal did not fire for %s: the call threw %s: %s', $variable, get_class($exception), $exception->getMessage()));
            $this->assertStringContainsString(sprintf('The Workbench bootstrap cache %s resolves to [%s], not to the owned', $variable, $path), $exception->getMessage());
            $this->assertStringContainsString('php workbench/bin/testbench', $exception->getMessage());

            return;
        }

        $this->fail(sprintf('The refusal accepted %s at [%s].', $variable, $path));
    }
}
