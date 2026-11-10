<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Workbench\App\Exceptions\WorkbenchJobFailed;

/**
 * A job that fails, unless the SUCCEED_FLAG cache key is set.
 *
 * The seeder sets the flag only while it works the one retried copy, so the retry completes
 * through the package's real retry path while every original attempt fails.
 */
class FailingJob implements ShouldQueue
{
    use Queueable;

    public const SUCCEED_FLAG = 'workbench:failing-job:succeed';

    public function __construct(public int $number, public bool $featured = false) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return $this->featured ? [TaggedJob::FEATURED_TAG] : [];
    }

    public function handle(): void
    {
        if (Cache::get(self::SUCCEED_FLAG) === true) {
            return;
        }

        throw new WorkbenchJobFailed(
            sprintf('Workbench failure #%d: the payment gateway timed out.', $this->number),
            ['number' => $this->number, 'gateway' => 'workbench-sandbox', 'timeout_seconds' => 30],
        );
    }
}
