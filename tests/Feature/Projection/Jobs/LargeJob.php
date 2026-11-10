<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An ordinary large job: a property of many integers, or a property that
 * holds a large serialized string.
 */
class LargeJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $ids
     */
    public function __construct(public array $ids = [], public string $snapshot = '')
    {
    }

    public function handle(): void
    {
    }
}
