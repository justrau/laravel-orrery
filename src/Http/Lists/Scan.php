<?php

namespace Justrau\Orrery\Http\Lists;

use Closure;

/**
 * An ordered scan of one or more index ranges, merged by the order key.
 *
 * - Each range is read in chunks, each with the same keyset condition after
 *   the range's last read key, lazily: a range is read again only when its
 *   buffer is empty and the merge needs its next row. So an index-only page
 *   with a chunk of per_page + 1 reads at most ranges × (per_page + 1) rows.
 * - Every read row counts against the budget (the rows examined). The
 *   first read of each range happens whatever the budget (at least one row
 *   each), so the merge always has the head of every range and a scan
 *   returns at least one row before it can stop: a request always makes
 *   progress (a row, or a cursor past an examined row). When a range needs
 *   another read and the budget is spent, one probe row (outside the
 *   budget) decides: no row means the range ended exactly at the budget;
 *   else the scan stops and `limited` is true; the rows already returned are exactly
 *   the rows up to the last one in the merged order, so a later scan after
 *   that row's key misses none.
 * - The merge compares the order values as numbers, then the keys; a scan
 *   of several ranges always has integer keys (the tag sources are one
 *   range).
 *
 * Items are objects with `o` (the order value: a decimal or null), `k` (the
 * key) and whatever the fetch adds.
 */
final class Scan
{
    /** The scan stopped because the budget was spent before the end. */
    public bool $limited = false;

    /** The rows read so far. */
    public int $examined = 0;

    /** @var array<int, list<ScanItem>> */
    private array $buffers = [];

    /** @var array<int, bool> */
    private array $exhausted = [];

    /** @var array<int, bool> whether the range had its first read */
    private array $started = [];

    /** @var array<int, array{0: string|null, 1: int|string}|null> */
    private array $after = [];

    /**
     * @param  list<ListRange>  $ranges
     * @param  Closure(ListRange, bool, array{0: string|null, 1: int|string}|null, int): list<ScanItem>  $fetch  reads a chunk of a range: the range, ascending, after, limit
     * @param  array{0: string|null, 1: int|string}|null  $start  the key to start after, or null for the start of the ranges
     */
    public function __construct(
        private readonly array $ranges,
        private readonly Closure $fetch,
        private readonly bool $ascending,
        ?array $start,
        private readonly int $chunk,
        private int $budget,
    ) {
        foreach (array_keys($ranges) as $index) {
            $this->buffers[$index] = [];
            $this->exhausted[$index] = false;
            $this->started[$index] = false;
            $this->after[$index] = $start;
        }
    }

    /**
     * The next item in the merged order, or null at the end or when the
     * budget is spent (`limited`).
     */
    public function next(): ?ScanItem
    {
        foreach ($this->ranges as $index => $range) {
            if ($this->buffers[$index] !== [] || $this->exhausted[$index]) {
                continue;
            }

            // Every range gets its first read whatever the budget (at most one row over it per
            // range): the merge needs the head of every range to return its first row, so a
            // limited scan always returns a row or ends after an examined one.
            if ($this->budget <= 0 && $this->started[$index]) {
                // A range that ends exactly at the budget is not limited: one probe row decides.
                if (($this->fetch)($range, $this->ascending, $this->after[$index], 1) === []) {
                    $this->exhausted[$index] = true;

                    continue;
                }

                $this->limited = true;

                return null;
            }

            $limit = max(1, min($this->chunk, $this->budget));
            $this->started[$index] = true;
            $rows = ($this->fetch)($range, $this->ascending, $this->after[$index], $limit);
            $this->budget -= count($rows);
            $this->examined += count($rows);
            $this->exhausted[$index] = count($rows) < $limit;

            if ($rows !== []) {
                $last = $rows[count($rows) - 1];
                $this->after[$index] = [self::orderOf($last), $last->k];
            }

            $this->buffers[$index] = $rows;
        }

        $best = null;

        foreach (array_keys($this->ranges) as $index) {
            if ($this->buffers[$index] === []) {
                continue;
            }

            if ($best === null || $this->before($this->buffers[$index][0], $this->buffers[$best][0])) {
                $best = $index;
            }
        }

        if ($best === null) {
            return null;
        }

        return array_shift($this->buffers[$best]);
    }

    /**
     * Whether $a comes before $b in the scan's direction.
     */
    private function before(ScanItem $a, ScanItem $b): bool
    {
        $orderA = self::orderOf($a);
        $orderB = self::orderOf($b);
        $compare = ($orderA === null || $orderB === null) ? 0 : ((float) $orderA <=> (float) $orderB);

        if ($compare === 0) {
            $compare = is_int($a->k) && is_int($b->k) ? $a->k <=> $b->k : strcmp((string) $a->k, (string) $b->k);
        }

        return $this->ascending ? $compare < 0 : $compare > 0;
    }

    private static function orderOf(ScanItem $item): ?string
    {
        return $item->o;
    }
}
