<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Observation\DatabaseDriverFilter;

/**
 * A completion (the rule complete). Laravel fires JobProcessed
 * also after a released or failed attempt, so it counts only when neither
 * hasFailed() nor isReleased(): a release is not throughput.
 */
final class ObserveJobProcessed
{
    public function __construct(private readonly JobStore $store)
    {
    }

    public function handle(JobProcessed $event): void
    {
        if ($event->job->hasFailed() || $event->job->isReleased()) {
            return;
        }

        $observation = ObservesAttempts::observation(Observation::COMPLETE, DatabaseDriverFilter::connectionOf($event), $event->job);

        if ($observation !== null) {
            $this->store->observe($observation);
        }
    }
}
