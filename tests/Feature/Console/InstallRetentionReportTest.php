<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\UsesPrivateSkeleton;
use Justrau\Orrery\Tests\TestCase;

/**
 * orrery:install validates the retention policy (at the
 * maintenance and install entry points). The install writes into a private
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
}
