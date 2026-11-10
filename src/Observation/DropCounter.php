<?php

namespace Justrau\Orrery\Observation;

/**
 * Where CommitDeferral counts the observations that it drops (Phase 1b core
 * spec, C3). Two counters, so a business rollback does not look like data
 * loss:
 *
 * - unverified(): an observation that did not run and whose outcome the
 *   deferral cannot prove (a real drop; a later observation or
 *   reconciliation repairs the row).
 * - rollbackUnverified(): an observation under a root transaction that
 *   rolled back. Usually a business rollback after a push; a lost COMMIT
 *   acknowledgement cannot be ruled out.
 * - overBudget(): an observation that the deferral dropped because the
 *   held items were at a budget: `count_cap` (CommitDeferral::MAX_HELD) or
 *   `byte_budget` (CommitDeferral::byteBudget()). It counts as
 *   `unverified`, and the reason is recorded on its own.
 *
 * The degradation adapter binds it to the degradation record. An implementation never throws.
 */
interface DropCounter
{
    public function unverified(int $items = 1): void;

    public function rollbackUnverified(int $items = 1): void;

    /**
     * @param  string  $reason  CommitDeferral::COUNT_CAP or CommitDeferral::BYTE_BUDGET
     */
    public function overBudget(string $reason, int $items = 1): void;
}
