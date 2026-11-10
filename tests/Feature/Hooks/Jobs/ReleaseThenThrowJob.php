<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * At its first attempt it releases itself and then throws, so the exception
 * event names the job id that the release replaced. It completes at its
 * second attempt.
 */
class ReleaseThenThrowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            $this->release(0);

            throw new RuntimeException('released, then thrown');
        }
    }
}
