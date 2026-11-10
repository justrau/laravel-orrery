<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Workbench\App\Exceptions\WorkbenchJobFailed;

/**
 * A job with the monitored tag TAG. Every hundredth job fails.
 */
class CampaignJob implements ShouldQueue
{
    use Queueable;

    public const TAG = 'campaign:spring';

    public function __construct(public int $number) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [self::TAG];
    }

    public function handle(): void
    {
        if ($this->number % 100 === 0) {
            throw new WorkbenchJobFailed('The campaign recipient has unsubscribed.', ['number' => $this->number]);
        }
    }
}
