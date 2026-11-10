<?php

namespace Justrau\Orrery\Retry;

use RuntimeException;

/**
 * The next step of an operation needs an access to a host SQLite file that
 * only an operator action may make (D17 and D18): a write
 * (Operations::SQLITE_HOST_FILE), or a read of a file without automatic
 * reads (Operations::SQLITE_READS_NOT_ALLOWED). The retry handler turns it
 * into OperationStep::awaiting(); nothing was written.
 */
final class AwaitingOperator extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $connection)
    {
        parent::__construct("orrery: the next step of the operation needs an operator action on the SQLite file of connection [{$connection}] ({$reason}).");
    }
}
