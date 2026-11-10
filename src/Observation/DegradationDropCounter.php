<?php

namespace Justrau\Orrery\Observation;

use Justrau\Orrery\Runtime\Degradation;
use Throwable;

/**
 * The DropCounter of the commit deferral on the degradation record:
 * `unverified` goes to the `unverified` counter,
 * `rollback_unverified` to its own counter. The health windows of
 * orrery_health_events carry both. An over-budget drop counts as
 * `unverified`, and its reason (`deferral.count_cap` or
 * `deferral.byte_budget`) goes to the window's errors, and to its
 * last_error_operation only while no other error is set
 * (Degradation::reason()). It never throws.
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
            Degradation::reason('deferral.'.$reason);
        } catch (Throwable) {
            // The degradation record is the last place to count in.
        }
    }
}
