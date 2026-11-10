<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A showcase job (ShowcaseSeeder): creates the PDF invoice of an order.
 */
class GenerateInvoicePdf implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $order, public int $customer) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['order:'.$this->order, 'customer:'.$this->customer];
    }

    public function handle(): void
    {
        usleep(22_000 + $this->order % 6 * 2_000);
    }
}
