<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Control;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * A test provider for a worker child (ORRERY_TEST_PROVIDERS): the child
 * calls Queue::withoutInterruptionPolling(), so Laravel's worker reads no
 * pause or restart flag (the documented limit of a queue
 * pause).
 */
final class WithoutInterruptionPollingProvider extends ServiceProvider
{
    public function boot(): void
    {
        Queue::withoutInterruptionPolling();
    }
}
