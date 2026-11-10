<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Container\Container;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Whether Laravel's notification sender reached a channel's send() and did
 * not report its failure. A long-wait send that throws after
 * the channel delivered (a throwing listener of the application on
 * NotificationSent) is then still a delivered message: its cooldown holds,
 * and the next tick does not send it again.
 *
 * Laravel sends a clone of the notification, so the outcomes are keyed by
 * the notification's id, which begin() sets before the send and the clones
 * keep. It listens to NotificationSending (the send is about to run) and
 * NotificationFailed (the channel's send threw). It registers itself on the
 * first send of a process, after the application's own listeners, so a
 * throwing application listener of NotificationSending stops it from
 * seeing the send (a failure, as it is), and a throwing application
 * listener of NotificationFailed hides the failure (the message counts as
 * delivered; its cooldown holds 5 minutes). A container binding: no state
 * outlives the application, and end() forgets each send.
 */
final class NotificationDeliveries
{
    /** @var array<string, array<string, bool>> per notification id, each channel: true reached its send, false failed */
    private array $outcomes = [];

    /**
     * The process's instance, with its listeners registered on first use.
     */
    public static function current(): self
    {
        $container = Container::getInstance();

        if (! $container->bound(self::class)) {
            $deliveries = new self;
            $container->instance(self::class, $deliveries);
            $events = $container->make('events');
            $events->listen(NotificationSending::class, static function (NotificationSending $event) use ($deliveries): void {
                $deliveries->mark($event->notification, $event->channel, true);
            });
            $events->listen(NotificationFailed::class, static function (NotificationFailed $event) use ($deliveries): void {
                $deliveries->mark($event->notification, $event->channel, false);
            });
        }

        /** @var self $deliveries */
        $deliveries = $container->make(self::class);

        return $deliveries;
    }

    /**
     * Gives $notification an id when it has none, so its clones can be
     * matched, and forgets an earlier outcome of $channel.
     */
    public function begin(Notification $notification, string $channel): void
    {
        if (! is_string($notification->id) || $notification->id === '') {
            $notification->id = (string) Str::uuid();
        }

        $this->outcomes[$notification->id] ??= [];
        unset($this->outcomes[$notification->id][$channel]);
    }

    /**
     * Whether the channel's send() of $notification ran and no failure of
     * it was reported. It forgets the outcome.
     */
    public function end(Notification $notification, string $channel): bool
    {
        $id = (string) $notification->id;
        $delivered = ($this->outcomes[$id][$channel] ?? false) === true;
        unset($this->outcomes[$id][$channel]);

        if (($this->outcomes[$id] ?? null) === []) {
            unset($this->outcomes[$id]);
        }

        return $delivered;
    }

    private function mark(object $notification, string $channel, bool $sent): void
    {
        $id = $notification instanceof Notification ? $notification->id : null;

        // Only a send that begin() prepared: the application's other notifications leave no entry.
        if (! is_string($id) || ! array_key_exists($id, $this->outcomes)) {
            return;
        }

        // A failure is never overwritten by a later Sending of the same channel.
        if (($this->outcomes[$id][$channel] ?? null) === false && $sent) {
            return;
        }

        $this->outcomes[$id][$channel] = $sent;
    }
}
