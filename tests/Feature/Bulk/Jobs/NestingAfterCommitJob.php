<?php

namespace Justrau\Orrery\Tests\Feature\Bulk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;

/**
 * A job whose tags() pushes an after-commit job on the queue connection
 * $pushTo: the bulk classifies the pushed payload, and Laravel inserts it
 * at the commit, through its own pushToDatabase().
 */
class NestingAfterCommitJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $pushTo = 'hooks')
    {
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        Queue::connection($this->pushTo)->push((new BasicJob)->afterCommit());

        return ['outer-bulk'];
    }

    public function handle(): void
    {
    }
}
