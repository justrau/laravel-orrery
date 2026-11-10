<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Incidents;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Justrau\Orrery\Events\StuckJobDetected;
use Justrau\Orrery\Tests\Support\Barrier;

/**
 * A test provider for a worker child (ORRERY_TEST_PROVIDERS): each
 * StuckJobDetected that the child dispatches arrives at a marker
 * `dispatch-<pid>-<random>` in the barrier directory of DIRECTORY, so the
 * parent test counts the dispatches of every child, also of a child that it
 * killed afterwards.
 */
final class RecordStuckDispatchesProvider extends ServiceProvider
{
    /** The barrier directory of the dispatch markers. */
    public const DIRECTORY = 'ORRERY_TEST_DISPATCH_DIR';

    /** The prefix of a dispatch marker. */
    public const PREFIX = 'dispatch-';

    public function boot(): void
    {
        $directory = getenv(self::DIRECTORY);

        if (! is_string($directory) || $directory === '') {
            return;
        }

        $barrier = Barrier::open($directory);

        Event::listen(StuckJobDetected::class, static function (StuckJobDetected $event) use ($barrier): void {
            $barrier->arrive(sprintf('%s%d-%s', self::PREFIX, getmypid(), bin2hex(random_bytes(4))));
        });
    }

    /**
     * The dispatch markers in $barrier's directory.
     */
    public static function count(Barrier $barrier): int
    {
        clearstatcache();

        return count(array_filter(scandir($barrier->directory()) ?: [], static fn (string $entry): bool => str_starts_with($entry, self::PREFIX)));
    }
}
