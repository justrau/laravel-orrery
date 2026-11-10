<?php

namespace Justrau\Orrery\Storage;

use RuntimeException;

/**
 * The mirror followed database.default to another connection or database
 * inside one process (orrery.database is empty). PackageSession::mirror()
 * reports it once per process to the application's exception handler; it
 * is never thrown. The message names the connections only, never a host,
 * a database name or a credential.
 */
final class MirrorMoved extends RuntimeException
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        parent::__construct(sprintf(
            'The Orrery mirror followed database.default from connection [%s] to %s inside one process. orrery.database is empty, so the package tables follow the default connection; an application that switches its default connection or database at runtime (for example a multi-tenant package) must set ORRERY_CONNECTION to a fixed connection.',
            $from,
            $from === $to ? 'another database of the same connection' : "connection [{$to}]",
        ));
    }
}
