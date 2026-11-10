<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Releases itself at its first attempt and completes at the second. It
 * records the raw payload of each attempt.
 */
class ReleasingOnceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var list<string> the raw payload of each attempt */
    public static array $rawBodies = [];

    public function handle(): void
    {
        self::$rawBodies[] = $this->job->getRawBody();

        if ($this->attempts() === 1) {
            $this->release(0);
        }
    }
}
