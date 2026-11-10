<?php

namespace Justrau\Orrery\Monitoring\Phases;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Monitoring\Samples;
use Justrau\Orrery\Monitoring\TickMeasurement;

/**
 * The queue state of the current minute (before: the queue
 * samples): one `queue` row of orrery_samples per measured
 * queue, from the run-scoped measurement (TickMeasurement), which this
 * phase takes first and the long-wait phase (960) reuses. It writes nothing when the live workers
 * could not be read, so a minute without a successful observation stays
 * unsampled. Samples::write() has the bounds, the cursor and the deadline
 * checks.
 */
final class SamplesPhase implements TickPhase
{
    public function __construct(
        private readonly TickMeasurement $measurement,
        private readonly Samples $samples,
    ) {}

    public function name(): string
    {
        return 'samples';
    }

    public function priority(): int
    {
        return 405;
    }

    public function run(TickBudget $budget): void
    {
        $measurement = $this->measurement->forRun($budget);

        if ($measurement['workers'] === null) {
            return;
        }

        $this->samples->write($measurement['rows'], $measurement['workers'], $measurement['measured_at'], $budget);
    }
}
