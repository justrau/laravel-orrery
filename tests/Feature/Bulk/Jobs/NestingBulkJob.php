<?php

namespace Justrau\Orrery\Tests\Feature\Bulk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ClassifiedJob;

/**
 * A job whose tags() pushes another job on the queue connection $connection,
 * so the other job is inserted while the bulk classifies this job.
 */
class NestingBulkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $pushTo = 'hooks') {}

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        Queue::connection($this->pushTo)->push(new ClassifiedJob(['inner-of-bulk']));

        return ['outer-bulk'];
    }

    public function handle(): void {}
}
