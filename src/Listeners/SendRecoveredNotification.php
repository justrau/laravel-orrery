<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Container\Container;
use Illuminate\Notifications\Notification;
use Justrau\Orrery\Events\LongWaitRecovered;
use Justrau\Orrery\Monitoring\LongWaitIncidents;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;

/**
 * Sends the long-wait recovery to the channels in the event's list (the
 * channels that got the incident's start message), when
 * `notifications.long_wait.recovered` is true. One message per channel and
 * incident, so no cooldown; the down states, the budget of the long-wait
 * phase and the watched sends are SendNotification::deliver()'s.
 *
 * A channel that did not get it (down, failed, or not tried because the
 * phase's budget ended) is kept as a pending recovery
 * (LongWaitIncidents::keepRecovery()). LongWaitPhase calls retry() on
 * each tick, which sends the pending channels again for RETRY_SECONDS
 * after the incident ended; then they are dropped, with a health error
 * (DROPPED_OPERATION). A channel that the notification's via() no longer
 * names, or whose driver is missing, is not kept.
 */
class SendRecoveredNotification
{
    /** How long the channels that did not get a recovery are tried again. */
    public const RETRY_SECONDS = 600;

    /** The health error of a recovery that some channels never got. */
    public const DROPPED_OPERATION = 'long_wait:recovery_dropped';

    public function handle(LongWaitRecovered $event): void
    {
        if ($event->channels === [] || ! self::enabled()) {
            return;
        }

        $remaining = $this->send($event, $event->channels);

        if ($remaining !== []) {
            Container::getInstance()->make(LongWaitIncidents::class)
                ->keepRecovery($event->connection, $event->queue, $event->seconds, $event->startedAt, $remaining, Clock::now() + self::RETRY_SECONDS);
        }
    }

    /**
     * Sends the pending recoveries again; drops those past their time.
     */
    public function retry(float $now): void
    {
        $incidents = Container::getInstance()->make(LongWaitIncidents::class);

        foreach ($incidents->recoveries() as $key => [$stored, $recovery]) {
            if (! self::enabled()) {
                $incidents->close($key, $stored);

                continue;
            }

            if ($recovery['until'] < $now) {
                if ($incidents->close($key, $stored)) {
                    $problem = sprintf('The long-wait recovery was not delivered to %d channel(s) within %d seconds.', count($recovery['channels']), self::RETRY_SECONDS);
                    Degradation::error(self::DROPPED_OPERATION, $problem);
                    Watcher::instance()->report(self::DROPPED_OPERATION, $problem);
                }

                continue;
            }

            $event = new LongWaitRecovered($recovery['connection'], $recovery['queue'], $recovery['seconds'], $recovery['started_at'], $recovery['channels']);
            $incidents->narrowRecovery($key, $stored, $recovery, $this->send($event, $recovery['channels']));
        }
    }

    /**
     * Sends the recovery to $channels; the channels to try again.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function send(LongWaitRecovered $event, array $channels): array
    {
        $notification = $event->toNotification();

        if (! $notification instanceof Notification) {
            return [];
        }

        $outcomes = (new SendNotification)->deliver(NotificationState::current(), $notification, $channels, null, 0);
        $again = array_filter($outcomes, static fn (string $outcome): bool => in_array($outcome, [SendNotification::DOWN, SendNotification::FAILED, SendNotification::BUDGET], true));

        return array_map('strval', array_keys($again));
    }

    private static function enabled(): bool
    {
        return Container::getInstance()->make('config')->get('orrery.notifications.long_wait.recovered', true) !== false;
    }
}
