<?php

namespace Justrau\Orrery\Runtime;

use Closure;

/**
 * Named points in the code where a test can pause a process.
 *
 * reach() does nothing unless a test sets $callback. The test worker CLI
 * (tests/Support/bin/artisan) sets it, so that a worker waits at a barrier
 * when it reaches a point. A test can then force an interleaving of two
 * processes without sleeps.
 *
 * The test suite resets $callback before and after every test
 * (tests/Support/ResetsPackageState.php).
 */
final class Hooks
{
    /**
     * @var (Closure(string): void)|null
     */
    public static ?Closure $callback = null;

    public static function reach(string $point): void
    {
        $callback = self::$callback;

        if ($callback !== null) {
            $callback($point);
        }
    }
}
