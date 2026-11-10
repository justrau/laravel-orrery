<?php

namespace Workbench\App\Jobs;

use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A job with `maxTries = 1` and a `retryUntil` one day ahead that releases itself on every
 * attempt. Laravel ignores the attempt limit until the deadline (the enforced rule `deadline`).
 */
class DeadlineReleaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(): void
    {
        $this->release();
    }
}
