<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A job with explicit tags. The seeder monitors FEATURED_TAG, so the package records these jobs
 * for the monitoring screens.
 */
class TaggedJob implements ShouldQueue
{
    use Queueable;

    public const FEATURED_TAG = 'workbench:featured';

    public function __construct(public int $number, public int $customer)
    {
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [self::FEATURED_TAG, 'customer:'.$this->customer];
    }

    public function handle(): void
    {
        usleep(300 * (1 + $this->number % 4));
    }
}
