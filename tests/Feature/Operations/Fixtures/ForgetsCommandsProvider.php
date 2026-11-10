<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * The child commands of the forget process tests
 * (tests/Support/bin/artisan loads this provider when ORRERY_TEST_PROVIDERS
 * names it). The package's verifying extender binds its
 * CapturingUuidFailedJobProvider; this provider adds:
 * - test:new-forget {uuid} {occurrence} {--null-failer}: one
 *   ForgetService::forget() (with --null-failer, after the identity check, the
 *   null failer), printed as one JSON line;
 * - test:forget-all {--connection=} {--queue=}: one ForgetAllRecords::run(),
 *   printed as one JSON line.
 */
class ForgetsCommandsProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([NewForgetCommand::class, ForgetAllCommand::class]);
    }
}
