<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retention\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * A job that runs longer than every retention window: while it is reserved,
 * the clock moves on by $minutes and the scheduler runs orrery:tick (the
 * rewrite of the probe LongRunningJobProbeTest).
 */
class TickingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $minutes = 20_000)
    {
    }

    public function handle(): void
    {
        Carbon::setTestNow(Carbon::now()->addMinutes($this->minutes));
        Artisan::call('orrery:tick');
    }
}
