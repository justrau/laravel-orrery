<?php

namespace Justrau\Orrery\Monitoring\Phases;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Monitoring\SampleCounts;

/**
 * The per-minute counts of orrery_samples. It runs before
 * retention (100), so a row that the counts have not passed is counted
 * before retention can delete it. SampleCounts has the bounds.
 */
final class SampleCountsPhase implements TickPhase
{
    public function __construct(private readonly SampleCounts $counts)
    {
    }

    public function name(): string
    {
        return 'sample_counts';
    }

    public function priority(): int
    {
        return 97;
    }

    public function run(TickBudget $budget): void
    {
        $this->counts->run($budget);
    }
}
