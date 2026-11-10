<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * A job behind Laravel's RateLimited middleware with the limiter
 * LIMITER (the test defines it): once the limit is used, every attempt is
 * released by the middleware after 1 second, without running handle().
 * `tries = 0` puts `maxTries: 0` (no attempt limit) in the payload.
 */
class RateLimitedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const LIMITER = 'orrery-stuck-test';

    public int $tries = 0;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new RateLimited(self::LIMITER))->releaseAfter(1)];
    }

    public function handle(): void
    {
        // The attempt that the limiter lets through releases itself: the job never finishes.
        $this->release(1);
    }
}
