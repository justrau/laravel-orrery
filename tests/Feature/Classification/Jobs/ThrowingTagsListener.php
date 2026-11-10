<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

/**
 * A queued listener whose tags() throws while Tags keeps its event.
 */
class ThrowingTagsListener implements ShouldQueue
{
    /**
     * @return list<string>
     */
    public function tags(?object $event = null): array
    {
        throw new RuntimeException('tags() of ThrowingTagsListener failed');
    }

    public function handle(NestingEvent $event): void
    {
    }
}
