<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retention\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * A job with the given tags. It completes, or it fails at its first
 * attempt when $fails is true.
 */
class TaggedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<string>  $jobTags
     */
    public function __construct(public array $jobTags = [], public bool $fails = false)
    {
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->jobTags;
    }

    public function handle(): void
    {
        if ($this->fails) {
            throw new RuntimeException('tagged failure');
        }
    }
}
