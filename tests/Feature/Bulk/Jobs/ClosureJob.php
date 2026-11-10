<?php

namespace Justrau\Orrery\Tests\Feature\Bulk\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job whose handle() runs ClosureJob::$during (for a sync dispatch that
 * runs a bulk inside its own unit of work).
 */
class ClosureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?Closure $during = null;

    public function handle(): void
    {
        $during = self::$during;
        self::$during = null;

        if ($during !== null) {
            $during();
        }
    }
}
