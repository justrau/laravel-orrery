<?php

namespace App\Jobs;

use App\Exceptions\PaymentGatewayTimeout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A showcase job (ShowcaseSeeder): charges a customer's subscription. A job created with
 * $fails = true times out at the payment gateway on every attempt.
 */
class ChargeSubscription implements ShouldQueue
{
    use Queueable;

    public const MESSAGE = 'The payment gateway did not answer within 30 seconds (POST /v1/charges).';

    public int $tries = 3;

    public function __construct(public int $subscription, public int $customer, public bool $fails = false) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['customer:'.$this->customer];
    }

    public function handle(): void
    {
        usleep(9_000 + $this->subscription % 7 * 1_000);

        if ($this->fails) {
            throw new PaymentGatewayTimeout(self::MESSAGE);
        }
    }
}
