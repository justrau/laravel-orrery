<?php

namespace Justrau\Orrery\Markers\Phases;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Markers\Markers;

/**
 * The retention of the chart markers: 7 days, oldest first, at
 * most 5,000 rows per tick (Markers::prune()). It is a phase of its own, so
 * a cache error of the restart phase never stops it.
 */
final class MarkerRetentionPhase implements TickPhase
{
    public function __construct(private readonly Markers $markers)
    {
    }

    public function name(): string
    {
        return 'marker_retention';
    }

    public function priority(): int
    {
        return 875;
    }

    public function run(TickBudget $budget): void
    {
        $this->markers->prune($budget);
    }
}
