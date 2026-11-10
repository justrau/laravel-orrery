<?php

namespace Justrau\Orrery\Monitoring\Phases;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Monitoring\StuckIncidents;

/**
 * The stuck incidents of tick: creates the incidents of the
 * stuck signals and of the unknown rows (StuckIncidents::createIncidents()),
 * inside a share of 2 s. The late phase `stuck_events` delivers them as
 * StuckJobDetected.
 *
 * It writes only to the mirror (orrery_incidents and orrery_settings), which
 * is never an application's SQLite file (D17), and it reads only mirror
 * rows.
 */
final class StuckIncidentsPhase implements TickPhase
{
    public function __construct(private readonly StuckIncidents $incidents)
    {
    }

    public function name(): string
    {
        return 'stuck_incidents';
    }

    public function priority(): int
    {
        return 410;
    }

    public function run(TickBudget $budget): void
    {
        $this->incidents->createIncidents($budget);
    }
}
