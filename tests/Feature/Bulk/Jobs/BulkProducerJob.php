<?php

namespace Justrau\Orrery\Tests\Feature\Bulk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;

/**
 * A producer that a worker process runs: it pushes $count jobs with one
 * bulk() call on the queue $queue of the connection `database`.
 */
class BulkProducerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $count, public string $targetQueue) {}

    public function handle(): void
    {
        $jobs = [];

        for ($i = 0; $i < $this->count; $i++) {
            $jobs[] = new BasicJob;
        }

        Queue::connection('database')->bulk($jobs, '', $this->targetQueue);
    }
}
