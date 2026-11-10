<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Registration\Modules\StorageModule;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\OffGridTime;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The watcher is a container singleton.
        $container = new Container;
        $container->singleton(Watcher::class);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        StorageModule::resetForTesting();
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * Rejects: time() or a whole-second Carbon value.
     */
    public function test_now_follows_carbon_test_now_and_keeps_microseconds(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1790000000.123456'));

        self::assertSame((float) '1790000000.123456', Clock::now());

        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1790000007.000001'));

        self::assertSame((float) '1790000007.000001', Clock::now());

        $first = Clock::monotonic();
        $second = Clock::monotonic();

        self::assertGreaterThanOrEqual($first, $second);
    }

    /**
     * Rejects: a string cast of the float, which follows PHP's precision setting.
     */
    public function test_to_decimal_keeps_six_places_whatever_the_precision_setting(): void
    {
        $precision = ini_get('precision');
        ini_set('precision', '14');

        try {
            self::assertSame('1789999999.1235', (string) 1789999999.123456, 'The control: a string cast loses the microseconds.');
            self::assertSame('1789999999.123456', Clock::toDecimal(1789999999.123456));
            self::assertSame('1789999999.123457', Clock::toDecimal(1789999999.123457));
            self::assertSame('1790000000.000001', Clock::toDecimal(1790000000.000001));
            self::assertSame('0.000000', Clock::toDecimal(0.0));
            self::assertSame('-12.500000', Clock::toDecimal(-12.5));
        } finally {
            ini_set('precision', (string) $precision);
        }

        foreach ([NAN, INF, -INF] as $invalid) {
            try {
                Clock::toDecimal($invalid);
                self::fail('A time that is not finite is refused.');
            } catch (\InvalidArgumentException $expected) {
                self::assertStringContainsString('finite', $expected->getMessage());
            }
        }
    }

    /**
     * Rejects: %f, which writes a decimal comma in some locales.
     */
    public function test_to_decimal_ignores_a_decimal_comma_locale(): void
    {
        $locale = (string) setlocale(LC_NUMERIC, '0');
        $comma = setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR');

        try {
            if ($comma === false) {
                self::markTestSkipped('This system has no locale with a decimal comma (de_DE or fr_FR).');
            }

            self::assertSame('0,5', sprintf('%.1f', 0.5), 'The control: %f follows the locale.');
            self::assertSame('1789999999.123456', Clock::toDecimal(1789999999.123456));
        } finally {
            setlocale(LC_NUMERIC, $locale);
        }
    }

    /**
     * Rejects: an offset that monotonic() ignores, an offset that replaces the
     * clock instead of adding to it, and a second advance that overwrites the first.
     */
    public function test_advance_monotonic_for_testing_adds_to_the_clock(): void
    {
        $before = Clock::monotonic();

        Clock::advanceMonotonicForTesting(300.0);
        Clock::advanceMonotonicForTesting(0.25);

        $after = Clock::monotonic();
        $real = hrtime(true) / 1e9;

        self::assertGreaterThanOrEqual($before + 300.25, $after);
        self::assertLessThan($before + 301.25, $after, 'The offset adds 300.25 s, not more.');
        self::assertGreaterThan($real + 299.0, $after, 'The offset is added to the running clock.');
    }

    /**
     * Rejects: a module reset that leaves the offset of Clock, so it leaks
     * into the next test through the reset hook (StorageModule).
     */
    public function test_storage_reset_clears_the_clock_offset(): void
    {
        Clock::advanceMonotonicForTesting(1000.0);

        StorageModule::resetForTesting();

        self::assertLessThan(hrtime(true) / 1e9 + 1.0, Clock::monotonic());
    }

    /**
     * Rejects: a watcher clock that reads hrtime() directly, so the offset of
     * Clock does not reach it; and a frozen watcher clock that still follows it.
     */
    public function test_the_offset_reaches_the_watcher_clock_unless_it_is_frozen(): void
    {
        $before = Watcher::monotonic();

        Clock::advanceMonotonicForTesting(50.0);

        self::assertGreaterThanOrEqual($before + 50.0, Watcher::monotonic());

        Watcher::freezeMonotonicForTesting();
        $frozen = Watcher::monotonic();

        Clock::advanceMonotonicForTesting(50.0);

        self::assertSame($frozen, Watcher::monotonic());
    }

    /**
     * Rejects: a frozen clock that still follows hrtime(), a freeze that does
     * not keep the current value (the offset counted twice, nanoseconds), a
     * frozen clock that ignores the test offset, and a module reset that
     * leaves it frozen.
     */
    public function test_a_frozen_clock_moves_only_by_the_offset(): void
    {
        Clock::advanceMonotonicForTesting(5.0);
        $before = Clock::monotonic();
        Clock::freezeMonotonicForTesting();
        $frozen = Clock::monotonic();

        self::assertGreaterThanOrEqual($before, $frozen, 'The freeze keeps the current value.');
        self::assertLessThan($before + 1.0, $frozen, 'The freeze does not add the offset twice.');

        // Real time passes: about 1 ms of busy work.
        $until = hrtime(true) + 1_000_000;
        while (hrtime(true) < $until) {
        }

        self::assertSame($frozen, Clock::monotonic());

        Clock::advanceMonotonicForTesting(0.25);

        self::assertSame($frozen + 0.25, Clock::monotonic());

        StorageModule::resetForTesting();
        $first = Clock::monotonic();
        $until = hrtime(true) + 1_000_000;
        while (hrtime(true) < $until) {
        }

        self::assertGreaterThan($first, Clock::monotonic(), 'The reset lets the clock run again.');
    }

    /**
     * Rejects: a frozen base read from hrtime() as it is. The sum of such a
     * base and an advance loses its last bits when it crosses a power of two,
     * so a frozen duration of 5 s read as 4.999999999999986 on a CI runner
     * that had run for about two minutes (ConnectAttemptsTest). The check
     * crosses every power of two up to 2^42 s, so an unaligned base fails
     * here on every run. Still passes: a base on a coarser grid (whole
     * seconds).
     */
    public function test_a_frozen_clock_adds_seconds_exactly_across_powers_of_two(): void
    {
        $real = hrtime(true) / 1e9;
        Clock::freezeMonotonicForTesting();

        self::assertGreaterThanOrEqual($real, Clock::monotonic(), 'The freeze never moves the clock back.');
        self::assertLessThan($real + 1.0, Clock::monotonic());

        self::assertExactAcrossPowersOfTwo(Clock::monotonic(...), Clock::advanceMonotonicForTesting(...));
    }

    /**
     * Rejects: a frozen watcher base taken from a running Clock::monotonic()
     * as it is: the monitoring cache of 10 s then read 9.999999999999986 s
     * as still fresh on a CI runner (HotPathBudgetTest). Still passes: a base
     * on a coarser grid (whole seconds).
     */
    public function test_a_frozen_watcher_clock_adds_seconds_exactly_across_powers_of_two(): void
    {
        $real = hrtime(true) / 1e9;
        Watcher::freezeMonotonicForTesting();

        self::assertGreaterThanOrEqual($real, Watcher::monotonic(), 'The freeze never moves the clock back.');
        self::assertLessThan($real + 1.0, Watcher::monotonic());

        self::assertExactAcrossPowersOfTwo(Watcher::monotonic(...), Watcher::advanceMonotonicForTesting(...));
    }

    /**
     * Advances a frozen clock to just below each power of two above it, up
     * to 2^42 s, and then by 5 s across that power: each 5 s must read as
     * exactly 5.0.
     *
     * @param  Closure(): float  $read
     * @param  Closure(float): void  $advance
     */
    private static function assertExactAcrossPowersOfTwo(Closure $read, Closure $advance): void
    {
        $frozen = $read();
        $offset = 0.0;
        $power = 1.0;

        while ($power <= $frozen) {
            $power *= 2.0;
        }

        for (; $power <= 2.0 ** 42; $power *= 2.0) {
            // Whole seconds to between 3 s and 2 s below the power.
            $step = floor($power - $frozen) - 2.0 - $offset;
            $advance($step);
            $offset += $step;
            $below = $read();
            self::assertLessThan($power, $below);

            $advance(5.0);
            $offset += 5.0;
            $elapsed = $read() - $below;

            self::assertSame(5.0, $elapsed, sprintf('5 s across 2^%d s read as %.17g s.', (int) log($power, 2), $elapsed));
        }
    }

    /**
     * Rejects: a stored time read with a plain (float) cast. SQLite 3.45 on
     * x86-64 keeps 1796604698.689484 as the float above it, so 600 s after
     * it read as 599.99999976 s and a row at the exact end of its grace
     * stayed pending on CI (ActiveReconciliationTest). Also rejects
     * round($value * 1e6) / 1e6: before PHP 8.4, round() pre-rounds to 15
     * significant digits and misses the microsecond (CI, PHP 8.3). Still
     * passes: a reader that is exact only for times before 2^31 s (the
     * float grid is coarser after it; no case here).
     */
    public function test_a_stored_time_off_the_float_grid_reads_as_its_decimal(): void
    {
        foreach (['1796604698.689484', '1802792363.274526', '1790000000.000001', '1100000000.999999'] as $decimal) {
            foreach ([1, -1] as $direction) {
                $stored = (float) OffGridTime::neighbour($decimal, $direction);
                self::assertNotSame((float) $decimal, $stored, 'the stored float is off the grid');
                self::assertSame((float) $decimal, Clock::stored($stored), $decimal);
            }

            self::assertSame((float) $decimal, Clock::stored($decimal), 'a decimal string, as MySQL and Postgres give it');
        }

        $start = (float) OffGridTime::neighbour('1796604698.689484', 1);
        self::assertSame(600.0, (float) '1796605298.689484' - Clock::stored($start));
    }
}
