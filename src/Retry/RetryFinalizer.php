<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Justrau\Orrery\Lifecycle\CycleStart;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\FailedTags;
use Justrau\Orrery\Lifecycle\History;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Rules;
use Justrau\Orrery\Storage\Clock;

/**
 * The two mirror transactions of a retry operation that end it (retry steps 8 and 9.3),
 * each inside the caller's mirror package
 * transaction:
 *
 * - finalizeIn(): lock the row `WHERE uuid = ? AND status = 'retrying' AND
 *   operation_id = ?`; read the operation; freeze the ending cycle's
 *   summary; the row becomes `pending` in cycle k+1 (CycleStart::columns()
 *   with cycle_gen = the operation id, the first job id J and the
 *   operation's source), with the operation's payload, operation_id and the
 *   failed record's binding cleared; a `retried` history row (dedupe
 *   `retried:<k+1>`); the → done transition. The publisher, the runner and
 *   a worker's adoption (JobStore) call it.
 * - abortIn(): the row back to `failed` with the same occurrence, the hold
 *   cleared, `provider_id` = the restored record's new id when a restore
 *   happened, the failed tags written again, a `retry_aborted` history row,
 *   and aborting → aborted.
 *
 * Neither prepares a payload or runs application code.
 */
final class RetryFinalizer
{
    public function __construct(
        private readonly CycleSummaries $summaries,
        private readonly History $history,
        private readonly Operations $operations,
    ) {
    }

    /**
     * False when the row is not held by the operation, or the operation is
     * not claimed or published.
     */
    public function finalizeIn(Connection $tx, string $operationId, string $uuid, int $jobId, float $at): bool
    {
        $guard = static fn (Connection $tx) => $tx->table(JobStore::TABLE)
            ->where('uuid', $uuid)
            ->where('status', 'retrying')
            ->where('operation_id', $operationId);

        $locked = $guard($tx)->lockForUpdate()->first();

        if ($locked === null) {
            return false;
        }

        $operation = $this->operations->find($tx, $operationId, true);

        if ($operation === null || ! in_array($operation->state, [Operations::CLAIMED, Operations::PUBLISHED], true) || ! is_string($operation->payload)) {
            return false;
        }

        $this->summaries->freeze($tx, $locked, $at);

        $payload = $operation->payload;
        $data = json_decode($payload, true);
        $cycle = (int) $locked->cycle + 1;
        $time = Clock::toDecimal($at);
        $source = (string) $operation->source;

        $guard($tx)->update([
            'status' => 'pending',
            'job_id' => $jobId,
            'payload' => $payload,
            'payload_bytes' => strlen($payload),
            'available_at' => $time,
            'reserved_at' => null,
            'reserved_by' => null,
            'worker_tries' => null,
            'worker_timeout' => null,
            'worker_evidence_attempt' => null,
            'finished_at' => null,
            'retried_count' => (int) $locked->retried_count + 1,
            'retried_at' => $time,
            'operation_id' => null,
            // The binding belonged to the failure.
            'provider_id' => null,
            'provider_target' => null,
            'activity_at' => $time,
            'updated_at' => $time,
        ] + Rules::limitColumns(is_array($data) ? $data : []) + CycleStart::columns($cycle, $operationId, $jobId, $source, $at));

        $this->history->insert($tx, $uuid, $cycle, null, 'retried', ['occurred_at' => $time, 'source' => $source], "retried:{$cycle}");
        $this->operations->markDone($tx, $operationId, $jobId);

        return true;
    }

    /**
     * Step 9.3. True when this call ended the operation (aborting → aborted).
     */
    public function abortIn(Connection $tx, string $operationId, string $uuid, ?int $restoredId, float $at): bool
    {
        $time = Clock::toDecimal($at);
        $operation = $this->operations->find($tx, $operationId, true);

        if ($operation === null || $operation->state !== Operations::ABORTING) {
            return false;
        }

        $guard = static fn (Connection $tx) => $tx->table(JobStore::TABLE)
            ->where('uuid', $uuid)
            ->where('status', 'retrying')
            ->where('operation_id', $operationId);

        $locked = $guard($tx)->lockForUpdate()->first();

        if ($locked !== null) {
            $set = [
                'observed_gap' => new Expression('CASE WHEN observed_attempts + late_outcomes < attempts THEN attempts - (observed_attempts + late_outcomes) ELSE 0 END'),
                'status' => 'failed',
                'operation_id' => null,
                'updated_at' => $time,
            ];

            if ($restoredId !== null) {
                $set['provider_id'] = $restoredId;
            }

            $guard($tx)->update($set);

            $tags = $tx->table('orrery_job_tags')->where('job_uuid', $uuid)->pluck('tag')->map(static fn (mixed $tag): string => (string) $tag)->all();
            $finishedAt = is_numeric($locked->finished_at ?? null) ? Clock::toDecimal((float) $locked->finished_at) : $time;
            FailedTags::write($tx, $uuid, $tags, $finishedAt);

            $this->history->insert($tx, $uuid, (int) $locked->cycle, null, 'retry_aborted', [
                'occurred_at' => $time,
                'reason' => is_string($operation->error_code) ? mb_substr($operation->error_code, 0, 32) : null,
                'source' => (string) $operation->source,
            ], null);
        }

        return $this->operations->markAborted($tx, $operationId);
    }
}
