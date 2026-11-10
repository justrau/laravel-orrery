<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Hooks;

use Illuminate\Support\ServiceProvider;
use Justrau\Orrery\Runtime\Watcher;

/**
 * A test provider for a worker child (ORRERY_TEST_PROVIDERS): the watched
 * operations of the swapped pop() hook fail, so the child's reservations are
 * never observed.
 */
final class FailPopObservationProvider extends ServiceProvider
{
    public function register(): void
    {
        Watcher::failOperationsForTesting(['hook:pop']);
    }
}
