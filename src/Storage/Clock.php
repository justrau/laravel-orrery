<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The one clock of the package.
 *
 * Every package time is epoch seconds with microseconds, stored in a
 * decimal(16,6) column. SQL never calls the server clock. now() follows
 * Carbon::setTestNow(), so tests control it. A duration inside one process
 * uses monotonic(), which a change of the wall clock never moves.
 *
 * Storage contract. now() returns a float for arithmetic. A float must never
 * reach the database as a binding: Laravel binds a float as a string, and
 * PHP converts it with the `precision` setting (14 by default), so
 * 1789999999.123456 becomes 1789999999.1235. So every writer binds a stored
 * time, and every time predicate (a cutoff, a deadline, a lease), through
 * toDecimal(), from the value that it sampled or computed once:
 *
 *     $now = Clock::now();
 *     $query->where('lease_until', '<', Clock::toDecimal($now))
 *           ->update(['finished_at' => Clock::toDecimal($now)]);
 *
 * A second call of now() is never a replacement for the formatted sample.
 */
final class Clock
{
    /** Seconds that tests add to monotonic() (advanceMonotonicForTesting()). */
    private static float $monotonicOffset = 0.0;

    /** The frozen base of monotonic() (freezeMonotonicForTesting()), or null while it runs. */
    private static ?float $frozenMonotonic = null;

    /**
     * Epoch seconds with microseconds.
     */
    public static function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }

    /**
     * Seconds from an arbitrary fixed point, for durations in one process.
     */
    public static function monotonic(): float
    {
        return (self::$frozenMonotonic ?? hrtime(true) / 1e9) + self::$monotonicOffset;
    }

    /**
     * Stops monotonic() at its current value (tests only): it then moves only
     * through advanceMonotonicForTesting(), so a test that measures a duration
     * does not depend on how fast the machine runs. Watcher::monotonic()
     * stops with it while the watcher is not frozen itself.
     */
    public static function freezeMonotonicForTesting(): void
    {
        self::$frozenMonotonic = hrtime(true) / 1e9;
    }

    /**
     * Lets $seconds elapse on monotonic() (tests only), so a test proves a
     * duration without a sleep. The offset also reaches Watcher::monotonic()
     * while that clock is not frozen; both measure the same time.
     * StorageModule::resetForTesting() (the test reset hook) removes it.
     */
    public static function advanceMonotonicForTesting(float $seconds): void
    {
        self::$monotonicOffset += $seconds;
    }

    public static function resetForTesting(): void
    {
        self::$monotonicOffset = 0.0;
        self::$frozenMonotonic = null;
    }

    /**
     * The decimal(16,6) text of a time that was already sampled or computed:
     * six decimal places, a point as the separator in every locale, and no
     * dependency on PHP's precision setting.
     *
     * @throws InvalidArgumentException  for NAN and INF
     */
    public static function toDecimal(float $seconds): string
    {
        if (! is_finite($seconds)) {
            throw new InvalidArgumentException('A package time must be a finite number of seconds.');
        }

        // %F is the locale-independent form of %f.
        return sprintf('%.6F', $seconds);
    }
}
