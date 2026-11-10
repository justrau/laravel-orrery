<?php

namespace Justrau\Orrery\Tests\Feature\Bulk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;

/**
 * A job whose tags() runs a second bulk of $count jobs on the queue
 * connection $connection, so two bulk calls overlap. The inner jobs are
 * after-commit jobs when $afterCommit is true.
 */
class NestedBulkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $bulkOn = 'hooks', public int $count = 1, public bool $innerAfterCommit = true) {}

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $jobs = [];

        for ($i = 0; $i < $this->count; $i++) {
            $job = new BasicJob;

            if ($this->innerAfterCommit) {
                $job->afterCommit();
            }

            $jobs[] = $job;
        }

        Queue::connection($this->bulkOn)->bulk($jobs, '', 'default');

        return ['nested-bulk'];
    }

    public function handle(): void {}
}
