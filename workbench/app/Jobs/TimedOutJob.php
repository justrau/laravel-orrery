<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use RuntimeException;

/**
 * A job with `timeout = 1` and `failOnTimeout = true` that fails with the exception of a timeout.
 *
 * A real timeout makes the worker kill its own process (SIGKILL), which the one seeding process
 * cannot survive. So the job throws the exception that the worker's timeout handler builds,
 * TimeoutExceededException::forJob(), and the worker fails it through its normal exception path.
 * Laravel's JobTimedOut event does not fire, so the mirror counts no timeout.
 */
class TimedOutJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1;

    public bool $failOnTimeout = true;

    public function handle(): void
    {
        if ($this->job === null) {
            throw new RuntimeException('The timed-out job runs only on a queue.');
        }

        throw TimeoutExceededException::forJob($this->job);
    }
}
