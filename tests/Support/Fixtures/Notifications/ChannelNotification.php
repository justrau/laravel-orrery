<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Notifications;

use Illuminate\Notifications\Notification;
use Justrau\Orrery\Contracts\LongWaitDetectedNotification;

/**
 * A long-wait notification whose via() returns the channels that the test
 * names (NotificationTest). The test binds the contract to it.
 */
final class ChannelNotification extends Notification implements LongWaitDetectedNotification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $seconds,
        private readonly array $channels,
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }
}
