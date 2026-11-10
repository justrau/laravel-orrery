<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Hooks;

use Illuminate\Support\ServiceProvider;

/**
 * A test provider for a worker child (ORRERY_TEST_PROVIDERS): it turns the
 * connector swap off before the queue manager resolves.
 */
final class SwapOffProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('orrery.swap_connector', false);
    }
}
