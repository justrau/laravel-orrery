<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

/**
 * The event of ScalarTagsListener. Its tags hold a numeric string that
 * equals the listener's tag in a loose comparison, an invalid boolean and a
 * valid string.
 */
class ScalarTagsEvent
{
    /**
     * @return list<mixed>
     */
    public function tags(): array
    {
        return ['1', true, 'vip'];
    }
}
