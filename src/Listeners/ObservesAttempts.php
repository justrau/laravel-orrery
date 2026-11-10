<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Contracts\Queue\Job;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Observation\QueueObjects;
use Justrau\Orrery\Storage\Clock;

/**
 * Builds the observation of an attempt event (an exception, a timeout or a
 * completion) of a database queue job: jobs.id j, the attempt n and the
 * swap mode of its queue connection.
 */
final class ObservesAttempts
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public static function observation(string $kind, mixed $connection, Job $job, array $fields = []): ?Observation
    {
        $connection = is_string($connection) ? $connection : null;
        $queue = QueueObjects::queueObject($connection);

        if ($connection === null || $queue === null) {
            return null;
        }

        return Observation::fromPayload($kind, $job->getRawBody(), $connection, $job->getQueue(), $fields + [
            'jobId' => $job->getJobId(),
            'attempt' => $job->attempts(),
            'at' => Clock::now(),
            'instrumented' => QueueObjects::isSwapped($queue),
        ]);
    }
}
