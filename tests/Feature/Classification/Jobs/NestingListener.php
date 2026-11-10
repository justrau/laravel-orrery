<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;

/**
 * A queued listener whose tags() dispatches EventAwareJob. Tags keeps the
 * listener's event while it collects the listener's tags, so the inner job
 * is classified while that state is set.
 */
class NestingListener implements ShouldQueue
{
    /**
     * @return list<string>
     */
    public function tags(?object $event = null): array
    {
        Queue::push(new EventAwareJob);

        return ['listener-tag'];
    }

    public function handle(NestingEvent $event): void
    {
    }
}
