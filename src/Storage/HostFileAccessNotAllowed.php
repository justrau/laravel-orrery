<?php

namespace Justrau\Orrery\Storage;

use RuntimeException;

/**
 * PackageSession::for() refused an automatic session on a host SQLite file
 * (D17 and D18): the file holds the application's data, and its journal
 * mode or busy timeout does not allow automatic package reads. The watcher
 * treats it as a skip, not as an error: no report, no drop count, no
 * dependency error. The caller returns unknown.
 */
final class HostFileAccessNotAllowed extends RuntimeException
{
    public function __construct(public readonly string $connection, string $reason)
    {
        parent::__construct("Orrery makes no automatic access to the SQLite file of connection [{$connection}]: {$reason}");
    }
}
