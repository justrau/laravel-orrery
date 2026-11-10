<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * test:throw: a command that throws, for the exit status of the worker CLI
 * (tests/Support/bin/artisan). A throwing child must exit non-zero, so that
 * WorkerProcess::waitForExit() sees the failure.
 */
class ThrowingCommand extends Command
{
    public const MESSAGE = 'test:throw failed on purpose';

    /** @var string */
    protected $signature = 'test:throw';

    /** @var string */
    protected $description = 'Throw an exception (test fixture).';

    public function handle(): int
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
