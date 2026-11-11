<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Justrau\Orrery\Maintenance\RetentionPolicy;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The read rule of the per-minute counts: every reader of
 * counts (the throughput, the queue history, the job classes, the workload
 * rates of DatabaseWorkloadRepository) decides each
 * minute m of a window through of().
 *
 * One call reads, in this order:
 * 1. the settings rows INVALID (its version) and COUNTED_THROUGH (M), in one
 *    statement;
 * 2. the stored counts of the minutes before M: one statement that selects
 *    the `total` rows and the rows of the requested series together, so a
 *    `total` row never authorises group rows that it did not see (one
 *    statement is one consistent snapshot on every engine);
 * 3. (the hold point AFTER_COVERAGE) the mirror scan of the minutes from M
 *    on (without M: the current minute only), one grouped statement per
 *    requested kind, only where the evidence is still retained: a minute m
 *    is scanned for completions when m >= now - min(trim.completed,
 *    trim.silenced), for failures when m >= now - min(trim.failed,
 *    trim.completed, trim.silenced, trim.unknown) (a failure whose row later
 *    completed is trimmed under the retention of its later state, as the
 *    throughput horizons have always said), and for arrivals (by pushed_at,
 *    every status) when m >= now - the shortest of the same four
 *    keys, because a pushed row may since have reached any of those states
 *    (`monitored` only lengthens a retention, so it never shortens a
 *    horizon). Each key is its shortest value over `orrery.trim` and every
 *    `orrery.trim_overrides` entry (RetentionPolicy::shortest());
 *    a single queue series uses its own queue's effective values
 *    (retentionMinutes());
 * 4. the settings rows INVALID and RETIRED_THROUGH again.
 *
 * A reader requests its kinds (of() $kinds; the outcome readers request
 * completed and failed, /api/workload all three); a kind that it did not
 * request is neither read nor scanned, and is unknown in every minute.
 *
 * Then each minute is decided, with the time at the end of the reads:
 * - the version of INVALID changed between reads 1 and 4: every minute is
 *   unknown (a trim or a clear committed during the reads);
 * - m before the samples' retention (m < minuteOf(end -
 *   `samples.retention_hours`) + 60, the horizon of the aggregation and of
 *   Samples::retire()): unknown;
 * - m inside an invalid interval, or at or before RETIRED_THROUGH: unknown,
 *   whatever else exists (an older `total` row included);
 * - m < M with a `total` row: the stored counts; the arrivals only when that
 *   `total` row has a non-null `pushed` (a minute counted before schema step
 *   7 keeps null: unknown, never 0), and then a `queue` or `job` row with a
 *   null `pushed` (a queue-state row, a group without arrivals) reads 0;
 * - m < M without one: unknown;
 * - m >= M (or the current minute without M): the scanned counts, when m is
 *   at or after the trim horizon of its kind both at the start and at the
 *   end of the reads (the wall clock can step back); else unknown.
 * So a trim that commits during a read can make a minute unknown, never a
 * covered undercount.
 *
 * A row bound ($rowLimit, the job classes): one budget of $rowLimit rows
 * for the whole call. The stored read (2) takes at most the budget, and each
 * scan (3), in the order of $kinds, at most what is left, each newest minute
 * first. When a read has more, the minute of its first row past its share
 * and every earlier minute are unknown for what that read decides (the
 * stored counts, or the scanned kind), and a scan with no budget left makes
 * all its minutes unknown. So a bounded read is a lower bound, never a
 * covered undercount, and SampleWindow::$capped says so. The rows of a
 * series that changes its name per job stay within the bound, and the rows
 * at or before a cut keep no label.
 *
 * Series are byte-exact: the stored rows by their digest, the scan grouped
 * by CAST(... AS BINARY) on MySQL (Postgres and SQLite compare bytes under
 * their default collations), each connection mapped to its primary alias
 * (WaitTime::primaryAliases()). A single-series read filters with the
 * collated, sargable columns and then compares the names with ===.
 *
 * @phpstan-import-type Counts from SampleWindow
 * @phpstan-import-type Label from SampleWindow
 */
final class SampleCoverage
{
    /** After the coverage lookup (reads 1 and 2), before the scan (tests only). */
    public const AFTER_COVERAGE = 'samples.after_coverage';

    private const ZERO = ['completed' => 0, 'failed' => 0, 'runtime_ms_sum' => 0, 'runtime_count' => 0, 'pushed' => 0];

    public function __construct(
        private readonly WaitTime $waits,
        private readonly SampleCounts $counts,
        private readonly RetentionPolicy $policy,
    ) {}

    /**
     * The primary alias of a queue connection (the name under which the
     * samples store the queues of a shared jobs table); a name that is not
     * a database queue connection stays as it is.
     */
    public function primary(string $connection): string
    {
        return $this->waits->primaryAliases()[$connection] ?? $connection;
    }

    /**
     * The minutes [from, to) (minute boundaries) of the scope: the `total`
     * series, one series ($only: `connection` (the primary alias) and
     * `queue`, or `name`), or every series of the scope ($only null).
     *
     * @param  'total'|'queue'|'job'  $scope
     * @param  array{connection?: string, queue?: string, name?: string}|null  $only
     * @param  list<'completed'|'failed'|'pushed'>  $kinds  the kinds to decide (see the class comment)
     * @param  int|null  $rowLimit  the row budget of the call (see the class comment), or null for no bound
     */
    public function of(string $scope, ?array $only, int $from, int $to, array $kinds = SampleWindow::OUTCOMES, ?int $rowLimit = null): SampleWindow
    {
        $rowLimit = $rowLimit === null ? null : max(1, $rowLimit);
        $kinds = array_values(array_intersect(SampleWindow::KINDS, $kinds));
        $session = Container::getInstance()->make(PackageSession::class)->mirror();
        $from = SampleCounts::minuteOf((float) $from);
        $to = max($from, SampleCounts::minuteOf((float) $to - 1) + SampleCounts::MINUTE);
        $start = Clock::now();
        $current = SampleCounts::minuteOf($start);
        $aliases = $scope === 'total' ? [] : $this->waits->primaryAliases();

        if ($only !== null && isset($only['connection'])) {
            // A request may name any alias of a shared jobs table: the samples store its primary alias.
            $only['connection'] = $aliases[$only['connection']] ?? $only['connection'];
        }

        // 1. The version of the invalid minutes and the frontier M.
        [$before, $through] = $this->frontier($session, $current);

        // 2. The stored counts before M.
        $stored = $through === null || $through <= $from ? [] : $this->stored($session, $scope, $only, $from, min($to, $through), in_array('pushed', $kinds, true), $rowLimit);
        $storedCut = $stored['cut'] ?? null;
        $budget = $rowLimit === null ? null : $rowLimit - ($stored['used'] ?? 0);
        Hooks::reach(self::AFTER_COVERAGE);

        // 3. The scan from M on (without M: the current minute only), within the trim horizons.
        $scanFrom = max($from, $through ?? $current);
        $retention = $this->retentionMinutes($scope, $only, $aliases);
        $horizons = self::horizons($start, $retention);
        $scanned = [];
        $scanCuts = ['completed' => null, 'failed' => null, 'pushed' => null];
        $labels = $stored['labels'] ?? [];

        foreach ($kinds as $kind) {
            $scanned[$kind] = [];
            $kindFrom = max($scanFrom, self::ceilMinute($horizons[$kind]));

            if ($kindFrom >= $to) {
                continue;
            }

            if ($budget !== null && $budget <= 0) {
                // No budget left: every minute of this scan is unknown.
                $scanCuts[$kind] = $to - SampleCounts::MINUTE;

                continue;
            }

            [$scanned[$kind], $scanCuts[$kind], $used] = $this->scan($session, $kind, $scope, $only, $aliases, $kindFrom, $to, $labels, $budget);

            if ($budget !== null) {
                $budget -= $used;
            }
        }

        // 4. The invalid minutes and the retired frontier after the scans, with the time at their end.
        [$after, $intervals, $retired] = $this->invalidity($session, $current);
        $end = Clock::now();
        $endHorizons = self::horizons($end, $retention);
        // The wall clock can step back during a read: a minute is known only from the later of the
        // two horizons, so a minute that the scan left out (before the start horizon) never reads as 0.
        foreach ($horizons as $kind => $horizon) {
            $horizons[$kind] = max($horizon, $endHorizons[$kind]);
        }

        // The samples' retention horizon, as the aggregation and Samples::retire() use it.
        $kept = SampleCounts::minuteOf($end - $this->counts->retentionHours() * 3600) + SampleCounts::MINUTE;
        $changed = $after !== $before;
        $known = [];
        $rows = [];

        for ($minute = $from; $minute < $to; $minute += SampleCounts::MINUTE) {
            $known[$minute] = ['completed' => false, 'failed' => false, 'pushed' => false];

            if ($changed || $minute < $kept || SampleCounts::covers($intervals, $minute) || ($retired !== null && $minute <= $retired)) {
                continue;
            }

            if ($through !== null && $minute < $through) {
                // A minute at or before the cut of a bounded read has no `total` row (stored()): unknown.
                if (isset($stored['totals'][$minute])) {
                    foreach ($kinds as $kind) {
                        // The arrivals only from a `total` row that has them (never from a sum of group rows).
                        $known[$minute][$kind] = $kind !== 'pushed' || $stored['totals'][$minute]['pushed'];
                    }

                    $rows[$minute] = $stored['rows'][$minute] ?? [];
                }

                continue;
            }

            if ($minute < $scanFrom) {
                continue;
            }

            foreach ($kinds as $kind) {
                // At or after both horizons, and after the cut of a bounded scan: the scan covered it.
                if ($minute >= $horizons[$kind] && ($scanCuts[$kind] === null || $minute > $scanCuts[$kind])) {
                    $known[$minute][$kind] = true;

                    foreach ($scanned[$kind][$minute] ?? [] as $series => $counts) {
                        $row = $rows[$minute][$series] ?? self::ZERO;
                        $rows[$minute][$series] = [
                            'completed' => $row['completed'] + ($counts['completed'] ?? 0),
                            'failed' => $row['failed'] + ($counts['failed'] ?? 0),
                            'runtime_ms_sum' => $row['runtime_ms_sum'] + ($counts['runtime_ms_sum'] ?? 0),
                            'runtime_count' => $row['runtime_count'] + ($counts['runtime_count'] ?? 0),
                            'pushed' => $row['pushed'] + ($counts['pushed'] ?? 0),
                        ];
                    }
                }
            }
        }

        $base = (float) ($through ?? $current);
        $scannedFrom = ['completed' => INF, 'failed' => INF];

        foreach ($kinds as $kind) {
            $scannedFrom[$kind] = max($base, $horizons[$kind]);
        }

        $capped = $storedCut !== null || array_filter($scanCuts, static fn (?int $cut): bool => $cut !== null) !== [];

        return new SampleWindow($from, $to, $end, $known, $rows, $labels, $scannedFrom, $capped);
    }

    /**
     * The minute of the oldest `total` row, or null (the throughput's
     * retention_from).
     */
    public function oldestTotal(): ?int
    {
        $value = Container::getInstance()->make(PackageSession::class)->mirror()->table(SampleCounts::TABLE)
            ->where('scope', 'total')
            ->where('series', SampleCounts::series('total'))
            ->min('minute');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Read 1: the version of INVALID and M, in one statement.
     *
     * @return array{0: int, 1: ?int}
     */
    private function frontier(Connection $session, int $current): array
    {
        $values = $session->table('orrery_settings')
            ->whereIn('key', [SampleCounts::INVALID, SampleCounts::COUNTED_THROUGH])
            ->pluck('value', 'key')
            ->all();
        $through = $values[SampleCounts::COUNTED_THROUGH] ?? null;

        return [
            SampleCounts::parseInvalid($values[SampleCounts::INVALID] ?? null, $current)['version'],
            is_numeric($through) ? SampleCounts::minuteOf((float) $through) : null,
        ];
    }

    /**
     * Read 4: the version and intervals of INVALID and RETIRED_THROUGH.
     *
     * @return array{0: int, 1: list<array{0: int, 1: int}>, 2: ?int}
     */
    private function invalidity(Connection $session, int $current): array
    {
        $values = $session->table('orrery_settings')
            ->whereIn('key', [SampleCounts::INVALID, SampleCounts::RETIRED_THROUGH])
            ->pluck('value', 'key')
            ->all();
        $invalid = SampleCounts::parseInvalid($values[SampleCounts::INVALID] ?? null, $current);
        $retired = $values[SampleCounts::RETIRED_THROUGH] ?? null;

        return [$invalid['version'], $invalid['intervals'], is_numeric($retired) ? (int) $retired : null];
    }

    /**
     * Read 2: the `total` rows and the rows of the requested series of
     * [from, to) in one statement. With $arrivals it also reads `pushed`
     * (only then: a schema before step 7 has no such column), and `totals`
     * says per minute whether the `total` row has arrivals. When that read
     * fails on a schema before step 7 (SampleCounts::arrivalsMissing()), it
     * reports SchemaOutdated (SampleCounts::OUTDATED_OPERATION, at most once
     * per 60 s) and reads again without `pushed`: the stored arrivals are
     * unknown, the outcomes stay known. With $rowLimit, it reads at most
     * that many rows, newest minute first; `cut` is then the minute of the
     * first row past the bound (that minute and the earlier ones are
     * unknown), else null.
     *
     * @param  'total'|'queue'|'job'  $scope
     * @param  array{connection?: string, queue?: string, name?: string}|null  $only
     * @return array{totals: array<int, array{pushed: bool}>, rows: array<int, array<string, Counts>>, labels: array<string, Label>, cut: ?int, used: int}
     */
    private function stored(Connection $session, string $scope, ?array $only, int $from, int $to, bool $arrivals, ?int $rowLimit): array
    {
        $total = SampleCounts::series('total');
        $digest = self::digest($scope, $only);

        $query = $session->table(SampleCounts::TABLE)
            ->where('minute', '>=', $from)
            ->where('minute', '<', $to)
            ->where(static function (Builder $query) use ($scope, $digest, $total): void {
                $query->where(static fn (Builder $query) => $query->where('scope', 'total')->where('series', $total));

                if ($scope !== 'total') {
                    $query->orWhere(static function (Builder $query) use ($scope, $digest): void {
                        $query->where('scope', $scope);

                        if ($digest !== null) {
                            $query->where('series', $digest);
                        }
                    });
                }
            });

        if ($rowLimit !== null) {
            $query->orderByDesc('minute')->limit($rowLimit + 1);
        }

        $totals = [];
        $rows = [];
        $labels = [];
        $cut = null;

        $columns = ['scope', 'series', 'connection', 'queue', 'name', 'minute', 'completed', 'failed', 'runtime_ms_sum', 'runtime_count'];

        try {
            // Qualified: SQLite reads an unknown double-quoted bare name as a string literal, not as an error.
            // A clone: a get() that throws leaves its columns on the builder.
            $read = (clone $query)->get($arrivals ? [...$columns, SampleCounts::TABLE.'.pushed'] : $columns);
        } catch (QueryException $error) {
            if (! $arrivals || ! SampleCounts::arrivalsMissing($session)) {
                throw $error;
            }

            Watcher::instance()->report(SampleCounts::OUTDATED_OPERATION, SampleCounts::outdated());
            $arrivals = false;
            $read = $query->get($columns);
        }

        $past = $rowLimit === null ? null : $read->get($rowLimit);

        if ($past !== null) {
            $cut = (int) $past->minute;
        }

        $used = $rowLimit === null ? $read->count() : min($read->count(), $rowLimit);
        // Each row is freed once it is read, so the rows and their counts are not both held whole.
        $items = $read->all();
        unset($read, $past);

        foreach ($items as $index => $row) {
            unset($items[$index]);
            $minute = (int) $row->minute;

            if ($cut !== null && $minute <= $cut) {
                continue;
            }

            $pushed = $arrivals ? $row->pushed : null;

            if ($row->scope === 'total') {
                $totals[$minute] = ['pushed' => $pushed !== null];

                if ($scope !== 'total') {
                    continue;
                }
            }

            $series = (string) $row->series;
            $rows[$minute][$series] = [
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
                'runtime_ms_sum' => (int) $row->runtime_ms_sum,
                'runtime_count' => (int) $row->runtime_count,
                // Null in a queue-state row or a group without arrivals: 0 once the `total` row has a number.
                'pushed' => (int) ($pushed ?? 0),
            ];
            $labels[$series] ??= [
                'connection' => $row->connection === null ? null : (string) $row->connection,
                'queue' => $row->queue === null ? null : (string) $row->queue,
                'name' => $row->name === null ? null : (string) $row->name,
            ];
        }

        return ['totals' => $totals, 'rows' => $rows, 'labels' => $labels, 'cut' => $cut, 'used' => $used];
    }

    /**
     * The scan of one kind over [from, to): per minute and series, the
     * completions (count, runtime sum and count), the failures, or the
     * arrivals (every status by pushed_at). It adds the labels of the
     * scanned series. With $rowLimit, it reads at most that many groups,
     * newest minute first, and gives the minute of the first group past
     * the bound as the cut (that minute and the earlier ones are unknown
     * for this kind), else a null cut.
     *
     * @param  'completed'|'failed'|'pushed'  $kind
     * @param  'total'|'queue'|'job'  $scope
     * @param  array{connection?: string, queue?: string, name?: string}|null  $only
     * @param  array<string, string>  $aliases
     * @param  array<string, Label>  $labels
     * @return array{0: array<int, array<string, array<string, int>>>, 1: ?int, 2: int} the counts, the cut and the rows of the budget that it used
     */
    private function scan(Connection $session, string $kind, string $scope, ?array $only, array $aliases, int $from, int $to, array &$labels, ?int $rowLimit): array
    {
        $binary = in_array($session->getDriverName(), ['mysql', 'mariadb'], true);
        $sqlite = $session->getDriverName() === 'sqlite';
        $column = match ($kind) {
            'completed' => 'finished_at',
            'failed' => 'failed_at',
            'pushed' => 'pushed_at',
        };
        $query = $session->table('orrery_jobs')
            ->where($column, '>=', Clock::toDecimal((float) $from))
            ->where($column, '<', Clock::toDecimal((float) $to));

        if ($kind !== 'pushed') {
            // The arrivals count every status; the index `pushed` (pushed_at, id) serves the range.
            $query->whereIn('status', $kind === 'completed' ? ['completed'] : Throughput::STATUSES);
        }

        if ($only !== null && isset($only['name'])) {
            $query->where('name', $only['name']);
        } elseif ($only !== null && isset($only['connection'], $only['queue'])) {
            $connections = [$only['connection']];

            foreach ($aliases as $alias => $primary) {
                if ($primary === $only['connection']) {
                    $connections[] = (string) $alias;
                }
            }

            $query->whereIn('connection', array_values(array_unique($connections)))->where('queue', $only['queue']);
        }

        $bucket = $sqlite ? 'CAST('.$column.' / 60 AS INTEGER)' : 'FLOOR('.$column.' / 60)';
        // Each series expression, keyed by its alias in the select.
        $names = match ($scope) {
            'queue' => $binary ? ['orrery_c' => 'CAST(`connection` AS BINARY)', 'orrery_q' => 'CAST(`queue` AS BINARY)'] : ['orrery_c' => '"connection"', 'orrery_q' => '"queue"'],
            'job' => $binary ? ['orrery_n' => 'CAST(`name` AS BINARY)'] : ['orrery_n' => '"name"'],
            default => [],
        };
        $select = $bucket.' AS orrery_bucket';

        foreach ($names as $alias => $expression) {
            $select .= ', '.$expression.' AS '.$alias;
        }

        $select .= match ($kind) {
            'completed' => $binary ? ', COUNT(*) AS completed, SUM(`runtime_ms`) AS runtime_ms_sum, COUNT(`runtime_ms`) AS runtime_count' : ', COUNT(*) AS completed, SUM("runtime_ms") AS runtime_ms_sum, COUNT("runtime_ms") AS runtime_count',
            'failed' => ', COUNT(*) AS failed',
            'pushed' => ', COUNT(*) AS pushed',
        };

        // MySQL and MariaDB group by the select aliases: MariaDB's
        // ONLY_FULL_GROUP_BY (Laravel's strict mode) does not match a
        // selected expression with the same expression in GROUP BY.
        $groups = $binary ? 'orrery_bucket' : $bucket;

        foreach ($names as $alias => $expression) {
            $groups .= ', '.($binary ? $alias : $expression);
        }

        $result = [];
        $query->selectRaw($select)->groupByRaw($groups);

        if ($rowLimit !== null) {
            $query->orderByRaw('orrery_bucket DESC')->limit($rowLimit + 1);
        }

        $read = $query->get();
        $past = $rowLimit === null ? null : $read->get($rowLimit);
        $cut = $past === null ? null : (int) $past->orrery_bucket * SampleCounts::MINUTE;
        $used = $rowLimit === null ? $read->count() : min($read->count(), $rowLimit);
        // Each row is freed once it is read.
        $items = $read->all();
        unset($read, $past);

        foreach ($items as $index => $row) {
            unset($items[$index]);
            $minute = (int) $row->orrery_bucket * SampleCounts::MINUTE;

            // The minutes at or before the cut are unknown: no counts, no label.
            if ($cut !== null && $minute <= $cut) {
                continue;
            }

            [$series, $label] = self::seriesOf($scope, $row, $aliases);

            if ($series === null || ! self::matches($only, $label)) {
                continue;
            }

            $counts = match ($kind) {
                'completed' => ['completed' => (int) $row->completed, 'runtime_ms_sum' => (int) ($row->runtime_ms_sum ?? 0), 'runtime_count' => (int) $row->runtime_count],
                'failed' => ['failed' => (int) $row->failed],
                'pushed' => ['pushed' => (int) $row->pushed],
            };

            foreach ($counts as $field => $value) {
                $result[$minute][$series][$field] = ($result[$minute][$series][$field] ?? 0) + $value;
            }

            $labels[$series] ??= $label;
        }

        return [$result, $cut, $used];
    }

    /**
     * The series digest and label of a scanned group.
     *
     * @param  'total'|'queue'|'job'  $scope
     * @param  array<string, string>  $aliases
     * @return array{0: ?string, 1: Label}
     */
    private static function seriesOf(string $scope, object $row, array $aliases): array
    {
        $none = ['connection' => null, 'queue' => null, 'name' => null];

        if ($scope === 'total') {
            return [SampleCounts::series('total'), $none];
        }

        if ($scope === 'job') {
            $name = (string) ($row->orrery_n ?? '');

            return [SampleCounts::series('job', $name), ['name' => $name] + $none];
        }

        $connection = (string) ($row->orrery_c ?? '');
        $connection = $aliases[$connection] ?? $connection;
        $queue = (string) ($row->orrery_q ?? '');

        return [SampleCounts::series('queue', $connection, $queue), ['connection' => $connection, 'queue' => $queue, 'name' => null]];
    }

    /**
     * The byte-exact filter of a single-series read.
     *
     * @param  array{connection?: string, queue?: string, name?: string}|null  $only
     * @param  Label  $label
     */
    private static function matches(?array $only, array $label): bool
    {
        if ($only === null) {
            return true;
        }

        if (isset($only['name'])) {
            return $label['name'] === $only['name'];
        }

        return $label['connection'] === ($only['connection'] ?? null) && $label['queue'] === ($only['queue'] ?? null);
    }

    /**
     * @param  'total'|'queue'|'job'  $scope
     * @param  array{connection?: string, queue?: string, name?: string}|null  $only
     */
    private static function digest(string $scope, ?array $only): ?string
    {
        if ($only === null) {
            return null;
        }

        if (isset($only['name'])) {
            return SampleCounts::series('job', $only['name']);
        }

        return isset($only['connection'], $only['queue']) && $scope === 'queue' ? SampleCounts::series('queue', $only['connection'], $only['queue']) : null;
    }

    /**
     * The retention minutes that bound the scan of each kind:
     * completions by min(completed, silenced), failures and arrivals
     * by min(completed, silenced, failed, unknown) (a failure whose row later
     * completed is trimmed under its later state; a pushed row may since
     * have reached any trimmed state). `monitored` only lengthens a
     * retention, so it never takes part.
     * - A single queue series (the queue history): the effective values of
     *   that queue on its primary connection (the overrides match every
     *   alias through it), so the queue's own overrides decide its minutes.
     * - Every other read: RetentionPolicy::shortest() of each key, over the
     *   global value and every override.
     * An invalid value deletes nothing, so it does not move a horizon; null
     * means no horizon.
     *
     * @param  'total'|'queue'|'job'  $scope
     * @param  array{connection?: string, queue?: string, name?: string}|null  $only
     * @param  array<string, string>  $aliases
     * @return array{completed: ?int, failed: ?int, pushed: ?int}
     */
    private function retentionMinutes(string $scope, ?array $only, array $aliases): array
    {
        $values = [];

        if ($scope === 'queue' && $only !== null && isset($only['connection'], $only['queue'])) {
            // The overrides match by the primary alias, so every alias of the series has these values.
            foreach ($this->policy->effective($only['connection'], $only['queue'], $aliases) as $key => $effective) {
                $values[$key][] = $effective['minutes'];
            }
        } else {
            foreach (RetentionPolicy::STATES as $key) {
                $values[$key][] = RetentionPolicy::shortest($key);
            }
        }

        $min = static fn (string ...$keys): ?int => self::shortest(array_merge(...array_map(static fn (string $key): array => $values[$key] ?? [], $keys)));
        $states = $min(...RetentionPolicy::STATES);

        return [
            'completed' => $min('completed', 'silenced'),
            'failed' => $states,
            'pushed' => $states,
        ];
    }

    /**
     * The trim horizons of the scan at $now: the earliest time whose rows
     * retention keeps for each kind.
     *
     * @param  array{completed: ?int, failed: ?int, pushed: ?int}  $minutes
     * @return array{completed: float, failed: float, pushed: float}
     */
    private static function horizons(float $now, array $minutes): array
    {
        $horizons = [];

        foreach ($minutes as $kind => $value) {
            $horizons[$kind] = $value === null ? -INF : $now - $value * 60;
        }

        return $horizons;
    }

    /**
     * @param  list<int|null>  $values
     */
    private static function shortest(array $values): ?int
    {
        $values = array_values(array_filter($values, static fn (?int $value): bool => $value !== null));

        return $values === [] ? null : min($values);
    }

    /**
     * The first minute boundary at or after $time.
     */
    private static function ceilMinute(float $time): int
    {
        if (! is_finite($time)) {
            return PHP_INT_MIN;
        }

        return (int) (ceil($time / SampleCounts::MINUTE) * SampleCounts::MINUTE);
    }
}
