<?php

namespace Workbench\App\Jobs;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A job behind Laravel's RateLimited middleware. The limiter allows one job per hour, so after
 * the first job every attempt is released again (the `repeated_releases` stuck signal).
 */
class ThrottledJob implements ShouldQueue
{
    use Queueable;

    public const LIMITER = 'workbench-throttled';

    /** Above the releases of the seeder, so the job never fails: its rule is an attempt limit. */
    public int $tries = 25;

    public function __construct(public int $number)
    {
    }

    /**
     * The limiter of the middleware. The seeder defines it before it works the queue.
     */
    public static function defineLimiter(): void
    {
        RateLimiter::for(self::LIMITER, static fn (): Limit => Limit::perHour(1)->by('workbench'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // A release of one second (instead of the limiter's wait of up to an hour) lets the
        // seeder reach the next attempt by moving the clock two seconds.
        return [(new RateLimited(self::LIMITER))->releaseAfter(1)];
    }

    public function handle(): void
    {
    }
}
