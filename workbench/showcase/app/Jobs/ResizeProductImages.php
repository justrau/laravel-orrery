<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A showcase job (ShowcaseSeeder): resizes the photos of a product.
 */
class ResizeProductImages implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $product)
    {
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['product:'.$this->product];
    }

    public function handle(): void
    {
        usleep(28_000 + $this->product % 4 * 3_000);
    }
}
