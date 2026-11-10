<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Releases itself at its first attempt with self::$releaseDelay seconds, and
 * completes at its second attempt.
 */
class ReleaseOnceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public static int $releaseDelay = 0;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            $this->release(self::$releaseDelay);
        }
    }
}
