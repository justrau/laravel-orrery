<?php

namespace Justrau\Orrery\Retry;

/**
 * The result of RetryService::retry().
 *
 * - accepted: the publication committed (jobId and cycle name it), also
 *   when its finalisation failed (the runner finishes it).
 * - not_eligible: nothing changed: not_failed, occurrence_mismatch,
 *   operation_in_progress, record_missing.
 * - failed: nothing was published: prepare_failed, claim_failed,
 *   record_gone, record_delete_failed, cancelled, deadline_passed,
 *   publish_failed.
 * - uncertain: the operation waits for the runner or an operator:
 *   claim_uncertain, publish_uncertain, record_delete_uncertain,
 *   restore_failed, restore_conflict, target_changed (after the claim),
 *   awaiting_operator (the abort's next step needs an operator action on a
 *   host SQLite file; awaitingReason is sqlite_host_file or
 *   sqlite_reads_not_allowed, and the operation is marked awaiting).
 * - refused: before any write: provider_read_only, ids_may_be_reused,
 *   occurrence_unprovable, fence_missing, queue_unreachable,
 *   mirror_unreachable, record_unreachable, target_changed.
 *
 * operationId names the operation once it was claimed. message is the text
 * of a refusal, or of the awaiting state, that the operator reads.
 */
final class RetryResult
{
    public const ACCEPTED = 'accepted';

    public const NOT_ELIGIBLE = 'not_eligible';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    public const REFUSED = 'refused';

    public function __construct(
        public readonly string $result,
        public readonly ?string $code,
        public readonly ?string $operationId = null,
        public readonly ?int $jobId = null,
        public readonly ?int $cycle = null,
        public readonly ?string $message = null,
        public readonly ?string $awaitingReason = null,
    ) {
    }
}
