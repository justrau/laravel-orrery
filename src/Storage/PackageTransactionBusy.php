<?php

namespace Justrau\Orrery\Storage;

use RuntimeException;

/**
 * A SQLite package transaction got SQLITE_BUSY in each of its 3 attempts.
 * The watcher drops the observation.
 */
final class PackageTransactionBusy extends RuntimeException
{
    /** The package session (connection name) of the transaction, when known. */
    public ?string $session = null;
}
