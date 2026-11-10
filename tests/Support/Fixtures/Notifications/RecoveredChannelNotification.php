<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Notifications;

use Illuminate\Notifications\Notification;
use Justrau\Orrery\Contracts\LongWaitRecoveredNotification;

/**
 * A long-wait recovery whose via() returns the channels that the test
 * names (LongWaitIncidentTest). The test binds the contract to it.
 */
final class RecoveredChannelNotification extends Notification implements LongWaitRecoveredNotification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $seconds,
        private readonly array $channels,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }
}
