<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Workbench\App\Exceptions\WorkbenchJobFailed;

/**
 * A member of the seeded batch. The member created with $fails = true throws.
 */
class BatchMemberJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(public int $number, public bool $fails = false)
    {
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        usleep(300 * (1 + $this->number % 3));

        if ($this->fails) {
            throw new WorkbenchJobFailed(
                sprintf('Workbench import row %d has an invalid e-mail address.', $this->number),
                ['row' => $this->number],
            );
        }
    }
}
