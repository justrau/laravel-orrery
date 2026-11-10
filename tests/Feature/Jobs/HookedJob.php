<?php

namespace Justrau\Orrery\Tests\Feature\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Justrau\Orrery\Runtime\Hooks;

/**
 * A job that reaches a hook point while it runs, so a test worker that holds
 * at that point keeps the job reserved (see Tests\Support\Barrier).
 */
class HookedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public string $point)
    {
    }

    public function handle(): void
    {
        Hooks::reach($this->point);
    }
}
