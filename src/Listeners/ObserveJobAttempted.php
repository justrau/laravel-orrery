<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Queue\Events\JobAttempted;
use Justrau\Orrery\Lifecycle\RuntimeClock;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Runtime\WatchesItself;

/**
 * The end of an attempt. It clears the job's runtime clock.
 * A release of a queue object that is not the package's is not
 * observed.
 *
 * It watches itself (WatchesItself): the local cleanup runs even while the
 * breaker is open. It touches no database.
 */
final class ObserveJobAttempted implements WatchesItself
{
    public function handle(JobAttempted $event): void
    {
        $uuid = PayloadIdentity::uuidOf($event->job->getRawBody());

        if ($uuid !== null) {
            RuntimeClock::forget($uuid);
        }
    }
}
