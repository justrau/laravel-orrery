<?php

namespace Justrau\Orrery\Queue;

use Illuminate\Database\Connection;

/**
 * The database target of a cached database queue object: the
 * connection instance that its statements run on, that instance's name (the
 * observer session's source) and the queue table.
 */
final class QueueTarget
{
    public function __construct(
        public readonly Connection $connection,
        public readonly string $name,
        public readonly string $table,
    ) {}
}
