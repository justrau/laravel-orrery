<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Container\Container;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\Phases\LongWaitPhase;
use Justrau\Orrery\Monitoring\StuckNotifications;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

/**
 * Sends the long-wait notification.
 *
 * - A pre-send cache lock of LOCK_SECONDS per (connection, queue): when
 *   another process holds it, this process sends nothing. finally releases
 *   it. A cache store without locks sends without one.
 * - Each channel of the notification's via() is sent on its own, inside its
 *   own watched operation `notification:<channel>`, so one failing channel
 *   never skips the others.
 * - A per-channel cooldown of COOLDOWN_SECONDS per (connection, queue)
 *   starts only after a successful send. A failed channel sends again when
 *   its down backoff ends.
 * - A failed channel is down for DOWN_BACKOFF (60 s after a failure, 300 s
 *   after a failure that follows another one within STREAK_SECONDS), for
 *   every queue: a channel that hangs is not tried first on every tick.
 *   A sent message ends the streak.
 * - Inside the long-wait phase, no channel is sent after the phase's
 *   budget ended (LongWaitPhase::BUDGET).
 * - A channel whose driver the notification manager does not know (for
 *   example `slack` without laravel/slack-notification-channel) is skipped.
 *   A driver that cannot be built for another reason fails inside its own
 *   watched send, as that channel's failed send.
 *   One warning per channel is logged, remembered in the cache for
 *   WARNING_SECONDS, so the next ticks do not repeat it.
 * - The lock, the cooldowns, the down states and the markers live in the
 *   default cache store, or in the mirror's orrery_settings when that store writes to a host
 *   SQLite file (D17), keeps its entries for one process only (`array`,
 *   `null`), or throws (NotificationState).
 */
class SendNotification
{
    public const LOCK_SECONDS = 60;

    public const COOLDOWN_SECONDS = 300;

    public const WARNING_SECONDS = 86400;

    /** How long a channel is down after its 1st and its later consecutive failures (as the stuck notifications). */
    public const DOWN_BACKOFF = StuckNotifications::DOWN_BACKOFF;

    /** How long a failure counts for the next one's longer backoff. */
    public const STREAK_SECONDS = 3600;

    public function handle(LongWaitDetected $event): void
    {
        $state = NotificationState::current();
        $release = $state->lock(self::lockKey($event->connection, $event->queue), self::LOCK_SECONDS);

        if ($release === null) {
            return;
        }

        $container = Container::getInstance();
        $budget = $container->bound(LongWaitPhase::BUDGET) ? $container->make(LongWaitPhase::BUDGET) : null;

        try {
            $notification = $event->toNotification();

            if (! $notification instanceof Notification || ! method_exists($notification, 'via')) {
                return;
            }

            $routes = $this->routes();
            $channels = $notification->via($this->notifiable($routes));

            foreach ((array) $channels as $channel) {
                if (! is_string($channel) || $channel === '') {
                    continue;
                }

                if ($budget instanceof TickBudget && $budget->exhausted()) {
                    break;
                }

                $this->sendChannel($state, $event, $notification, $channel, $routes[$channel] ?? null);
            }
        } finally {
            $release();
        }
    }

    public static function lockKey(string $connection, string $queue): string
    {
        return 'orrery:notification:lock:'.sha1($connection."\0".$queue);
    }

    public static function cooldownKey(string $connection, string $queue, string $channel): string
    {
        return 'orrery:notification:cooldown:'.sha1($connection."\0".$queue."\0".$channel);
    }

    public static function downKey(string $channel): string
    {
        return 'orrery:notification:down:'.sha1($channel);
    }

    public static function streakKey(string $channel): string
    {
        return 'orrery:notification:streak:'.sha1($channel);
    }

    private function sendChannel(NotificationState $state, LongWaitDetected $event, Notification $notification, string $channel, mixed $route): void
    {
        $cooldown = self::cooldownKey($event->connection, $event->queue, $channel);

        if ($state->has($cooldown) || $state->has(self::downKey($channel))) {
            return;
        }

        if (! $this->channelAvailable($channel)) {
            $this->warnMissing($state, $channel);

            return;
        }

        $deliveries = NotificationDeliveries::current();
        $deliveries->begin($notification, $channel);
        $sent = Watcher::instance()->attempt('notification:'.$channel, static function () use ($notification, $channel, $route): void {
            $notifiable = (new AnonymousNotifiable)->route($channel, $route);
            Container::getInstance()->make(Dispatcher::class)->sendNow($notifiable, $notification, [$channel]);
        });

        // A send that threw after the channel delivered (a throwing listener of
        // NotificationSent) is a sent message: its cooldown holds.
        $delivered = $deliveries->end($notification, $channel);

        if ($sent->succeeded() || $delivered) {
            $state->put($cooldown, self::COOLDOWN_SECONDS);

            if ($state->has(self::streakKey($channel))) {
                $state->forget(self::streakKey($channel));
            }

            return;
        }

        // Watched: a state store that fails now (the mirror in an outage) ends only this record.
        Watcher::instance()->attempt(NotificationState::OPERATION, static function () use ($state, $channel): void {
            $streak = self::streakKey($channel);
            $state->put(self::downKey($channel), self::DOWN_BACKOFF[$state->has($streak) ? 2 : 1]);
            $state->put($streak, self::STREAK_SECONDS);
        });
    }

    /**
     * False when the notification manager has no driver for the channel. A
     * test fake of the manager accepts every channel. Any other error of the
     * driver's build gives true, so this check, which may run outside the
     * channel's watched operation, never throws.
     */
    private function channelAvailable(string $channel): bool
    {
        // Notification::fake() replaces the manager behind the dispatcher contract.
        /** @var mixed $manager */
        $manager = Container::getInstance()->make(Dispatcher::class);

        if (! $manager instanceof ChannelManager) {
            return true;
        }

        try {
            $manager->driver($channel);
        } catch (InvalidArgumentException) {
            return false;
        } catch (Throwable) {
            // A driver that cannot be built (a creator that throws, a dependency that cannot be
            // resolved): the watched send builds it again and fails there, as that channel's failed try.
            return true;
        }

        return true;
    }

    private function warnMissing(NotificationState $state, string $channel): void
    {
        try {
            $first = $state->add('orrery:notification:missing:'.sha1($channel), self::WARNING_SECONDS);
        } catch (Throwable) {
            $first = true;
        }

        if ($first) {
            Log::warning("Orrery skipped the long-wait notification channel [{$channel}]: the notification manager has no driver for it. Install its package (for example laravel/slack-notification-channel or laravel/vonage-notification-channel), or remove its route.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function routes(): array
    {
        return array_filter([
            'slack' => Orrery::$slackWebhookUrl,
            'vonage' => Orrery::$smsNumber,
            'mail' => Orrery::$email,
        ], static fn (mixed $route): bool => $route !== null);
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    private function notifiable(array $routes): AnonymousNotifiable
    {
        $notifiable = new AnonymousNotifiable;

        foreach ($routes as $channel => $route) {
            $notifiable->route($channel, $route);
        }

        return $notifiable;
    }
}
