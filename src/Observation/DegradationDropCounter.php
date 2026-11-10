<?php

namespace Justrau\Orrery\Observation;

use Justrau\Orrery\Runtime\Degradation;
use Throwable;

/**
 * The DropCounter of the commit deferral on the degradation record:
 * `unverified` goes to the `unverified` counter,
 * `rollback_unverified` to its own counter. The health windows of
 * orrery_health_events carry both. An over-budget drop counts as
 * `unverified`, and its reason is the window's error operation
 * (`deferral.count_cap` or `deferral.byte_budget`). It never throws.
 */
final class DegradationDropCounter implements DropCounter
{
    public function unverified(int $items = 1): void
    {
        try {
            Degradation::unverified($items);
        } catch (Throwable) {
            // The degradation record is the last place to count in.
        }
    }

    public function rollbackUnverified(int $items = 1): void
    {
        try {
            Degradation::rollbackUnverified($items);
        } catch (Throwable) {
            // The degradation record is the last place to count in.
        }
    }

    public function overBudget(string $reason, int $items = 1): void
    {
        try {
            Degradation::unverified($items);
            Degradation::error('deferral.'.$reason, $reason === CommitDeferral::BYTE_BUDGET
                ? 'The commit deferral dropped an observation: the held observations reached the byte budget.'
                : 'The commit deferral dropped an observation: the held observations reached the count cap.');
        } catch (Throwable) {
            // The degradation record is the last place to count in.
        }
    }
}
