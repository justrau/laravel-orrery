<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use RuntimeException;

/**
 * A hold point that stops this process (RetryProtocolTestCase::killAt()):
 * the in-process stand-in for a killed publisher.
 */
final class KilledAt extends RuntimeException
{
    public function __construct(public readonly string $point)
    {
        parent::__construct("Killed at {$point} (test).");
    }
}
