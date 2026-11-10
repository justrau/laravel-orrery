<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Justrau\Orrery\Contracts\Silenced;

/**
 * A silenced job (the Silenced contract) with the tag tenant:42.
 */
class SilencedTaggedJob implements ShouldQueue, Silenced
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['tenant:42'];
    }

    public function handle(): void
    {
    }
}
