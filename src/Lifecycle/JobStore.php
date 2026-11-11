<?php

namespace Justrau\Orrery\Lifecycle;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Retry\RetryFinalizer;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\EscapedValue;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Storage\TrustedSql;

/**
 * Applies the lifecycle rules (Rules; docs/architecture/lifecycle-rules.md)
 * to orrery_jobs.
 *
 * Write path:
 * - Each kind has one fast statement for its expected case: push and bulkPush
 *   are an insertOrIgnore, reserve and release (instrumented, with a known
 *   generation) are one conditional UPDATE. Every other case reads the row
 *   first.
 * - When the fast statement affects no row, the store reads the row, calls
 *   Rules::decide() and runs the decision's statement with the guard columns
 *   in WHERE. A guard miss repeats the read-decide-write, at most 3 times in
 *   total. Then it counts Degradation::dropped('store:<kind>').
 * - Mutation versus result: Laravel can repeat an autocommit statement
 *   after a lost connection, and the repeat affects no row. So a zero-row
 *   count only triggers the re-read. The result is a statement about the
 *   row's state, not about which call wrote it: StoreOutcome::$applied is
 *   true when this call's statement changed the row, or when, after a zero
 *   count, the re-read row already holds exactly this observation's result
 *   (Rules::holds()). A read that decide() would reject is also checked with
 *   holds(), on the row already read, so an ordinary duplicate on the
 *   read-decide-write path (uninstrumented, or no generation) gives the same
 *   result as a replay. Its rule is then Decision::HELD, unless a statement
 *   of this call missed first (then that statement's rule).
 * - No side effect depends on $applied: every side effect runs in the
 *   package transaction of its own write, where the count is reliable.
 * - Side effects (tag rows, failed tags, history, occurrence) run
 *   only when the transition changed a row, in one package transaction with
 *   it. An untagged insert without other effects, and a transition
 *   without effects, run in autocommit.
 * - The monitoring snapshot is taken before an insert transaction begins
 *   (the cache read order).
 *
 * Cycles and outcome accounting:
 * - Every statement that changes the inputs of observed_gap rewrites it, the
 *   fast reserve and release included (one statement each,
 *   OutcomeAccounting).
 * - olderCycle: before decide(), a row whose generation differs from a
 *   known instrumented generation is checked against the retained summaries
 *   (CycleSummaries::knownOlderGeneration()). An olderCycle outcome updates
 *   that summary in a package transaction
 *   (CycleSummaries::applyLateEvidence()).
 * - An unmatched decision counts Degradation::unmatched() and changes
 *   nothing.
 * - A decision with the `late` or `cycle_start` effect runs in a package
 *   transaction that first reads its row with the guard and a row lock. A
 *   late outcome inserts its `late:<cycle>:<n>` row, and only when that
 *   insert adds a row it counts late_outcomes and rewrites the gap.
 *   nativeRetry freezes the ending cycle's summary from the locked row,
 *   starts the new cycle, and deletes the row's failed tags, in the same
 *   transaction.
 *
 * Adoption (adoptRetryReservation,
 * adoptRetryCompletion and adoptRetryFailure): a decision with the `adopt`
 * effect runs one package transaction. It calls RetryFinalizer::finalizeIn()
 * with J = the observation's j (it locks the row that the operation holds and
 * ends the operation `done`), then reads the finalised row, decides the
 * observation again, and applies that transition (reserve, complete or fail)
 * in the same transaction. A finalisation that matches nothing (the publisher
 * or the resolution finalised first) writes nothing, and the store reads and
 * decides again.
 *
 * Every stored time comes from the observation's own time through
 * Clock::toDecimal(). The package tables are reached through
 * Container::getInstance()->make(PackageSession::class)->mirror(). The caller runs observe() inside the
 * watcher; the store rethrows errors so that the watcher counts them.
 */
final class JobStore
{
    public const TABLE = 'orrery_jobs';

    /** The hook point after the fallback read. */
    public const AFTER_READ = 'store.after_read';

    public const MAX_ATTEMPTS = 3;

    /** The hold point after the olderCycle lookup, before the transaction that applies the evidence to the summary. */
    public const BEFORE_OLDER_EVIDENCE = 'store.before_older_evidence';

    /**
     * The largest runtime_ms that every engine stores: Postgres keeps an
     * unsignedInteger column as a signed 32-bit integer, so a longer runtime
     * (about 24.9 days) is capped here instead of failing the completion.
     */
    public const MAX_RUNTIME_MS = 2_147_483_647;

    /** The exception columns that the `failed` history row copies from the failure's set. */
    private const EXCEPTION_COLUMNS = ['exception_class', 'exception_message', 'exception', 'exception_bytes', 'context', 'context_bytes'];

    private const ROW_COLUMNS = [
        'uuid', 'status', 'cycle', 'cycle_gen', 'cycle_first_job_id', 'job_id', 'prev_job_id', 'attempts',
        'job_id_unresolved', 'last_exception_attempt', 'last_timeout_attempt',
        'last_outcome_attempt', 'occurrence_id', 'batch_id', 'reserved_by', 'worker_evidence_attempt',
        'exceptions', 'timeouts', 'releases', 'reclaims', 'reserved_at', 'unknown_since', 'name',
        'connection', 'queue', 'observed_attempts', 'late_outcomes', 'retried_count', 'retry_requested_at', 'failed_at',
        'operation_id',
    ];

    public function __construct(
        private readonly PackageTransaction $transactions,
        private readonly MonitoredTagCache $monitoring,
        private readonly History $history,
        private readonly CycleSummaries $summaries,
        private readonly RetryFinalizer $operationFinalizer,
    ) {}

    /**
     * Applies one observation. The result's $applied is true when the row
     * holds this observation's transition after the call: a positive count
     * of this call's statement, or, after a zero count, a re-read row that
     * already holds exactly this observation's result. No caller may run a
     * side effect because of $applied; the store runs every side effect in
     * the package transaction of its write.
     */
    public function observe(Observation $o): StoreOutcome
    {
        // The rule of this call's last statement that changed no row.
        $missed = null;
        $fast = $this->fast($o, $missed);

        if ($fast !== null) {
            return $fast;
        }

        $decision = Decision::rejected();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $row = $this->read($o->uuid);
            Hooks::reach(self::AFTER_READ);

            if ($missed !== null && $row !== null && Rules::holds($row, $o)) {
                return new StoreOutcome($missed, true);
            }

            $decision = Rules::decide($row, $o, $this->olderCycle($row, $o));

            if ($decision->isRejected()) {
                // A duplicate of an applied observation finds its own result
                // here, on the row already read (no extra query): it reports
                // the same row state as a replay after a lost acknowledgement.
                if ($row !== null && Rules::holds($row, $o)) {
                    return new StoreOutcome($missed ?? Decision::HELD, true);
                }

                if ($decision->has('unmatched')) {
                    Degradation::unmatched();
                }

                return new StoreOutcome(Decision::REJECTED, false);
            }

            if ($decision->has('adopt')) {
                $adopted = $this->adopt($decision, $o);

                if ($adopted !== null) {
                    return $adopted;
                }

                // The claim changed before the finalisation: read and decide again.
                $missed = $decision->rule;

                continue;
            }

            if ($decision->rule === Decision::OLDER) {
                // olderCycle: the row does not change; the retained summary takes the evidence.
                Hooks::reach(self::BEFORE_OLDER_EVIDENCE);
                $applied = $this->transactions->run(Container::getInstance()->make(PackageSession::class)->mirror(), fn (Connection $tx): ?bool => $this->summaries->applyLateEvidence($tx, $o->uuid, $o));

                if ($applied === null) {
                    // A compaction merged the summary after the lookup: no retained summary knows the generation.
                    Degradation::unmatched();

                    return new StoreOutcome(Decision::REJECTED, false);
                }

                return new StoreOutcome(Decision::OLDER, false);
            }

            if ($decision->set === [] && $decision->effects === []) {
                // An exception of an attempt that is not higher, without accounting: nothing changes.
                return new StoreOutcome($decision->rule, false);
            }

            $outcome = $this->apply($decision, $o, $row, $missed);

            if ($outcome !== null) {
                return $outcome;
            }
        }

        // The last statement changed no row: one more read decides whether
        // the row holds this observation's result before the drop counts.
        $row = $this->read($o->uuid);

        if ($row !== null && Rules::holds($row, $o)) {
            return new StoreOutcome((string) $missed, true);
        }

        Degradation::dropped('store:'.$o->kind);

        return new StoreOutcome($decision->rule, false);
    }

    /**
     * olderCycle: the cycle of the retained individual summary whose
     * cycle_gen is the observation's generation. Read only for an existing
     * row of an instrumented connection, when both generations are known and
     * differ, and never for a push. So S observations (the hot path) never
     * read the summaries.
     */
    private function olderCycle(?RowState $row, Observation $o): ?int
    {
        if ($row === null
            || ! $o->instrumented
            || $o->generation === null
            || $row->cycleGen === null
            || $o->generation === $row->cycleGen
            || in_array($o->kind, [Observation::PUSH, Observation::REPUBLISH, Observation::BULK_PUSH], true)) {
            return null;
        }

        return $this->summaries->knownOlderGeneration(Container::getInstance()->make(PackageSession::class)->mirror(), $o->uuid, $o->generation);
    }

    /**
     * markUnknown (U1): marks the row `unknown` when it is still
     * `pending` or `reserved` with the inspected job id and cycle. True when
     * the row is unknown by this mark after the call: a positive count of
     * this call's statement, or a re-read row that is unknown with this job
     * id, cycle and time (a repeat after a lost acknowledgement, or the same
     * mark again). After the last zero count it reads once more before it
     * counts the drop.
     */
    public function markUnknown(string $uuid, int $jobId, int $cycle, string $reason, float $at): bool
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $row = $this->read($uuid);
            Hooks::reach(self::AFTER_READ);

            if ($row === null) {
                return false;
            }

            $decision = Rules::markUnknown($row, $jobId, $cycle, $reason, $at);

            if ($decision->isRejected()) {
                return $this->unknownByThisMark($row, $jobId, $cycle, $at);
            }

            if ($this->guarded(Container::getInstance()->make(PackageSession::class)->mirror(), $uuid, $decision->guard)->update($decision->set) > 0) {
                return true;
            }
        }

        $row = $this->read($uuid);

        if ($row !== null && $this->unknownByThisMark($row, $jobId, $cycle, $at)) {
            return true;
        }

        Degradation::dropped('store:unknown');

        return false;
    }

    private function unknownByThisMark(RowState $row, int $jobId, int $cycle, float $at): bool
    {
        return $row->status === 'unknown'
            && $row->jobId === $jobId
            && $row->cycle === $cycle
            && $row->unknownSince !== null
            && Clock::toDecimal($row->unknownSince) === Clock::toDecimal($at);
    }

    /**
     * JobRetryRequested: sets retry_requested_at only. It is not a
     * transition, so it keeps activity_at.
     */
    public function retryRequested(string $uuid, float $at): void
    {
        $time = Clock::toDecimal($at);

        Container::getInstance()->make(PackageSession::class)->mirror()->table(self::TABLE)
            ->where('uuid', $uuid)
            ->update(['retry_requested_at' => $time, 'updated_at' => $time]);
    }

    /**
     * The fast statement of the expected case, or null when it changed no
     * row (then $missed names its rule) or does not apply. The caller then
     * reads and decides.
     */
    private function fast(Observation $o, ?string &$missed): ?StoreOutcome
    {
        switch ($o->kind) {
            case Observation::PUSH:
            case Observation::REPUBLISH:
            case Observation::BULK_PUSH:
                // The expected case has no row: an insertOrIgnore.
                return $this->apply(Rules::decide(null, $o), $o, null, $missed);
            case Observation::RESERVE:
                return $this->counted('reserve', $this->fastReserve($o), $missed);
            case Observation::RELEASE:
                return $this->counted('release', $this->fastRelease($o), $missed);
        }

        return null;
    }

    /**
     * @param  int|null  $affected  null when the fast statement does not apply
     */
    private function counted(string $rule, ?int $affected, ?string &$missed): ?StoreOutcome
    {
        if ($affected === null) {
            return null;
        }

        if ($affected > 0) {
            return new StoreOutcome($rule, true);
        }

        $missed = $rule;

        return null;
    }

    /**
     * reserve of a `pending` row of the same generation: the affected rows, or
     * null when the fast statement does not apply.
     */
    private function fastReserve(Observation $o): ?int
    {
        if (! $o->instrumented || $o->generation === null || $o->jobId === null || $o->attempt === null) {
            return null;
        }

        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $time = Clock::toDecimal($o->at);

        $set = [
            // First: MySQL evaluates the SET list from left to right (OutcomeAccounting::gapExpression()).
            'observed_gap' => OutcomeAccounting::gapExpression($o->attempt),
            'status' => 'reserved',
            'job_id' => $o->jobId,
            'job_id_unresolved' => false,
            'attempts' => $o->attempt,
            'reserved_at' => Rules::reservedAt($o),
            'cycle_first_job_id' => TrustedSql::expression('COALESCE(cycle_first_job_id, %s)', $o->jobId),
            'activity_at' => $time,
            'updated_at' => $time,
        ] + Rules::reservationWorker($o) + $this->batchFill($connection, $o);

        return $connection->table(self::TABLE)
            ->where('uuid', $o->uuid)
            ->where('status', 'pending')
            ->where('cycle_gen', $o->generation)
            ->where(static function (Builder $query) use ($o): void {
                $query->where('job_id', $o->jobId)->orWhereNull('job_id');
            })
            ->update($set);
    }

    /**
     * release of the current reservation of the same generation: the affected
     * rows, or null when the fast statement does not apply.
     */
    private function fastRelease(Observation $o): ?int
    {
        if (! $o->instrumented || $o->generation === null || $o->jobId === null || $o->attempt === null) {
            return null;
        }

        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $time = Clock::toDecimal($o->at);

        $n = $o->attempt;

        // The release is the outcome of attempt n. A late outcome
        // (last_outcome_attempt > n) needs its history row, so it takes the
        // read-decide-write path. The order of the first three columns is the
        // evaluation order that MySQL needs (OutcomeAccounting::gapExpression()).
        return $connection->table(self::TABLE)
            ->where('uuid', $o->uuid)
            ->where('status', 'reserved')
            ->where('cycle_gen', $o->generation)
            ->where('job_id', $o->jobId)
            ->where('attempts', $n)
            ->where('last_outcome_attempt', '<=', $n)
            ->update([
                'observed_gap' => OutcomeAccounting::gapExpression($n),
                'observed_attempts' => TrustedSql::expression('CASE WHEN last_outcome_attempt < %s THEN observed_attempts + 1 ELSE observed_attempts END', $n),
                'last_outcome_attempt' => TrustedSql::expression('CASE WHEN last_outcome_attempt < %1$s THEN %1$s ELSE last_outcome_attempt END', $n),
                'status' => 'pending',
                'prev_job_id' => $o->jobId,
                'job_id' => $o->newJobId,
                // absent_since belongs to the previous job id, as in Rules::revision(), and so does its reason.
                'absent_since' => null,
                'unknown_reason' => null,
                'available_at' => Rules::releaseAvailableAt($o),
                'releases' => new Expression('releases + 1'),
                'activity_at' => $time,
                'updated_at' => $time,
            ] + $this->batchFill($connection, $o));
    }

    /**
     * @return array<string, Expression<literal-string>>
     */
    private function batchFill(Connection $connection, Observation $o): array
    {
        $batch = Rules::batchIdOf($o);

        // Only a plain id (Laravel's are UUIDs) is inlined: the statement's SQL text can reach a
        // watcher report, and any other value waits for a later read-decide-write, which binds it.
        if ($batch === null || preg_match('/^[A-Za-z0-9-]{1,64}$/D', $batch) !== 1) {
            return [];
        }

        return ['batch_id' => TrustedSql::expression('COALESCE(batch_id, %s)', EscapedValue::of($connection, $batch))];
    }

    /**
     * Runs a decision. Null when its statement changed no row (the guard
     * missed, or an insert found a row); then $missed names the rule, and the
     * caller reads again.
     */
    private function apply(Decision $decision, Observation $o, ?RowState $row, ?string &$missed): ?StoreOutcome
    {
        $set = self::withRuntime($decision, $o, $row, self::withFailureKey($this->withNewOccurrence($decision), $row));

        $tags = $decision->isInsert() ? Rules::tagsOf($o)['tags'] : [];

        if ($tags !== []) {
            // Before the insert transaction begins: two autocommit reads.
            $snapshot = $this->monitoring->snapshot();
            $set['monitored'] = array_intersect_key($snapshot['tags'], array_flip($tags)) !== [];
            $set['monitor_version'] = $snapshot['version'];
        }

        $effects = array_values(array_diff($decision->effects, ['fill_generation']));
        $connection = Container::getInstance()->make(PackageSession::class)->mirror();

        if ($effects === []) {
            $affected = $decision->isInsert()
                ? $connection->table(self::TABLE)->insertOrIgnore($set)
                : $this->guarded($connection, $o->uuid, $decision->guard)->update($set);

            if ($affected > 0) {
                // An accounting-only write changed counters, not the transition.
                return new StoreOutcome($decision->rule, $decision->rule !== Decision::ACCOUNTED);
            }

            $missed = $decision->rule;

            return null;
        }

        $outcome = $this->transactions->run($connection, fn (Connection $tx): ?StoreOutcome => $this->applyIn($tx, $decision, $o, $row, $set));

        if ($outcome === null) {
            $missed = $decision->rule;
        }

        return $outcome;
    }

    /**
     * The statement of a decision and its side effects, inside $tx. Null when
     * its statement changed no row.
     *
     * @param  array<string, mixed>  $set
     */
    private function applyIn(Connection $tx, Decision $decision, Observation $o, ?RowState $row, array $set): ?StoreOutcome
    {
        if ($row !== null && ($decision->has('late') || $decision->has('cycle_start'))) {
            return $this->applyLocked($tx, $decision, $o, $row, $set);
        }

        $affected = $decision->isInsert()
            ? $tx->table(self::TABLE)->insertOrIgnore($set)
            : $this->guarded($tx, $o->uuid, $decision->guard)->update($set);

        if ($affected === 0) {
            return null;
        }

        return new StoreOutcome($decision->rule, $decision->rule !== Decision::ACCOUNTED, $this->effects($tx, $decision, $o, $row, $set));
    }

    /**
     * Adoption: the retry finalisation with J = j, then the
     * observation's own transition, in one package transaction. Null when
     * the finalisation matched nothing.
     */
    private function adopt(Decision $decision, Observation $o): ?StoreOutcome
    {
        return $this->transactions->run(Container::getInstance()->make(PackageSession::class)->mirror(), function (Connection $tx) use ($decision, $o): ?StoreOutcome {
            // The finalisation of the operation that holds the row, with J = j.
            if (! $this->operationFinalizer->finalizeIn($tx, (string) $decision->guard['operation_id'], $o->uuid, (int) $o->jobId, $o->at)) {
                return null;
            }

            $read = $tx->table(self::TABLE)->where('uuid', $o->uuid)->lockForUpdate()->first(self::ROW_COLUMNS);
            $row = $read === null ? null : RowState::fromRow($read);
            $next = Rules::decide($row, $o);

            if ($row === null || $next->isRejected() || $next->has('adopt') || ($next->set === [] && $next->effects === [])) {
                // The finalisation is the adoption; the transition adds nothing.
                return new StoreOutcome($decision->rule, true);
            }

            $applied = $this->applyIn($tx, $next, $o, $row, self::withRuntime($next, $o, $row, self::withFailureKey($this->withNewOccurrence($next), $row)));

            return new StoreOutcome($decision->rule, true, $applied?->occurrenceId);
        });
    }

    /**
     * The set of a decision; fail and failWithoutRow start a new occurrence
     * without the bound record of an earlier one: provider_id and
     * provider_target stay null until the package's provider binds this
     * failure's record, never filled later from an older
     * failure.
     *
     * @return array<string, mixed>
     */
    private function withNewOccurrence(Decision $decision): array
    {
        $set = $decision->set;

        if (in_array($decision->rule, ['fail', 'failWithoutRow'], true)) {
            $set['provider_id'] = null;
            $set['provider_target'] = null;
        }

        return $set;
    }

    /**
     * The set with the failure group key. When the set
     * writes the exception columns, it also writes failure_key =
     * GroupKey::of(exception_class, exception_message, name) of the values
     * that this statement stores; the name comes from the set, else from the
     * row. It adds a column to the same statement, never a statement.
     *
     * A set that writes only one of the two exception columns, or a value
     * that is not text, gets a null key: the other stored value is unknown
     * here. Every failure writes both columns (fail, failWithoutRow), so
     * every failed row has its key, and the failure groups read only stored
     * keys.
     *
     * @param  array<string, mixed>  $set
     * @return array<string, mixed>
     */
    private static function withFailureKey(array $set, ?RowState $row): array
    {
        $hasClass = array_key_exists('exception_class', $set);
        $hasMessage = array_key_exists('exception_message', $set);

        if (! $hasClass && ! $hasMessage) {
            return $set;
        }

        $class = $set['exception_class'] ?? null;
        $message = $set['exception_message'] ?? null;
        $name = array_key_exists('name', $set) ? $set['name'] : $row?->name;

        $set['failure_key'] = $hasClass && $hasMessage
            && ($class === null || is_string($class))
            && ($message === null || is_string($message))
            && is_string($name)
                ? GroupKey::of($class, $message, $name)
                : null;

        return $set;
    }

    /**
     * The set with runtime_ms for a completion (complete,
     * completeWithoutRow): the runtime clock's milliseconds, else (at -
     * reserved_at) x 1000 from the row read (complete only, of a `reserved`
     * row at the completed attempt, and not at the start of a new cycle,
     * whose reservation was not observed: an earlier attempt's reserved_at
     * would inflate the runtime), else null:
     * an unknown runtime is never 0. It adds a column to the completion's
     * statement, never a statement.
     *
     * @param  array<string, mixed>  $set
     * @return array<string, mixed>
     */
    private static function withRuntime(Decision $decision, Observation $o, ?RowState $row, array $set): array
    {
        if (! in_array($decision->rule, ['complete', 'completeWithoutRow'], true) || ($set['status'] ?? null) !== 'completed') {
            return $set;
        }

        $runtime = RuntimeClock::elapsedMs($o->uuid);

        if ($runtime === null && $decision->rule === 'complete' && ! $decision->has('cycle_start') && $row?->reservedAt !== null
            && $row->status === 'reserved' && $row->attempts === $o->attempt) {
            $runtime = max(0.0, ($o->at - $row->reservedAt) * 1000);
        }

        $set['runtime_ms'] = $runtime === null ? null : (int) min(self::MAX_RUNTIME_MS, round($runtime));

        return $set;
    }

    /**
     * A decision whose write depends on a statement inside its transaction:
     * the late outcome row or the frozen summary of nativeRetry. The
     * guarded read locks the row first (on SQLite, BEGIN IMMEDIATE already
     * holds the write lock), so the update after it cannot miss. Null when
     * the guard missed: nothing was written, and the caller reads again.
     *
     * @param  array<string, mixed>  $set
     */
    private function applyLocked(Connection $tx, Decision $decision, Observation $o, RowState $row, array $set): ?StoreOutcome
    {
        $locked = $this->guarded($tx, $o->uuid, $decision->guard)->lockForUpdate()->first();

        if ($locked === null) {
            return null;
        }

        if ($decision->has('late') && $o->attempt !== null) {
            $added = $this->history->insert($tx, $o->uuid, $row->cycle, $o->attempt, 'late_outcome', ['occurred_at' => Clock::toDecimal($o->at)], History::lateKey($row->cycle, $o->attempt));

            if ($added !== null) {
                $set['late_outcomes'] = $row->lateOutcomes + 1;
                $set['observed_gap'] = OutcomeAccounting::gap(
                    (string) ($set['status'] ?? $row->status),
                    (int) ($set['attempts'] ?? $row->attempts),
                    (int) ($set['observed_attempts'] ?? $row->observedAttempts),
                    $row->lateOutcomes + 1,
                    (int) ($set['last_outcome_attempt'] ?? $row->lastOutcomeAttempt),
                );
            }
        }

        if ($decision->has('cycle_start')) {
            $this->summaries->freeze($tx, $locked, $o->at);
            $tx->table(FailedTags::TABLE)->where('job_uuid', $o->uuid)->delete();
        }

        if ($set === []) {
            // A late outcome whose row exists already: nothing is counted twice.
            return new StoreOutcome($decision->rule, false);
        }

        $this->guarded($tx, $o->uuid, $decision->guard)->update($set);

        return new StoreOutcome($decision->rule, $decision->rule !== Decision::ACCOUNTED, $this->effects($tx, $decision, $o, $row, $set));
    }

    /**
     * The side effects of a transition that changed its row, inside its
     * package transaction.
     *
     * @param  array<string, mixed>  $set
     * @return int|null the occurrence id of a failure
     */
    private function effects(Connection $tx, Decision $decision, Observation $o, ?RowState $row, array $set): ?int
    {
        $tags = Rules::tagsOf($o)['tags'];
        $cycle = (int) ($set['cycle'] ?? ($row === null ? 1 : $row->cycle));
        $occurrence = null;

        if ($decision->has('tags') && $tags !== []) {
            $tx->table('orrery_job_tags')->insertOrIgnore(array_map(
                static fn (string $tag): array => ['tag' => $tag, 'job_uuid' => $o->uuid, 'pushed_at' => $set['pushed_at']],
                $tags,
            ));
        }

        $event = $decision->historyEvent();

        $at = ['occurred_at' => Clock::toDecimal($o->at)];

        if ($event === 'exception' || $event === 'timeout') {
            // Only after the guarded update raised the high-water column.
            $this->history->insert($tx, $o->uuid, $cycle, $o->attempt, $event, $at + ExceptionText::columns($o->exception), History::dedupeKey($event, $cycle, (int) $o->attempt));
        } elseif ($event === 'failed') {
            $occurrence = (int) $this->history->insert($tx, $o->uuid, $cycle, $o->attempt, 'failed', $at + array_intersect_key($set, array_flip(self::EXCEPTION_COLUMNS)), null);

            $tx->table(self::TABLE)->where('uuid', $o->uuid)->update(['occurrence_id' => $occurrence]);
        } elseif ($event === 'completed') {
            $this->history->insert($tx, $o->uuid, $cycle, $o->attempt, 'completed', $at, null);
        }

        if ($decision->has('failed_tags')) {
            FailedTags::write($tx, $o->uuid, $tags, Clock::toDecimal($o->at));
        }

        return $occurrence;
    }

    /**
     * @param  array<string, mixed>  $guard
     */
    private function guarded(Connection $connection, string $uuid, array $guard): Builder
    {
        $query = $connection->table(self::TABLE)->where('uuid', $uuid);

        foreach ($guard as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    private function read(string $uuid): ?RowState
    {
        $row = Container::getInstance()->make(PackageSession::class)->mirror()->table(self::TABLE)->where('uuid', $uuid)->first(self::ROW_COLUMNS);

        return $row === null ? null : RowState::fromRow($row);
    }
}
