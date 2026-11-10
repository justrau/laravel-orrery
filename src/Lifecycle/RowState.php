<?php

namespace Justrau\Orrery\Lifecycle;

/**
 * The columns of one orrery_jobs row that the rules read. The
 * database drivers return integers and decimals as ints, floats or strings,
 * so fromRow() normalises them.
 */
final class RowState
{
    private function __construct(
        public readonly string $uuid,
        public readonly string $status,
        public readonly int $cycle,
        public readonly ?string $cycleGen,
        public readonly ?int $cycleFirstJobId,
        public readonly ?int $jobId,
        public readonly ?int $prevJobId,
        public readonly int $attempts,
        public readonly bool $jobIdUnresolved,
        public readonly int $lastExceptionAttempt,
        public readonly int $lastTimeoutAttempt,
        public readonly int $lastOutcomeAttempt,
        public readonly ?int $occurrenceId,
        public readonly ?string $batchId,
        public readonly ?string $reservedBy,
        public readonly ?int $workerEvidenceAttempt,
        public readonly int $exceptions,
        public readonly int $timeouts,
        public readonly int $releases,
        public readonly int $reclaims,
        public readonly ?float $reservedAt,
        public readonly ?float $unknownSince,
        public readonly string $name,
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $observedAttempts = 0,
        public readonly int $lateOutcomes = 0,
        public readonly int $retriedCount = 0,
        public readonly ?float $retryRequestedAt = null,
        public readonly ?float $failedAt = null,
        public readonly ?string $operationId = null,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $value = static fn (string $column): mixed => property_exists($row, $column) ? $row->{$column} : null;
        $int = static fn (string $column, int $default = 0): int => is_numeric($value($column)) ? (int) $value($column) : $default;
        $nullableInt = static fn (string $column): ?int => is_numeric($value($column)) ? (int) $value($column) : null;
        $nullableFloat = static fn (string $column): ?float => is_numeric($value($column)) ? (float) $value($column) : null;
        $nullableString = static fn (string $column): ?string => $value($column) === null ? null : (string) $value($column);

        return new self(
            uuid: (string) $value('uuid'),
            status: (string) $value('status'),
            cycle: $int('cycle', 1),
            cycleGen: $nullableString('cycle_gen'),
            cycleFirstJobId: $nullableInt('cycle_first_job_id'),
            jobId: $nullableInt('job_id'),
            prevJobId: $nullableInt('prev_job_id'),
            attempts: $int('attempts'),
            jobIdUnresolved: (bool) $value('job_id_unresolved'),
            lastExceptionAttempt: $int('last_exception_attempt'),
            lastTimeoutAttempt: $int('last_timeout_attempt'),
            lastOutcomeAttempt: $int('last_outcome_attempt'),
            occurrenceId: $nullableInt('occurrence_id'),
            batchId: $nullableString('batch_id'),
            reservedBy: $nullableString('reserved_by'),
            workerEvidenceAttempt: $nullableInt('worker_evidence_attempt'),
            exceptions: $int('exceptions'),
            timeouts: $int('timeouts'),
            releases: $int('releases'),
            reclaims: $int('reclaims'),
            reservedAt: $nullableFloat('reserved_at'),
            unknownSince: $nullableFloat('unknown_since'),
            name: (string) $value('name'),
            connection: (string) $value('connection'),
            queue: (string) $value('queue'),
            observedAttempts: $int('observed_attempts'),
            lateOutcomes: $int('late_outcomes'),
            retriedCount: $int('retried_count'),
            retryRequestedAt: $nullableFloat('retry_requested_at'),
            failedAt: $nullableFloat('failed_at'),
            operationId: $nullableString('operation_id'),
        );
    }
}
