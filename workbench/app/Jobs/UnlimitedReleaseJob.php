<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A job with `tries = 0` (no attempt limit) that releases itself on every attempt
 * (the `attempts_not_capped` stuck signal with the enforced rule `none`).
 */
class UnlimitedReleaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public function handle(): void
    {
        $this->release();
    }
}
