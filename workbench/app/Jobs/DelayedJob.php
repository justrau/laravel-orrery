<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A job that the seeder pushes with a one-hour delay, so it stays pending.
 */
class DelayedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $number) {}

    public function handle(): void
    {
        usleep(300);
    }
}
