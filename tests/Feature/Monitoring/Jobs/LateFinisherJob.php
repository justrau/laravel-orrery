<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Justrau\Orrery\Runtime\Hooks;

/**
 * Attempt 1 reaches the hook point POINT and then completes, so a test
 * worker that holds there finishes late; every later attempt releases
 * itself. `tries = 5`.
 */
class LateFinisherJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const POINT = 'stuck.late_finisher';

    public int $tries = 5;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            Hooks::reach(self::POINT);

            return;
        }

        $this->release(0);
    }
}
