<?php

namespace Justrau\Orrery\Failed;

use Justrau\Orrery\Retry\Operations;

/**
 * The null failer: no failed record exists. A retry
 * publishes the mirror payload (mode none); a forget removes only the
 * mirror row.
 */
final class NoFailedRecords extends FailedRecords
{
    public function kind(): string
    {
        return self::NONE;
    }

    public function mode(string $queueConnection): string
    {
        return Operations::NONE;
    }
}
