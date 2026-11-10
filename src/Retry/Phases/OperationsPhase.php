<?php

namespace Justrau\Orrery\Retry\Phases;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Retry\OperationRunner;

/**
 * The tick phase `operations` (priority 480): one run of the
 * operation runner. It runs after the maintenance phases, so an unreachable
 * queue database never starves retention or reconciliation. The runner
 * writes only the mirror and, outside an operator action, never a host
 * SQLite file (D17).
 */
final class OperationsPhase implements TickPhase
{
    public function __construct(private readonly OperationRunner $runner) {}

    public function name(): string
    {
        return 'operations';
    }

    public function priority(): int
    {
        return 480;
    }

    public function run(TickBudget $budget): void
    {
        $this->runner->runOnce($budget);
    }
}
