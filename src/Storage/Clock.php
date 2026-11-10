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
     * stops with it while the watcher is not frozen itself. The frozen value
     * is on the grid of frozenBaseForTesting().
     */
    public static function freezeMonotonicForTesting(): void
    {
        self::$frozenMonotonic = self::frozenBaseForTesting(hrtime(true) / 1e9);
    }

    /**
     * The first multiple of 1/1024 s at or after $seconds: the base of a
     * frozen test clock. Whole seconds and binary fractions (0.5, 0.25) then
     * add to it and subtract from it exactly. A base read from hrtime() as it
     * is loses its last bits when a sum crosses a power of two, so a frozen
     * 5 s can read as 4.999999999999986 s. The base is never before $seconds,
     * so a freeze never moves a clock back.
     */
    public static function frozenBaseForTesting(float $seconds): float
    {
        return ceil($seconds * 1024.0) / 1024.0;
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
     * A time read from a decimal(16,6) column, as the float that now() gives
     * for the same decimal. MySQL and Postgres return the decimal text.
     * SQLite keeps a REAL from its own text conversion, and some builds
     * (3.45 on x86-64) round a decimal to the float next to the nearest one.
     * A difference with now() then misses whole seconds by a hair, and a
     * check at the exact end of a grace or a lease decides wrongly. Every
     * stored time that PHP compares with now(), or with a bound computed
     * from it, reads through this: it rounds to the microsecond grid of the
     * column, so whole seconds add to the result and subtract from it
     * exactly.
     */
    public static function stored(int|float|string $value): float
    {
        return round((float) $value * 1_000_000) / 1_000_000;
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
