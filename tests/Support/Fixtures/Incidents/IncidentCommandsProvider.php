<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Incidents;

use Illuminate\Support\ServiceProvider;

/**
 * The child commands of the stuck-notification process tests
 * (tests/Support/bin/artisan loads this provider when ORRERY_TEST_PROVIDERS
 * names it): test:stuck-notifications.
 */
final class IncidentCommandsProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([StuckNotificationsCommand::class]);
        }
    }
}
