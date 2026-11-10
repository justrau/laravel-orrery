<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Notifications;

use Closure;
use Illuminate\Notifications\Notification;

/**
 * A notification channel whose send() runs the test's closure
 * (NotificationTest). The closure throws to simulate a failed send.
 */
final class ScriptedChannel
{
    /**
     * @param  Closure(mixed, Notification): mixed  $send
     */
    public function __construct(private readonly Closure $send)
    {
    }

    public function send(mixed $notifiable, Notification $notification): mixed
    {
        return ($this->send)($notifiable, $notification);
    }
}
