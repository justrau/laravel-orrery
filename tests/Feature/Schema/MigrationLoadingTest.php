<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Schema;

use Justrau\Orrery\Tests\TestCase;

/**
 * orrery.load_migrations (ORRERY_LOAD_MIGRATIONS): the package loads its
 * migrations from its own directory unless the key is false or a false word,
 * read when the provider boots.
 */
final class MigrationLoadingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->name() === 'test_false_loads_no_package_migration') {
            $app['config']->set('orrery.load_migrations', false);
        }

        if ($this->name() === 'test_a_blank_environment_value_keeps_the_loading_on') {
            // ORRERY_LOAD_MIGRATIONS= (blank).
            $app['config']->set('orrery.load_migrations', '');
        }

        if ($this->name() === 'test_a_false_word_from_the_environment_loads_no_package_migration') {
            // ORRERY_LOAD_MIGRATIONS=0: env() keeps "0" a string.
            $app['config']->set('orrery.load_migrations', '0');
        }
    }

    /**
     * Rejects: a switch that is ignored, so a `migrate` for every tenant database still runs
     * the package migration from the vendor directory (and its rollback drops the package
     * tables).
     */
    public function test_false_loads_no_package_migration(): void
    {
        self::assertNotContains(self::packagePath(), $this->paths());
    }

    /**
     * Rejects: a switch that reads only the boolean false, so ORRERY_LOAD_MIGRATIONS=0 (a string
     * after env()) still loads the package migrations.
     * Still passes: a switch that also takes an unknown word as false (only "0" is set here).
     */
    public function test_a_false_word_from_the_environment_loads_no_package_migration(): void
    {
        self::assertNotContains(self::packagePath(), $this->paths());
    }

    /**
     * Rejects: a blank ORRERY_LOAD_MIGRATIONS= that turns the loading off (filter_var() reads an
     * empty string as false), so a plain `migrate` creates no package table.
     */
    public function test_a_blank_environment_value_keeps_the_loading_on(): void
    {
        self::assertContains(self::packagePath(), $this->paths());
    }

    /**
     * Rejects: a default that loads nothing, so a plain `php artisan migrate` creates no
     * package table.
     */
    public function test_the_default_loads_the_package_migrations(): void
    {
        self::assertTrue($this->app['config']->get('orrery.load_migrations'));
        self::assertContains(self::packagePath(), $this->paths());
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return array_values(array_map(static fn (string $path): string => (string) realpath($path), $this->app['migrator']->paths()));
    }

    private static function packagePath(): string
    {
        return (string) realpath(__DIR__.'/../../../database/migrations');
    }
}
