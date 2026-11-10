<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Justrau\Orrery\Contracts\Silenced;

/**
 * A silenced job (the Silenced contract) that releases itself at its first
 * attempt and completes at the second.
 */
class SilencedReleasingJob implements ShouldQueue, Silenced
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            $this->release(0);
        }
    }
}
