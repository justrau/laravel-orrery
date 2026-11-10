<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Hooks;

use Justrau\Orrery\DatabaseQueue;

/**
 * The package queue with the getQueue() of Laravel 12: a
 * UnitEnum queue name stays an enum (EnumKeepingConnector).
 */
final class EnumKeepingQueue extends DatabaseQueue
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
