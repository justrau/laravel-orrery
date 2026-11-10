<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Notifications;

use ArrayObject;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Contracts\LongWaitDetectedNotification;
use Justrau\Orrery\Contracts\LongWaitRecoveredNotification;
use Justrau\Orrery\Events\LongWaitRecovered;
use Justrau\Orrery\Listeners\SendNotification;
use Justrau\Orrery\Listeners\SendRecoveredNotification;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\LongWaitIncidents;
use Justrau\Orrery\Monitoring\Phases\LongWaitPhase;
use Justrau\Orrery\Notifications\LongWaitRecovered as LongWaitRecoveredMessage;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\ChannelNotification;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\RecoveredChannelNotification;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\ScriptedChannel;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * The long-wait incident record of LongWaitPhase: an incident opens at the
 * first alerting tick, ends after LongWaitIncidents::QUIET_TICKS
 * consecutive known ticks below the threshold, and then dispatches
 * LongWaitRecovered once, whose package notification goes only to the
 * channels that got the start message.
 */
final class LongWaitIncidentTest extends TestCase
{
    /** A tick time on a whole second. */
    private const START = 1_790_000_000;

    /** @var ArrayObject<int, string> the channel sends, as "<channel>:<start|recovered>" */
    private ArrayObject $sent;

    /** @var ArrayObject<int, LongWaitRecovered> */
    private ArrayObject $recovered;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        // A configured queue is measured also while its jobs table holds none of its jobs.
        $app['config']->set('orrery.waits', ['*' => 60, 'database:default' => 60]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = new ArrayObject;
        $this->recovered = new ArrayObject;
        $recovered = $this->recovered;
        $this->app['events']->listen(LongWaitRecovered::class, static function (LongWaitRecovered $event) use ($recovered): void {
            $recovered[] = $event;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a recovery sent to every channel of via() (also the one whose
     * start failed), a recovery on the first quiet tick or after two, a
     * second recovery of one incident, and a duration other than the last
     * alerting tick minus the first.
     */
    public function test_the_recovery_fires_once_and_only_on_the_channels_that_got_the_start(): void
    {
        $failures = new ArrayObject;
        $this->channel('orrery-good');
        $this->channel('orrery-flaky', static function () use ($failures): void {
            // The start fails once; the channel works again at the recovery.
            if (count($failures) === 0) {
                $failures[] = 1;

                throw new RuntimeException('The flaky channel is down.');
            }
        });
        $this->useChannels(['orrery-good', 'orrery-flaky']);
        $this->waitingJob('default', self::START - 100);

        $this->tickAt(self::START);
        self::assertSame(['orrery-good:start'], $this->sent->getArrayCopy());
        self::assertSame(['orrery-good'], $this->incident('database', 'default')['channels'] ?? null);
        // Still over the limit 30 s later, inside the flaky channel's down backoff.
        $this->tickAt(self::START + 30);

        $this->drain('default');
        $this->tickAt(self::START + 120);
        $this->tickAt(self::START + 180);
        self::assertSame([], $this->recovered->getArrayCopy(), 'two quiet minutes keep the incident open');
        self::assertNotNull($this->incident('database', 'default'));

        $this->tickAt(self::START + 240);
        self::assertSame(['orrery-good:start', 'orrery-good:recovered'], $this->sent->getArrayCopy(), 'the flaky channel got no start, so no recovery');
        self::assertCount(1, $this->recovered);
        self::assertSame(['database', 'default', 30, ['orrery-good']], [$this->recovered[0]->connection, $this->recovered[0]->queue, $this->recovered[0]->seconds, $this->recovered[0]->channels]);
        self::assertNull($this->incident('database', 'default'));

        $this->tickAt(self::START + 300);
        $this->tickAt(self::START + 360);
        self::assertCount(1, $this->recovered, 'recovered exactly once');
        self::assertCount(2, $this->sent);
    }

    /**
     * Rejects: an event that fires only when a channel sent the start, or a
     * recovery message although no channel got the start.
     */
    public function test_the_event_fires_even_when_no_channel_sent_the_start(): void
    {
        $this->useChannels([]);
        $this->waitingJob('default', self::START - 100);

        $this->tickAt(self::START);
        $this->drain('default');

        foreach ([60, 120, 180] as $offset) {
            $this->tickAt(self::START + $offset);
        }

        self::assertCount(1, $this->recovered);
        self::assertSame([], $this->recovered[0]->channels);
        self::assertSame([], $this->sent->getArrayCopy());
    }

    /**
     * Rejects: an incident that ends at the first quiet tick, or a quiet
     * count that an alerting tick does not reset (the queue that flaps
     * would end its incident after three quiet minutes in all and start a
     * new one).
     */
    public function test_a_queue_flapping_around_its_limit_sends_one_start_and_one_recovery(): void
    {
        $this->channel('orrery-good');
        $this->useChannels(['orrery-good']);
        $at = self::START;

        // One tick a minute: over, under, under, over, under, under. Never three quiet minutes in a row.
        foreach ([true, false, false, true, false, false] as $over) {
            $over ? $this->waitingJob('default', $at - 100) : $this->drain('default');
            $this->tickAt($at);
            $at += 60;
        }

        self::assertSame([], $this->recovered->getArrayCopy());
        $this->tickAt($at);

        self::assertSame(['orrery-good:start', 'orrery-good:recovered'], $this->sent->getArrayCopy());
        self::assertCount(1, $this->recovered);
    }

    /**
     * Rejects: a quiet count per tick instead of per minute: several
     * servers, or a manual tick, would end the incident within one minute.
     */
    public function test_several_ticks_in_one_minute_count_one_quiet_minute(): void
    {
        $this->useChannels([]);
        $this->waitingJob('default', self::START - 100);
        $this->tickAt(self::START);
        $this->drain('default');

        // Three ticks in one minute, then one in the next: two quiet minutes.
        foreach ([60, 70, 80, 120] as $offset) {
            $this->tickAt(self::START + $offset);
        }

        self::assertSame([], $this->recovered->getArrayCopy());
        self::assertSame(2, $this->incident('database', 'default')['quiet'] ?? null);

        $this->tickAt(self::START + 180);
        self::assertCount(1, $this->recovered);
    }

    /**
     * Rejects: an unknown row that keeps the quiet count, so quiet minutes
     * on both sides of it add up; or one that ends the incident.
     */
    public function test_an_unknown_row_starts_the_quiet_count_again(): void
    {
        $incidents = $this->app->make(LongWaitIncidents::class);
        $incidents->advance([$this->row(over: true)], self::START);

        self::assertSame([], $incidents->advance([$this->row(over: false)], self::START + 60));
        self::assertSame([], $incidents->advance([$this->row(over: false, unknown: true)], self::START + 120));
        self::assertSame([], $incidents->advance([$this->row(over: false)], self::START + 180));
        self::assertSame([], $incidents->advance([$this->row(over: false)], self::START + 240));
        self::assertNotNull($this->incident('database', 'default'), 'two known quiet minutes after the unknown one');

        $ended = $incidents->advance([$this->row(over: false)], self::START + 300);
        self::assertCount(1, $ended, 'the third known quiet minute ends it');
    }

    /**
     * Rejects: a missing row that keeps the quiet count (it is unknown, not quiet).
     */
    public function test_a_missing_row_starts_the_quiet_count_again(): void
    {
        $incidents = $this->app->make(LongWaitIncidents::class);
        $incidents->advance([$this->row(over: true)], self::START);
        $incidents->advance([$this->row(over: false)], self::START + 60);
        $incidents->advance([$this->row(over: false)], self::START + 120);
        $incidents->advance([], self::START + 180);

        self::assertSame(0, $this->incident('database', 'default')['quiet'] ?? null);
        self::assertSame([], $incidents->advance([$this->row(over: false)], self::START + 240));
    }

    /**
     * Rejects: a stale incident (not seen for longer than the measured
     * window) that stays open for ever, or a new long wait that continues it
     * with its old start and channels.
     */
    public function test_a_stale_incident_ends_silently_and_a_new_alert_starts_a_new_one(): void
    {
        $stale = ['connection' => 'database', 'queue' => 'gone-quiet', 'started_at' => self::START - 9000.0, 'channels' => ['mail'], 'quiet' => 0, 'quiet_minute' => null, 'last_seen' => self::START - LongWaitIncidents::STALE_SECONDS - 1.0];
        $old = ['connection' => 'database', 'queue' => 'default', 'started_at' => self::START - 9000.0, 'channels' => ['mail'], 'quiet' => 1, 'quiet_minute' => null, 'last_seen' => self::START - LongWaitIncidents::STALE_SECONDS - 1.0];
        $this->mirror()->table('orrery_settings')->insert([
            ['key' => LongWaitIncidents::key('database', 'gone-quiet'), 'value' => (string) json_encode($stale)],
            ['key' => LongWaitIncidents::key('database', 'default'), 'value' => (string) json_encode($old)],
        ]);
        $this->useChannels([]);
        $this->waitingJob('default', self::START - 100);

        $this->tickAt(self::START);

        self::assertNull($this->incident('database', 'gone-quiet'));
        $fresh = $this->incident('database', 'default');
        self::assertSame([(float) self::START, [], 0], [(float) ($fresh['started_at'] ?? 0), $fresh['channels'] ?? null, $fresh['quiet'] ?? null]);
        self::assertSame([], $this->recovered->getArrayCopy());
    }

    /**
     * Rejects: an unguarded update of the incident row, which would drop a
     * change that another process made between the read and the write.
     */
    public function test_a_concurrent_change_is_merged_not_overwritten(): void
    {
        $incidents = $this->app->make(LongWaitIncidents::class);
        $incidents->advance([$this->row(over: true)], self::START);
        $key = LongWaitIncidents::key('database', 'default');
        $mirror = $this->mirror();
        $done = false;
        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(function (string $sql) use (&$done, $mirror, $key): void {
            if ($done || ! str_starts_with(strtolower(ltrim($sql)), 'update')) {
                return;
            }

            // Another process adds its channel first.
            $done = true;
            $record = (array) json_decode((string) $mirror->table('orrery_settings')->where('key', $key)->value('value'), true);
            $record['channels'] = ['orrery-other'];
            $mirror->table('orrery_settings')->where('key', $key)->update(['value' => json_encode($record)]);
        });

        $incidents->delivered('database', 'default', ['orrery-good']);

        self::assertSame(['orrery-other', 'orrery-good'], $this->incident('database', 'default')['channels'] ?? null);
    }

    /**
     * Rejects: a recovery lost to a channel that failed at that moment; or
     * a channel tried again after it got the message.
     */
    public function test_a_channel_that_fails_the_recovery_gets_it_on_a_later_tick(): void
    {
        $calls = new ArrayObject;
        $this->channel('orrery-good', static function () use ($calls): void {
            // The start works; the first recovery fails.
            $calls[] = 1;

            if (count($calls) === 2) {
                throw new RuntimeException('The channel is down.');
            }
        });
        $this->useChannels(['orrery-good']);
        $this->waitingJob('default', self::START - 100);
        $this->tickAt(self::START);
        $this->drain('default');

        foreach ([60, 120, 180] as $offset) {
            $this->tickAt(self::START + $offset);
        }

        self::assertSame(['orrery-good:start'], $this->sent->getArrayCopy(), 'the recovery failed');
        self::assertCount(1, $this->recovered);

        // After the channel's down backoff.
        $this->tickAt(self::START + 180 + SendNotification::DOWN_BACKOFF[1]);
        self::assertSame(['orrery-good:start', 'orrery-good:recovered'], $this->sent->getArrayCopy());

        $this->tickAt(self::START + 600);
        self::assertCount(2, $this->sent, 'sent once');
        self::assertSame(0, $this->mirror()->table('orrery_settings')->where('key', 'like', LongWaitIncidents::RECOVERY_PREFIX.'%')->count());
    }

    /**
     * Rejects: a pending recovery kept for ever, or dropped without a health error.
     */
    public function test_a_recovery_that_never_gets_through_is_dropped_with_a_health_error(): void
    {
        $calls = new ArrayObject;
        $this->channel('orrery-good', static function () use ($calls): void {
            $calls[] = 1;

            if (count($calls) > 1) {
                throw new RuntimeException('The channel is down.');
            }
        });
        $this->useChannels(['orrery-good']);
        $this->waitingJob('default', self::START - 100);
        $this->tickAt(self::START);
        $this->drain('default');

        foreach (range(60, 180 + SendRecoveredNotification::RETRY_SECONDS, 60) as $offset) {
            $this->tickAt(self::START + $offset);
        }

        self::assertSame(1, $this->mirror()->table('orrery_settings')->where('key', 'like', LongWaitIncidents::RECOVERY_PREFIX.'%')->count(), 'kept while its time lasts');

        $this->tickAt(self::START + 240 + SendRecoveredNotification::RETRY_SECONDS);
        self::assertSame(0, $this->mirror()->table('orrery_settings')->where('key', 'like', LongWaitIncidents::RECOVERY_PREFIX.'%')->count());
        self::assertArrayHasKey(SendRecoveredNotification::DROPPED_OPERATION, Degradation::record()['errors'] ?? []);
        self::assertSame(['orrery-good:start'], $this->sent->getArrayCopy());
    }

    /**
     * Rejects: a recovery message while `notifications.long_wait.recovered`
     * is false, or an event that the switch also stops.
     */
    public function test_the_recovery_message_can_be_turned_off(): void
    {
        $this->app['config']->set('orrery.notifications.long_wait.recovered', false);
        $this->channel('orrery-good');
        $this->useChannels(['orrery-good']);
        $this->waitingJob('default', self::START - 100);
        $this->tickAt(self::START);
        $this->drain('default');

        foreach ([60, 120, 180] as $offset) {
            $this->tickAt(self::START + $offset);
        }

        self::assertSame(['orrery-good:start'], $this->sent->getArrayCopy());
        self::assertCount(1, $this->recovered);
    }

    /**
     * Rejects: an incident whose connection was removed that stays open
     * for ever or ends with an event; or one whose row is only missing
     * this tick that ends.
     */
    public function test_a_removed_connection_closes_its_incident_silently(): void
    {
        $record = ['connection' => 'gone', 'queue' => 'default', 'started_at' => self::START - 600.0, 'channels' => ['mail'], 'quiet' => 0, 'last_seen' => self::START - 600.0];
        $missing = ['connection' => 'database', 'queue' => 'not-measured', 'started_at' => self::START - 600.0, 'channels' => [], 'quiet' => 2, 'last_seen' => self::START - 600.0];
        $this->mirror()->table('orrery_settings')->insert([
            ['key' => LongWaitIncidents::key('gone', 'default'), 'value' => (string) json_encode($record)],
            ['key' => LongWaitIncidents::key('database', 'not-measured'), 'value' => (string) json_encode($missing)],
        ]);
        $this->useChannels([]);

        foreach ([0, 60, 120, 180] as $offset) {
            $this->tickAt(self::START + $offset);
        }

        self::assertNull($this->incident('gone', 'default'));
        self::assertNotNull($this->incident('database', 'not-measured'), 'a missing row keeps the incident open');
        self::assertSame(0, $this->incident('database', 'not-measured')['quiet'] ?? null, 'and starts its quiet count again');
        self::assertSame([], $this->recovered->getArrayCopy());
    }

    /**
     * Rejects: a recovery text without the queue, the connection or the
     * duration, or one with the alert's tone.
     */
    public function test_the_recovery_texts_name_the_queue_and_the_duration(): void
    {
        $message = new LongWaitRecoveredMessage('database', 'emails', 420);

        self::assertSame('Orrery: the "emails" queue on the "database" connection waits within its limit again, after 420 seconds.', $message->smsText());
        $mail = $message->toMail(new AnonymousNotifiable);
        self::assertSame('success', $mail->level);
        self::assertSame('Orrery: Long Wait Recovered', $mail->subject);
        self::assertStringContainsString('"emails" queue on the "database" connection waits within its limit again, after a long wait of 420 seconds.', html_entity_decode((string) $mail->introLines[0], ENT_QUOTES | ENT_HTML5));
    }

    /**
     * A measured row of database:default (threshold 60).
     *
     * @return array{connection: string, queue: string, oldest_age: ?float, wait: ?int, threshold: int, backlog_unknown: bool}
     */
    private function row(bool $over, bool $unknown = false): array
    {
        return ['connection' => 'database', 'queue' => 'default', 'oldest_age' => $unknown ? null : ($over ? 90.0 : 5.0), 'wait' => $unknown ? null : 0, 'threshold' => 60, 'backlog_unknown' => $unknown];
    }

    private function tickAt(int $at): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($at, 'UTC'));
        $this->app->make(LongWaitPhase::class)->run(new TickBudget(Clock::monotonic() + LongWaitPhase::SHARE_SECONDS));
    }

    private function waitingJob(string $queue, int $availableAt): void
    {
        $this->app['db']->connection()->table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }

    private function drain(string $queue): void
    {
        $this->app['db']->connection()->table('jobs')->where('queue', $queue)->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function incident(string $connection, string $queue): ?array
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', LongWaitIncidents::key($connection, $queue))->value('value');

        return is_string($value) ? (array) json_decode($value, true) : null;
    }

    /**
     * A scripted channel that records each send as "<channel>:<start|recovered>", after $before (which may throw).
     */
    private function channel(string $name, ?Closure $before = null): void
    {
        $sent = $this->sent;
        $send = static function (mixed $notifiable, Notification $notification) use ($sent, $name, $before): void {
            if ($before !== null) {
                $before();
            }

            $sent[] = $name.':'.($notification instanceof LongWaitRecoveredNotification ? 'recovered' : 'start');
        };
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
        $this->app->bind(LongWaitRecoveredNotification::class, static fn ($app, array $parameters): RecoveredChannelNotification => new RecoveredChannelNotification(
            (string) $parameters['connection'],
            (string) $parameters['queue'],
            (int) $parameters['seconds'],
            $channels,
        ));
    }
}
