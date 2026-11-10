<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

/**
 * The event of NestingListener.
 */
class NestingEvent
{
    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['event-tag'];
    }
}
