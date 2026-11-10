<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection\Jobs;

use Illuminate\Notifications\Notification;

/**
 * A notification for the Slack route of an AnonymousNotifiable.
 */
class CanaryNotification extends Notification
{
    public string $clientSecret;

    public function __construct()
    {
        $this->clientSecret = 'canary-notification-secret';
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['slack'];
    }
}
