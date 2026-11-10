<?php

namespace Justrau\Orrery\Storage;

use RuntimeException;

/**
 * PackageSession refused a pooled Postgres session: its
 * configuration would change the namespace or a session setting that a
 * transaction pooler shares between clients, or its search path is not the
 * server's default. The application's own connection is untouched.
 */
final class PooledSessionRefused extends RuntimeException
{
    public function __construct(public readonly string $connection, string $message)
    {
        parent::__construct($message);
    }
}
