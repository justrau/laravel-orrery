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
 * 1. The window. The settings value KEY keeps the decision's own progress,
 *    `checked_through`: every completed decision moves it to its end, apart
 *    from the tick outcome. The window starts there; only while KEY has no
 *    `checked_through` (the first run after an upgrade) it starts at the
 *    start of the previous finished tick (`started_at` of
 *    Maintenance\TickOutcome), and that start is stored first, so a walk that
 *    fails still leaves it. With neither (the first tick ever) there is no
 *    window, and the decision only writes `checked_through`. A decision
 *    that fails, or a `sample_counts` phase that the tick's deadline skips,
 *    leaves `checked_through`, so the next tick decides the same window
 *    again; a failure after the decision is no gap. Both times come from the
 *    PHP clock of the tick server, the clock of the samples.
 * 2. The short window. When the window is at most GAP_SECONDS, nothing is
 *    read: `checked_through` moves to now with one guarded write (a compare
 *    and set on the value read), skipped while it is younger than
 *    REFRESH_SECONDS. So a normal minute tick costs one read and one write.
 * 3. The evidence. Inside [max(start, now − samples.retention_hours), now),
 *    an instant is evidence of observation when an observing process wrote
 *    it on the observation path, at the time of the observation, into a
 *    timestamp that has an index: EVIDENCE, the `reserved_at` of a row of
 *    any status (a completion or a release keeps it, so a row that changes
 *    state during the walk keeps its evidence; one subquery per status, so
 *    the index st_reserved serves each), the `finished_at` of a `completed`
 *    row and the `failed_at` of a `failed` row. `pushed_at` is no evidence:
 *    a row that a reservation creates takes the payload's push time, so a
 *    job pushed while nothing observed would read as observed. Missing
 *    evidence (a row whose time was overwritten, or a trimmed row) only
 *    makes a hole longer, so the rule errs to unknown, never to 0.
 * 4. The holes. A hole is a span of the window longer than GAP_SECONDS
 *    that holds no evidence: from the last evidence before it (or the
 *    window's start) to the first evidence after it (or now, the time
 *    before the walk, so a row observed during the walk never falls into
 *    it). walk() finds them with one statement per probe (probe(): one
 *    indexed `ORDER BY … LIMIT 1` per evidence column). Each probe that
 *    finds evidence moves on from it, and a hole costs two more, so a
 *    window costs at most about 3 × window ÷ GAP_SECONDS probes; the cap is
 *    that number plus 3 (maxProbes()). A walk that reaches the cap stops:
 *    the rest of the window stays undecided (no hole, no count:
 *    SampleCounts::run() publishes no minute from the minute of the stop
 *    on), `checked_through` is the stop, and the next tick goes on from it.
 * 5. The record. In one package transaction under the lock of
 *    SampleCounts::INVALID (the lock of every writer of the counts), KEY is
 *    read again: when another run decided since the read of step 1,
 *    nothing is written. Else each hole's minutes [minuteOf(from), ceil(to))
 *    become an invalid interval (SampleCounts::addInvalidIntervals(): no
 *    `total` row, so every count reader, SampleCoverage::of(), answers
 *    unknown), and KEY gets the holes and the new `checked_through`. A
 *    minute that a hole only touches is unknown whole.
 *
 * So a scheduler outage while workers observe keeps its counts (each
 * worker's outcomes are evidence), and an off period, or a mirror outage,
 * reads as unknown. A quiet span longer than GAP_SECONDS inside the window
 * (no job reserved, finished or failed) is unknown too: the package cannot
 * tell it from a blind one.
 *
 * KEY, the JSON {checked_through, gaps}: `gaps` lists the holes as
 * [from, to, start] epoch seconds: the exact evidence times (not minute
 * boundaries), and the start of the window whose walk found the hole (the
 * last time the tick side had decided, not clipped to the samples
 * retention; Maintenance\Reconciliation anchors its suppression rule
 * there). A hole that begins where the walk began on a window clipped to
 * the samples retention is recorded from the last evidence before the clip
 * (one more probe), else from the window's start; its invalid minutes stay
 * clipped. A gap written as [from, to] reads with
 * start = from. They are sorted, the overlapping ones merged, and kept
 * while they end after now − `samples.retention_hours`. covering() answers
 * whether a moment falls inside one.
 */
final class BlindGaps
{
    /** The orrery_settings key of the recorded gaps. */
    public const KEY = 'samples_blind_gaps';

    /**
     * The longest span without evidence that still counts as observed, in
     * seconds; also the window that triggers a walk. Five minutes: the
     * recount window of the counts (SampleCounts::RECOUNT_SECONDS), five
     * missed ticks, and longer than a worker's idle wait between jobs.
     */
    public const GAP_SECONDS = 300;

    /**
     * How old `checked_through` must be before a short window writes it
     * again: less than one tick, so each minute tick writes it once and a
     * second tick inside the same minute (a retry, another server) does not.
     * So only a time of more than GAP_SECONDS without a completed decision
     * (about five missed ticks) is ever walked.
     */
    public const REFRESH_SECONDS = 50;

    /** The most recent gaps that recent() returns. */
    public const RECENT = 10;

    /** After the walk, before the record's transaction (tests only). */
    public const BEFORE_RECORD = 'blind_gaps.before_record';

    /** Before the compare-and-set write of a short window (tests only). */
    public const BEFORE_ADVANCE = 'blind_gaps.before_advance';

    /**
     * The evidence columns: alias => [status, timestamp], each read through
     * its index (st_reserved, st_finished, st_failed).
     */
    private const EVIDENCE = [
        'orrery_rp' => ['pending', 'reserved_at'],
        'orrery_rr' => ['reserved', 'reserved_at'],
        'orrery_rc' => ['completed', 'reserved_at'],
        'orrery_rf' => ['failed', 'reserved_at'],
        'orrery_rt' => ['retrying', 'reserved_at'],
        'orrery_ru' => ['unknown', 'reserved_at'],
        'orrery_f' => ['completed', 'finished_at'],
        'orrery_x' => ['failed', 'failed_at'],
    ];

    private const JSON = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private ?int $maxProbesForTesting = null;

    public function __construct(private readonly PackageTransaction $transactions) {}

    /**
     * Steps 1 to 5 of the class comment. The caller called
     * SampleCounts::ensureInvalidRow(). Returns the time through which the
     * window is decided: $now, or the stop of a walk at its cap (the minutes
     * from that minute on are not decided yet).
     */
    public function decide(Connection $session, float $now, int $retentionHours): float
    {
        $values = $session->table('orrery_settings')
            ->whereIn('key', [TickOutcome::KEY, self::KEY])
            ->pluck('value', 'key')
            ->all();
        $raw = is_string($values[self::KEY] ?? null) ? $values[self::KEY] : null;
        $state = self::decode($raw);
        $start = $state['checked_through'];
        $horizon = $now - $retentionHours * 3600;

        if ($start === null) {
            $outcome = is_string($values[TickOutcome::KEY] ?? null) ? TickOutcome::decode($values[TickOutcome::KEY]) : null;

            if ($outcome === null) {
                // The first tick ever: nothing to measure from. Only the progress starts.
                $this->advance($session, $raw, $state, $now, $horizon);

                return $now;
            }

            $start = $outcome['started_at'];
            // The first decision after an upgrade: the window's start is stored first (one write, once), so a
            // walk that fails now leaves that start for the next tick instead of a newer tick outcome.
            $session->table('orrery_settings')->insertOrIgnore(['key' => self::KEY, 'value' => self::encode($start, [])]);
            $raw = $session->table('orrery_settings')->where('key', self::KEY)->value('value');
            $raw = is_string($raw) ? $raw : null;
            $state = self::decode($raw);
            $start = $state['checked_through'] ?? $start;
        }

        if ($now - $start <= self::GAP_SECONDS) {
            if ($state['checked_through'] === null || $now - $start >= self::REFRESH_SECONDS) {
                $this->advance($session, $raw, $state, $now, $horizon);
            }

            return $now;
        }

        $from = max($start, $horizon);
        [$holes, $through] = $this->walk($session, $from, $now);
        // The gaps as recorded: a hole that begins at a window clipped to the samples retention begins, in the
        // record, at the last evidence before the clip (one more probe), or at the window's start.
        $recorded = $holes;

        if ($holes !== [] && $holes[0][0] === $from && $from > $start) {
            $recorded[0] = [$this->probe($session, $start, $from, true) ?? $start, $holes[0][1]];
        }

        Hooks::reach(self::BEFORE_RECORD);

        return $this->transactions->run($session, function (Connection $tx) use ($raw, $holes, $recorded, $through, $start, $now, $retentionHours, $horizon): float {
            $invalid = SampleCounts::lockInvalid($tx, SampleCounts::minuteOf($now));
            $live = $tx->table('orrery_settings')->where('key', self::KEY)->value('value');

            if ($live !== $raw) {
                // Another run decided this window after the read above.
                return self::decode($live)['checked_through'] ?? $through;
            }

            $intervals = [];

            foreach ($holes as [$holeFrom, $holeTo]) {
                $intervals[] = [SampleCounts::minuteOf($holeFrom), (int) (ceil($holeTo / SampleCounts::MINUTE) * SampleCounts::MINUTE)];
            }

            SampleCounts::addInvalidIntervals($tx, $invalid, $intervals, $now, $retentionHours);
            $found = array_map(static fn (array $hole): array => [$hole[0], $hole[1], $start], $recorded);
            $tx->table('orrery_settings')->upsert(
                [['key' => self::KEY, 'value' => self::encode($through, self::merge([...self::decode($live)['gaps'], ...$found], $horizon))]],
                ['key'],
                ['value'],
            );

            return $through;
        });
    }

    /**
     * Lowers the probe cap of a walk (tests only); null restores maxProbes().
     */
    public function useMaxProbesForTesting(?int $probes): void
    {
        $this->maxProbesForTesting = $probes;
    }

    /**
     * The probe cap of a window of $seconds: 3 × window ÷ GAP_SECONDS + 3
     * (step 4 of the class comment).
     */
    public static function maxProbes(float $seconds): int
    {
        return (int) ceil(3 * max(0.0, $seconds) / self::GAP_SECONDS) + 3;
    }

    /**
     * Step 2: `checked_through` moves to $now with a compare and set on the
     * value read (insert-or-ignore when there was none). A miss means that
     * another run wrote the value since: it moved the progress itself.
     *
     * @param  array{checked_through: ?float, gaps: list<array{0: float, 1: float, 2: float}>}  $state
     */
    private function advance(Connection $session, ?string $raw, array $state, float $now, float $horizon): void
    {
        $value = self::encode(max($now, $state['checked_through'] ?? -INF), self::merge($state['gaps'], $horizon));
        Hooks::reach(self::BEFORE_ADVANCE);

        if ($raw === null) {
            $session->table('orrery_settings')->insertOrIgnore(['key' => self::KEY, 'value' => $value]);

            return;
        }

        $session->table('orrery_settings')->where('key', self::KEY)->where('value', $raw)->update(['value' => $value]);
    }

    /**
     * The recorded gaps, oldest first, as [from, to] ([] when none or the
     * value does not decode).
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function read(?Connection $session = null): array
    {
        return array_map(static fn (array $gap): array => [$gap[0], $gap[1]], self::anchored($session));
    }

    /**
     * The recorded gaps, oldest first, as [from, to, start]: start is the
     * start of the window whose walk found the gap (the class comment).
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    public static function anchored(?Connection $session = null): array
    {
        $session ??= Container::getInstance()->make(PackageSession::class)->mirror();

        return self::decode($session->table('orrery_settings')->where('key', self::KEY)->value('value'))['gaps'];
    }

    /**
     * The time through which the blind gaps are decided, from the stored
     * values of KEY and of Maintenance\TickOutcome::KEY: `checked_through`,
     * else the previous tick's start (decide()'s fallback), else null (no
     * tick has run). SampleCoverage reads the minutes after it as unknown
     * while it is more than GAP_SECONDS old.
     */
    public static function decidedThrough(mixed $value, mixed $outcome): ?float
    {
        $checked = self::decode($value)['checked_through'];

        if ($checked !== null) {
            return $checked;
        }

        $decoded = is_string($outcome) ? TickOutcome::decode($outcome) : null;

        return $decoded === null ? null : $decoded['started_at'];
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
     * Step 4: the holes of [from, now), and the time through which the
     * walk decided the window ($now, or the stop at the cap).
     *
     * @return array{0: list<array{0: float, 1: float}>, 1: float}
     */
    private function walk(Connection $session, float $from, float $now): array
    {
        $holes = [];
        $at = $from;
        $probes = 0;
        $cap = $this->maxProbesForTesting ?? self::maxProbes($now - $from);

        while ($now - $at > self::GAP_SECONDS) {
            if ($probes >= $cap) {
                // The rest of the window stays undecided; the next tick goes on from here.
                return [$holes, $at];
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

        return [$holes, $now];
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
     * @return array{checked_through: ?float, gaps: list<array{0: float, 1: float, 2: float}>}
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
                // A gap written without its window's start reads with start = from.
                $gaps[] = [(float) $gap[0], (float) $gap[1], is_numeric($gap[2] ?? null) ? min((float) $gap[2], (float) $gap[0]) : (float) $gap[0]];
            }
        }

        return [
            'checked_through' => is_numeric($decoded['checked_through'] ?? null) ? (float) $decoded['checked_through'] : null,
            'gaps' => $gaps,
        ];
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float}>  $gaps
     */
    private static function encode(float $checkedThrough, array $gaps): string
    {
        return json_encode(['checked_through' => round($checkedThrough, 6), 'gaps' => $gaps], self::JSON);
    }

    /**
     * Sorted gaps, the overlapping ones merged (with the earlier start);
     * those that end at or before $horizon go.
     *
     * @param  list<array{0: float, 1: float, 2: float}>  $gaps
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private static function merge(array $gaps, float $horizon): array
    {
        usort($gaps, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $merged = [];

        foreach ($gaps as [$from, $to, $start]) {
            if ($to <= $horizon) {
                continue;
            }

            $from = round($from, 6);
            $to = round($to, 6);
            $start = round($start, 6);
            $last = count($merged) - 1;

            // Overlapping gaps merge; two that only touch stay apart (an evidence time lies between them).
            if ($last >= 0 && $from < $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
                $merged[$last][2] = min($merged[$last][2], $start);
            } else {
                $merged[] = [$from, $to, $start];
            }
        }

        return $merged;
    }
}
