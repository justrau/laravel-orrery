<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Justrau\Orrery\Contracts\Silenced;

/**
 * A job that the package silences through the Silenced marker interface.
 * It completes and appears in the silenced list, not in the completed list.
 */
class SilencedJob implements ShouldQueue, Silenced
{
    use Queueable;

    public function __construct(public int $number) {}

    public function handle(): void
    {
        usleep(300);
    }
}
