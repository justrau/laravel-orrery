<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A showcase job (ShowcaseSeeder): sends a push notification about a shipment.
 */
class SendShippingUpdate implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $order, public int $customer)
    {
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['order:'.$this->order, 'customer:'.$this->customer];
    }

    public function handle(): void
    {
        usleep(2_500 + $this->order % 3 * 700);
    }
}
