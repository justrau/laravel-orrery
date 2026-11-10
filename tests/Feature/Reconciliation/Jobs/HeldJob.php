<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Reconciliation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Justrau\Orrery\Runtime\Hooks;

/**
 * A job that reaches a hook point while it runs, with room for the attempt
 * that a release row carries (a worker that holds at the point keeps the job
 * reserved).
 */
class HeldJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $point)
    {
    }

    public function handle(): void
    {
        Hooks::reach($this->point);
    }
}
