<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Database\Connection;

/**
 * A stored time as some SQLite builds keep it. SQLite holds a decimal(16,6)
 * value as a REAL from its own text conversion, and SQLite 3.45 on x86-64
 * gives about one decimal in two thousand the neighbouring float instead of
 * the nearest one: 1796604698.689484 reads back as 1796604698.6894841. The
 * text of that neighbour, written to a SQLite column, stores the same float
 * on every SQLite build, so a test reproduces those reads on any machine.
 */
final class OffGridTime
{
    /**
     * The shortest text of the float next to $decimal's float, above it
     * ($direction 1) or below it (-1).
     */
    public static function neighbour(string $decimal, int $direction): string
    {
        $float = (float) $decimal;
        $step = 2.0 ** (floor(log($float, 2)) - 52);

        return sprintf('%.17g', $float + $direction * $step);
    }

    /**
     * The value to write to a time column of $connection: the neighbour on
     * SQLite. Other engines keep the exact decimal, so they get $decimal.
     */
    public static function stored(Connection $connection, string $decimal, int $direction): string
    {
        return $connection->getDriverName() === 'sqlite' ? self::neighbour($decimal, $direction) : $decimal;
    }
}
