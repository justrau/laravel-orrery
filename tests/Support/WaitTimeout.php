<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use RuntimeException;

/**
 * A wait of a process helper ended without its condition: the deadline
 * passed, or the process that it waited for is gone (see Barrier::await()
 * and WorkerProcess::waitUntil()).
 */
final class WaitTimeout extends RuntimeException
{
}
