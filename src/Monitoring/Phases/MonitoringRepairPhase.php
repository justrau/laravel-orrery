<?php

namespace Justrau\Orrery\Monitoring\Phases;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Monitoring\MonitoringRepair;

/**
 * The monitoring repair: rows with an old monitor_version, at
 * most 20,000 per tick. It runs before the retention phase, which verifies
 * the rows that it did not reach inline.
 */
final class MonitoringRepairPhase implements TickPhase
{
    public function __construct(private readonly MonitoringRepair $repair)
    {
    }

    public function name(): string
    {
        return 'monitoring_repair';
    }

    public function priority(): int
    {
        return 90;
    }

    public function run(TickBudget $budget): void
    {
        $this->repair->run($budget);
    }
}
