<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;

/**
 * A job whose tags() dispatches another job, so the other job is classified
 * while this job's classification runs.
 */
class NestingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        Queue::push(new ClassifiedJob(['inner-of-job']));

        return ['outer-job'];
    }

    public function handle(): void
    {
    }
}
