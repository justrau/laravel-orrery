<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Lifecycle;

use Illuminate\Support\ServiceProvider;

/**
 * The lifecycle test commands of the worker CLI (tests/Support/bin/artisan
 * loads it when ORRERY_TEST_PROVIDERS names it): test:observe.
 */
class LifecycleCommandsProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ObserveCommand::class]);
        }
    }
}
