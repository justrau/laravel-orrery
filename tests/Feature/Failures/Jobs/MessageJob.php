<?php

namespace Justrau\Orrery\Tests\Feature\Failures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * A job that always fails with the message of MessageJob::$message, read at
 * run time. The tests run it in the test process (queue:work --once), so a
 * test changes the message between two runs of one job.
 */
class MessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static string $message = 'MessageJob fails.';

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException(self::$message);
    }
}
