<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Container\Container;
use Illuminate\Notifications\Notification;
use Justrau\Orrery\Events\LongWaitRecovered;

/**
 * Sends the long-wait recovery to the channels in the event's list (the
 * channels that got the incident's start message), when
 * `notifications.long_wait.recovered` is true. One send per incident, so
 * no cooldown; the down states, the budget of the long-wait phase and the
 * watched sends are SendNotification::deliver()'s.
 */
class SendRecoveredNotification
{
    public function handle(LongWaitRecovered $event): void
    {
        if ($event->channels === [] || Container::getInstance()->make('config')->get('orrery.notifications.long_wait.recovered', true) === false) {
            return;
        }

        $notification = $event->toNotification();

        if (! $notification instanceof Notification) {
            return;
        }

        (new SendNotification)->deliver(NotificationState::current(), $notification, $event->channels, null, 0);
    }
}
