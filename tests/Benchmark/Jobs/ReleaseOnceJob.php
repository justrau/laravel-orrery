<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Benchmark\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The job of the release cost (IndexCostBenchmark, ORRERY_BENCHMARK_JOB=
 * release): attempt 1 releases it at once, attempt 2 completes.
 */
class ReleaseOnceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            $this->release(0);
        }
    }
}
