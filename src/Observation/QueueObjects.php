<?php

namespace Justrau\Orrery\Observation;

use Illuminate\Container\Container;
use Illuminate\Queue\DatabaseQueue as BaseDatabaseQueue;
use Justrau\Orrery\DatabaseQueue;

/**
 * The database queue objects that the queue manager holds.
 */
final class QueueObjects
{
    /**
     * The database queue object that the queue manager holds for the
     * connection, or null for another driver. It is swapped
     * when it is the package's DatabaseQueue.
     */
    public static function queueObject(?string $connection): ?BaseDatabaseQueue
    {
        if ($connection === null || $connection === '') {
            return null;
        }

        $queue = Container::getInstance()->make('queue')->connection($connection);

        return $queue instanceof BaseDatabaseQueue ? $queue : null;
    }

    public static function isSwapped(BaseDatabaseQueue $queue): bool
    {
        return $queue instanceof DatabaseQueue;
    }
}
