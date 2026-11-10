<?php

namespace Justrau\Orrery\Http\Lists;

/**
 * A count that stops at a cap: `SELECT COUNT(*) FROM
 * (SELECT 1 … ORDER BY <the range's order> LIMIT cap + 1)` on the list's
 * access path, so a count reads at most cap + 1 index entries per range. The
 * ORDER BY is the index order: without it Postgres reads a large range with
 * a sequential scan until the LIMIT, past cap + 1 rows. A count above the
 * cap answers `{count: cap, at_least: true}`. Merged ranges are counted one
 * by one and summed, and the sum is capped again.
 */
final class CappedCount
{
    /**
     * @param  list<ListRange>  $ranges
     * @return array{count: int, at_least: bool}
     */
    public static function of(array $ranges, int $cap): array
    {
        $total = 0;

        foreach ($ranges as $range) {
            $inner = $range->query()->selectRaw('1');

            if ($range->nullOrder) {
                $inner->whereNull($range->order);
            }

            $range->bound($inner, null, true);

            $range->orderBy($inner, 'asc')->limit($cap + 1);
            $count = (int) $inner->newQuery()->fromSub($inner, 'capped_rows')->count();
            $total += $count;

            if ($total > $cap) {
                break;
            }
        }

        return self::capped($total, $cap, false);
    }

    /**
     * @return array{count: int, at_least: bool}
     */
    public static function capped(int $count, int $cap, bool $atLeast): array
    {
        return $count > $cap ? ['count' => $cap, 'at_least' => true] : ['count' => $count, 'at_least' => $atLeast];
    }
}
