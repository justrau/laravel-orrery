<?php

namespace Justrau\Orrery\Storage;

use RuntimeException;

/**
 * A relation that the package uses on a pooled Postgres session does not
 * resolve under the server's default search path, also after the
 * session resolved its relations once more. The statement is not sent
 * unqualified, because a pooled backend can carry another search path.
 */
final class PooledRelationUnresolved extends RuntimeException
{
    public function __construct(public readonly string $connection, public readonly string $relation)
    {
        parent::__construct("The relation [{$relation}] does not resolve under the server's default search_path of the pooled connection [{$connection}].");
    }
}
