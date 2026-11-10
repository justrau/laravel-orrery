<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * The child commands of the bulk run process tests
 * (tests/Support/bin/artisan loads this provider when ORRERY_TEST_PROVIDERS
 * names it): test:bulk-forget.
 */
class BulkCommandsProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([BulkForgetCommand::class]);
        }
    }
}
