<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A job that releases itself back onto the queue on its first attempt and completes on the second.
 */
class ReleasesOnceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(): void
    {
        if ($this->attempts() < 2) {
            $this->release(0);

            return;
        }

        usleep(500);
    }
}
