<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A showcase job (ShowcaseSeeder): imports one chunk of a customer file, as a member of the
 * batch "Import customers". It takes $seconds, so a showcase worker holds it and the batch
 * moves on while the dashboard is open.
 */
class ImportCustomers implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $timeout = 120;

    public function __construct(public int $chunk, public int $seconds = 0) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $deadline = microtime(true) + $this->seconds;

        do {
            usleep(20_000);
        } while (microtime(true) < $deadline);
    }
}
