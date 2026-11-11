<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\UsesPrivateSkeleton;
use Justrau\Orrery\Tests\TestCase;

/**
 * orrery:install validates the retention policy (at the
 * maintenance and install entry points) and the worker pools. The install writes into a private
 * skeleton copy (Support\UsesPrivateSkeleton).
 */
#[FreshSchema]
final class InstallRetentionReportTest extends TestCase
{
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
     * Rejects: an install that accepts a negative or missing retention.
     */
    public function test_install_reports_an_invalid_retention_policy(): void
    {
        self::assertSame(realpath((string) self::privateSkeleton()), realpath(base_path()));

        self::assertSame(0, Artisan::call('orrery:install'));
        self::assertStringContainsString('retention policy is valid', Artisan::output());

        $this->app['config']->set('orrery.trim', ['completed' => -1] + (array) $this->app['config']->get('orrery.trim'));
        $this->app['config']->set('orrery.trim.unknown', null);

        self::assertSame(0, Artisan::call('orrery:install'));
        $output = Artisan::output();

        self::assertStringContainsString('retention policy is invalid', $output);
        self::assertStringContainsString('trim.completed', $output);
        self::assertStringContainsString('trim.unknown', $output);
    }

    /**
     * Rejects: an install that does not report invalid worker pools with their field, that fails on them (they
     * are a warning, as the retention policy), or that prints a pools line when none are declared.
     */
    public function test_install_reports_invalid_worker_pools(): void
    {
        self::assertSame(0, Artisan::call('orrery:install'));
        self::assertStringNotContainsString('worker pools', Artisan::output());

        $this->app['config']->set('orrery.workers.pools', ['default' => ['connection' => 'database', 'queues' => ['default'], 'processes' => 2]]);
        self::assertSame(0, Artisan::call('orrery:install'));
        self::assertStringContainsString('The worker pools are valid', Artisan::output());

        $this->app['config']->set('orrery.workers.pools', ['default' => ['connection' => 'database', 'queues' => ['default'], 'processes' => 0]]);
        self::assertSame(0, Artisan::call('orrery:install'));
        $output = Artisan::output();

        self::assertStringContainsString('The worker pools are invalid', $output);
        self::assertStringContainsString('orrery.workers.pools.default.processes', $output);

        // A timeout within 10 s of retry_after (90 here) is a warning, and the pool stays valid.
        $this->app['config']->set('orrery.workers.pools', ['default' => ['connection' => 'database', 'queues' => ['default'], 'processes' => 2, 'timeout' => 85]]);
        self::assertSame(0, Artisan::call('orrery:install'));
        $output = Artisan::output();
        self::assertStringContainsString('orrery.workers.pools.default.timeout', $output);
        self::assertStringContainsString('The worker pools are valid', $output);
    }

    /**
     * Rejects: an install that does not warn about a ping token that is too short (the route stays off), or that
     * warns when no token is set.
     */
    public function test_install_warns_about_a_short_ping_token(): void
    {
        self::assertSame(0, Artisan::call('orrery:install'));
        self::assertStringNotContainsString('ORRERY_PING_TOKEN', Artisan::output());

        $this->app['config']->set('orrery.ping.token', 'short');
        self::assertSame(0, Artisan::call('orrery:install'));
        self::assertStringContainsString('ORRERY_PING_TOKEN is shorter than 32 characters', Artisan::output());
    }
}
