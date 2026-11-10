<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Justrau\Orrery\Lifecycle\RuntimeClock;
use Justrau\Orrery\Observation\PayloadIdentity;

/**
 * Starts the job's runtime clock: the process-local runtime
 * map, which JobAttempted clears.
 */
final class ObserveJobProcessing
{
    public function handle(JobProcessing $event): void
    {
        $uuid = PayloadIdentity::uuidOf($event->job->getRawBody());

        if ($uuid !== null) {
            RuntimeClock::start($uuid);
        }
    }
}
