<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A queued listener whose tags hold a numeric string and an invalid boolean.
 */
class ScalarTagsListener implements ShouldQueue
{
    /**
     * @return list<mixed>
     */
    public function tags(?object $event = null): array
    {
        return ['01', true];
    }

    public function handle(ScalarTagsEvent $event): void
    {
    }
}
