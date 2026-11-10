<?php

namespace Justrau\Orrery\Actions;

use RuntimeException;

/**
 * An operator action that is refused before it changes anything: `reason`
 * names why (`not_database_queue`, `in_transaction`).
 */
final class ActionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
