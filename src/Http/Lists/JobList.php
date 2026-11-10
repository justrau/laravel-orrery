<?php

namespace Justrau\Orrery\Http\Lists;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Http\Tags;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Workers\WorkerLiveness;
use stdClass;
use Throwable;

/**
 * The list engine of `GET /api/jobs`.
 *
 * **Sources.** Each list is read from one access path, in index order (the
 * order key ends in `id`, or in `job_uuid` for a tag source):
 *
 * | Tab | Order | No connection filter | connection + queue |
 * | --- | --- | --- | --- |
 * | pending | pushed_at | st_pushed | cq_pushed |
 * | running (status reserved) | reserved_at | st_reserved | cq_reserved |
 * | completed, silenced | finished_at | st_sil_finished | retention, 2 ranges (monitored) |
 * | failed, retrying | failed_at (= finished_at) | st_failed | retention on finished_at, 4 ranges |
 * | unknown | finished_at | st_finished | retention, 4 ranges |
 *
 * A tag reads orrery_job_tags `tag_pushed` (status all, and the status tabs
 * as a residual filter) or orrery_failed_tags `tag_finished` (failed), then
 * the rows by uuid. A batch reads `batch` (batch_id, status, finished_at,
 * id); its pending and running members have a null finished_at and are read
 * as the null group, keyed on id. A full uuid in `q` reads the unique index.
 * Precedence: uuid, tag, batch, then the tab's index.
 *
 * **Byte-exact filters.** SQL compares connection, queue, batch and tag
 * with the collated, sargable equality, and on MySQL and MariaDB also
 * `CAST(… AS BINARY)` (the tag through Http\Tags::exactly(), the predicate
 * of the tag counts, so a tag's count equals its list total); PHP compares
 * every read value with `===` before a row enters the page or a count.
 *
 * **Index-only lists** (no residual filter) read at most per_page + 1 rows
 * per range and count with CappedCount on the same ranges
 * (`dashboard.list_count_cap`, 10,000).
 *
 * **Residual filters** (class, connection without queue, a search text, a
 * tag on a status tab, the filters after a tag or batch source, the
 * silenced flag after a batch source, from/to on a batch's null group) are
 * applied during one ordered scan that fills the page and goes on counting
 * matches, within `dashboard.search_candidates` (50,000) rows. When the
 * budget ends first: `search_limited`, the total is a lower bound,
 * `scanned_to` is the key of the last examined row, and a page that is not
 * full gets a `next_cursor` after that row. A residual total counts from the
 * request's start position: the whole list on the first page, a lower bound
 * (`at_least`) on a page with a cursor. Rows are never sorted in memory:
 * merged ranges are merged by the order key.
 *
 * **Cursors** (Cursor): `next_cursor` after the page's last row (null at
 * the end of the list), `prev_cursor` before its first row (null on the
 * first page). A `before` cursor that reaches the start answers the first
 * page. The first page carries `head`; a request with `newer_than` answers
 * `has_newer` (order desc only) from one LIMIT 1 seek per range, or a
 * residual scan within the budget (null when the budget ends).
 *
 * The list reads select only SCAN_COLUMNS, then the page's rows with
 * JobProjection::SUMMARY_COLUMNS: never payload, exception or context.
 */
final class JobList
{
    public const COUNT_CAP = 10_000;

    public const SEARCH_CANDIDATES = 50_000;

    /** The lower bound of every order value (epoch seconds), which also excludes NULL. */
    public const NOT_NULL_FLOOR = '0.000000';

    /** The chunk of a residual scan: within the 999 bindings of old SQLite builds. */
    public const CHUNK = 500;

    /** The columns of the scan: the residual filters and the order keys. */
    public const SCAN_COLUMNS = [
        'id', 'uuid', 'status', 'silenced', 'connection', 'queue', 'name', 'batch_id',
        'pushed_at', 'reserved_at', 'finished_at', 'failed_at',
    ];

    /** The mirror status of each tab (`all` has none). */
    public const TAB_STATUS = [
        'pending' => 'pending',
        'running' => 'reserved',
        'completed' => 'completed',
        'silenced' => 'completed',
        'failed' => 'failed',
        'retrying' => 'retrying',
        'unknown' => 'unknown',
    ];

    /** The index of each tab without a connection filter. */
    public const TAB_INDEX = [
        'pending' => 'st_pushed',
        'running' => 'st_reserved',
        'completed' => 'st_sil_finished',
        'silenced' => 'st_sil_finished',
        'failed' => 'st_failed',
        'retrying' => 'st_failed',
        'unknown' => 'st_finished',
    ];

    /** The order column of each tab's own index. */
    public const TAB_ORDER = [
        'pending' => 'pushed_at',
        'running' => 'reserved_at',
        'completed' => 'finished_at',
        'silenced' => 'finished_at',
        'failed' => 'failed_at',
        'retrying' => 'failed_at',
        'unknown' => 'finished_at',
        'all' => 'pushed_at',
    ];

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly JobProjection $projection,
        private readonly Reconciliation $reconciliation,
        private readonly Repository $config,
        private readonly WorkerLiveness $liveness,
    ) {
    }

    /**
     * The response of `GET /api/jobs`.
     *
     * @return array<string, mixed>
     */
    public function page(ListRequest $request): array
    {
        $this->projection->assertValid();
        $mirror = $this->sessions->mirror();

        return $this->sessions->bounded($mirror, fn (Connection $session): array => $this->read($session, $request));
    }

    /**
     * The total that the first page of $request answers (`total` of page()),
     * without the page: the same access path and residual filters, the same
     * cap (countCap()) and candidate budget. An index-only list counts its
     * ranges with CappedCount; a residual one scans in order and counts the
     * matches, a lower bound when the budget ends first. The batch show
     * (`members`) uses it, so each member count equals its tab's list total.
     *
     * @return array{count: int, at_least: bool}
     */
    public function total(ListRequest $request): array
    {
        return $this->totals([$request])[0];
    }

    /**
     * The totals of several requests, as total() answers each. Residual
     * requests whose ranges are the same statements (the same SQL and
     * bindings: for example the completed and silenced tabs of one batch,
     * which differ only in the silenced flag that the scan checks) share one
     * ordered scan, each counted with its own matcher, cap and the shared
     * budget; that gives the same totals as one scan per request, at about
     * half the cost. Every other request is counted on its own.
     *
     * @param  list<ListRequest>  $requests
     * @return list<array{count: int, at_least: bool}>
     */
    public function totals(array $requests): array
    {
        $mirror = $this->sessions->mirror();

        return $this->sessions->bounded($mirror, function (Connection $session) use ($requests): array {
            $countCap = $this->countCap();
            $totals = [];
            $groups = [];

            foreach ($requests as $index => $request) {
                $request = $request->withoutCursor();
                $plan = $this->plan($session, $request);

                if ($plan['kind'] === 'uuid') {
                    /** @var array{count: int, at_least: bool} $total */
                    $total = $this->read($session, $request)['total'];
                    $totals[$index] = $total;
                } elseif (! $plan['residual']) {
                    $totals[$index] = CappedCount::of($plan['ranges'], $countCap);
                } else {
                    $groups[self::scanKey($plan, $request)][] = [$index, $request, $plan];
                }
            }

            foreach ($groups as $group) {
                [, $first, $plan] = $group[0];
                $budget = $this->searchCandidates();
                $scan = new Scan($plan['ranges'], $this->fetcher($session, $first, $plan), $first->order !== 'desc', null, self::residualChunk($budget, count($plan['ranges'])), $budget);
                $counts = array_fill(0, count($group), 0);
                $capped = array_fill(0, count($group), false);

                while (in_array(false, $capped, true) && ($item = $scan->next()) !== null) {
                    foreach ($group as $member => [, $request, $memberPlan]) {
                        // As read(): every match counts, and the count stops past the cap.
                        if (! $capped[$member] && $this->matches($request, $memberPlan, $item) && ++$counts[$member] > $countCap) {
                            $capped[$member] = true;
                        }
                    }
                }

                foreach ($group as $member => [$index]) {
                    $totals[$index] = self::residualTotal($counts[$member], $capped[$member], $countCap, $scan->limited);
                }
            }

            ksort($totals);

            return array_values($totals);
        });
    }

    /**
     * The scan fetcher of a request: its access path, and its `q` tag (the
     * byte-exact tag check of Tags::exactly()) for the residual matcher.
     * read() and totals() share it, so a page and its total read alike.
     *
     * @param  array{kind: 'jobs'|'tags'|'uuid', ranges: list<ListRange>, residual: bool, tab_order: bool}  $plan
     * @return \Closure(ListRange, bool, ?array<int, mixed>, int): list<ScanItem>
     */
    private function fetcher(Connection $session, ListRequest $request, array $plan): \Closure
    {
        $tagExists = $request->q !== null && Tags::exactly($session, $session->table('orrery_job_tags'), $request->q)->limit(1)->exists();
        $qTag = $tagExists ? $request->q : null;

        return function (ListRange $range, bool $ascending, ?array $after, int $limit) use ($session, $plan, $qTag): array {
            /** @var array{0: string|null, 1: int|string}|null $after */
            return $this->fetch($session, $plan['kind'], $range, $ascending, $after, $limit, $qTag);
        };
    }

    /**
     * The total of a residual scan: the cap when the count passed it, else
     * the count, a lower bound when the scan did not see the whole list (the
     * budget ended, or it started at a cursor).
     *
     * @return array{count: int, at_least: bool}
     */
    private static function residualTotal(int $count, bool $capped, int $countCap, bool $lowerBound): array
    {
        return $capped ? ['count' => $countCap, 'at_least' => true] : CappedCount::capped($count, $countCap, $lowerBound);
    }

    /**
     * The identity of a residual scan: the SQL and bindings of its ranges,
     * its order, its `q` and its source kind. Two requests with one key read
     * the same rows in the same order with the same fetcher.
     *
     * @param  array{kind: 'jobs'|'tags'|'uuid', ranges: list<ListRange>, residual: bool, tab_order: bool}  $plan
     */
    private static function scanKey(array $plan, ListRequest $request): string
    {
        $ranges = array_map(static function (ListRange $range): array {
            $query = $range->query();

            return [$query->toSql(), $query->getBindings(), $range->order, $range->key, $range->nullOrder, $range->from, $range->to];
        }, $plan['ranges']);

        // A failed encoding throws: an empty key would put unrelated requests into one scan.
        return hash('sha256', json_encode([$plan['kind'], $request->order, $request->q, $ranges], JSON_THROW_ON_ERROR));
    }

    /**
     * The capped count of a status tab (`GET /api/counts`), on the tab's own index.
     *
     * @return array{count: int, at_least: bool}
     */
    public function tabCount(string $tab, int $cap): array
    {
        $mirror = $this->sessions->mirror();

        return $this->sessions->bounded($mirror, static function (Connection $session) use ($tab, $cap): array {
            $query = static function () use ($session, $tab): Builder {
                $query = self::hinted($session, $session->table('orrery_jobs'), 'orrery_jobs', self::TAB_INDEX[$tab])->where('status', self::TAB_STATUS[$tab]);

                if ($tab === 'completed' || $tab === 'silenced') {
                    $query->where('silenced', $tab === 'silenced');
                }

                return $query;
            };

            return CappedCount::of([new ListRange($query, self::TAB_ORDER[$tab], 'id')], $cap);
        });
    }

    /**
     * `orrery.dashboard.list_count_cap`, a whole number of at least 1.
     */
    public function countCap(): int
    {
        // At least a full page and its extra row, so a capped residual scan never stops before the page is full.
        return max(ListRequest::MAX_PER_PAGE + 1, self::positive($this->config->get('orrery.dashboard.list_count_cap', self::COUNT_CAP), self::COUNT_CAP));
    }

    /**
     * `orrery.dashboard.search_candidates`, a whole number of at least 1.
     */
    public function searchCandidates(): int
    {
        return self::positive($this->config->get('orrery.dashboard.search_candidates', self::SEARCH_CANDIDATES), self::SEARCH_CANDIDATES);
    }

    /**
     * Whether an error is a statement timeout of the bounded read (MySQL
     * 3024, MariaDB 1969, Postgres 57014): the list answers 503
     * `read_timeout`.
     */
    public static function isTimeout(Throwable $error): bool
    {
        if (! $error instanceof QueryException) {
            return false;
        }

        $info = $error->errorInfo;
        $state = is_array($info) ? (string) ($info[0] ?? '') : '';
        $code = is_array($info) ? (int) ($info[1] ?? 0) : 0;

        return $state === '57014' || in_array($code, [3024, 1969], true);
    }

    /**
     * The start time of the last complete reconciliation sweep of each
     * database queue connection, or null for "never checked" (the pending
     * and running tabs).
     *
     * @return array<string, float|null>|stdClass
     */
    public function lastVerified(): array|stdClass
    {
        $verified = [];

        foreach ($this->reconciliation->connections() as $connection) {
            $verified[$connection] = $this->reconciliation->lastVerified($connection);
        }

        return $verified === [] ? new stdClass : $verified;
    }

    /**
     * The parts of one row's summary that a list reads for its page (the
     * job detail): `tags`, and for a running row `lease_expires_at`
     * and `worker` by the same rule as a list row. $row has
     * JobProjection::SUMMARY_COLUMNS.
     *
     * @return array{tags: list<string>, lease_expires_at?: float|null, worker?: array{id: string|null, heartbeat_at: float|null, liveness: string, silent_since: float|null}}
     */
    public function rowParts(Connection $session, stdClass $row): array
    {
        $tags = array_map('strval', $session->table('orrery_job_tags')->where('job_uuid', (string) $row->uuid)->pluck('tag')->all());
        sort($tags, SORT_STRING);
        $parts = ['tags' => $tags];

        if ($row->status === 'reserved') {
            $lease = $this->liveness->leaseExpiresAt($row);
            $parts['lease_expires_at'] = $lease;
            $parts['worker'] = $this->liveness->of($row, $lease, $this->liveness->heartbeats($session, [$row]), Clock::now());
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(Connection $session, ListRequest $request): array
    {
        $plan = $this->plan($session, $request);
        $hash = $request->filterHash();
        $fetch = $this->fetcher($session, $request, $plan);
        $matches = fn (ScanItem $item): bool => $this->matches($request, $plan, $item);
        $descending = $request->order === 'desc';
        $cursor = $request->cursor;
        $backwards = $cursor !== null && $cursor->direction === Cursor::BEFORE;
        $start = $cursor === null ? null : [$cursor->order, $cursor->key];
        $budget = $this->searchCandidates();
        $chunk = $plan['residual'] ? self::residualChunk($budget, count($plan['ranges'])) : $request->perPage + 1;
        // The read direction: the list's order, or its reverse for a `before` cursor.
        $ascending = $backwards ? $descending : ! $descending;

        if ($plan['kind'] === 'uuid') {
            return $this->uuidPage($session, $request, $plan, $hash);
        }

        $scan = new Scan($plan['ranges'], $fetch, $ascending, $start, $chunk, $budget);
        $page = [];
        $extra = false;
        $count = 0;
        $countCap = $this->countCap();
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

            if (! $plan['residual'] && $extra) {
                break;
            }

            $count++;

            if ($plan['residual'] && $count > $countCap) {
                $capped = true;

                break;
            }
        }

        if ($backwards) {
            if (! $extra && ! $scan->limited) {
                // The start of the list: the first page.
                return $this->read($session, $request->withoutCursor());
            }

            $page = array_reverse($page);
        }

        $limited = $scan->limited;
        $scannedTo = $limited && $last !== null ? Cursor::at($last->o, $last->k, $backwards ? Cursor::BEFORE : Cursor::AFTER, $hash)->encode() : null;

        $total = $plan['residual']
            ? self::residualTotal($count, $capped, $countCap, $limited || $cursor !== null)
            : CappedCount::of($plan['ranges'], $countCap);

        $first = $page[0] ?? null;
        $lastRow = $page === [] ? null : $page[count($page) - 1];

        if ($backwards) {
            // The scan read backwards from the cursor: $firstExamined is the row next to it, $last the furthest one.
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

        return $this->response(
            $session,
            $request,
            $page,
            $total,
            $prev,
            $next,
            $cursor === null && $first !== null ? Cursor::at($first->o, $first->k, Cursor::AFTER, $hash)->encode() : null,
            $this->hasNewer($request, $plan, $fetch, $matches),
            $limited,
            $scannedTo,
        );
    }

    /**
     * The access path of a request.
     *
     * @return array{kind: 'jobs'|'tags'|'uuid', ranges: list<ListRange>, residual: bool, tab_order: bool}
     */
    private function plan(Connection $session, ListRequest $request): array
    {
        $tab = $request->status;
        $status = self::TAB_STATUS[$tab] ?? null;
        $binary = in_array($session->getDriverName(), ['mysql', 'mariadb'], true);
        $from = $request->from === null ? null : Clock::toDecimal($request->from);
        $to = $request->to === null ? null : Clock::toDecimal($request->to);

        if ($request->uuid !== null) {
            return ['kind' => 'uuid', 'ranges' => [], 'residual' => true, 'tab_order' => true];
        }

        if ($request->tag !== null) {
            $failed = $tab === 'failed';
            $table = $failed ? 'orrery_failed_tags' : 'orrery_job_tags';
            $order = $failed ? 'finished_at' : 'pushed_at';
            $tag = $request->tag;

            return [
                'kind' => 'tags',
                'ranges' => [new ListRange(static fn (): Builder => Tags::exactly($session, self::hinted($session, $session->table($table), $table, $failed ? 'tag_finished' : 'tag_pushed'), $tag), $order, 'job_uuid', false, $from, $to)],
                'residual' => ! in_array($tab, ['all', 'failed'], true) || $request->connection !== null || $request->class !== null || $request->batch !== null || $request->q !== null,
                'tab_order' => false,
            ];
        }

        if ($request->batch !== null) {
            $batch = $request->batch;
            $null = $tab === 'pending' || $tab === 'running';
            $query = static function () use ($session, $batch, $status, $binary): Builder {
                $query = self::hinted($session, $session->table('orrery_jobs'), 'orrery_jobs', 'batch')->where('batch_id', $batch)->where('status', $status);

                if ($binary) {
                    // MySQL and MariaDB collations fold case: `b` is not the batch `B`.
                    $query->whereRaw('CAST(`batch_id` AS BINARY) = CAST(? AS BINARY)', [$batch]);
                }

                return $query;
            };

            return [
                'kind' => 'jobs',
                'ranges' => [$null
                    ? new ListRange($query, 'finished_at', 'id', true)
                    : new ListRange($query, 'finished_at', 'id', false, $from, $to)],
                'residual' => $tab === 'completed' || $tab === 'silenced' || $request->connection !== null || $request->class !== null || $request->q !== null
                    || ($null && ($request->from !== null || $request->to !== null)),
                'tab_order' => $null,
            ];
        }

        $order = self::TAB_ORDER[$tab];
        $residual = $request->class !== null || $request->q !== null || ($request->connection !== null && $request->queue === null);

        if ($request->connection !== null && $request->queue !== null) {
            $connection = $request->connection;
            $queue = $request->queue;
            $index = match ($tab) {
                'pending' => 'cq_pushed',
                'running' => 'cq_reserved',
                default => 'retention',
            };
            $scoped = static function () use ($session, $connection, $queue, $status, $binary, $index): Builder {
                $query = self::hinted($session, $session->table('orrery_jobs'), 'orrery_jobs', $index)->where('connection', $connection)->where('queue', $queue)->where('status', $status);

                if ($binary) {
                    // MySQL and MariaDB collations fold case and accents: the bytes decide.
                    $query->whereRaw('CAST(`connection` AS BINARY) = CAST(? AS BINARY)', [$connection])
                        ->whereRaw('CAST(`queue` AS BINARY) = CAST(? AS BINARY)', [$queue]);
                }

                return $query;
            };

            if ($tab === 'pending' || $tab === 'running') {
                return ['kind' => 'jobs', 'ranges' => [new ListRange($scoped, $order, 'id', false, $from, $to)], 'residual' => $residual, 'tab_order' => false];
            }

            // The retention index (connection, queue, status, silenced, monitored, finished_at, id): merged ranges.
            $silenced = match ($tab) {
                'completed' => [false],
                'silenced' => [true],
                default => [false, true],
            };
            $ranges = [];

            foreach ($silenced as $isSilenced) {
                foreach ([false, true] as $monitored) {
                    $ranges[] = new ListRange(static fn (): Builder => $scoped()->where('silenced', $isSilenced)->where('monitored', $monitored), 'finished_at', 'id', false, $from, $to);
                }
            }

            return ['kind' => 'jobs', 'ranges' => $ranges, 'residual' => $residual, 'tab_order' => false];
        }

        $query = static function () use ($session, $status, $tab): Builder {
            $query = self::hinted($session, $session->table('orrery_jobs'), 'orrery_jobs', self::TAB_INDEX[$tab])->where('status', $status);

            if ($tab === 'completed' || $tab === 'silenced') {
                $query->where('silenced', $tab === 'silenced');
            }

            return $query;
        };

        return ['kind' => 'jobs', 'ranges' => [new ListRange($query, $order, 'id', false, $from, $to)], 'residual' => $residual, 'tab_order' => false];
    }

    /**
     * Reads one chunk of a range in index order after $after, as items `{o,
     * k, job, qtag}`.
     *
     * @param  array{0: string|null, 1: int|string}|null  $after
     * @return list<ScanItem>
     */
    private function fetch(Connection $session, string $kind, ListRange $range, bool $ascending, ?array $after, int $limit, ?string $qTag): array
    {
        $query = $range->query();
        $direction = $ascending ? 'asc' : 'desc';
        $operator = $ascending ? '>' : '<';

        $range->bound($query, $after, $ascending);

        if ($range->nullOrder) {
            $query->whereNull($range->order);

            if ($after !== null) {
                $query->where($range->key, $operator, $after[1]);
            }
        } elseif ($after !== null) {
            if (in_array($session->getDriverName(), ['mysql', 'mariadb'], true)) {
                // (o, k) < (v, kv) as nested terms, which MySQL's range optimizer serves.
                $query->where($range->order, $operator.'=', $after[0])
                    ->where(static function (Builder $query) use ($range, $operator, $after): void {
                        $query->where($range->order, $operator, $after[0])->orWhere($range->key, $operator, $after[1]);
                    });
            } else {
                $query->whereRaw(self::rowValue($range->order, $range->key, $ascending), [$after[0], $after[1]]);
            }
        }

        $range->orderBy($query, $direction)->limit($limit);

        if ($kind === 'tags') {
            $tagRows = $query->get([$range->order, 'job_uuid'])->all();
            $uuids = array_values(array_unique(array_map(static fn (object $row): string => (string) $row->job_uuid, $tagRows)));
            $jobs = $uuids === [] ? [] : $session->table('orrery_jobs')->whereIn('uuid', $uuids)->get(self::SCAN_COLUMNS)->keyBy('uuid')->all();
            $items = [];

            foreach ($tagRows as $row) {
                $job = $jobs[(string) $row->job_uuid] ?? null;
                $items[] = new ScanItem(self::decimal($row->{$range->order}), (string) $row->job_uuid, $job instanceof stdClass ? $job : null);
            }
        } else {
            $items = [];

            foreach ($query->get(self::SCAN_COLUMNS)->all() as $row) {
                $items[] = new ScanItem(self::decimal($row->{$range->order}), (int) $row->id, $row);
            }
        }

        if ($qTag !== null && $items !== []) {
            $uuids = array_values(array_filter(array_map(static fn (ScanItem $item): ?string => $item->job === null ? null : (string) $item->job->uuid, $items)));
            $tagged = $uuids === [] ? [] : array_flip(array_map('strval', Tags::exactly($session, $session->table('orrery_job_tags'), $qTag)->whereIn('job_uuid', $uuids)->pluck('job_uuid')->all()));

            foreach ($items as $item) {
                $item->qtag = $item->job !== null && isset($tagged[(string) $item->job->uuid]);
            }
        }

        return $items;
    }

    /**
     * The PHP predicate of every row: the tab, the byte-exact filters, the
     * search text, and from/to where the source cannot read them.
     *
     * @param  array{kind: string, ranges: list<ListRange>, residual: bool, tab_order: bool}  $plan
     */
    private function matches(ListRequest $request, array $plan, ScanItem $item): bool
    {
        $job = $item->job;

        if ($job === null || ! self::inTab($request->status, $job)) {
            return false;
        }

        if (($request->connection !== null && $job->connection !== $request->connection)
            || ($request->queue !== null && $job->queue !== $request->queue)
            || ($request->class !== null && $job->name !== $request->class)
            || ($request->batch !== null && $job->batch_id !== $request->batch)) {
            return false;
        }

        if ($plan['tab_order'] && ($request->from !== null || $request->to !== null)) {
            $value = $job->{self::TAB_ORDER[$request->status]} ?? null;

            if ($value === null
                || ($request->from !== null && (float) $value < $request->from)
                || ($request->to !== null && (float) $value >= $request->to)) {
                return false;
            }
        }

        if ($request->q !== null) {
            return $item->qtag || str_contains(mb_strtolower((string) $job->name), mb_strtolower($request->q));
        }

        return true;
    }

    /**
     * Whether a read row belongs to a tab.
     */
    public static function inTab(string $tab, stdClass $job): bool
    {
        if ($tab === 'all') {
            return true;
        }

        if ($job->status !== self::TAB_STATUS[$tab]) {
            return false;
        }

        return match ($tab) {
            'completed' => ! (bool) $job->silenced,
            'silenced' => (bool) $job->silenced,
            default => true,
        };
    }

    /**
     * `has_newer`: with `newer_than` and order desc, whether a
     * row with a key past `newer_than` matches; null when the residual seek
     * spends its budget, and without `newer_than` or with order asc.
     *
     * @param  array{kind: string, ranges: list<ListRange>, residual: bool, tab_order: bool}  $plan
     * @param  \Closure(ListRange, bool, array{0: string|null, 1: int|string}|null, int): list<ScanItem>  $fetch
     * @param  \Closure(ScanItem): bool  $matches
     */
    private function hasNewer(ListRequest $request, array $plan, \Closure $fetch, \Closure $matches): ?bool
    {
        $head = $request->newerThan;

        if ($head === null || $request->order !== 'desc') {
            return null;
        }

        $after = [$head->order, $head->key];

        if (! $plan['residual']) {
            // One LIMIT 1 seek per range past the head, in the newer direction.
            foreach ($plan['ranges'] as $range) {
                foreach ($fetch($range, true, $after, 1) as $item) {
                    if ($matches($item)) {
                        return true;
                    }
                }
            }

            return false;
        }

        $budget = $this->searchCandidates();
        $scan = new Scan($plan['ranges'], $fetch, true, $after, self::residualChunk($budget, count($plan['ranges'])), $budget);

        while (($item = $scan->next()) !== null) {
            if ($matches($item)) {
                return true;
            }
        }

        return $scan->limited ? null : false;
    }

    /**
     * The page of a full uuid: the unique index, then every filter as a check.
     *
     * @param  array{kind: string, ranges: list<ListRange>, residual: bool, tab_order: bool}  $plan
     * @return array<string, mixed>
     */
    private function uuidPage(Connection $session, ListRequest $request, array $plan, string $hash): array
    {
        $row = $session->table('orrery_jobs')->where('uuid', $request->uuid)->first(self::SCAN_COLUMNS);
        $item = null;

        if ($row !== null) {
            $tagged = $request->tag === null || Tags::exactly($session, $session->table($request->status === 'failed' ? 'orrery_failed_tags' : 'orrery_job_tags'), $request->tag)
                ->where('job_uuid', $row->uuid)->exists();
            $candidate = new ScanItem(self::decimal($row->{self::TAB_ORDER[$request->status]} ?? null), (int) $row->id, $row);

            if ($tagged && $this->matches($request, ['tab_order' => true] + $plan, $candidate)) {
                $item = $candidate;
            }
        }

        return $this->response($session, $request, $item === null ? [] : [$item], ['count' => $item === null ? 0 : 1, 'at_least' => false], null, null, null, null, false, null);
    }

    /**
     * @param  list<ScanItem>  $items
     * @param  array{count: int, at_least: bool}  $total
     * @return array<string, mixed>
     */
    private function response(Connection $session, ListRequest $request, array $items, array $total, ?string $prev, ?string $next, ?string $head, ?bool $hasNewer, bool $limited, ?string $scannedTo): array
    {
        return [
            'jobs' => $this->summaries($session, $request, $items),
            'total' => $total,
            'order' => $request->order,
            'next_cursor' => $next,
            'prev_cursor' => $prev,
            'head' => $head,
            'has_newer' => $hasNewer,
            'search_limited' => $limited,
            'scanned_to' => $scannedTo,
            'last_verified' => in_array($request->status, ['pending', 'running'], true) ? $this->lastVerified() : null,
        ];
    }

    /**
     * The summary rows of the page, in page order (summaryRows()). A row
     * that left the tab between the reads is left out.
     *
     * @param  list<ScanItem>  $items
     * @return list<array<string, mixed>>
     */
    private function summaries(Connection $session, ListRequest $request, array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if ($item->job !== null) {
                $ids[] = (int) $item->job->id;
            }
        }

        return $this->summaryRows($session, $ids, static fn (stdClass $row): bool => self::inTab($request->status, $row));
    }

    /**
     * The summary rows of $ids in that order: one read of SUMMARY_COLUMNS
     * (and $columns) by id, one of the tags, one of the heartbeats (running
     * rows, WorkerLiveness) and one of the ledger (held rows). A row that
     * $keep refuses (it changed between the reads) is left out. $more adds
     * the parts of one row to its summary (the stuck signals).
     *
     * @param  list<int>  $ids
     * @param  \Closure(stdClass): bool  $keep
     * @param  list<string>  $columns  more orrery_jobs columns for $keep and $more
     * @param  (\Closure(stdClass, float): array<string, mixed>)|null  $more
     * @return list<array<string, mixed>>
     */
    public function summaryRows(Connection $session, array $ids, \Closure $keep, array $columns = [], ?\Closure $more = null): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $session->table('orrery_jobs')->whereIn('id', $ids)->get(array_values(array_unique([...JobProjection::SUMMARY_COLUMNS, ...$columns])))->keyBy('id')->all();
        $ordered = [];

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;

            if ($row !== null && $keep($row)) {
                $ordered[] = $row;
            }
        }

        $uuids = array_map(static fn (object $row): string => (string) $row->uuid, $ordered);
        $tags = [];

        if ($uuids !== []) {
            // No ORDER BY: the `job` index has no tag order, so the tags of each row are sorted here.
            foreach ($session->table('orrery_job_tags')->whereIn('job_uuid', $uuids)->get(['job_uuid', 'tag']) as $tag) {
                $tags[(string) $tag->job_uuid][] = (string) $tag->tag;
            }

            foreach ($tags as $uuid => $list) {
                sort($list, SORT_STRING);
                $tags[$uuid] = $list;
            }
        }

        $workers = $this->liveness->heartbeats($session, $ordered);
        $leases = $this->liveness->leases();
        $operations = $this->operations($session, $ordered);
        $now = Clock::now();
        $summaries = [];

        foreach ($ordered as $row) {
            $extra = ['tags' => $tags[(string) $row->uuid] ?? []];

            if ($row->status === 'reserved') {
                $lease = $this->liveness->leaseExpiresAt($row, $leases);
                $extra['lease_expires_at'] = $lease;
                $extra['worker'] = $this->liveness->of($row, $lease, $workers, $now);
            }

            if (is_string($row->operation_id ?? null) && $row->operation_id !== '') {
                $extra['operation'] = $operations[$row->operation_id] ?? ['id' => $row->operation_id, 'action' => null, 'state' => null, 'awaiting_reason' => null, 'actor' => null, 'created_at' => null];
            }

            $summary = $this->projection->summary($row, $extra);
            $summaries[] = $more === null ? $summary : array_merge($summary, $more($row, $now));
        }

        return $summaries;
    }

    /**
     * The ledger rows of the operations that hold the page's rows, by id.
     *
     * @param  list<stdClass>  $rows
     * @return array<string, array{id: string, action: string, state: string, awaiting_reason: string|null, actor: string|null, created_at: float|null}>
     */
    private function operations(Connection $session, array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (is_string($row->operation_id ?? null) && $row->operation_id !== '') {
                $ids[$row->operation_id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $operations = [];

        foreach ($session->table(Operations::TABLE)->whereIn('id', array_keys($ids))->get(['id', 'action', 'state', 'awaiting_reason', 'actor', 'created_at']) as $operation) {
            $operations[(string) $operation->id] = [
                'id' => (string) $operation->id,
                'action' => (string) $operation->action,
                'state' => (string) $operation->state,
                'awaiting_reason' => $operation->awaiting_reason === null ? null : (string) $operation->awaiting_reason,
                'actor' => $operation->actor === null ? null : (string) $operation->actor,
                'created_at' => is_numeric($operation->created_at) ? (float) $operation->created_at : null,
            ];
        }

        return $operations;
    }

    /**
     * The keyset condition `("order", "key") > (?, ?)` (or `<`) of SQLite
     * and Postgres, which both serve a row-value comparison from the index.
     *
     * @return literal-string
     */
    private static function rowValue(string $order, string $key, bool $ascending): string
    {
        $orderColumn = match ($order) {
            'pushed_at' => '"pushed_at"',
            'reserved_at' => '"reserved_at"',
            'finished_at' => '"finished_at"',
            'failed_at' => '"failed_at"',
            default => throw new \LogicException('Not an order column of the list engine.'),
        };
        $keyColumn = match ($key) {
            'id' => '"id"',
            'job_uuid' => '"job_uuid"',
            default => throw new \LogicException('Not a key column of the list engine.'),
        };

        return '('.$orderColumn.', '.$keyColumn.') '.($ascending ? '>' : '<').' (?, ?)';
    }

    /**
     * MySQL and MariaDB: FORCE INDEX, SQLite: INDEXED BY the access path's
     * declared index. Their planners otherwise can prefer an index that
     * only gives the order (MySQL: `pushed` for a queue-scoped pending list,
     * `st_finished` for the completed tab; SQLite: `st_sil_finished` for a
     * queue-scoped retrying list) and filter the leading columns, which
     * examines far more rows than the page. Postgres has no hint and chooses
     * the declared index itself (ListPlanTest).
     */
    private static function hinted(Connection $session, Builder $query, string $table, string $suffix): Builder
    {
        return IndexName::force($query, $session, $table, $suffix);
    }

    /**
     * The chunk of a residual scan: at most CHUNK, and small enough that the
     * first read of every range leaves budget for the merge to go on. With
     * one chunk per range larger than budget / ranges, the first range would
     * take the whole budget and the scan would stop before its first row
     * (no row, no next cursor).
     */
    public static function residualChunk(int $budget, int $ranges): int
    {
        return min(self::CHUNK, max(1, intdiv($budget, $ranges + 1)));
    }

    private static function decimal(mixed $value): ?string
    {
        return is_numeric($value) ? Clock::toDecimal((float) $value) : null;
    }

    private static function positive(mixed $value, int $default): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d{0,17}$/D', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }
}
