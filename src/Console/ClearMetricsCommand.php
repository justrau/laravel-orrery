<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Justrau\Orrery\Monitoring\SampleCounts;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deletes the stored counts of orrery_samples
 * (SampleCounts::clear()). The queue state and the invalid minutes stay, and
 * every minute before the clear reads as unknown afterwards, never as 0.
 * An error reaches the console kernel, which exits 1.
 */
#[AsCommand(name: 'orrery:clear-metrics')]
class ClearMetricsCommand extends Command
{
    /** @var string */
    protected $signature = 'orrery:clear-metrics';

    /** @var string */
    protected $description = 'Delete the stored Orrery per-minute counts; earlier minutes read as unknown.';

    public function handle(SampleCounts $counts): int
    {
        $counts->clear();

        $this->components->info('Orrery metrics cleared.');

        return self::SUCCESS;
    }
}
