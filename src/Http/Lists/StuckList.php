<?php

namespace Justrau\Orrery\Http\Lists;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Lifecycle\EnforcedRule;
use Justrau\Orrery\Monitoring\StuckSignals;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Workers\WorkerLiveness;
use stdClass;

/**
 * The list of one stuck signal, `GET /api/jobs/stuck`, with
 * the list contract of 4.3: keyset cursors, capped totals, the candidate
 * budget and the summary rows (JobList::summaryRows()).
 *
 * **The reads** (ranges()), each an index range read in index order:
 *
 * | Signal | Index | Range | Order | Residual |
 * | --- | --- | --- | --- | --- |
 * | reservation_expired | st_reserved; with connection + queue cq_reserved | `status = reserved`, `reserved_at <= now − the shortest lease interval` (that connection's own with a queue) | reserved_at, oldest first | the lease of the row's own connection |
 * | outcome_not_observed | st_gap (deferred) | `status = pending` and `status = reserved`, `observed_gap > 0`: two ranges merged | observed_gap, largest first | none |
 * | repeated_releases | st_releases (deferred) | the two statuses, `releases >= release_threshold`: two ranges merged | releases, largest first | none |
 * | attempts_not_capped | st_attempts (deferred) | the two statuses, `attempts >= attempt_threshold`: two ranges merged | attempts, largest first | the enforced rule |
 *
 * - "Repeated releases" reads its own index: a cycle's releases can exceed
 *   its attempts (two lineages after a reclaim;
 *   ReleasesBeyondAttemptsTest), so an attempts range would miss rows.
 * - The lease interval is WorkerLiveness::retryAfter(): the retry_after
 *   of the row's own queue connection. The expiry range uses the shortest
 *   of all configured `database` queue connections;
 *   without a configured `database` queue connection the list is empty, and
 *   a row of a connection that is not configured is never listed (its lease
 *   is unknown).
 * - The filters `class`, and `connection` and `queue` outside the
 *   cq_reserved path, are residual and byte-exact (PHP `===`).
 * - A list with a residual predicate scans within
 *   `orrery.dashboard.search_candidates`, as JobList does: one scan fills the
 *   page and counts, and a budget end gives `search_limited`, a lower-bound
 *   total, `scanned_to`, and a `next_cursor` after the last examined row.
 *   The gap and release lists without filters, and the expiry list of one
 *   queue, are index-only and count with CappedCount.
 *
 * **index_missing.** A signal whose deferred index is missing
 * (DeferredIndexes, cached 60 s per process) throws IndexMissing before any
 * read, and records `index_missing` once per process.
 *
 * Each row adds `signals` (every signal that holds, StuckSignals::of()) and
 * `enforced_rule` (EnforcedRule::forRow()), both at the time of the read.
 * There is no `has_newer`: a stuck list is not in arrival order.
 */
final class StuckList
{
    /** The index of each signal without a connection + queue filter (PackageSchema suffixes). */
    public const INDEX = [
        StuckSignals::RESERVATION_EXPIRED => 'st_reserved',
        StuckSignals::OUTCOME_NOT_OBSERVED => 'st_gap',
        StuckSignals::REPEATED_RELEASES => 'st_releases',
        StuckSignals::ATTEMPTS_NOT_CAPPED => 'st_attempts',
    ];

    /** The order column of each signal. */
    public const ORDER = [
        StuckSignals::RESERVATION_EXPIRED => 'reserved_at',
        StuckSignals::OUTCOME_NOT_OBSERVED => 'observed_gap',
        StuckSignals::REPEATED_RELEASES => 'releases',
        StuckSignals::ATTEMPTS_NOT_CAPPED => 'attempts',
    ];

    /** The columns of a scan: the filters, the keys and the signals. */
    public const SCAN_COLUMNS = ['id', 'uuid', 'queue', 'name', ...StuckSignals::COLUMNS];

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly JobList $jobs,
        private readonly JobProjection $projection,
        private readonly StuckSignals $signals,
        private readonly WorkerLiveness $liveness,
        private readonly DeferredIndexes $indexes,
    ) {
    }

    /**
     * The response of `GET /api/jobs/stuck`.
     *
     * @return array<string, mixed>
     *
     * @throws IndexMissing
     * @throws RedactionConfigInvalid
     */
    public function page(StuckRequest $request): array
    {
        $this->projection->assertValid();
        $mirror = $this->sessions->mirror();

        return $this->sessions->bounded($mirror, function (Connection $session) use ($request): array {
            $this->assertIndex($session, $request->signal);

            return $this->read($session, $request, Clock::now());
        });
    }

    /**
     * Throws IndexMissing when the deferred index of $signal is missing on
     * the session, and records `index_missing` once per process.
     *
     * @throws IndexMissing
     */
    public function assertIndex(Connection $session, string $signal): void
    {
        $index = self::INDEX[$signal];

        if (array_key_exists($index, PackageSchema::DEFERRED_INDEXES) && ! $this->indexes->has($session, $index)) {
            $this->indexes->recordOnce($this->indexes->missing($session));

            throw new IndexMissing($index);
        }
    }

    /**
     * Whether a request needs a residual predicate: every list except an
     * unfiltered gap or release list, and the expiry list of one queue.
     */
    public static function residual(StuckRequest $request): bool
    {
        if ($request->signal === StuckSignals::RESERVATION_EXPIRED) {
            // With connection and queue the cq_reserved range is exact for that connection.
            return $request->class !== null || $request->queue === null;
        }

        return $request->signal === StuckSignals::ATTEMPTS_NOT_CAPPED || $request->connection !== null || $request->class !== null;
    }

    /**
     * Whether the signal's list is in ascending order (oldest reservation
     * first); the others are largest first.
     */
    public static function ascending(string $signal): bool
    {
        return $signal === StuckSignals::RESERVATION_EXPIRED;
    }

    /**
     * The index ranges of a signal at $now (the table of the class doc).
     *
     * @return list<ListRange>
     */
    public function ranges(Connection $session, string $signal, ?string $connection, ?string $queue, float $now): array
    {
        $order = self::ORDER[$signal];

        if ($signal === StuckSignals::RESERVATION_EXPIRED) {
            if ($connection !== null && $queue !== null) {
                $retryAfter = $this->liveness->retryAfter($connection);

                if ($retryAfter === null) {
                    // Not a configured database queue connection: its lease is unknown.
                    return [];
                }

                $cutoff = Clock::toDecimal($now - $retryAfter);
                $binary = in_array($session->getDriverName(), ['mysql', 'mariadb'], true);

                return [new ListRange(static function () use ($session, $connection, $queue, $cutoff, $binary): Builder {
                    $query = self::hinted($session, 'cq_reserved')->where('connection', $connection)->where('queue', $queue)->where('status', 'reserved');

                    if ($binary) {
                        // MySQL and MariaDB collations fold case and accents: the bytes decide.
                        $query->whereRaw('CAST(`connection` AS BINARY) = CAST(? AS BINARY)', [$connection])
                            ->whereRaw('CAST(`queue` AS BINARY) = CAST(? AS BINARY)', [$queue]);
                    }

                    return $query->where('reserved_at', '<=', $cutoff);
                }, $order, 'id')];
            }

            $shortest = $this->liveness->shortestRetryAfter();

            if ($shortest === null) {
                return [];
            }

            $cutoff = Clock::toDecimal($now - $shortest);

            return [new ListRange(static fn (): Builder => self::hinted($session, 'st_reserved')->where('status', 'reserved')->where('reserved_at', '<=', $cutoff), $order, 'id')];
        }

        $index = self::INDEX[$signal];
        $floor = match ($signal) {
            StuckSignals::REPEATED_RELEASES => $this->signals->releaseThreshold(),
            StuckSignals::ATTEMPTS_NOT_CAPPED => $this->signals->attemptThreshold(),
            default => 1,
        };

        if ($floor === null) {
            // The configuration turns the signal off: no row has it.
            return [];
        }

        $ranges = [];

        foreach (StuckSignals::ACTIVE as $status) {
            // The floor is the range's `from` (ListRange::bound()), an integer, so Postgres reads it as one.
            $ranges[] = new ListRange(static fn (): Builder => self::hinted($session, $index)->where('status', $status), $order, 'id', false, (string) $floor);
        }

        return $ranges;
    }

    /**
     * Reads one chunk of a range in index order after $after, as scan items
     * with SCAN_COLUMNS and the $extra columns (the incident creation reads
     * the row's cycle in the same statement). An integer order column
     * (observed_gap, attempts) binds the cursor's order value as an integer.
     *
     * @param  array{0: string|null, 1: int|string}|null  $after
     * @param  list<string>  $extra
     * @return list<ScanItem>
     */
    public function fetch(Connection $session, ListRange $range, bool $ascending, ?array $after, int $limit, array $extra = []): array
    {
        $query = $range->query();
        $direction = $ascending ? 'asc' : 'desc';
        $operator = $ascending ? '>' : '<';
        // The lower bound (the range's floor, which also excludes NULL), as CappedCount reads it.
        $range->bound($query, $after, $ascending);

        if ($after !== null) {
            $value = $range->order === 'reserved_at' ? $after[0] : (int) round((float) $after[0]);

            if (in_array($session->getDriverName(), ['mysql', 'mariadb'], true)) {
                // (o, k) < (v, kv) as nested terms, which MySQL's range optimizer serves.
                $query->where($range->order, $operator.'=', $value)
                    ->where(static function (Builder $query) use ($range, $operator, $value, $after): void {
                        $query->where($range->order, $operator, $value)->orWhere($range->key, $operator, $after[1]);
                    });
            } else {
                $query->whereRaw(self::rowValue($range->order, $ascending), [$value, $after[1]]);
            }
        }

        $range->orderBy($query, $direction)->limit($limit);
        $items = [];

        foreach ($query->get(array_values(array_unique([...self::SCAN_COLUMNS, ...$extra])))->all() as $row) {
            $items[] = new ScanItem(Clock::toDecimal((float) $row->{$range->order}), (int) $row->id, $row);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(Connection $session, StuckRequest $request, float $now): array
    {
        $signal = $request->signal;
        $ranges = $this->ranges($session, $signal, $request->connection, $request->queue, $now);
        $residual = self::residual($request);
        $hash = $request->filterHash();
        $fetch = function (ListRange $range, bool $ascending, ?array $after, int $limit) use ($session): array {
            /** @var array{0: string|null, 1: int|string}|null $after */
            return $this->fetch($session, $range, $ascending, $after, $limit);
        };
        // One walk of the queue configuration for the whole read, not one per row.
        $leases = $this->liveness->leases();
        $matches = fn (ScanItem $item): bool => $item->job !== null && $this->matches($request, $item->job, $now, $leases);
        $listAscending = self::ascending($signal);
        $cursor = $request->cursor;
        $backwards = $cursor !== null && $cursor->direction === Cursor::BEFORE;
        $start = $cursor === null ? null : [$cursor->order, $cursor->key];
        $ascending = $backwards ? ! $listAscending : $listAscending;
        $countCap = $this->jobs->countCap();

        $budget = $this->jobs->searchCandidates();
        $scan = new Scan($ranges, $fetch, $ascending, $start, $residual ? JobList::residualChunk($budget, count($ranges)) : $request->perPage + 1, $budget);
        $page = [];
        $extra = false;
        $count = 0;
        $firstExamined = null;
        $last = null;
        $capped = false;

        while (($item = $scan->next()) !== null) {
            $firstExamined ??= $item;
            $last = $item;

            if (! $matches($item)) {
                continue;
            }

            if (count($page) < $request->perPage) {
                $page[] = $item;
            } else {
                $extra = true;
            }

            if (! $residual && $extra) {
                break;
            }

            $count++;

            if ($residual && $count > $countCap) {
                $capped = true;

                break;
            }
        }

        if ($backwards) {
            if (! $extra && ! $scan->limited) {
                // The start of the list: the first page.
                return $this->read($session, $request->withoutCursor(), $now);
            }

            $page = array_reverse($page);
        }

        $limited = $scan->limited;
        $scannedTo = $limited && $last !== null ? Cursor::at($last->o, $last->k, $backwards ? Cursor::BEFORE : Cursor::AFTER, $hash)->encode() : null;

        if ($residual) {
            $total = $capped
                ? ['count' => $countCap, 'at_least' => true]
                : CappedCount::capped($count, $countCap, $limited || $cursor !== null);
        } else {
            $total = CappedCount::of($ranges, $countCap);
        }

        $first = $page[0] ?? null;
        $lastRow = $page === [] ? null : $page[count($page) - 1];

        if ($backwards) {
            // The scan read backwards from the cursor: $firstExamined is the row next to it, $last the furthest one (as JobList).
            $prev = match (true) {
                $extra && $first !== null => Cursor::at($first->o, $first->k, Cursor::BEFORE, $hash)->encode(),
                $limited && $last !== null => Cursor::at($last->o, $last->k, Cursor::BEFORE, $hash)->encode(),
                default => null,
            };
            $next = match (true) {
                $lastRow !== null => Cursor::at($lastRow->o, $lastRow->k, Cursor::AFTER, $hash)->encode(),
                // After the row next to the cursor: the cursor's own row is on the next page.
                $firstExamined !== null => Cursor::at($firstExamined->o, $firstExamined->k, Cursor::AFTER, $hash)->encode(),
                default => Cursor::at($cursor->order, $cursor->key, Cursor::AFTER, $hash)->encode(),
            };
        } else {
            $prev = null;

            if ($cursor !== null && $first !== null) {
                $prev = Cursor::at($first->o, $first->k, Cursor::BEFORE, $hash)->encode();
            } elseif ($cursor !== null && $firstExamined !== null) {
                // An empty page: before its first examined row, so the cursor's own row is on the page before.
                $prev = Cursor::at($firstExamined->o, $firstExamined->k, Cursor::BEFORE, $hash)->encode();
            }

            $next = null;

            if (count($page) === $request->perPage && $lastRow !== null && ($extra || $limited || $capped)) {
                $next = Cursor::at($lastRow->o, $lastRow->k, Cursor::AFTER, $hash)->encode();
            } elseif ($limited && $last !== null) {
                // The budget ended before the page filled: resume after the last examined row.
                $next = Cursor::at($last->o, $last->k, Cursor::AFTER, $hash)->encode();
            }
        }

        $ids = [];

        foreach ($page as $item) {
            if ($item->job !== null) {
                $ids[] = (int) $item->job->id;
            }
        }

        $jobs = $this->jobs->summaryRows(
            $session,
            $ids,
            fn (stdClass $row): bool => $this->signals->facts($signal, $row, $now, $leases) !== null,
            StuckSignals::COLUMNS,
            fn (stdClass $row): array => ['signals' => $this->signals->of($row, $now, $leases), 'enforced_rule' => EnforcedRule::forRow($row, $now)],
        );

        return [
            'signal' => $signal,
            'jobs' => $jobs,
            'total' => $total,
            'order' => $listAscending ? 'asc' : 'desc',
            'next_cursor' => $next,
            'prev_cursor' => $prev,
            'head' => null,
            'has_newer' => null,
            'search_limited' => $limited,
            'scanned_to' => $scannedTo,
            'last_verified' => $this->jobs->lastVerified(),
        ];
    }

    /**
     * The PHP predicate of a scanned row: the byte-exact filters, then the
     * signal itself (the residual of the read).
     *
     * @param  array<string, int>  $leases  WorkerLiveness::leases() of the read
     */
    private function matches(StuckRequest $request, stdClass $job, float $now, array $leases): bool
    {
        if (($request->connection !== null && $job->connection !== $request->connection)
            || ($request->queue !== null && $job->queue !== $request->queue)
            || ($request->class !== null && $job->name !== $request->class)) {
            return false;
        }

        return $this->signals->facts($request->signal, $job, $now, $leases) !== null;
    }

    /**
     * The keyset condition `("order", "id") > (?, ?)` (or `<`) of SQLite and
     * Postgres, which both serve a row-value comparison from the index.
     *
     * @return literal-string
     */
    private static function rowValue(string $order, bool $ascending): string
    {
        $column = match ($order) {
            'reserved_at' => '"reserved_at"',
            'observed_gap' => '"observed_gap"',
            'releases' => '"releases"',
            'attempts' => '"attempts"',
            default => throw new \LogicException('Not an order column of the stuck lists.'),
        };

        return '('.$column.', "id") '.($ascending ? '>' : '<').' (?, ?)';
    }

    /**
     * An orrery_jobs query with the access path's declared index forced on
     * MySQL, MariaDB (FORCE INDEX) and SQLite (INDEXED BY), as JobList does.
     */
    private static function hinted(Connection $session, string $suffix): Builder
    {
        return IndexName::force($session->table('orrery_jobs'), $session, 'orrery_jobs', $suffix);
    }
}
