<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Maintenance\TickOutcome;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;

/**
 * The blind gaps: the spans in which no process observed the queues, so
 * that the per-minute counts of their minutes are unknown, never 0.
 *
 * While nothing observes (the off switch `ORRERY_ENABLED=false`, or package
 * storage that no process could reach), jobs run without mirror rows. A
 * catch-up of the counts would then store 0 for those minutes. So, before
 * the counts move on, SampleCounts::run() calls decide():
 *
 * 1. The trigger. The window starts at a = the later of the start of the
 *    previous finished tick (`started_at` of Maintenance\TickOutcome, the
 *    value of observer_tick_at that it wrote) and the stored
 *    `checked_through` (the end of the last decision). The outcome is
 *    written after the phases, so during a tick it is the previous one's;
 *    a tick whose phases failed still writes it (a failed phase is no
 *    gap), and a tick that never finished or could not reach the mirror
 *    leaves the older one, so the window starts earlier. Without an
 *    outcome (the first tick ever) there is no window. When now − a is at
 *    most GAP_SECONDS, nothing is read. Both times come from the PHP clock
 *    of the tick server, the clock of the samples.
 * 2. The evidence. Inside [max(a, now − samples.retention_hours), now), an
 *    instant is evidence of observation when an observing process wrote
 *    it on the observation path, at the time of the observation, into a
 *    timestamp that has an index: EVIDENCE, the `finished_at` of a
 *    `completed` row, the `failed_at` of a `failed` row and the
 *    `reserved_at` of a `reserved` row. `pushed_at` is no evidence: a row
 *    that a reservation creates takes the payload's push time, so a job
 *    pushed while nothing observed would read as observed. Missing
 *    evidence (a row that changed state since, or a trimmed row) only
 *    makes a hole longer, so the rule errs to unknown, never to 0.
 * 3. The holes. A hole is a span of the window longer than GAP_SECONDS
 *    that holds no evidence: from the last evidence before it (or a) to
 *    the first evidence after it (or now). walk() finds them with seeks of
 *    one statement each (probe(): one indexed `ORDER BY … LIMIT 1` per
 *    evidence column), and two probes move past at least GAP_SECONDS of
 *    the window, so a window costs at most about 2 × window ÷ GAP_SECONDS
 *    statements, capped at MAX_PROBES. A walk that reaches the cap counts
 *    the rest of the window as a hole (unknown, never 0).
 * 4. The record. In one package transaction under the lock of
 *    SampleCounts::INVALID (the lock of every writer of the counts), the
 *    stored value KEY is read again: when another run decided since the
 *    read of step 1, nothing is written. Else each hole's minutes
 *    [minuteOf(from), ceil(to)) become an invalid interval
 *    (SampleCounts::addInvalidIntervals(): no `total` row, so every count
 *    reader, SampleCoverage::of(), answers unknown), and KEY gets the holes
 *    and `checked_through` = now. A minute that a hole only touches is
 *    unknown whole.
 *
 * So a scheduler outage while workers observe keeps its counts (each
 * worker's outcomes are evidence), and an off period, or a mirror outage,
 * reads as unknown. A quiet span longer than GAP_SECONDS inside the window
 * (no job finished, failed or was reserved) is unknown too: the package
 * cannot tell it from a blind one.
 *
 * KEY, the JSON {checked_through, gaps}: `gaps` lists the holes as
 * [from, to] epoch seconds (the exact evidence times, not minute
 * boundaries), sorted and merged, kept while they end after now −
 * `samples.retention_hours`. covering() answers whether a moment falls
 * inside one, for later readers (the health endpoint, the stuck rules).
 */
final class BlindGaps
{
    /** The orrery_settings key of the recorded gaps. */
    public const KEY = 'samples_blind_gaps';

    /**
     * The longest span without evidence that still counts as observed, in
     * seconds; also the time since the previous tick that triggers a decision. Five
     * minutes: the recount window of the counts (SampleCounts::RECOUNT_SECONDS),
     * five missed ticks, and longer than a worker's idle wait between jobs.
     */
    public const GAP_SECONDS = 300;

    /** The most probes of one decision. */
    public const MAX_PROBES = 600;

    /** The most recent gaps that recent() returns. */
    public const RECENT = 10;

    /** After the walk, before the record's transaction (tests only). */
    public const BEFORE_RECORD = 'blind_gaps.before_record';

    /** The evidence columns: [status, timestamp], each read through its index (st_finished, st_failed, st_reserved). */
    private const EVIDENCE = [
        'orrery_f' => ['completed', 'finished_at'],
        'orrery_x' => ['failed', 'failed_at'],
        'orrery_r' => ['reserved', 'reserved_at'],
    ];

    private const JSON = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(private readonly PackageTransaction $transactions) {}

    /**
     * Steps 1 to 4 of the class comment. The caller called
     * SampleCounts::ensureInvalidRow(). Returns the holes that this call
     * recorded.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function decide(Connection $session, float $now, int $retentionHours): array
    {
        $values = $session->table('orrery_settings')
            ->whereIn('key', [TickOutcome::KEY, self::KEY])
            ->pluck('value', 'key')
            ->all();
        $outcome = is_string($values[TickOutcome::KEY] ?? null) ? TickOutcome::decode($values[TickOutcome::KEY]) : null;

        if ($outcome === null) {
            // The first tick ever: there is no previous tick to measure from.
            return [];
        }

        $raw = $values[self::KEY] ?? null;
        $checked = self::decode($raw)['checked_through'];
        $start = max($outcome['started_at'], $checked ?? -INF);

        if ($now - $start <= self::GAP_SECONDS) {
            return [];
        }

        $horizon = $now - $retentionHours * 3600;
        $holes = $this->walk($session, max($start, $horizon), $now);
        Hooks::reach(self::BEFORE_RECORD);

        return $this->transactions->run($session, function (Connection $tx) use ($raw, $holes, $now, $retentionHours, $horizon): array {
            $invalid = SampleCounts::lockInvalid($tx, SampleCounts::minuteOf($now));
            $live = $tx->table('orrery_settings')->where('key', self::KEY)->value('value');

            if ($live !== $raw) {
                // Another run decided this window after the read above.
                return [];
            }

            $intervals = [];

            foreach ($holes as [$from, $to]) {
                $intervals[] = [SampleCounts::minuteOf($from), (int) (ceil($to / SampleCounts::MINUTE) * SampleCounts::MINUTE)];
            }

            SampleCounts::addInvalidIntervals($tx, $invalid, $intervals, $now, $retentionHours);
            $gaps = self::merge([...self::decode($live)['gaps'], ...$holes], $horizon);
            $tx->table('orrery_settings')->upsert(
                [['key' => self::KEY, 'value' => json_encode(['checked_through' => round($now, 6), 'gaps' => $gaps], self::JSON)]],
                ['key'],
                ['value'],
            );

            return $holes;
        });
    }

    /**
     * The recorded gaps, oldest first ([] when none or the value does not decode).
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function read(?Connection $session = null): array
    {
        $session ??= Container::getInstance()->make(PackageSession::class)->mirror();

        return self::decode($session->table('orrery_settings')->where('key', self::KEY)->value('value'))['gaps'];
    }

    /**
     * The newest RECENT gaps, newest first, as {from, to}.
     *
     * @return list<array{from: float, to: float}>
     */
    public static function recent(?Connection $session = null): array
    {
        $recent = [];

        foreach (array_slice(array_reverse(self::read($session)), 0, self::RECENT) as [$from, $to]) {
            $recent[] = ['from' => $from, 'to' => $to];
        }

        return $recent;
    }

    /**
     * The gap that holds $at (from <= at < to), or null.
     *
     * @param  list<array{0: float, 1: float}>  $gaps
     * @return array{0: float, 1: float}|null
     */
    public static function covering(array $gaps, float $at): ?array
    {
        foreach ($gaps as $gap) {
            if ($at >= $gap[0] && $at < $gap[1]) {
                return $gap;
            }
        }

        return null;
    }

    /**
     * Step 3: the holes of [from, now).
     *
     * @return list<array{0: float, 1: float}>
     */
    private function walk(Connection $session, float $from, float $now): array
    {
        $holes = [];
        $at = $from;
        $probes = 0;

        while ($now - $at > self::GAP_SECONDS) {
            if ($probes >= self::MAX_PROBES) {
                // Not walked: unknown, never a covered 0.
                $holes[] = [$at, $now];

                break;
            }

            $probes++;
            $latest = $this->probe($session, $at, $at + self::GAP_SECONDS, true);

            if ($latest !== null) {
                // Observed within GAP_SECONDS: go on from the last evidence of that span.
                $at = max($latest, $at + 0.000001);

                continue;
            }

            $probes++;
            $next = $this->probe($session, $at + self::GAP_SECONDS, $now, false);
            $holes[] = [$at, $next ?? $now];

            if ($next === null) {
                break;
            }

            $at = $next;
        }

        return $holes;
    }

    /**
     * One statement: the latest evidence in (lo, hi] ($latest), or the
     * earliest in (lo, hi), over every EVIDENCE column; null when none.
     */
    private function probe(Connection $session, float $lo, float $hi, bool $latest): ?float
    {
        $query = $session->query();

        foreach (self::EVIDENCE as $alias => [$status, $column]) {
            $query->selectSub(
                $session->table('orrery_jobs')
                    ->where('status', $status)
                    ->where($column, '>', Clock::toDecimal($lo))
                    ->where($column, $latest ? '<=' : '<', Clock::toDecimal($hi))
                    ->orderBy($column, $latest ? 'desc' : 'asc')
                    ->limit(1)
                    ->select($column),
                $alias,
            );
        }

        $row = $query->first();
        $times = [];

        foreach (array_keys(self::EVIDENCE) as $alias) {
            $value = $row->{$alias} ?? null;

            if (is_numeric($value)) {
                $times[] = Clock::stored($value);
            }
        }

        if ($times === []) {
            return null;
        }

        return $latest ? max($times) : min($times);
    }

    /**
     * @return array{checked_through: ?float, gaps: list<array{0: float, 1: float}>}
     */
    private static function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($decoded)) {
            return ['checked_through' => null, 'gaps' => []];
        }

        $gaps = [];

        foreach (is_array($decoded['gaps'] ?? null) ? $decoded['gaps'] : [] as $gap) {
            if (is_array($gap) && is_numeric($gap[0] ?? null) && is_numeric($gap[1] ?? null) && (float) $gap[0] < (float) $gap[1]) {
                $gaps[] = [(float) $gap[0], (float) $gap[1]];
            }
        }

        return [
            'checked_through' => is_numeric($decoded['checked_through'] ?? null) ? (float) $decoded['checked_through'] : null,
            'gaps' => $gaps,
        ];
    }

    /**
     * Sorted gaps, the overlapping ones merged; those that end at or
     * before $horizon go.
     *
     * @param  list<array{0: float, 1: float}>  $gaps
     * @return list<array{0: float, 1: float}>
     */
    private static function merge(array $gaps, float $horizon): array
    {
        usort($gaps, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $merged = [];

        foreach ($gaps as [$from, $to]) {
            if ($to <= $horizon) {
                continue;
            }

            $from = round($from, 6);
            $to = round($to, 6);
            $last = count($merged) - 1;

            // Overlapping gaps merge; two that only touch stay apart (an evidence time lies between them).
            if ($last >= 0 && $from < $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
            } else {
                $merged[] = [$from, $to];
            }
        }

        return $merged;
    }
}
