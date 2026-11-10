<?php

namespace Justrau\Orrery\Lifecycle;

use Justrau\Orrery\Observation\TagNormalizer;
use Justrau\Orrery\Storage\Clock;

/**
 * The lifecycle rules. Each rule is a
 * method of this class, and Decision::$rule holds the name of the method
 * that decided. docs/architecture/lifecycle-rules.md gives each rule in one
 * line, with its old id (P1, R1 and so on). Every other combination is
 * rejected.
 *
 * - S (sameGeneration()): the observation belongs to the row's current
 *   cycle. Adoption (adoptRetryReservation, adoptRetryCompletion,
 *   adoptRetryFailure) is decided before S.
 * - olderCycle: g is the cycle_gen of a retained individual summary (the
 *   store passes that summary's cycle as $olderCycle). The row does not
 *   change. An outcome gives Decision::OLDER with the `summary` effect; a
 *   reservation is rejected.
 * - An observation of an existing row that is not S, not olderCycle and
 *   not a native retry is unmatched (Decision::unmatched()): it changes
 *   nothing and counts Degradation::unmatched(). Pushes are never unmatched
 *   (they never change an existing row). A reservation of a `failed` row
 *   that only the id fallback of S admits (a higher id at n >= 2,
 *   generations not compared) is unmatched too: the ambiguity of a
 *   native retry whose first reservation was missed.
 * - Outcome accounting runs for every outcome with S, before the
 *   rejection of its transition: a rejected transition whose accounting
 *   changes the row gives Decision::ACCOUNTED, which writes only the
 *   counters (and the `late` effect). observed_gap is written by every
 *   decision that changes its inputs (status, attempts and the counters),
 *   and the guard of every revision holds the three accounting columns.
 *
 * decide() is pure: it reads the row state and the observation, and it does
 * no I/O. Every stored time is formatted with Clock::toDecimal() from the
 * observation's own time. The rules that the doc lists under "Conventions"
 * (activity_at, duplicates of the current reservation, the cycle of a row
 * that a rule without a row creates, leaving `unknown`) hold for every
 * method.
 */
final class Rules
{
    public const MAX_TAGS = 50;

    private const ACTIVE = ['pending', 'reserved', 'unknown'];

    /**
     * @param  int|null  $olderCycle  olderCycle: the cycle of the retained individual summary whose cycle_gen is the observation's generation (the store reads it only for an instrumented connection when both generations are known and differ)
     */
    public static function decide(?RowState $row, Observation $o, ?int $olderCycle = null): Decision
    {
        return match ($o->kind) {
            Observation::PUSH, Observation::REPUBLISH => $row === null ? self::push($o) : Decision::rejected(),
            Observation::BULK_PUSH => $row === null ? self::bulkPush($o) : Decision::rejected(),
            Observation::RESERVE => $row === null ? self::reserveWithoutRow($o) : self::reservation($row, $o, $olderCycle),
            Observation::RELEASE, Observation::EXCEPTION, Observation::TIMEOUT, Observation::COMPLETE, Observation::FAIL => self::outcome($row, $o, $olderCycle),
            default => Decision::rejected(),
        };
    }

    /**
     * S: the observation belongs to the row's current cycle.
     */
    public static function sameGeneration(RowState $row, Observation $o): bool
    {
        if ($o->instrumented && $row->cycleGen !== null && $o->generation !== null) {
            return $o->generation === $row->cycleGen;
        }

        $j = $o->jobId;

        if ($j === null) {
            return false;
        }

        if ($j === $row->jobId || $j === $row->prevJobId) {
            return true;
        }

        return $j > max($row->jobId ?? 0, $row->cycleFirstJobId ?? 0) && ($o->attempt ?? 0) >= 2;
    }

    /**
     * Whether the row already holds exactly this observation's result: the
     * same generation, job id, attempt and status, and for a reservation the
     * same worker. The store asks it only after a statement of its own
     * changed no row (a lost acknowledgement that Laravel repeated, or a
     * duplicate observation). It covers the kinds that the store can write
     * in autocommit: pushes, reservations and releases.
     */
    public static function holds(RowState $row, Observation $o): bool
    {
        if ($o->generation !== null && $row->cycleGen !== $o->generation) {
            return false;
        }

        return match ($o->kind) {
            Observation::PUSH, Observation::REPUBLISH => $row->status === 'pending' && $o->jobId !== null && $row->jobId === $o->jobId,
            Observation::BULK_PUSH => $row->status === 'pending' && $row->jobIdUnresolved && $row->jobId === null,
            Observation::RESERVE => $row->status === 'reserved'
                && $o->jobId !== null
                && $row->jobId === $o->jobId
                && $row->attempts === $o->attempt
                && $row->reservedBy === self::reservationWorker($o)['reserved_by'],
            Observation::RELEASE => $row->status === 'pending'
                && $o->jobId !== null
                && $row->prevJobId === $o->jobId
                && $row->jobId === $o->newJobId
                && $row->attempts === $o->attempt,
            default => false,
        };
    }

    /**
     * markUnknown (U1): reconciliation found job $jobId of cycle $cycle
     * absent from the jobs table. Only a `pending` or `reserved` row
     * of that resolved job id and cycle becomes `unknown`.
     */
    public static function markUnknown(RowState $row, int $jobId, int $cycle, string $reason, float $at): Decision
    {
        if (! in_array($row->status, ['pending', 'reserved'], true)
            || $row->jobId !== $jobId
            || $row->cycle !== $cycle
            || $row->jobIdUnresolved) {
            return Decision::rejected();
        }

        $time = Clock::toDecimal($at);

        return new Decision(
            __FUNCTION__,
            [
                'status' => 'unknown',
                'finished_at' => $time,
                'unknown_since' => $time,
                'unknown_reason' => mb_substr($reason, 0, 32),
                'activity_at' => $time,
                'updated_at' => $time,
                // The running attempt ended without an observed outcome.
                'observed_gap' => OutcomeAccounting::gap('unknown', $row->attempts, $row->observedAttempts, $row->lateOutcomes, $row->lastOutcomeAttempt),
            ],
            // attempts too: the gap above is computed from it.
            ['status' => $row->status, 'job_id' => $jobId, 'cycle' => $cycle, 'job_id_unresolved' => false, 'attempts' => $row->attempts] + self::accountingGuard($row),
        );
    }

    /**
     * The payload's tags, normalised by TagNormalizer (at most 50 unique
     * tags), and its truncation flag.
     *
     * @return array{tags: list<string>, truncated: bool}
     */
    public static function tagsOf(Observation $o): array
    {
        $data = $o->payloadData();
        // The classifier's normalisation again: a raw payload (pushRaw(), another producer) never passed it,
        // and on a classified list it changes nothing (the normalizer is idempotent).
        $tags = TagNormalizer::normalizeList(is_array($data['tags'] ?? null) ? $data['tags'] : [], self::MAX_TAGS);

        return [
            'tags' => $tags['tags'],
            'truncated' => ($data['tagsTruncated'] ?? false) === true || $tags['truncated'],
        ];
    }

    /**
     * The payload's data.batchId, cut to the column.
     */
    public static function batchIdOf(Observation $o): ?string
    {
        $batch = $o->payloadData()['data']['batchId'] ?? null;

        return is_string($batch) && $batch !== '' ? mb_substr($batch, 0, 255) : null;
    }

    /**
     * The worker columns of a reservation: W and W's evidence for attempt n
     * when the swapped pop() hook verified it, else none.
     *
     * @return array{reserved_by: ?string, worker_tries: ?int, worker_timeout: ?int, worker_evidence_attempt: ?int}
     */
    public static function reservationWorker(Observation $o): array
    {
        if (! $o->isVerifiedReservation()) {
            return ['reserved_by' => null, 'worker_tries' => null, 'worker_timeout' => null, 'worker_evidence_attempt' => null];
        }

        return [
            'reserved_by' => mb_substr((string) $o->workerId, 0, 255),
            'worker_tries' => $o->worker?->tries,
            'worker_timeout' => $o->worker?->timeout,
            'worker_evidence_attempt' => $o->worker === null ? null : $o->attempt,
        ];
    }

    /**
     * The reserved_at of a reservation: the observation time.
     */
    public static function reservedAt(Observation $o): string
    {
        return Clock::toDecimal($o->at);
    }

    /**
     * The available_at of a release: at + delay, else null.
     */
    public static function releaseAvailableAt(Observation $o): ?string
    {
        return $o->delay === null ? null : Clock::toDecimal($o->at + $o->delay);
    }

    /**
     * A reservation of an existing row: adoption first, then S, olderCycle and
     * the native retry, then one of the reservation rules of the row's status.
     */
    private static function reservation(RowState $row, Observation $o, ?int $olderCycle): Decision
    {
        $j = $o->jobId;
        $n = $o->attempt;

        if ($j === null || $n === null) {
            return Decision::rejected();
        }

        $adoption = self::adoptRetryReservation($row, $o);

        if ($adoption !== null) {
            return $adoption;
        }

        if (! self::sameGeneration($row, $o)) {
            if (self::isOlder($o, $olderCycle)) {
                // olderCycle: a reservation is not outcome evidence, and the row keeps its cycle.
                return Decision::rejected();
            }

            return self::nativeRetry($row, $o) ?? Decision::unmatched();
        }

        if ($row->status === 'failed' && ! self::generationsCompared($row, $o) && $j !== $row->jobId && $j !== $row->prevJobId) {
            // The ambiguity of a native retry: a higher id at n >= 2 on a failed
            // row is a native retry whose first reservation was missed.
            return Decision::unmatched();
        }

        $current = $row->jobId === $j && $row->attempts === $n;

        if ($row->status === 'reserved' && $current) {
            // Every observation of the current reservation is a duplicate,
            // except a verified worker for a reservation without one.
            return $o->isVerifiedReservation() && $row->reservedBy === null ? self::fillWorker($row, $o) : Decision::rejected();
        }

        if ($row->status === 'unknown' && $current) {
            return Decision::rejected();
        }

        return match (true) {
            $row->status === 'pending' && ($row->jobId === $j || $row->jobId === null) => self::reserve($row, $o, $j, $n),
            $row->status === 'reserved' && $row->jobId === $j && $n > $row->attempts => self::reclaim($row, $o, $j, $n),
            in_array($row->status, self::ACTIVE, true) && $row->jobId !== $j && $n >= $row->attempts => self::reserveReplacement($row, $o, $j, $n),
            $row->status === 'unknown' && $row->jobId === $j && $n >= $row->attempts => self::reserveUnknown($row, $o, $j, $n),
            default => Decision::rejected(),
        };
    }

    /**
     * reserve (R1): the first reservation of a `pending` row, or of its
     * unresolved bulk row.
     */
    private static function reserve(RowState $row, Observation $o, int $j, int $n): Decision
    {
        return self::reservationRevision(__FUNCTION__, $row, $o, $j, $n, []);
    }

    /**
     * reclaim (R2): a `reserved` row reserved again at a higher attempt (its
     * lease expired). It counts a reclaim.
     */
    private static function reclaim(RowState $row, Observation $o, int $j, int $n): Decision
    {
        return self::reservationRevision(__FUNCTION__, $row, $o, $j, $n, ['reclaims' => $row->reclaims + 1]);
    }

    /**
     * reserveReplacement (R3): an active row reserved under another job id
     * (the row of a release), not at a lower attempt. The old id becomes
     * prev_job_id.
     */
    private static function reserveReplacement(RowState $row, Observation $o, int $j, int $n): Decision
    {
        return self::reservationRevision(__FUNCTION__, $row, $o, $j, $n, ['prev_job_id' => $row->jobId]);
    }

    /**
     * reserveUnknown (R6): an `unknown` row of the same job id reserved
     * again. The row leaves `unknown`.
     */
    private static function reserveUnknown(RowState $row, Observation $o, int $j, int $n): Decision
    {
        return self::reservationRevision(__FUNCTION__, $row, $o, $j, $n, []);
    }

    /**
     * fillWorker (R8): a verified worker fills the worker of a reservation
     * that only a committed read confirmed. It also fills a missing
     * generation and a null batch id. No counter, reserved_at or
     * activity_at changes.
     */
    private static function fillWorker(RowState $row, Observation $o): Decision
    {
        return self::revision(__FUNCTION__, $row, $o, self::reservationWorker($o) + ['updated_at' => Clock::toDecimal($o->at)]);
    }

    /**
     * The transition of reserve, reclaim, reserveReplacement and
     * reserveUnknown: the row becomes `reserved` for job $j at attempt $n.
     *
     * @param  array<string, mixed>  $extra  the rule's own columns
     */
    private static function reservationRevision(string $rule, RowState $row, Observation $o, int $j, int $n, array $extra): Decision
    {
        $set = [
            'status' => 'reserved',
            'job_id' => $j,
            'job_id_unresolved' => false,
            'attempts' => $n,
            'reserved_at' => self::reservedAt($o),
            'observed_gap' => OutcomeAccounting::gap('reserved', $n, $row->observedAttempts, $row->lateOutcomes, $row->lastOutcomeAttempt),
        ] + self::reservationWorker($o) + self::transition($o);

        if ($row->cycleFirstJobId === null) {
            $set['cycle_first_job_id'] = $j;
        }

        foreach ($extra as $column => $value) {
            $set[$column] = $value;
        }

        if ($row->status === 'unknown') {
            $set += ['unknown_since' => null, 'unknown_reason' => null, 'finished_at' => null];
        }

        return self::revision($rule, $row, $o, $set);
    }

    /**
     * reserveWithoutRow (R7): a reservation of a job without a row (its push
     * was not observed). It creates the row as `reserved`.
     */
    private static function reserveWithoutRow(Observation $o): Decision
    {
        return self::insert(__FUNCTION__, $o);
    }

    /**
     * push (P1): a push or a republish of a job without a row creates the
     * row as `pending`.
     */
    private static function push(Observation $o): Decision
    {
        return self::insert(__FUNCTION__, $o);
    }

    /**
     * bulkPush (P2): a job of a bulk insert without a row creates the row as
     * `pending`, with an unresolved job id and the bulk's floor id.
     */
    private static function bulkPush(Observation $o): Decision
    {
        return self::insert(__FUNCTION__, $o);
    }

    /**
     * nativeRetry (R5): an unobserved native retry of a failed row. `failed`,
     * n = 1, not S and not olderCycle (the caller checked both), j above
     * every id of the current cycle, and a known g on an instrumented
     * connection. It freezes the summary (the `cycle_start` effect), starts
     * the new cycle, and then reserves as reserve does. The new publication's
     * payload and limit columns replace the old ones, so the row follows the
     * queue row.
     */
    private static function nativeRetry(RowState $row, Observation $o): ?Decision
    {
        $j = $o->jobId;

        if ($row->status !== 'failed' || $j === null || $o->attempt !== 1) {
            return null;
        }

        if ($j <= max($row->jobId ?? 0, $row->cycleFirstJobId ?? 0)) {
            return null;
        }

        if ($o->instrumented && $o->generation === null) {
            return null;
        }

        $data = $o->payloadData();

        $set = [
            'status' => 'reserved',
            'job_id' => $j,
            'attempts' => 1,
            'reserved_at' => self::reservedAt($o),
            'finished_at' => null,
            'retried_count' => $row->retriedCount + 1,
            'retried_at' => Clock::toDecimal($o->at),
            'payload' => $o->payload,
            'payload_bytes' => strlen($o->payload),
        ] + self::limitColumns($data) + self::reservationWorker($o) + self::transition($o) + CycleStart::columns($row->cycle + 1, $o->generation, $j, 'native', $o->at);

        return self::revision(__FUNCTION__, $row, $o, $set, [], ['cycle_start']);
    }

    /**
     * The native retry at the outcome: a queue object that is not the
     * package's mirrors only outcomes, so nativeRetry never sees the first
     * reservation of a native `queue:retry`. A completion or a failure of an
     * uninstrumented connection, on a `failed` row whose native retry was
     * requested (retry_requested_at), of a higher job id than the row's at n
     * = 1, starts the next cycle as nativeRetry does, and then applies the
     * outcome as the first counted outcome of that cycle (named complete or
     * fail). The request must not be older than the row's failure
     * (retry_requested_at >= failed_at), so a request left from an earlier
     * cycle starts nothing. Its reservation was not observed, so reserved_at
     * is null (unknown), and the runtime fallback of runtime_ms does not use
     * the old cycle's reservation.
     */
    private static function nativeRetryOutcome(RowState $row, Observation $o): ?Decision
    {
        $j = $o->jobId;
        $complete = $o->kind === Observation::COMPLETE;

        if ($o->instrumented
            || ! in_array($o->kind, [Observation::COMPLETE, Observation::FAIL], true)
            || $row->status !== 'failed'
            || $row->retryRequestedAt === null
            || $row->failedAt === null
            || $row->retryRequestedAt < $row->failedAt
            || $j === null
            || $o->attempt !== 1
            || $j <= max($row->jobId ?? 0, $row->cycleFirstJobId ?? 0)) {
            return null;
        }

        $time = Clock::toDecimal($o->at);
        $status = $complete ? 'completed' : 'failed';

        $set = [
            'status' => $status,
            'job_id' => $j,
            'attempts' => 1,
            'reserved_at' => null,
            'finished_at' => $time,
            'retried_count' => $row->retriedCount + 1,
            'retried_at' => $time,
            'payload' => $o->payload,
            'payload_bytes' => strlen($o->payload),
        ] + self::limitColumns($o->payloadData()) + self::transition($o) + CycleStart::columns($row->cycle + 1, $o->generation, $j, 'native', $o->at);

        // The outcome of attempt 1 is the first counted outcome of the new cycle.
        $set['observed_attempts'] = 1;
        $set['last_outcome_attempt'] = 1;
        $set['observed_gap'] = OutcomeAccounting::gap($status, 1, 1, 0, 1);

        if ($complete) {
            return self::revision('complete', $row, $o, $set, [], ['cycle_start', 'history:completed']);
        }

        $set = ['failed_at' => $time] + ExceptionText::columns($o->exception) + self::failureWorker($o) + $set;

        return self::revision('fail', $row, $o, $set, [], array_merge(['cycle_start'], self::failureEffects($o)));
    }

    /**
     * The outcome kinds: the rules without a row, adoption, olderCycle and
     * unmatched first, then the outcome's transition, then the outcome
     * accounting.
     */
    private static function outcome(?RowState $row, Observation $o, ?int $olderCycle): Decision
    {
        if ($row === null) {
            return match ($o->kind) {
                Observation::COMPLETE => self::completeWithoutRow($o),
                Observation::FAIL => self::failWithoutRow($o),
                default => Decision::rejected(),
            };
        }

        if ($o->kind === Observation::COMPLETE || $o->kind === Observation::FAIL) {
            $adoption = $o->kind === Observation::COMPLETE ? self::adoptRetryCompletion($row, $o) : self::adoptRetryFailure($row, $o);

            if ($adoption !== null) {
                return $adoption;
            }
        }

        if (! self::sameGeneration($row, $o)) {
            if (self::isOlder($o, $olderCycle)) {
                return self::olderCycle();
            }

            return self::nativeRetryOutcome($row, $o) ?? Decision::unmatched();
        }

        $decision = match ($o->kind) {
            Observation::RELEASE => self::release($row, $o),
            Observation::EXCEPTION, Observation::TIMEOUT => self::exception($row, $o),
            Observation::COMPLETE => self::complete($row, $o),
            default => self::fail($row, $o),
        };

        return self::account($row, $o, $decision);
    }

    /**
     * Adds the outcome accounting to an outcome's decision. A
     * rejected transition (or an exception of an attempt that is not higher)
     * whose accounting changes the row becomes Decision::ACCOUNTED, which
     * writes only the counters. The `late` effect makes the store insert the
     * `late:<cycle>:<n>` row and count it only when the insert adds a row.
     */
    private static function account(RowState $row, Observation $o, Decision $decision): Decision
    {
        $n = $o->attempt;

        if ($n === null) {
            return $decision;
        }

        $counted = OutcomeAccounting::count($row->observedAttempts, $row->lastOutcomeAttempt, $n);
        $set = $decision->set;
        $effects = $decision->effects;
        $transition = ! $decision->isRejected() && $decision->set !== [];

        if ($counted['observed'] !== $row->observedAttempts) {
            $set['observed_attempts'] = $counted['observed'];
            $set['last_outcome_attempt'] = $counted['last'];
        }

        if ($counted['late']) {
            $effects[] = 'late';
        }

        if ($set === [] && ! $counted['late']) {
            return $decision;
        }

        if ($set !== []) {
            $set['observed_gap'] = OutcomeAccounting::gap(
                (string) ($set['status'] ?? $row->status),
                (int) ($set['attempts'] ?? $row->attempts),
                (int) ($set['observed_attempts'] ?? $row->observedAttempts),
                $row->lateOutcomes,
                (int) ($set['last_outcome_attempt'] ?? $row->lastOutcomeAttempt),
            );
        }

        if ($transition) {
            return new Decision($decision->rule, $set, $decision->guard, $effects);
        }

        return new Decision(Decision::ACCOUNTED, $set, self::guard($row), $effects);
    }

    /**
     * adoptRetryReservation (R4): the reservation of a package retry's
     * publication adopts the retry (see adoption()).
     */
    private static function adoptRetryReservation(RowState $row, Observation $o): ?Decision
    {
        return self::adoption(__FUNCTION__, $row, $o);
    }

    /**
     * adoptRetryCompletion (C2): the completion of a package retry's
     * publication whose reservation was not observed adopts the retry.
     */
    private static function adoptRetryCompletion(RowState $row, Observation $o): ?Decision
    {
        return self::adoption(__FUNCTION__, $row, $o);
    }

    /**
     * adoptRetryFailure (the adoption case of F1): the failure of a package
     * retry's publication whose reservation was not observed adopts the
     * retry.
     */
    private static function adoptRetryFailure(RowState $row, Observation $o): ?Decision
    {
        return self::adoption(__FUNCTION__, $row, $o);
    }

    /**
     * olderCycle (O): an outcome of a known older generation, whose cycle
     * has a retained individual summary. The row does not change; the
     * summary takes the evidence (the `summary` effect).
     */
    private static function olderCycle(): Decision
    {
        return new Decision(Decision::OLDER, [], [], ['summary']);
    }

    /**
     * Adoption: the row is `retrying`,
     * and the observation's generation is the operation_id that holds it (a
     * package retry's orreryGen is its operation id). It is decided before
     * S, because that generation differs from the row's cycle_gen. The
     * decision has the `adopt` effect: the store runs the retry
     * finalisation with J = j in one package transaction, then decides the
     * observation again on the finalised row and applies that transition in
     * the same transaction. The guard holds the operation; the finalisation
     * checks the operation's state itself.
     */
    private static function adoption(string $rule, RowState $row, Observation $o): ?Decision
    {
        if ($row->status !== 'retrying'
            || $row->operationId === null
            || $o->generation === null
            || $o->jobId === null
            || ! hash_equals($row->operationId, $o->generation)) {
            return null;
        }

        return new Decision($rule, [], ['status' => 'retrying', 'operation_id' => $row->operationId], ['adopt']);
    }

    /**
     * The limit columns of a payload: every statement that writes the
     * payload writes them from the same payload.
     *
     * @param  array<string, mixed>  $data
     * @return array{max_tries: ?int, retry_until: ?int, max_exceptions: ?int, timeout: ?int, fail_on_timeout: bool}
     */
    public static function limitColumns(array $data): array
    {
        return [
            'max_tries' => self::limit($data['maxTries'] ?? null),
            'retry_until' => self::limit($data['retryUntil'] ?? null, PHP_INT_MAX),
            'max_exceptions' => self::limit($data['maxExceptions'] ?? null),
            'timeout' => self::limit($data['timeout'] ?? null),
            'fail_on_timeout' => ($data['failOnTimeout'] ?? false) === true,
        ];
    }

    /**
     * olderCycle applies only to an instrumented connection and a known
     * generation.
     */
    private static function isOlder(Observation $o, ?int $olderCycle): bool
    {
        return $olderCycle !== null && $o->instrumented && $o->generation !== null;
    }

    /**
     * Whether S compares generations (instrumented, both known) instead of
     * ids.
     */
    private static function generationsCompared(RowState $row, Observation $o): bool
    {
        return $o->instrumented && $row->cycleGen !== null && $o->generation !== null;
    }

    /**
     * release (L1): the current reservation released. The row becomes
     * `pending` under the new job id, and the old one becomes prev_job_id.
     * Also a release at a higher attempt of the reserved job id (its reclaim
     * was not observed), and a release of a `pending` row's job id (its
     * reservation was not observed): the job id that Laravel deleted must
     * not stay on the row. A reserved row's attempts rise to
     * the release's attempt, as for a completion; a pending row's attempts
     * keep counting the observed reservations only.
     */
    private static function release(RowState $row, Observation $o): Decision
    {
        if ($o->jobId === null
            || $o->attempt === null
            || ! self::sameGeneration($row, $o)
            || $row->jobId !== $o->jobId
            || ! (($row->status === 'reserved' && $row->attempts <= $o->attempt) || $row->status === 'pending')) {
            return Decision::rejected();
        }

        return self::revision(__FUNCTION__, $row, $o, [
            'status' => 'pending',
            'prev_job_id' => $o->jobId,
            'job_id' => $o->newJobId,
            'available_at' => self::releaseAvailableAt($o),
            'releases' => $row->releases + 1,
        ] + ($row->status === 'reserved' && $o->attempt > $row->attempts ? ['attempts' => $o->attempt] : []) + self::transition($o));
    }

    /**
     * exception (E1): an exception or a timeout of the row's current or
     * previous job id. Only a higher attempt counts it and adds its history
     * row; the status does not change.
     */
    private static function exception(RowState $row, Observation $o): Decision
    {
        if ($o->jobId === null
            || $o->attempt === null
            || ! self::sameGeneration($row, $o)
            || ! in_array($o->jobId, [$row->jobId, $row->prevJobId], true)) {
            return Decision::rejected();
        }

        $timeout = $o->kind === Observation::TIMEOUT;
        $high = $timeout ? $row->lastTimeoutAttempt : $row->lastExceptionAttempt;

        if ($o->attempt <= $high) {
            // Not a higher attempt: no counter changes and no row is added.
            return new Decision(__FUNCTION__);
        }

        $counter = $timeout ? 'timeouts' : 'exceptions';
        $column = $timeout ? 'last_timeout_attempt' : 'last_exception_attempt';

        return self::revision(__FUNCTION__, $row, $o, [
            $counter => ($timeout ? $row->timeouts : $row->exceptions) + 1,
            $column => $o->attempt,
            'updated_at' => Clock::toDecimal($o->at),
        ], [$column => $high], ['history:'.($timeout ? 'timeout' : 'exception')]);
    }

    /**
     * complete (C1): a completion of the row's current cycle (the outcome
     * predicates of outcomeAllowed()).
     */
    private static function complete(RowState $row, Observation $o): Decision
    {
        if (! self::outcomeAllowed($row, $o)) {
            return Decision::rejected();
        }

        $time = Clock::toDecimal($o->at);
        // No metric effect: the tick counts the minutes from the rows.
        $effects = [];

        if ($row->cycle > 1) {
            $effects[] = 'history:completed';
        }

        return self::revision(__FUNCTION__, $row, $o, ['status' => 'completed', 'finished_at' => $time] + self::outcomeAttempts($row, $o) + self::leaveUnknown($row) + self::transition($o), [], $effects);
    }

    /**
     * fail (F1): a failure of the row's current cycle (the outcome
     * predicates of outcomeAllowed()). It starts a new occurrence.
     */
    private static function fail(RowState $row, Observation $o): Decision
    {
        if (! self::outcomeAllowed($row, $o)) {
            return Decision::rejected();
        }

        $time = Clock::toDecimal($o->at);

        return self::revision(__FUNCTION__, $row, $o, [
            'status' => 'failed',
            'finished_at' => $time,
            'failed_at' => $time,
            // A new failure starts the absence check of its provider record.
            'absent_since' => null,
        ] + ExceptionText::columns($o->exception) + self::failureWorker($o) + self::outcomeAttempts($row, $o) + self::leaveUnknown($row) + self::transition($o), [], self::failureEffects($o));
    }

    /**
     * The attempts of an outcome of a queue object that is not the package's:
     * its reservations are not observed, so the outcome's attempt raises the
     * attempts. On an instrumented connection the reservation set them,
     * unless the outcome is of the reserved job id at a higher attempt (a
     * reclaim that was not observed).
     *
     * @return array{attempts?: int}
     */
    private static function outcomeAttempts(RowState $row, Observation $o): array
    {
        if ($o->attempt === null) {
            return [];
        }

        if ($o->instrumented) {
            // Only a higher attempt of the reserved job id: the reservation of the rows of a
            // release (another job id) or of an adopted retry counts on its own.
            return $row->status === 'reserved' && $row->jobId === $o->jobId && $o->attempt > $row->attempts ? ['attempts' => $o->attempt] : [];
        }

        return ['attempts' => max($row->attempts, $o->attempt)];
    }

    /**
     * The predicates of complete and fail.
     */
    private static function outcomeAllowed(RowState $row, Observation $o): bool
    {
        $j = $o->jobId;
        $n = $o->attempt;

        if ($j === null || $n === null || ! self::sameGeneration($row, $o)) {
            return false;
        }

        // A higher attempt of the reserved job id: its reclaim was not observed.
        return ($row->status === 'reserved' && $row->jobId === $j && $row->attempts <= $n)
            || (in_array($row->status, ['pending', 'unknown'], true) && $row->jobId === $j)
            || (in_array($row->status, self::ACTIVE, true) && $row->jobId !== $j && $n >= $row->attempts);
    }

    /**
     * completeWithoutRow (C3): a completion of a job without a row creates
     * the row as `completed`.
     */
    private static function completeWithoutRow(Observation $o): Decision
    {
        return self::insert(__FUNCTION__, $o);
    }

    /**
     * failWithoutRow (F1 without a row): a failure of a job without a row
     * creates the row as `failed`, with a new occurrence.
     */
    private static function failWithoutRow(Observation $o): Decision
    {
        return self::insert(__FUNCTION__, $o);
    }

    /**
     * A row-creation rule: push, bulkPush, reserveWithoutRow,
     * completeWithoutRow or failWithoutRow. The store adds `monitored` and
     * `monitor_version` from the monitoring cache.
     */
    private static function insert(string $rule, Observation $o): Decision
    {
        $data = $o->payloadData();
        $tags = self::tagsOf($o);
        $time = Clock::toDecimal($o->at);
        $bulk = $rule === 'bulkPush';
        $push = $bulk || $rule === 'push';
        $pushedAt = self::pushedAt($data, $o->at);

        $cycle = 1;
        $inferred = false;

        if (! $push && $o->isOperationRetry()) {
            // OrreryRetry.cycle only when orreryRetry.operation is the observation's own generation.
            $cycle = $o->retryCycle ?? 1;
            $inferred = true;
        }

        $set = [
            'uuid' => $o->uuid,
            'cycle' => $cycle,
            'cycle_gen' => $o->generation,
            'cycle_first_job_id' => $bulk ? null : $o->jobId,
            'cycle_started_at' => $push ? $pushedAt : null,
            'cycle_source' => $push ? 'push' : 'unknown',
            'job_id' => $bulk ? null : $o->jobId,
            'job_id_unresolved' => $bulk,
            'bulk_floor_id' => $bulk ? $o->bulkFloor : null,
            'batch_id' => self::batchIdOf($o),
            'connection' => mb_substr($o->connection, 0, 191),
            'queue' => mb_substr($o->queue, 0, 255),
            'name' => self::name($data),
            'type' => self::type($data),
            'silenced' => ($data['silenced'] ?? false) === true,
            'monitored' => false,
            'monitor_version' => null,
            'tags_truncated' => $tags['truncated'],
            'payload' => $o->payload,
            'payload_bytes' => strlen($o->payload),
            'max_tries' => self::limit($data['maxTries'] ?? null),
            'retry_until' => self::limit($data['retryUntil'] ?? null, PHP_INT_MAX),
            'max_exceptions' => self::limit($data['maxExceptions'] ?? null),
            'timeout' => self::limit($data['timeout'] ?? null),
            'fail_on_timeout' => ($data['failOnTimeout'] ?? false) === true,
            'attempts' => 0,
            'retried_count' => $cycle - 1,
            'retried_count_inferred' => $inferred,
            'pushed_at' => $pushedAt,
            'activity_at' => $time,
            'updated_at' => $time,
        ];

        $effects = $tags['tags'] === [] ? [] : ['tags'];

        $n = max(0, $o->attempt ?? 0);

        if (in_array($rule, ['completeWithoutRow', 'failWithoutRow'], true) && $n > 0) {
            // The outcome of attempt n is the first counted outcome.
            $set['observed_attempts'] = 1;
            $set['last_outcome_attempt'] = $n;
            $set['observed_gap'] = OutcomeAccounting::gap($rule === 'completeWithoutRow' ? 'completed' : 'failed', $n, 1, 0, $n);
        } elseif ($rule === 'reserveWithoutRow') {
            $set['observed_gap'] = OutcomeAccounting::gap('reserved', $n, 0, 0, 0);
        }

        switch ($rule) {
            case 'push':
            case 'bulkPush':
                $set['status'] = 'pending';
                $set['available_at'] = Clock::toDecimal($o->at + ($o->delay ?? 0));
                break;
            case 'reserveWithoutRow':
                $set = [
                    'status' => 'reserved',
                    'attempts' => $o->attempt ?? 0,
                    'reserved_at' => self::reservedAt($o),
                ] + self::reservationWorker($o) + $set;
                break;
            case 'completeWithoutRow':
                $set = ['status' => 'completed', 'attempts' => $o->attempt ?? 0, 'finished_at' => $time] + $set;

                if ($cycle > 1) {
                    $effects[] = 'history:completed';
                }
                break;
            case 'failWithoutRow':
                $set = [
                    'status' => 'failed',
                    'attempts' => $o->attempt ?? 0,
                    'finished_at' => $time,
                    'failed_at' => $time,
                ] + ExceptionText::columns($o->exception) + self::failureWorker($o) + $set;
                $effects = array_merge($effects, self::failureEffects($o));
                break;
        }

        return new Decision($rule, $set, [], $effects);
    }

    /**
     * A transition of an existing row: fills a missing generation and a null
     * batch id, and adds the guard.
     *
     * @param  array<string, mixed>  $set
     * @param  array<string, mixed>  $guard  extra guard columns
     * @param  list<string>  $effects
     */
    private static function revision(string $rule, RowState $row, Observation $o, array $set, array $guard = [], array $effects = []): Decision
    {
        if ($row->cycleGen === null && $o->generation !== null) {
            $set['cycle_gen'] = $o->generation;
            $effects[] = 'fill_generation';
        }

        // absent_since belongs to the inspected job id: a transition to
        // another job id starts the absence check again.
        if (array_key_exists('job_id', $set) && $set['job_id'] !== $row->jobId) {
            $set['absent_since'] = null;
        }

        $batch = self::batchIdOf($o);

        if ($row->batchId === null && $batch !== null) {
            $set['batch_id'] = $batch;
        }

        return new Decision($rule, $set, self::guard($row) + $guard, $effects);
    }

    /**
     * The row columns that a decision depends on, re-checked in WHERE.
     *
     * @return array<string, mixed>
     */
    private static function guard(RowState $row): array
    {
        return [
            'status' => $row->status,
            'cycle' => $row->cycle,
            'cycle_gen' => $row->cycleGen,
            'job_id' => $row->jobId,
            'prev_job_id' => $row->prevJobId,
            'attempts' => $row->attempts,
            'reserved_by' => $row->reservedBy,
        ] + self::accountingGuard($row);
    }

    /**
     * The accounting columns that a decision computed its counters and its
     * gap from.
     *
     * @return array{observed_attempts: int, late_outcomes: int, last_outcome_attempt: int}
     */
    private static function accountingGuard(RowState $row): array
    {
        return [
            'observed_attempts' => $row->observedAttempts,
            'late_outcomes' => $row->lateOutcomes,
            'last_outcome_attempt' => $row->lastOutcomeAttempt,
        ];
    }

    /**
     * @return array{activity_at: string, updated_at: string}
     */
    private static function transition(Observation $o): array
    {
        $time = Clock::toDecimal($o->at);

        return ['activity_at' => $time, 'updated_at' => $time];
    }

    /**
     * @return array<string, null>
     */
    private static function leaveUnknown(RowState $row): array
    {
        return $row->status === 'unknown' ? ['unknown_since' => null, 'unknown_reason' => null] : [];
    }

    /**
     * A failure in a worker writes that worker's evidence for attempt n; a
     * failure outside a worker clears it.
     *
     * @return array{worker_tries: ?int, worker_timeout: ?int, worker_evidence_attempt: ?int}
     */
    private static function failureWorker(Observation $o): array
    {
        if (! $o->inWorker || $o->worker === null) {
            return ['worker_tries' => null, 'worker_timeout' => null, 'worker_evidence_attempt' => null];
        }

        return ['worker_tries' => $o->worker->tries, 'worker_timeout' => $o->worker->timeout, 'worker_evidence_attempt' => $o->attempt];
    }

    /**
     * @return list<string>
     */
    private static function failureEffects(Observation $o): array
    {
        $effects = ['history:failed', 'occurrence'];

        if (self::tagsOf($o)['tags'] !== []) {
            $effects[] = 'failed_tags';
        }

        return $effects;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function name(array $data): string
    {
        $name = $data['displayName'] ?? null;

        if (! is_string($name) || $name === '') {
            $job = $data['job'] ?? null;
            $name = is_string($job) && $job !== '' ? explode('@', $job, 2)[0] : '';
        }

        $name = mb_substr(mb_scrub($name, 'UTF-8'), 0, 255);

        return $name === '' ? 'unknown' : $name;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function type(array $data): string
    {
        $type = $data['type'] ?? null;

        return is_string($type) && $type !== '' ? mb_substr(mb_scrub($type, 'UTF-8'), 0, 32) : 'job';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function pushedAt(array $data, float $at): string
    {
        $pushedAt = $data['pushedAt'] ?? null;

        return is_numeric($pushedAt) && (float) $pushedAt > 0 ? Clock::toDecimal((float) $pushedAt) : Clock::toDecimal($at);
    }

    /**
     * A limit of the payload: a whole number of 0 or more, also as a string of
     * digits (Laravel compares it as a number), clamped to $max (the largest
     * value of a signed 32-bit column; retry_until is a bigint).
     */
    private static function limit(mixed $value, int $max = 2147483647): ?int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $digits = ltrim($value, '0');
            $value = strlen($digits) > 18 ? $max : (int) $value;
        }

        return is_int($value) && $value >= 0 ? min($value, $max) : null;
    }
}
