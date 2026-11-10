<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Workbench\App\Exceptions\WorkbenchJobFailed;

/**
 * A job that always fails with the same message, so all of its failures form one failure group
 * (more than the 200 members of one selection).
 */
class FlakyApiJob implements ShouldQueue
{
    use Queueable;

    public const MESSAGE = 'The mail API answered 503 Service Unavailable.';

    public function __construct(public int $number)
    {
    }

    public function handle(): void
    {
        throw new WorkbenchJobFailed(self::MESSAGE, ['number' => $this->number]);
    }
}
