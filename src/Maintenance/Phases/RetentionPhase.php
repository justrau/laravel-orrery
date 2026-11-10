<?php

namespace Justrau\Orrery\Maintenance\Phases;

use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;

/**
 * The partitioned retention of the mirror rows, after the
 * monitoring repair. It replaces the legacy trims.
 */
final class RetentionPhase implements TickPhase
{
    public function __construct(private readonly Retention $retention) {}

    public function name(): string
    {
        return 'retention';
    }

    public function priority(): int
    {
        return 100;
    }

    public function run(TickBudget $budget): void
    {
        $this->retention->run($budget);
    }
}
