<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job whose tags show the event that Tags gives to tags(): a tag
 * "event:none" when it gets no event.
 */
class EventAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @return list<string>
     */
    public function tags(?object $event = null): array
    {
        return ['inner-of-listener', 'event:'.($event === null ? 'none' : $event::class)];
    }

    public function handle(): void {}
}
