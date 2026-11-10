<?php

namespace Justrau\Orrery\Actions;

/**
 * The outcome of one clear of a queue (WaitingJobs::clear()).
 *
 * - `deleted`: the waiting jobs that the queue deleted.
 * - `mirrorRows`: the mirror rows deleted with them, `unresolvedRows` of
 *   them unresolved bulk rows (matched by uuid and generation).
 * - `undecodable`: payloads that could not be decoded, whose unresolved bulk
 *   rows, if any, stay.
 * - `countMismatches`: batches whose delete count differed from the lock.
 * - `mirrorFailed`: a mirror delete failed (reconciliation marks those rows
 *   unknown later).
 * - `complete`: every waiting job of the queue was handled; else `reason`
 *   says why it stopped: `probe_failed` (the unresolved bulk rows could not
 *   be checked, so that batch was kept), `time_budget`, or `write_failed`
 *   (a later batch failed after earlier ones committed; the counts are those
 *   of the committed batches, and the error is reported).
 */
final class ClearResult
{
    public const PROBE_FAILED = 'probe_failed';

    public const TIME_BUDGET = 'time_budget';

    public const WRITE_FAILED = 'write_failed';

    public function __construct(
        public readonly int $deleted,
        public readonly int $mirrorRows,
        public readonly int $unresolvedRows,
        public readonly int $undecodable,
        public readonly int $countMismatches,
        public readonly bool $mirrorFailed,
        public readonly bool $complete,
        public readonly ?string $reason,
    ) {}

    /**
     * @return array{deleted: int, mirror_rows: int, unresolved_rows: int, undecodable: int, count_mismatches: int, mirror_failed: bool, complete: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'deleted' => $this->deleted,
            'mirror_rows' => $this->mirrorRows,
            'unresolved_rows' => $this->unresolvedRows,
            'undecodable' => $this->undecodable,
            'count_mismatches' => $this->countMismatches,
            'mirror_failed' => $this->mirrorFailed,
            'complete' => $this->complete,
            'reason' => $this->reason,
        ];
    }
}
