<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Justrau\Orrery\Tests\TestCase;
use Workbench\App\Providers\WorkbenchServiceProvider;
use Workbench\App\Support\WorkbenchDatabase;

/**
 * The PHPUnit suite never loads the Workbench app. Only testbench.yaml loads
 * WorkbenchServiceProvider, and the package TestCase registers its own providers.
 * If the suite loaded it, every test would run against workbench/database/database.sqlite.
 */
final class WorkbenchIsolationTest extends TestCase
{
    public function test_the_package_test_case_does_not_load_the_workbench_provider(): void
    {
        $this->assertFalse(
            $this->app->providerIsLoaded(WorkbenchServiceProvider::class),
            'The package TestCase loaded WorkbenchServiceProvider.',
        );

        // The provider's side effects are absent too.
        $this->assertNull($this->app['config']->get('queue.connections.database-secondary'));
        $this->assertFalse(Gate::has('viewOrrery'));
        $this->assertNotSame(
            realpath(WorkbenchDatabase::directory()),
            realpath($this->app->databasePath()),
        );

        $default = (string) $this->app['config']->get('database.default');
        $this->assertNotSame(
            WorkbenchDatabase::resolve(null),
            $this->app['config']->get("database.connections.{$default}.database"),
        );
    }
}
