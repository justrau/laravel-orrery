<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Hooks;

use Illuminate\Queue\DatabaseQueue;

/**
 * Laravel's queue (the swap off mode) with the getQueue() of Laravel 12: a
 * UnitEnum queue name stays an enum (EnumKeepingConnector).
 */
final class EnumKeepingNativeQueue extends DatabaseQueue
{
    /**
     * @param  \UnitEnum|string|null  $queue
     * @return \UnitEnum|string
     */
    #[\Override]
    public function getQueue($queue)
    {
        return $queue ?: $this->default;
    }
}
