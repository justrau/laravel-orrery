<?php

namespace Justrau\Orrery\Monitoring\Phases;

use Justrau\Orrery\Maintenance\RunsPastDeadline;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\StuckIncidents;

/**
 * The delivery of the stuck incidents as StuckJobDetected
 * (StuckIncidents::deliverEvents()): late in the tick
 * (priority 940, before the two notification phases), so a slow listener
 * of the application delays no other phase. Inside a share of
 * StuckIncidents::SHARE_SECONDS and the tick budget; it runs also after the
 * tick's deadline, with its share (RunsPastDeadline).
 *
 * It writes only to the mirror (orrery_incidents), which is never an
 * application's SQLite file (D17), and it reads only mirror rows.
 */
final class StuckEventsPhase implements RunsPastDeadline
{
    public function __construct(private readonly StuckIncidents $incidents)
    {
    }

    public function name(): string
    {
        return 'stuck_events';
    }

    public function priority(): int
    {
        return 940;
    }

    public function shareSeconds(): float
    {
        return StuckIncidents::SHARE_SECONDS;
    }

    public function run(TickBudget $budget): void
    {
        $this->incidents->deliverEvents($budget);
    }
}
