<?php

namespace App\Jobs;

use App\Exceptions\WebhookEndpointGone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A showcase job (ShowcaseSeeder): posts an order event to a partner's webhook. A job created
 * with $fails = true finds the endpoint gone.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public const MESSAGE = 'POST https://hooks.partner.example/orders answered 410 Gone.';

    public int $tries = 3;

    public function __construct(public int $order, public string $event = 'order.paid', public bool $fails = false) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['order:'.$this->order];
    }

    public function handle(): void
    {
        usleep(6_000 + $this->order % 4 * 1_500);

        if ($this->fails) {
            throw new WebhookEndpointGone(self::MESSAGE);
        }
    }
}
