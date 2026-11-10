<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;

/**
 * Tagged "parent". At its first attempt it pushes a job tagged "child", then
 * releases itself, so the worker process pushes another job just before the
 * release.
 */
class DispatchThenReleaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['parent'];
    }

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            Queue::push(new ClassifiedJob(['child']));
            $this->release(0);
        }
    }
}
