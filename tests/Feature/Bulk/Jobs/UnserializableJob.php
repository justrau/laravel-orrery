<?php

namespace Justrau\Orrery\Tests\Feature\Bulk\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job that holds a closure, so Laravel's payload creation throws.
 */
class UnserializableJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public Closure $callback;

    public function __construct()
    {
        $this->callback = static fn (): null => null;
    }

    public function handle(): void
    {
    }
}
