<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * A showcase job (ShowcaseSeeder): mails the confirmation of an order. A job created with
 * $fails = true gets the SMTP server's 421 answer on every attempt.
 */
class SendOrderConfirmation implements ShouldQueue
{
    use Queueable;

    public const MESSAGE = 'Expected response code "250" but got code "421", with message "421 4.7.0 Try again later, closing connection."';

    public int $tries = 3;

    public function __construct(public int $order, public int $customer, public bool $fails = false)
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
        usleep(4_000 + $this->order % 5 * 1_000);

        if ($this->fails) {
            throw new TransportException(self::MESSAGE);
        }
    }
}
