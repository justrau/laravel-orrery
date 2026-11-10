<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A short job that completes. The runtime varies a little, so the metric charts are not flat.
 */
class QuickJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $number) {}

    public function handle(): void
    {
        usleep(200 * (1 + $this->number % 5));
    }
}
