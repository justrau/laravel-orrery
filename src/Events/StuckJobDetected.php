<?php

namespace Justrau\Orrery\Events;

use Illuminate\Container\Container;
use Illuminate\Notifications\Notification;
use Justrau\Orrery\Notifications\StuckJobDetected as StuckJobNotification;

/**
 * A stuck incident: a job's current cycle shows a stuck
 * signal (Monitoring\StuckSignals: `reservation_expired`,
 * `outcome_not_observed`, `repeated_releases`, `attempts_not_capped`), or
 * its row became `unknown`.
 *
 * The tick phase `stuck_incidents` dispatches it at most 3 times per job,
 * cycle and signal: a process that stops between a dispatch and its record
 * can cause a duplicate within those 3, and delivery is not guaranteed when
 * a listener fails every time.
 *
 * It carries no payload, exception text or context: the job's uuid, the
 * cycle, the signal, the queue connection, the queue, the job's name and
 * the signal's facts (`evidence`, numbers and enum values only, for example
 * `{"gap": 2}` or `{"attempts": 31, "rule": "none"}`; `{"truncated": true}`
 * when the stored facts were longer than 1 KB).
 *
 * The tick also sends a notification of the incident to the routes of the
 * long-wait notification (mail, Slack, SMS; Monitoring\StuckNotifications),
 * tracked per channel apart from this event.
 * Applications can listen to the event as well.
 */
class StuckJobDetected
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $uuid,
        public readonly int $cycle,
        public readonly string $signal,
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $name,
        public readonly array $evidence,
    ) {
    }

    /**
     * The notification of the package's routes, built through the container,
     * so an application can bind its own class (any notification: the sender
     * checks its via() and each channel name). $more is the count of the
     * stuck jobs of the same queue and signal that the cooldown held back.
     */
    public function toNotification(int $more = 0): Notification
    {
        /** @var Notification $notification */
        $notification = Container::getInstance()->make(StuckJobNotification::class, ['event' => $this, 'more' => $more]);

        return $notification;
    }
}
