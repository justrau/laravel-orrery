<?php

namespace Justrau\Orrery\Monitoring\Phases;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Monitoring\Samples;

/**
 * The retention of orrery_samples: the minutes older than
 * `samples.retention_hours` (24), oldest first, at most 5,000 rows per tick,
 * each minute retired before its rows go (Samples::retire()).
 */
final class SampleRetentionPhase implements TickPhase
{
    public function __construct(private readonly Samples $samples) {}

    public function name(): string
    {
        return 'sample_retention';
    }

    public function priority(): int
    {
        return 870;
    }

    public function run(TickBudget $budget): void
    {
        $this->samples->retire($budget);
    }
}
