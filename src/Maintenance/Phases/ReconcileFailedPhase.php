<?php

namespace Justrau\Orrery\Maintenance\Phases;

use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;

/**
 * The reconciliation of the failed rows with a bound failed record:
 * Reconciliation::reconcileFailed() checks each
 * record by its exact id and uuid, and removes a row whose record is gone
 * through ForgetService::removeAbsent().
 */
final class ReconcileFailedPhase implements TickPhase
{
    public function __construct(private readonly Reconciliation $reconciliation) {}

    public function name(): string
    {
        return 'reconcile_failed';
    }

    public function priority(): int
    {
        return 460;
    }

    public function run(TickBudget $budget): void
    {
        $this->reconciliation->reconcileFailed($budget);
    }
}
