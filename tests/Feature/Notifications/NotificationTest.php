<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Notifications;

use ArrayObject;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\SlackMessage as LegacySlackMessage;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Contracts\LongWaitDetectedNotification;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Listeners\NotificationState;
use Justrau\Orrery\Listeners\SendNotification;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Monitoring\TickMeasurement;
use Justrau\Orrery\Monitoring\Phases\LongWaitPhase;
use Justrau\Orrery\Monitoring\StuckNotifications;
use Justrau\Orrery\Notifications\LongWaitDetected as LongWaitNotification;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\ChannelNotification;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\ScriptedChannel;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\ThrowingStore;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Long-wait notifications: a pre-send lock of 60 s per
 * (connection, queue), one watched send per channel, a per-channel cooldown
 * of 5 minutes that starts only after a successful send, the lock released
 * in finally, and a channel without its package skipped with one warning.
 */
final class NotificationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('mail.default', 'array');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: one watcher call around all channels.
     */
    public function test_one_failing_channel_does_not_skip_the_others(): void
    {
        $sent = new ArrayObject;
        $this->channel('orrery-failing', static function (): never {
            throw new RuntimeException('The failing channel is down.');
        });
        $this->channel('orrery-recording', static function () use ($sent): void {
            $sent[] = 'orrery-recording';
        });
        $this->useChannels(['orrery-failing', 'orrery-recording']);

        event(new LongWaitDetected('database', 'default', 90));

        self::assertSame(['orrery-recording'], $sent->getArrayCopy(), 'the second channel still sends');
        $errors = Degradation::record()['errors'] ?? [];
        self::assertArrayHasKey('notification:orrery-failing', $errors, 'the failed channel is its own watched operation');
        self::assertArrayNotHasKey('notification:orrery-recording', $errors);
    }

    /**
     * Rejects: a driver check that lets an error other than
     * InvalidArgumentException out of its channel (a creator that throws, a
     * channel class with an unresolvable dependency): the later channels
     * would send nothing. The failure is a failed send of that channel only:
     * recorded as its watched operation, with no cooldown.
     *
     * Known limit: it would pass a check that catches only RuntimeException.
     */
    public function test_a_channel_whose_driver_cannot_be_built_does_not_skip_the_others(): void
    {
        $sent = new ArrayObject;
        $creations = new ArrayObject;
        $this->app->make(ChannelManager::class)->extend('orrery-unbuildable', function () use ($creations): never {
            $creations[] = 1;

            throw new RuntimeException('The channel cannot be built.');
        });
        $this->channel('orrery-recording', static function () use ($sent): void {
            $sent[] = 'orrery-recording';
        });
        $this->useChannels(['orrery-unbuildable', 'orrery-recording']);

        event(new LongWaitDetected('database', 'default', 90));

        self::assertSame(['orrery-recording'], $sent->getArrayCopy(), 'the second channel still sends');
        $errors = Degradation::record()['errors'] ?? [];
        self::assertArrayHasKey('notification:orrery-unbuildable', $errors, 'the build failure is that channel\'s failed send');

        // No cooldown for the failed channel: the next tick after its down backoff tries it again.
        $before = count($creations);
        Carbon::setTestNow(Carbon::now()->addSeconds(SendNotification::DOWN_BACKOFF[1]));
        event(new LongWaitDetected('database', 'default', 90));
        self::assertGreaterThan($before, count($creations), 'the failed channel is tried again');
    }

    /**
     * Rejects: a long-wait webhook message without a send timeout (a Slack
     * webhook that does not answer would hold the tick for the HTTP
     * client's default), or one other than the stuck messages' timeout.
     *
     * Known limit: it would pass a timeout that the webhook channel ignores (the channel's use of the options is Laravel's).
     */
    public function test_the_long_wait_webhook_message_carries_the_send_timeout(): void
    {
        $message = (new LongWaitNotification('database', 'default', 90))->toSlack((new AnonymousNotifiable)->route('slack', 'https://hooks.slack.test/orrery'));

        self::assertInstanceOf(LegacySlackMessage::class, $message);
        self::assertSame(['timeout' => StuckNotifications::SEND_TIMEOUT_SECONDS, 'connect_timeout' => StuckNotifications::SEND_TIMEOUT_SECONDS], $message->http);
    }

    /**
     * Rejects: the long-wait check (with its sends) before another tick
     * phase: a channel that hangs for 40 s would push the tick past its
     * deadline, and the samples, the operations, the retentions and the
     * stuck notifications would be skipped on every tick while the queue
     * alerts.
     *
     * Known limit: it would pass a long-wait phase that is last only while no other phase has a higher priority (the tagged phases of today are run).
     */
    #[FreshSchema]
    public function test_a_slow_long_wait_channel_delays_no_other_phase(): void
    {
        $this->app['config']->set('orrery.waits', ['*' => 60]);
        $start = Carbon::now()->startOfSecond();
        Carbon::setTestNow($start);
        Queue::push(new BasicJob);
        Carbon::setTestNow($start->copy()->addSeconds(120));
        Clock::freezeMonotonicForTesting();
        $sent = new ArrayObject;
        $this->channel('orrery-slow', static function () use ($sent): void {
            // A slow channel: the monotonic clock moves on (the test froze it).
            Clock::advanceMonotonicForTesting(40.0);
            $sent[] = 'orrery-slow';
        });
        $this->useChannels(['orrery-slow']);

        $report = $this->app->make(TickRunner::class)->run($this->app->tagged(TickCommand::PHASES_TAG));

        self::assertSame(['orrery-slow'], $sent->getArrayCopy(), 'the long-wait notification was sent');
        self::assertContains('samples', $report->ran());
        self::assertContains('operations', $report->ran());
        self::assertContains('stuck_notifications', $report->ran());
        self::assertSame([], $report->skipped(), 'no phase was skipped');
        self::assertSame(['long_wait'], $report->overruns(), 'only the last phase ran past the deadline');
    }

    /**
     * Rejects: a long-wait check that the tick skips once an earlier phase
     * used the deadline: while the mirror is slow or the
     * backlog is large, no queue would alert; and a late check that
     * measures the queues again instead of using the run's measurement.
     *
     * Known limit: it would pass a runner that also runs the other skipped phases after the deadline when they are not asserted by name (operation_retention is).
     */
    #[FreshSchema]
    public function test_the_long_wait_check_runs_after_the_tick_deadline(): void
    {
        $this->app['config']->set('orrery.waits', ['*' => 60]);
        $start = Carbon::now()->startOfSecond();
        Carbon::setTestNow($start);
        Queue::push(new BasicJob);
        Carbon::setTestNow($start->copy()->addSeconds(120));
        Clock::freezeMonotonicForTesting();
        $sent = new ArrayObject;
        $this->channel('orrery-recording', static function () use ($sent): void {
            $sent[] = 'orrery-recording';
        });
        $this->useChannels(['orrery-recording']);
        // A phase after the samples that uses the whole deadline.
        $slow = new class implements TickPhase
        {
            public function name(): string
            {
                return 'orrery_test_slow';
            }

            public function priority(): int
            {
                return 500;
            }

            public function run(TickBudget $budget): void
            {
                Clock::advanceMonotonicForTesting(31.0);
            }
        };

        $report = $this->app->make(TickRunner::class)->run([...$this->app->tagged(TickCommand::PHASES_TAG), $slow]);

        self::assertSame(['orrery-recording'], $sent->getArrayCopy(), 'the long-wait notification was sent after the deadline');
        self::assertContains('long_wait', $report->ran());
        self::assertContains('stuck_notifications', $report->ran());
        self::assertContains('operation_retention', $report->skipped(), 'a phase that is not a notification phase is still skipped');
        self::assertNotContains('long_wait', $report->skipped());
        self::assertSame(['orrery_test_slow'], $report->overruns());
        self::assertSame(1, $this->app->make(TickMeasurement::class)->measurements(), 'the late check uses the measurement of its run');
    }

    /**
     * Rejects: a long-wait channel that hangs and fails tried again first on
     * every tick: it would use the phase's share each time, and
     * no later channel or queue would ever be sent; and a send after the
     * share ended (one hang per channel would add up).
     *
     * Known limit: it would pass a down state of one channel that also holds the other channels back while no other channel fails here (the recording channel never fails).
     */
    #[FreshSchema]
    public function test_a_hanging_channel_is_down_and_the_later_queues_still_alert(): void
    {
        $this->app['config']->set('orrery.waits', ['*' => 60]);
        $start = Carbon::now()->startOfSecond();
        Carbon::setTestNow($start);
        Queue::push(new BasicJob, '', 'alpha');
        Queue::push(new BasicJob, '', 'beta');
        Carbon::setTestNow($start->copy()->addSeconds(120));
        Clock::freezeMonotonicForTesting();
        $hangs = new ArrayObject;
        $sent = new ArrayObject;
        $this->channel('orrery-hang', static function () use ($hangs): never {
            // A mail server that does not answer: the send gives up after 40 s.
            $hangs[] = 1;
            Clock::advanceMonotonicForTesting(40.0);
            Carbon::setTestNow(Carbon::now()->addSeconds(40));

            throw new RuntimeException('The mail server did not answer.');
        });
        $this->channel('orrery-recording', static function (mixed $notifiable, ChannelNotification $notification) use ($sent): void {
            $sent[] = $notification->queue;
        });
        $this->useChannels(['orrery-hang', 'orrery-recording']);
        $phase = $this->app->make(LongWaitPhase::class);

        $phase->run(new TickBudget(Clock::monotonic() + LongWaitPhase::SHARE_SECONDS));
        self::assertCount(1, $hangs);
        self::assertSame([], $sent->getArrayCopy(), 'no send after the share ended');

        // The next tick, a minute after the first: the hanging channel is down, the others send for every queue.
        Carbon::setTestNow($start->copy()->addSeconds(180));
        $phase->run(new TickBudget(Clock::monotonic() + LongWaitPhase::SHARE_SECONDS));
        self::assertCount(1, $hangs, 'the down channel was not tried again');
        $queues = $sent->getArrayCopy();
        sort($queues);
        self::assertSame(['alpha', 'beta'], $queues);
    }

    /**
     * Rejects: a lock and a cooldown that live only in a store that throws
     * (a Redis or Memcached outage): every long-wait event would end at the
     * lock, and no channel would send; or, after a fallback for the lock
     * only, a channel sent on every tick.
     *
     * Known limit: it would pass a fallback that keeps the cooldown in the mirror but the lock in no store (one process sends here).
     */
    public function test_a_throwing_cache_store_still_sends_and_keeps_the_cooldown(): void
    {
        $this->app['config']->set('cache.stores.orrery-throwing', ['driver' => 'orrery-throwing']);
        $this->app['config']->set('cache.default', 'orrery-throwing');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        Cache::clearResolvedInstances();
        // A plain closure: the package's tests never pass a static closure to an extend() creator.
        Cache::extend('orrery-throwing', fn ($app): \Illuminate\Cache\Repository => new \Illuminate\Cache\Repository(new ThrowingStore));
        $sent = new ArrayObject;
        $this->channel('orrery-recording', static function () use ($sent): void {
            $sent[] = 'orrery-recording';
        });
        $this->useChannels(['orrery-recording']);

        event(new LongWaitDetected('database', 'default', 90));
        self::assertSame(['orrery-recording'], $sent->getArrayCopy(), 'the store outage blocks no send');

        event(new LongWaitDetected('database', 'default', 90));
        self::assertCount(1, $sent, 'the cooldown holds without the store');

        Carbon::setTestNow(Carbon::now()->addSeconds(SendNotification::COOLDOWN_SECONDS + 1));
        event(new LongWaitDetected('database', 'default', 90));
        self::assertCount(2, $sent, 'the cooldown ends');
    }

    /**
     * Rejects: a cooldown kept in a store of one process (`array`, or
     * `null`, which keeps nothing): each tick is a new process, so each
     * channel would get one message per minute.
     *
     * Known limit: it would pass a rule that knows only the array store when the null store is not in the data set (both are).
     */
    #[DataProvider('processStores')]
    public function test_a_store_of_one_process_keeps_the_cooldown_in_the_package_tables(string $store): void
    {
        $this->app['config']->set('cache.default', $store);
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        Cache::clearResolvedInstances();
        $sent = new ArrayObject;
        $this->channel('orrery-recording', static function () use ($sent): void {
            $sent[] = 'orrery-recording';
        });
        $this->useChannels(['orrery-recording']);

        event(new LongWaitDetected('database', 'default', 90));
        // The next tick is a new process: a new store instance with nothing in it.
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        Cache::clearResolvedInstances();
        event(new LongWaitDetected('database', 'default', 90));

        self::assertCount(1, $sent, 'the cooldown outlives the process');
    }

    /**
     * Rejects: a cooldown kept behind a `failover` store whose first store is
     * down: the failover store answers from its `array` store
     * without a throw, so each tick, a new process, sends again.
     *
     * Known limit: it would pass a rule that sends every failover store to the package tables, also one whose stores all last (that is the rule built).
     */
    public function test_a_failover_store_keeps_the_cooldown_in_the_package_tables(): void
    {
        $this->app['config']->set('cache.stores.orrery-throwing', ['driver' => 'orrery-throwing']);
        $this->app['config']->set('cache.stores.orrery-failover', ['driver' => 'failover', 'stores' => ['orrery-throwing', 'array']]);
        $this->app['config']->set('cache.default', 'orrery-failover');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        Cache::clearResolvedInstances();
        // A plain closure: the package's tests never pass a static closure to an extend() creator.
        Cache::extend('orrery-throwing', fn ($app): \Illuminate\Cache\Repository => new \Illuminate\Cache\Repository(new ThrowingStore));
        $sent = new ArrayObject;
        $this->channel('orrery-recording', static function () use ($sent): void {
            $sent[] = 'orrery-recording';
        });
        $this->useChannels(['orrery-recording']);

        event(new LongWaitDetected('database', 'default', 90));
        // The next tick is a new process: the failover store's array store is empty again.
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        Cache::clearResolvedInstances();
        Cache::extend('orrery-throwing', fn ($app): \Illuminate\Cache\Repository => new \Illuminate\Cache\Repository(new ThrowingStore));
        event(new LongWaitDetected('database', 'default', 90));

        self::assertCount(1, $sent, 'the cooldown outlives the process');
    }

    /**
     * Rejects: a lock in the package tables whose FOR UPDATE reads a row
     * that may not exist yet: on Postgres that locks nothing, so two ticks
     * at once both take the lock and both send. The key is
     * inserted, expired, before the locking read. It would still pass on
     * SQLite and MySQL without the insert (BEGIN IMMEDIATE and the gap lock
     * serialize there); the statement order is what is asserted.
     */
    public function test_the_package_table_lock_inserts_its_key_before_it_locks_it(): void
    {
        $state = \Justrau\Orrery\Listeners\NotificationState::current();
        self::assertTrue($state->usesMirror(), 'the harness cache store is array');
        $statements = [];
        Event::listen(\Illuminate\Database\Events\QueryExecuted::class, static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$statements): void {
            if (str_contains($query->sql, 'orrery_settings')) {
                $statements[] = $query->sql;
            }
        });

        self::assertTrue($state->add('orrery:test:lock', 60));
        self::assertMatchesRegularExpression('/^\s*insert\b/i', $statements[0] ?? '', 'the locking read came before the key existed');
        self::assertMatchesRegularExpression('/^\s*select\b/i', $statements[1] ?? '');
        self::assertTrue($state->has('orrery:test:lock'));
        self::assertFalse($state->add('orrery:test:lock', 60), 'a held key was taken again');
        self::assertFalse($state->has('orrery:test:other'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function processStores(): array
    {
        return ['array' => ['array'], 'null' => ['null']];
    }

    /**
     * Rejects: a delivered long-wait message that counts as a failed send
     * because an application listener of NotificationSent throws after the
     * channel sent: no cooldown, the channel down, and the message sent
     * again at the next tick. It would still pass a rule that
     * also takes a throwing NotificationFailed listener for a delivery (the
     * class docblock states that limit); the second part keeps a real
     * failure a failure.
     */
    public function test_a_throwing_notification_sent_listener_keeps_the_cooldown(): void
    {
        $sent = new ArrayObject;
        $this->channel('orrery-recording', static function () use ($sent): void {
            $sent[] = 'orrery-recording';
        });
        $this->useChannels(['orrery-recording']);
        Event::listen(NotificationSent::class, static function (): never {
            throw new RuntimeException('an application listener of NotificationSent fails');
        });

        event(new LongWaitDetected('database', 'default', 90));
        event(new LongWaitDetected('database', 'default', 90));

        self::assertCount(1, $sent, 'the delivered message was sent again within its cooldown');
        self::assertFalse(NotificationState::current()->has(SendNotification::downKey('orrery-recording')), 'the channel is down after a delivery');

        $this->channel('orrery-failing', static function (): never {
            throw new RuntimeException('the channel fails');
        });
        $this->useChannels(['orrery-failing']);
        event(new LongWaitDetected('database', 'default', 90));
        self::assertTrue(NotificationState::current()->has(SendNotification::downKey('orrery-failing')), 'a failed channel counted as delivered');
    }

    /**
     * Rejects: a cooldown set before the send, and a failed channel tried
     * again before its down backoff ends.
     */
    public function test_cooldown_only_after_success(): void
    {
        $attempts = new ArrayObject;
        $sent = new ArrayObject;
        $this->channel('orrery-flaky', static function () use ($attempts, $sent): void {
            $attempts[] = count($attempts) + 1;

            if (count($attempts) === 1) {
                throw new RuntimeException('The first send fails.');
            }

            $sent[] = count($attempts);
        });
        $this->useChannels(['orrery-flaky']);

        event(new LongWaitDetected('database', 'default', 90));
        self::assertSame([1], $attempts->getArrayCopy());
        self::assertSame([], $sent->getArrayCopy());

        // The failed send set no cooldown, and finally released the lock: the channel is down for its
        // backoff, and the next tick after it sends.
        event(new LongWaitDetected('database', 'default', 90));
        self::assertSame([1], $attempts->getArrayCopy(), 'the down channel is not tried within its backoff');
        Carbon::setTestNow(Carbon::now()->addSeconds(SendNotification::DOWN_BACKOFF[1]));
        event(new LongWaitDetected('database', 'default', 90));
        self::assertSame([2], $sent->getArrayCopy(), 'the second send runs, because the failure set no cooldown');

        // The success starts the cooldown of 5 minutes.
        event(new LongWaitDetected('database', 'default', 90));
        self::assertSame([1, 2], $attempts->getArrayCopy(), 'the cooldown skips the third send');

        Carbon::setTestNow(Carbon::now()->addSeconds(301));
        event(new LongWaitDetected('database', 'default', 90));
        self::assertSame([2, 3], $sent->getArrayCopy(), 'the cooldown ends after 5 minutes');
    }

    /**
     * Rejects: a send without the pre-send lock, a lock shared by all queues, or a lock that a failure keeps.
     */
    public function test_pre_send_lock_blocks_a_concurrent_send_and_is_released(): void
    {
        // A store that outlives the process, so the lock is the cache store's lock.
        $this->useSharedCache();
        $sent = new ArrayObject;
        $this->channel('orrery-recording', static function (mixed $notifiable, ChannelNotification $notification) use ($sent): void {
            $sent[] = $notification->queue;
        });
        $this->useChannels(['orrery-recording']);

        $held = Cache::lock(SendNotification::lockKey('database', 'default'), 60);
        self::assertTrue($held->get());

        event(new LongWaitDetected('database', 'default', 90));
        event(new LongWaitDetected('database', 'emails', 90));
        self::assertSame(['emails'], $sent->getArrayCopy(), 'another process holds the lock of database:default; database:emails has its own lock');

        $held->release();
        event(new LongWaitDetected('database', 'default', 90));
        self::assertSame(['emails', 'default'], $sent->getArrayCopy());
        self::assertTrue(Cache::lock(SendNotification::lockKey('database', 'default'), 60)->get(), 'the listener released its lock');
    }

    /**
     * Rejects: an exception, or one warning per tick.
     */
    public function test_missing_channel_package_is_skipped_with_one_warning(): void
    {
        // laravel/slack-notification-channel is a dev dependency, so a fake manager
        // stands for an application without it: its driver('slack') throws.
        $this->app->forgetInstance(ChannelManager::class);
        $this->app->singleton(ChannelManager::class, static fn ($app): MissingSlackChannelManager => new MissingSlackChannelManager($app));
        self::assertInstanceOf(MissingSlackChannelManager::class, $this->app->make(Dispatcher::class));
        Orrery::routeSlackNotificationsTo('https://hooks.slack.test/orrery');
        Orrery::routeMailNotificationsTo('ops@example.test');

        $sent = new ArrayObject;
        Event::listen(NotificationSent::class, static function (NotificationSent $event) use ($sent): void {
            $sent[] = $event->channel;
        });
        // The logged warnings, from Laravel's MessageLogged event. Log::spy() would
        // replace the log manager: its null channel() breaks Laravel's
        // deprecation handler, which a mail send on the lowest Symfony
        // components reaches (css-selector passes null to strtolower()).
        $warnings = new ArrayObject;
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($warnings): void {
            if ($event->level === 'warning' && str_contains($event->message, '[slack]')) {
                $warnings[] = $event->message;
            }
        });

        event(new LongWaitDetected('database', 'default', 90));
        // A second tick, after the mail cooldown ended.
        Carbon::setTestNow(Carbon::now()->addSeconds(301));
        event(new LongWaitDetected('database', 'default', 90));

        self::assertSame(['mail', 'mail'], $sent->getArrayCopy(), 'the mail channel sends in both ticks');
        self::assertCount(1, $warnings, 'one warning for the missing slack channel, not one per tick');
        self::assertArrayNotHasKey('notification:slack', Degradation::record()['errors'] ?? [], 'a missing package is no failed send');
    }

    /**
     * Rejects: a notification error that stops tick.
     */
    #[FreshSchema]
    public function test_notification_failure_during_an_outage_keeps_tick_running(): void
    {
        $this->app['config']->set('orrery.waits', ['*' => 60]);
        $start = Carbon::now()->startOfSecond();
        Carbon::setTestNow($start);
        Queue::push(new BasicJob);
        Carbon::setTestNow($start->copy()->addSeconds(120));
        $jobs = DB::connection(TestEnvironment::PRIMARY)->table('jobs')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();

        $calls = new ArrayObject;
        $sent = new ArrayObject;
        $outage = null;
        $this->channel('orrery-outage', function () use ($calls, $sent, &$outage): void {
            $calls[] = count($calls) + 1;

            if (count($calls) === 1) {
                // The package storage fails while the notification is sent.
                $outage = $this->mirrorOutage();

                throw new RuntimeException('The channel failed during the mirror outage.');
            }

            $sent[] = count($calls);
        });
        $this->useChannels(['orrery-outage']);

        $status = Artisan::call('orrery:tick');
        $errors = Degradation::record()['errors'] ?? [];

        self::assertNotNull($outage, 'the channel began the outage');
        $outage->end();

        self::assertSame(0, $status, 'tick exits 0: '.Artisan::output());
        self::assertSame([1], $calls->getArrayCopy());
        self::assertArrayHasKey('notification:orrery-outage', $errors);
        self::assertArrayNotHasKey('tick:long_wait', $errors, 'the notification error stays inside its channel operation');
        // The long-wait check is the last phase (test_a_slow_long_wait_channel_delays_no_other_phase), so no
        // phase follows it: the check itself ended without an error.
        self::assertSame($jobs, DB::connection(TestEnvironment::PRIMARY)->table('jobs')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(), 'the application table is unchanged');

        // After recovery, the next tick sends: the failure set no cooldown. A minute later, as the
        // schedule runs it: the lock in the mirror (the array store is of one process) was not
        // released in the outage, and it expires after its seconds.
        Watcher::advanceMonotonicForTesting(31);
        Carbon::setTestNow($start->copy()->addSeconds(120 + SendNotification::LOCK_SECONDS));
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame([2], $sent->getArrayCopy());
    }

    /**
     * @param  \Closure(mixed, mixed): mixed  $send
     */
    private function channel(string $name, \Closure $send): void
    {
        // A plain closure: the package's tests never pass a static closure to an extend() creator.
        $this->app->make(ChannelManager::class)->extend($name, fn (): ScriptedChannel => new ScriptedChannel($send));
    }

    /**
     * @param  list<string>  $channels
     */
    private function useChannels(array $channels): void
    {
        $this->app->bind(LongWaitDetectedNotification::class, static fn ($app, array $parameters): ChannelNotification => new ChannelNotification(
            (string) $parameters['connection'],
            (string) $parameters['queue'],
            (int) $parameters['seconds'],
            $channels,
        ));
    }
}
