<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * A job with `tries` and a `retryUntil` deadline (epoch seconds) that never
 * finishes: in mode `release` each attempt releases itself; in mode
 * `alternate` odd attempts release and even attempts throw (the worker then
 * releases it while the deadline has not passed).
 */
class DeadlineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $tries, public int $until, public string $mode = 'release')
    {
    }

    public function retryUntil(): int
    {
        return $this->until;
    }

    public function handle(): void
    {
        if ($this->mode === 'alternate' && $this->attempts() % 2 === 0) {
            throw new RuntimeException('scripted exception at attempt '.$this->attempts());
        }

        $this->release(0);
    }
}
