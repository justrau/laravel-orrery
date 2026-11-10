<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A job that always fails: its retryUntil() is already past, so the worker
 * fails it at its first attempt (a retryUntil replaces the tries). Its
 * retryUntil() throws while self::$refuse is true, so a native queue:retry
 * fails while it prepares the payload.
 */
class RetryUntilFailsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public static bool $refuse = false;

    public function retryUntil(): DateTimeInterface
    {
        if (self::$refuse) {
            throw new RuntimeException('retryUntil() refuses the retry');
        }

        return Carbon::now()->subMinute();
    }

    public function handle(): void
    {
        throw new RuntimeException('RetryUntilFailsJob always fails');
    }
}
