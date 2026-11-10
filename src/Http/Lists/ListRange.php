<?php

namespace Justrau\Orrery\Http\Lists;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * One index range of a list: the equality and range
 * predicates that lead the index, the order column and the key column that
 * close it. JobList reads it in index order with a keyset condition.
 *
 * `nullOrder` is the null group of a batch's pending and running members:
 * `finished_at IS NULL`, ordered by (finished_at, id) as the index is, with
 * the keyset condition on the key alone.
 */
final class ListRange
{
    /**
     * @param  Closure(): Builder  $query  a new query with the range's predicates, without an order or a limit
     */
    public function __construct(
        public readonly Closure $query,
        public readonly string $order,
        public readonly string $key,
        public readonly bool $nullOrder = false,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}

    /**
     * The range bounds of a read on the order column: `from` (else the floor
     * `>= 0`, which also excludes NULL) and `to`. A keyset condition in the
     * read direction is itself a bound: it replaces the bound on its side
     * whenever it is at least as strict, so a read never has two lower (or
     * two upper) bounds on one column. Two such bounds make SQLite (with
     * planner statistics) read the index by skip-scan and sort (ListPlanTest).
     *
     * @param  array{0: string|null, 1: int|string}|null  $after
     */
    public function bound(Builder $query, ?array $after, bool $ascending): Builder
    {
        if ($this->nullOrder) {
            return $query;
        }

        $keyset = $after !== null && $after[0] !== null ? (float) $after[0] : null;

        // The lower bound, unless an ascending keyset is at or above it (it also excludes NULL).
        if (! ($ascending && $keyset !== null && ($this->from === null || $keyset >= (float) $this->from))) {
            $query->where($this->order, '>=', $this->from ?? JobList::NOT_NULL_FLOOR);
        }

        // The upper bound, unless a descending keyset is below it.
        if ($this->to !== null && ! (! $ascending && $keyset !== null && $keyset < (float) $this->to)) {
            $query->where($this->order, '<', $this->to);
        }

        return $query;
    }

    public function query(): Builder
    {
        return ($this->query)();
    }

    /**
     * Orders $query in the range's index order: (order, key), the order of
     * the index on every engine. MySQL and MariaDB order a null group by the
     * key alone: they sort on (finished_at, id) although `finished_at IS
     * NULL` holds that index part constant; Postgres needs both columns, or
     * it sorts. The page reads (JobList) and the counts (CappedCount) share
     * it.
     *
     * @param  'asc'|'desc'  $direction
     */
    public function orderBy(Builder $query, string $direction): Builder
    {
        $connection = $query->getConnection();
        $driver = $connection instanceof Connection ? $connection->getDriverName() : null;

        if ($this->nullOrder && in_array($driver, ['mysql', 'mariadb'], true)) {
            return $query->orderBy($this->key, $direction);
        }

        return $query->orderBy($this->order, $direction)->orderBy($this->key, $direction);
    }
}
