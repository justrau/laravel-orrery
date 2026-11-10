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
 *
 * The degradation adapter binds it to the degradation record. An implementation never throws.
 */
interface DropCounter
{
    public function unverified(int $items = 1): void;

    public function rollbackUnverified(int $items = 1): void;
}
