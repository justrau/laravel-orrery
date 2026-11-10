<?php

namespace Justrau\Orrery\Tests\Feature\Operations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job that runs past its one-second timeout: the worker's alarm fails it
 * and kills the worker.
 */
class TimeoutJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1;

    public bool $failOnTimeout = true;

    public function handle(): void
    {
        sleep(20);
    }
}
