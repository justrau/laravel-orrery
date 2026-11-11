<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Tests\TestCase;
use Justrau\Orrery\Workers\ProcessConfig;

/**
 * orrery:workers prints the process-manager configuration of
 * orrery.workers.pools. The exact formats are pinned in
 * Unit\Workers\ProcessConfigTest; this test pins the command around them.
 */
final class WorkersCommandTest extends TestCase
{
    private const POOLS = [
        'default' => ['connection' => 'database', 'queues' => ['high', 'default'], 'processes' => 2, 'tries' => 3],
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->name() === 'test_it_runs_while_the_package_is_off') {
            $app['config']->set('orrery.enabled', false);
        }
    }

    /**
     * Rejects: a command that fails (or prints a configuration) without pools.
     */
    public function test_no_pools_print_a_hint_and_exit_zero(): void
    {
        self::assertSame(0, Artisan::call('orrery:workers'));
        self::assertStringContainsString('No worker pools are declared', Artisan::output());
    }

    /**
     * Rejects: a command that prints a configuration for invalid pools, or exits 0.
     */
    public function test_invalid_pools_print_the_errors_and_exit_one(): void
    {
        $this->app['config']->set('orrery.workers.pools', ['default' => ['connection' => 'redis', 'queues' => [], 'processes' => 2]]);

        self::assertSame(1, Artisan::call('orrery:workers', ['--format' => 'supervisor']));
        $output = Artisan::output();

        self::assertStringContainsString('orrery.workers.pools.default.connection', $output);
        self::assertStringContainsString('orrery.workers.pools.default.queues', $output);
        self::assertStringNotContainsString('[program:', $output);
    }

    /**
     * Rejects: a default list format that is not the exact queue:work line with --name and the process count.
     */
    public function test_the_default_format_is_the_list(): void
    {
        $this->app['config']->set('orrery.workers.pools', self::POOLS);

        self::assertSame(0, Artisan::call('orrery:workers'));
        self::assertSame("default (2 processes): php artisan queue:work database --queue=high,default --name=default --tries=3\n", Artisan::output());
    }

    /**
     * Rejects: defaults other than the application's base path, its storage/logs and its name as a slug, and options
     * that do not reach the output.
     */
    public function test_supervisor_defaults_and_options(): void
    {
        $this->app['config']->set('orrery.workers.pools', self::POOLS);
        $this->app['config']->set('app.name', 'My Shop!');

        self::assertSame(0, Artisan::call('orrery:workers', ['--format' => 'supervisor', '--php' => '/opt/php', '--user' => 'deploy']));
        $output = Artisan::output();

        self::assertStringContainsString("[program:my-shop-default]\n", $output);
        self::assertStringContainsString('command=/opt/php '.ProcessConfig::shellWord(base_path().'/artisan').' queue:work database', $output);
        self::assertStringContainsString("user=deploy\n", $output);
        self::assertStringContainsString('stdout_logfile='.str_replace('%', '%%', storage_path('logs')).'/my-shop-default.log', $output);

        self::assertSame(0, Artisan::call('orrery:workers', ['--format' => 'systemd', '--prefix' => 'acme', '--path' => '/srv/app/', '--php' => 'php']));
        self::assertStringContainsString("ExecStart=php /srv/app/artisan queue:work database --queue=high,default --name=default --tries=3\n", Artisan::output());
    }

    /**
     * Rejects: a forge output without the Custom tab's command or the note that the Queue worker tab cannot set --name.
     */
    public function test_the_forge_format_prints_tables(): void
    {
        $this->app['config']->set('orrery.workers.pools', self::POOLS);

        self::assertSame(0, Artisan::call('orrery:workers', ['--format' => 'forge', '--prefix' => 'acme', '--user' => 'forge']));
        $output = Artisan::output();

        self::assertStringContainsString('Use the Custom tab', $output);
        self::assertStringContainsString('Pool default, Custom tab:', $output);
        self::assertMatchesRegularExpression('/\| Command +\| php artisan queue:work database --queue=high,default --name=default --tries=3 +\|/', $output);
        self::assertMatchesRegularExpression('/\| Number of Processes +\| 2 +\|/', $output);
    }

    /**
     * Rejects: a command that accepts an unknown format or an unsafe prefix.
     */
    public function test_invalid_options_exit_one(): void
    {
        $this->app['config']->set('orrery.workers.pools', self::POOLS);

        self::assertSame(1, Artisan::call('orrery:workers', ['--format' => 'docker']));
        self::assertSame(1, Artisan::call('orrery:workers', ['--format' => 'supervisor', '--prefix' => 'My App']));
        self::assertSame(1, Artisan::call('orrery:workers', ['--format' => 'systemd', '--user' => "a\nb"]));
    }

    /**
     * Rejects: a command that refuses while the package is off: it reads only the configuration.
     */
    public function test_it_runs_while_the_package_is_off(): void
    {
        $this->app['config']->set('orrery.workers.pools', self::POOLS);

        self::assertSame(0, Artisan::call('orrery:workers'));
        self::assertStringContainsString('--name=default', Artisan::output());
    }
}
