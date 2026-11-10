<?php

namespace Justrau\Orrery\Retry;

/**
 * The result of ForgetService::forget().
 *
 * - deleted: the operation is done: the exact failed record (if any) and the
 *   mirror row are gone. Code row_moved: the record is gone, and the row had
 *   moved on (a native retry started a new cycle), so only the hold ended.
 * - mirror_pending: the record is deleted (or there is none), and the mirror
 *   delete did not commit; the operation stays claimed, and the runner
 *   finishes it. Code: mirror_error.
 * - not_eligible: nothing written. Codes: occurrence_mismatch, not_failed,
 *   operation_in_progress.
 * - failed: nothing deleted. Codes: claim_failed, provider_error (the
 *   delete transaction failed before its COMMIT), cancelled (the fence row
 *   of the delete was decided by another actor), deadline_passed.
 * - uncertain: the operation stays claimed, and the runner decides it.
 *   Codes: claim_uncertain, record_delete_uncertain (the COMMIT of the
 *   delete failed), target_changed (after the claim).
 * - not_confirmed: a bulk run's forget whose run has no free slot of its
 *   confirmed total left: nothing was claimed or written.
 * - refused: before any write. Codes: provider_read_only, ids_may_be_reused,
 *   occurrence_unprovable, target_changed, fence_missing,
 *   mirror_unreachable, record_unreachable; `message` holds the operator
 *   text of the read-only and unprovable refusals.

 */
final class ForgetResult
{
    public const DELETED = 'deleted';

    public const MIRROR_PENDING = 'mirror_pending';

    public const NOT_ELIGIBLE = 'not_eligible';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    public const REFUSED = 'refused';

    public const NOT_CONFIRMED = 'not_confirmed';

    public function __construct(
        public readonly string $result,
        public readonly ?string $code = null,
        public readonly ?string $operationId = null,
        public readonly ?int $occurrenceId = null,
        public readonly ?string $message = null,
    ) {
    }
}
