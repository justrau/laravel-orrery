<?php

namespace Justrau\Orrery\Maintenance\Phases;

use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;

/**
 * The reconciliation of the active rows: one batch of 1,000
 * rows per queue connection with the `database` driver, while the tick budget
 * lasts (Reconciliation::reconcileActive()), then one batch of the rows of
 * connections that are no longer configured, checked against every
 * configured queue table (reconcileRemoved()).
 *
 * The priority 260 runs it after the bulk resolution (250), so rows that
 * the resolution resolved in this tick are checked.
 */
final class ReconcileActivePhase implements TickPhase
{
    public function __construct(private readonly Reconciliation $reconciliation)
    {
    }

    public function name(): string
    {
        return 'reconcile_active';
    }

    public function priority(): int
    {
        return 260;
    }

    public function run(TickBudget $budget): void
    {
        foreach ($this->reconciliation->connections() as $connection) {
            if ($budget->exhausted()) {
                return;
            }

            $this->reconciliation->reconcileActive($connection);
        }

        if (! $budget->exhausted()) {
            $this->reconciliation->reconcileRemoved($budget);
        }
    }
}
