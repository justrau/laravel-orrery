<?php

namespace Justrau\Orrery\Retry;

use RuntimeException;

/**
 * Thrown inside a PackageTransaction::run() closure to roll the transaction
 * back with a decided reason (a lost fence key, a passed deadline, a lost
 * guard). The caller catches it after the rollback; it is never reported.
 */
final class RolledBack extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("orrery: the operation transaction rolled back ({$reason}).");
    }
}
