<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\OffSwitch;

use Illuminate\Support\ServiceProvider;

/**
 * The worker CLI's test:dispatch-pair (DispatchPairCommand), for a dispatch
 * in a child process (tests/Support/bin/artisan with ORRERY_TEST_PROVIDERS).
 */
class DispatchProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DispatchPairCommand::class]);
        }
    }
}
