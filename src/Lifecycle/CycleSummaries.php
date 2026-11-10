<?php

namespace Justrau\Orrery\Lifecycle;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\Clock;

/**
 * The cycle summaries.
 *
 * - A cycle's summary is written once, when the next cycle of the same row
 *   starts (retry finalisation, adoption, or nativeRetry), in the transaction
 *   that starts it, from the row's values for the ending cycle. The current
 *   cycle is always read from the row.
 * - Evidence after the freeze: an observation whose generation equals the
 *   cycle_gen of a retained individual summary (olderCycle, instrumented)
 *   updates that summary by the same guarded rules as the row.
 * - Compaction: the freeze that makes a job's 51st individual summary
 *   merges the oldest one into the one `merged` row, and deletes the merged
 *   cycle's incidents (IncidentCleanup). A merged row is never
 *   updated by evidence.
 */
final class CycleSummaries
{
    public const TABLE = 'orrery_cycle_summaries';

    /** The individual summaries that a job keeps. */
    public const INDIVIDUAL = 50;

    /** The counters that the merged row sums. */
    private const SUMMED = ['attempts', 'releases', 'exceptions', 'timeouts', 'reclaims', 'observed_attempts', 'late_outcomes', 'observed_gap'];

    public function __construct(private readonly History $history)
    {
    }

    /**
     * Freezes the summary of the row's ending cycle. $row is the full
     * orrery_jobs row, read in $tx before the next cycle's update. A second
     * freeze of the same cycle adds nothing. Then compacts the 51st.
     *
     * @param  \stdClass  $row
     */
    public function freeze(Connection $tx, object $row, float $at): void
    {
        $uuid = (string) $row->uuid;
        $cycle = (int) $row->cycle;

        [$tries, $triesSource] = self::effective($row->max_tries ?? null, $row->worker_tries ?? null, $row->worker_evidence_attempt ?? null);
        [$timeout, $timeoutSource] = self::effective($row->timeout ?? null, $row->worker_timeout ?? null, $row->worker_evidence_attempt ?? null);

        $summary = [
            'job_uuid' => $uuid,
            'cycle' => $cycle,
            'cycle_gen' => $row->cycle_gen ?? null,
            'occurrence_id' => self::intOrNull($row->occurrence_id ?? null),
            'outcome' => in_array($row->status, ['failed', 'retrying'], true) ? 'failed' : mb_substr((string) $row->status, 0, 16),
            'merged' => false,
            'history_truncated' => (bool) ($row->history_truncated ?? false),
            'exception_class' => $row->exception_class ?? null,
            'exception_message' => $row->exception_message ?? null,
            'max_tries' => self::intOrNull($row->max_tries ?? null),
            'max_exceptions' => self::intOrNull($row->max_exceptions ?? null),
            'timeout' => self::intOrNull($row->timeout ?? null),
            'fail_on_timeout' => (bool) ($row->fail_on_timeout ?? false),
            'retry_until' => self::intOrNull($row->retry_until ?? null),
            'effective_tries' => $tries,
            'effective_tries_source' => $triesSource,
            'effective_timeout' => $timeout,
            'effective_timeout_source' => $timeoutSource,
            'started_at' => self::time($row->cycle_started_at ?? null),
            'started_source' => $row->cycle_source ?? null,
            'finished_at' => self::time($row->finished_at ?? null),
        ];

        foreach (CycleStart::COUNTERS as $counter) {
            $summary[$counter] = (int) ($row->{$counter} ?? 0);
        }

        if ($tx->table(self::TABLE)->insertOrIgnore($summary) === 0) {
            return;
        }

        $this->compact($tx, $uuid, $at);
    }

    /**
     * olderCycle (evidence after the freeze): applies the outcome and
     * exception evidence of $o to the retained individual summary whose
     * cycle_gen is the observation's generation. True when the summary
     * changed, false for a retained summary that already holds the evidence
     * (a duplicate), and null when no retained individual summary has the
     * generation any more (a compaction between the caller's lookup and this
     * read): the caller counts that observation as unmatched.
     *
     * It locks the job row first, as every path that inserts history does
     * (History::insert()), so nativeRetry and its compaction cannot run
     * between this read and its writes.
     */
    public function applyLateEvidence(Connection $tx, string $uuid, Observation $o): ?bool
    {
        $n = $o->attempt;

        if ($o->generation === null || $n === null || ! OutcomeAccounting::isOutcome($o)) {
            return false;
        }

        $tx->table(JobStore::TABLE)->where('uuid', $uuid)->lockForUpdate()->first(['id']);

        $summary = $tx->table(self::TABLE)
            ->where('job_uuid', $uuid)
            ->where('cycle_gen', $o->generation)
            ->where('merged', false)
            ->lockForUpdate()
            ->first();

        if ($summary === null) {
            return null;
        }

        $cycle = (int) $summary->cycle;
        $set = [];
        /** @var list<string> $events the exception or timeout history row, written after the update */
        $events = [];

        if ($o->kind === Observation::EXCEPTION || $o->kind === Observation::TIMEOUT) {
            $timeout = $o->kind === Observation::TIMEOUT;
            $column = $timeout ? 'last_timeout_attempt' : 'last_exception_attempt';
            $counter = $timeout ? 'timeouts' : 'exceptions';

            if ($n > (int) $summary->{$column}) {
                $set[$counter] = (int) $summary->{$counter} + 1;
                $set[$column] = $n;
                $events[] = $timeout ? 'timeout' : 'exception';
            }
        }

        $observed = (int) $summary->observed_attempts;
        $late = (int) $summary->late_outcomes;
        $counted = OutcomeAccounting::count($observed, (int) $summary->last_outcome_attempt, $n);

        if ($counted['observed'] !== $observed) {
            $set['observed_attempts'] = $counted['observed'];
            $set['last_outcome_attempt'] = $counted['last'];
        }

        if ($counted['late'] && $this->history->insert($tx, $uuid, $cycle, $n, 'late_outcome', ['occurred_at' => Clock::toDecimal($o->at)], History::lateKey($cycle, $n)) !== null) {
            $set['late_outcomes'] = $late + 1;
        }

        if ($set === []) {
            return false;
        }

        // A frozen cycle has no running attempt (a = 0).
        $set['observed_gap'] = OutcomeAccounting::gap('failed', (int) $summary->attempts, $set['observed_attempts'] ?? $observed, $set['late_outcomes'] ?? $late, $counted['last']);

        $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->where('merged', false)->update($set);

        foreach ($events as $event) {
            $this->history->insert($tx, $uuid, $cycle, $n, $event, ['occurred_at' => Clock::toDecimal($o->at)] + ExceptionText::columns($o->exception), History::dedupeKey($event, $cycle, $n));
        }

        return true;
    }

    /**
     * olderCycle: the cycle of the retained individual summary whose cycle_gen is
     * $generation, or null.
     */
    public function knownOlderGeneration(Connection $tx, string $uuid, ?string $generation): ?int
    {
        if ($generation === null) {
            return null;
        }

        $cycle = $tx->table(self::TABLE)
            ->where('job_uuid', $uuid)
            ->where('cycle_gen', $generation)
            ->where('merged', false)
            ->value('cycle');

        return $cycle === null ? null : (int) $cycle;
    }

    /**
     * Merges the oldest individual summaries until 50 remain. After one
     * freeze that is exactly one merge, so each freeze does a constant
     * amount of work.
     */
    private function compact(Connection $tx, string $uuid, float $at): void
    {
        $individual = $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('merged', false)->count();

        if ($individual <= self::INDIVIDUAL) {
            return;
        }

        $oldest = $tx->table(self::TABLE)
            ->where('job_uuid', $uuid)
            ->where('merged', false)
            ->orderBy('cycle')
            ->limit($individual - self::INDIVIDUAL)
            ->lockForUpdate()
            ->get();

        $incidents = Container::getInstance()->make(IncidentCleanup::class);

        foreach ($oldest as $summary) {
            $this->merge($tx, $uuid, $summary);
            $incidents->mergedCycle($tx, $uuid, (int) $summary->cycle, $at);
        }
    }

    /**
     * @param  \stdClass  $summary
     */
    private function merge(Connection $tx, string $uuid, object $summary): void
    {
        $cycle = (int) $summary->cycle;
        $retry = $cycle > 1 ? 1 : 0;

        $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->where('merged', false)->delete();

        $merged = $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('merged', true)->lockForUpdate()->first();

        if ($merged === null) {
            $row = [
                'job_uuid' => $uuid,
                'cycle' => $cycle,
                'cycle_gen' => null,
                'occurrence_id' => null,
                'outcome' => null,
                'merged' => true,
                'first_cycle' => $cycle,
                'merged_cycles' => 1,
                'merged_retries' => $retry,
                'history_truncated' => (bool) $summary->history_truncated,
                'started_at' => self::time($summary->started_at),
                'started_source' => $summary->started_source,
                'finished_at' => self::time($summary->finished_at),
            ];

            foreach (self::SUMMED as $counter) {
                $row[$counter] = (int) $summary->{$counter};
            }

            $tx->table(self::TABLE)->insert($row);

            return;
        }

        $set = [
            'cycle' => $cycle,
            'merged_cycles' => (int) $merged->merged_cycles + 1,
            'merged_retries' => (int) $merged->merged_retries + $retry,
            'history_truncated' => (bool) $merged->history_truncated || (bool) $summary->history_truncated,
            'finished_at' => self::time($summary->finished_at),
        ];

        foreach (self::SUMMED as $counter) {
            $set[$counter] = (int) $merged->{$counter} + (int) $summary->{$counter};
        }

        $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('merged', true)->update($set);
    }

    /**
     * The effective limit of the failing attempt and its source: a non-null
     * payload value, else the worker evidence of that attempt, else unknown.
     * A failed row's worker columns are the failure's (fail writes them for a
     * failure in a worker and clears them for a failure outside a worker).
     *
     * @return array{0: ?int, 1: string}
     */
    private static function effective(mixed $payload, mixed $worker, mixed $evidenceAttempt): array
    {
        if (is_numeric($payload)) {
            return [(int) $payload, 'payload'];
        }

        if (is_numeric($worker) && is_numeric($evidenceAttempt)) {
            return [(int) $worker, 'worker'];
        }

        return [null, 'unknown'];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function time(mixed $value): ?string
    {
        return is_numeric($value) ? Clock::toDecimal((float) $value) : null;
    }
}
