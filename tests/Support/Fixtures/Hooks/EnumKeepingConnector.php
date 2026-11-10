<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Hooks;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Connectors\DatabaseConnector;

/**
 * A database connector whose queue keeps a UnitEnum queue name, as
 * Laravel 12 does (getQueue() returns `$queue ?: $default`). Laravel 13
 * applies enum_value() in getQueue() (the floor 13.30 too), so there this
 * connector gives the queue names of Laravel 12.
 *
 * With $swapped, the queue is the package queue; else it is Laravel's queue
 * (the swap off mode).
 */
final class EnumKeepingConnector extends DatabaseConnector
{
    public function __construct(ConnectionResolverInterface $connections, private readonly bool $swapped)
    {
        parent::__construct($connections);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): Queue
    {
        $arguments = [
            $this->connections->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null,
        ];

        return $this->swapped ? new EnumKeepingQueue(...$arguments) : new EnumKeepingNativeQueue(...$arguments);
    }
}
