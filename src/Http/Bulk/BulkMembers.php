<?php

namespace Justrau\Orrery\Http\Bulk;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Http\Lists\CappedCount;
use Justrau\Orrery\Http\Lists\ListRange;
use Justrau\Orrery\Http\Lists\Scan;
use Justrau\Orrery\Http\Lists\ScanItem;
use Justrau\Orrery\Http\Tags;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Storage\Clock;
use LogicException;
use stdClass;

/**
 * The members of a frozen bulk run and their access paths.
 *
 * A member is a mirror row with `status IN ('failed', 'retrying')`,
 * `occurrence_id <= max_occurrence` and the source predicate. Every source is
 * read newest first, in index order, the `failed` and `retrying` ranges
 * merged by the order key (Http\Lists\Scan):
 *
 * | Source | Segments | Index | Order |
 * | --- | --- | --- | --- |
 * | groups | one per key, in request order | `failure` (status, failure_key, failed_at, id), with a queue `failure_scope` | failed_at, id |
 * | list without connection + queue | one | `st_failed` (status, failed_at, id) | failed_at, id |
 * | list with connection + queue | one | `retention`, 4 ranges per status (silenced × monitored) | finished_at (= failed_at), id |
 * | batch | one | `batch` (batch_id, status, finished_at, id) | finished_at, id |
 *
 * The window [from, to), the key, the batch id and the queue scope are part
 * of the index range; the occurrence bound is a predicate of the range.
 * The class, a connection without a queue and the tag are residual on a
 * list source: the scan reads the tag rows of each chunk (orrery_job_tags,
 * one statement) and checks them in PHP. A retrying row has no failed tags
 * (its claim deleted them), so the tag of a list source is read from
 * orrery_job_tags, where every tag of every job has a row.
 *
 * PHP checks every read row again byte for byte (status, occurrence, key,
 * scope, filters): the SQL comparison of MySQL and MariaDB folds case and
 * accents; on MySQL and MariaDB the scope and the batch id are also compared
 * as binary in SQL.
 */
final class BulkMembers
{
    public const STATUSES = ['failed', 'retrying'];

    /** The columns of a scan and of the re-read of a member. */
    public const COLUMNS = [
        'id', 'uuid', 'status', 'occurrence_id', 'connection', 'queue', 'name', 'batch_id', 'failure_key',
        'failed_at', 'finished_at', 'retried_at',
    ];

    /** Rows per chunk of a scan: within the 999 bindings of old SQLite builds (the tag lookup). */
    public const CHUNK = 500;

    /**
     * @param  Connection|Closure(): Connection  $session  the mirror session, or a resolver that
     *                                                     returns the current one at each read (a
     *                                                     bulk page: a member's failed step can
     *                                                     release the session object that it held)
     */
    public function __construct(
        private readonly Connection|Closure $session,
        private readonly BulkSource $source,
        private readonly int $maxOccurrence,
    ) {
    }

    private function session(): Connection
    {
        return $this->session instanceof Connection ? $this->session : ($this->session)();
    }

    /**
     * The number of segments: the keys of a groups source, else 1.
     */
    public function segments(): int
    {
        return $this->source->kind === BulkSource::GROUPS ? count($this->source->keys) : 1;
    }

    /**
     * Whether the list has a residual filter (class, a connection without a
     * queue, a tag): its counts are scans within the candidate budget.
     */
    public function residual(): bool
    {
        return $this->source->kind === BulkSource::LIST
            && ($this->source->class !== null || $this->source->tag !== null || ($this->source->connection !== null && $this->source->queue === null));
    }

    /**
     * The order column of the source's ranges.
     */
    public function order(): string
    {
        return match (true) {
            $this->source->kind === BulkSource::BATCH => 'finished_at',
            $this->source->kind === BulkSource::LIST && $this->source->queue !== null => 'finished_at',
            default => 'failed_at',
        };
    }

    /**
     * A scan of one segment, newest first, after a keyset position (or from
     * its start).
     *
     * @param  array{0: string, 1: int}|null  $after
     * @param  list<string>  $statuses
     */
    public function scan(int $segment, ?array $after, int $budget, array $statuses = self::STATUSES): Scan
    {
        $fetch = fn (ListRange $range, bool $ascending, ?array $position, int $limit): array => $this->fetch($range, $position, $limit);
        $ranges = $this->ranges($segment, $statuses);
        // Progress: Scan stops (limited) when a range needs a read and the budget is spent, before it yields a row.
        // So every scan gets at least one read per range and one more, and the first fill of every range leaves
        // budget for the merge: each page then examines at least one row, also with a tiny search_candidates.
        $budget = max($budget, count($ranges) + 1);
        $chunk = max(1, min(self::CHUNK, intdiv($budget, count($ranges) + 1)));

        return new Scan($ranges, $fetch, false, $after, $chunk, $budget);
    }

    /**
     * The members after a position, with a cap: per segment for a groups
     * source (each key capped, summed), else for the whole list. A residual
     * list counts during a scan within $budget rows (`limited` when the
     * budget ends first).
     *
     * @param  list<string>  $statuses
     * @return array{count: int, at_least: bool, limited: bool}
     */
    public function count(int $cap, ?BulkCursor $after, int $budget, array $statuses = self::STATUSES): array
    {
        $count = 0;
        $atLeast = false;
        $limited = false;

        for ($segment = $after->segment ?? 0; $segment < $this->segments(); $segment++) {
            $position = $after !== null && $after->segment === $segment ? [$after->order, $after->id] : null;

            if ($this->residual()) {
                $scan = $this->scan($segment, $position, $budget, $statuses);
                $matched = 0;

                while (($item = $scan->next()) !== null) {
                    if ($this->accepts($item)) {
                        $matched++;

                        if ($matched > $cap) {
                            break;
                        }
                    }
                }

                $part = CappedCount::capped($matched, $cap, $scan->limited);
                $limited = $limited || $scan->limited;
            } else {
                $ranges = array_map(fn (ListRange $range): ListRange => $this->after($range, $position), $this->ranges($segment, $statuses));
                $part = CappedCount::of($ranges, $cap);
            }

            $count += $part['count'];
            $atLeast = $atLeast || $part['at_least'];
        }

        return ['count' => $count, 'at_least' => $atLeast, 'limited' => $limited];
    }

    /**
     * Whether a scanned item is a member: its row (status, occurrence,
     * predicate) and, for a tag, its tag row.
     */
    public function accepts(ScanItem $item): bool
    {
        if ($item->job === null || $this->notMember($item->job) !== null) {
            return false;
        }

        return $this->source->tag === null || $item->qtag;
    }

    /**
     * Why a row is not a member (a code of the bulk items), or null for a
     * member. The tag is not checked here: a job's tags never change.
     */
    public function notMember(stdClass $row): ?string
    {
        if (! in_array($row->status, self::STATUSES, true)) {
            return 'not_failed';
        }

        if (! is_numeric($row->occurrence_id) || (int) $row->occurrence_id > $this->maxOccurrence) {
            return 'occurrence_changed';
        }

        $failedAt = is_numeric($row->failed_at) ? (float) $row->failed_at : null;
        $source = $this->source;

        switch ($source->kind) {
            case BulkSource::GROUPS:
                if (! in_array($row->failure_key, $source->keys, true)) {
                    return 'other_group';
                }

                return $failedAt !== null && $source->scope !== null && $source->scope->contains((string) $row->connection, (string) $row->queue, $failedAt) ? null : 'out_of_scope';
            case BulkSource::BATCH:
                return $row->batch_id === $source->batchId ? null : 'out_of_scope';
            default:
                if (($source->connection !== null && $row->connection !== $source->connection)
                    || ($source->queue !== null && $row->queue !== $source->queue)
                    || ($source->class !== null && $row->name !== $source->class)
                    || ($source->from !== null && ($failedAt === null || $failedAt < $source->from))
                    || ($source->to !== null && ($failedAt === null || $failedAt >= $source->to))) {
                    return 'out_of_scope';
                }

                return null;
        }
    }

    /**
     * The index ranges of a segment, one per status (a list with a queue:
     * four per status).
     *
     * @param  list<string>  $statuses
     * @return list<ListRange>
     */
    public function ranges(int $segment, array $statuses = self::STATUSES): array
    {
        $source = $this->source;
        $order = $this->order();
        $ranges = [];

        foreach ($statuses as $status) {
            if ($source->kind === BulkSource::GROUPS) {
                $key = $source->keys[$segment] ?? throw new LogicException('No such segment of the bulk run.');
                $scope = $source->scope;
                $scoped = $scope?->connection !== null;
                $ranges[] = new ListRange(fn (): Builder => $this->window(
                    $this->scoped($this->table($scoped ? 'failure_scope' : 'failure')->where('status', $status), $scoped ? $scope->connection : null, $scoped ? $scope->queue : null)
                        ->where('failure_key', $key),
                    $scope?->from,
                    $scope?->to,
                ), $order, 'id');

                continue;
            }

            if ($source->kind === BulkSource::BATCH) {
                $batch = (string) $source->batchId;
                $ranges[] = new ListRange(fn (): Builder => $this->window($this->binary($this->table('batch')->where('batch_id', $batch), 'batch_id', $batch)->where('status', $status), null, null), $order, 'id');

                continue;
            }

            if ($source->queue === null) {
                $ranges[] = new ListRange(fn (): Builder => $this->window($this->table('st_failed')->where('status', $status), $source->from, $source->to), $order, 'id');

                continue;
            }

            // The retention index (connection, queue, status, silenced, monitored, finished_at, id).
            foreach ([false, true] as $silenced) {
                foreach ([false, true] as $monitored) {
                    $ranges[] = new ListRange(fn (): Builder => $this->window(
                        $this->scoped($this->table('retention'), $source->connection, $source->queue)
                            ->where('status', $status)->where('silenced', $silenced)->where('monitored', $monitored),
                        $source->from,
                        $source->to,
                    ), $order, 'id');
                }
            }
        }

        return $ranges;
    }

    /**
     * A range with the keyset condition after a position (newest first).
     *
     * @param  array{0: string, 1: int}|null  $position
     */
    private function after(ListRange $range, ?array $position): ListRange
    {
        if ($position === null) {
            return $range;
        }

        return new ListRange(fn (): Builder => $this->keyset($range->query(), $range, $position), $range->order, $range->key);
    }

    /**
     * Reads one chunk of a range, newest first, after a position.
     *
     * @param  array<array-key, mixed>|null  $position  [order value, id], as Scan passes it
     * @return list<ScanItem>
     */
    private function fetch(ListRange $range, ?array $position, int $limit): array
    {
        $query = $range->query();

        if ($position !== null) {
            $this->keyset($query, $range, [(string) $position[0], (int) $position[1]]);
        }

        $rows = $query->orderBy($range->order, 'desc')->orderBy($range->key, 'desc')->limit($limit)->get(self::COLUMNS)->all();
        $items = [];

        foreach ($rows as $row) {
            $items[] = new ScanItem(Clock::toDecimal((float) $row->{$range->order}), (int) $row->id, $row);
        }

        if ($this->source->tag !== null && $items !== []) {
            $uuids = array_map(static fn (ScanItem $item): string => (string) $item->job?->uuid, $items);
            $tagged = array_flip(array_map('strval', Tags::exactly($session = $this->session(), $session->table('orrery_job_tags'), $this->source->tag)->whereIn('job_uuid', $uuids)->pluck('job_uuid')->all()));

            foreach ($items as $item) {
                // The tag is stored as normalised and read byte for byte (Tags::exactly()); PHP compares the uuid's tag row by its key only.
                $item->qtag = isset($tagged[(string) $item->job?->uuid]);
            }
        }

        return $items;
    }

    /**
     * `(order, id) < (value, id)`: a row value on SQLite and Postgres, nested
     * terms on MySQL and MariaDB (which their range optimizer serves).
     *
     * @param  array{0: string, 1: int}  $position
     */
    private function keyset(Builder $query, ListRange $range, array $position): Builder
    {
        if ($this->mysql()) {
            return $query->where($range->order, '<=', $position[0])
                ->where(static function (Builder $query) use ($range, $position): void {
                    $query->where($range->order, '<', $position[0])->orWhere($range->key, '<', $position[1]);
                });
        }

        $sql = match ($range->order) {
            'failed_at' => '("failed_at", "id") < (?, ?)',
            'finished_at' => '("finished_at", "id") < (?, ?)',
            default => throw new LogicException('Not an order column of a bulk run.'),
        };

        return $query->whereRaw($sql, [$position[0], $position[1]]);
    }

    /**
     * The order column's window and the occurrence bound of a range.
     */
    private function window(Builder $query, ?float $from, ?float $to): Builder
    {
        $order = $this->order();

        if ($from !== null) {
            $query->where($order, '>=', Clock::toDecimal($from));
        }

        if ($to !== null) {
            $query->where($order, '<', Clock::toDecimal($to));
        }

        return $query->whereNotNull($order)->where('occurrence_id', '<=', $this->maxOccurrence);
    }

    /**
     * The connection and queue equality, as binary on MySQL and MariaDB.
     */
    private function scoped(Builder $query, ?string $connection, ?string $queue): Builder
    {
        if ($connection === null || $queue === null) {
            return $query;
        }

        $query->where('connection', $connection)->where('queue', $queue);
        $this->binary($query, 'connection', $connection);

        return $this->binary($query, 'queue', $queue);
    }

    private function binary(Builder $query, string $column, string $value): Builder
    {
        if (! $this->mysql()) {
            return $query;
        }

        $sql = match ($column) {
            'connection' => 'CAST(`connection` AS BINARY) = CAST(? AS BINARY)',
            'queue' => 'CAST(`queue` AS BINARY) = CAST(? AS BINARY)',
            'batch_id' => 'CAST(`batch_id` AS BINARY) = CAST(? AS BINARY)',
            default => throw new LogicException('Not a byte-exact column of a bulk run.'),
        };

        return $query->whereRaw($sql, [$value]);
    }

    /**
     * The mirror table with the access path's index forced on MySQL,
     * MariaDB and SQLite (as the list engine does); Postgres chooses it.
     */
    private function table(string $index): Builder
    {
        $session = $this->session();
        $query = $session->table(JobStore::TABLE);

        return IndexName::force($query, $session, JobStore::TABLE, $index);
    }

    private function mysql(): bool
    {
        return in_array($this->session()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
