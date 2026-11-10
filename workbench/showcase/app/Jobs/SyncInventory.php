<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A showcase job (ShowcaseSeeder): syncs the stock of a product with the warehouse.
 */
class SyncInventory implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $product) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['product:'.$this->product];
    }

    public function handle(): void
    {
        usleep(3_000 + $this->product % 5 * 800);
    }
}
