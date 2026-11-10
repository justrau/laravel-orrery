<?php

namespace Justrau\Orrery\Connectors;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Connection;
use Illuminate\Queue\Connectors\DatabaseConnector as BaseConnector;
use Justrau\Orrery\DatabaseQueue;
use RuntimeException;

class DatabaseConnector extends BaseConnector
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): Queue
    {
        $connection = $this->connections->connection($config['connection'] ?? null);

        if (! $connection instanceof Connection) {
            throw new RuntimeException(sprintf(
                'orrery requires a [%s] database connection; got [%s].',
                Connection::class,
                get_debug_type($connection),
            ));
        }

        return new DatabaseQueue(
            $connection,
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null,
        );
    }
}
