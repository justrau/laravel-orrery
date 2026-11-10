<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Notifications;

use ArrayObject;
use Closure;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Contracts\LongWaitDetectedNotification;
use Justrau\Orrery\Contracts\LongWaitRecoveredNotification;
use Justrau\Orrery\Events\LongWaitRecovered;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\LongWaitIncidents;
use Justrau\Orrery\Monitoring\Phases\LongWaitPhase;
use Justrau\Orrery\Notifications\LongWaitRecovered as LongWaitRecoveredMessage;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\ChannelNotification;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\RecoveredChannelNotification;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\ScriptedChannel;
use Justrau\Orrery\Tests\TestCase;
use Illuminate\Notifications\Notification;
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
     * second recovery of one incident, and a duration that is not measured
     * from the first alerting tick.
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

        $this->drain('default');
        $this->tickAt(self::START + 60);
        $this->tickAt(self::START + 120);
        self::assertSame([], $this->recovered->getArrayCopy(), 'two quiet ticks keep the incident open');
        self::assertNotNull($this->incident('database', 'default'));

        $this->tickAt(self::START + 180);
        self::assertSame(['orrery-good:start', 'orrery-good:recovered'], $this->sent->getArrayCopy(), 'the flaky channel got no start, so no recovery');
        self::assertCount(1, $this->recovered);
        self::assertSame(['database', 'default', 180, ['orrery-good']], [$this->recovered[0]->connection, $this->recovered[0]->queue, $this->recovered[0]->seconds, $this->recovered[0]->channels]);
        self::assertNull($this->incident('database', 'default'));

        $this->tickAt(self::START + 240);
        $this->tickAt(self::START + 300);
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
     * would end its incident after three quiet ticks in all and start a new
     * one).
     */
    public function test_a_queue_flapping_around_its_limit_sends_one_start_and_one_recovery(): void
    {
        $this->channel('orrery-good');
        $this->useChannels(['orrery-good']);
        $at = self::START;

        // Over, under, under, over, under, under, over: never three quiet ticks in a row.
        foreach ([true, false, false, true, false, false, true] as $over) {
            $over ? $this->waitingJob('default', $at - 100) : $this->drain('default');
            $this->tickAt($at);
            $at += 20;
        }

        self::assertSame([], $this->recovered->getArrayCopy());
        $this->drain('default');

        foreach ([0, 1, 2] as $tick) {
            $this->tickAt($at + 20 * $tick);
        }

        self::assertSame(['orrery-good:start', 'orrery-good:recovered'], $this->sent->getArrayCopy());
        self::assertCount(1, $this->recovered);
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
        $mail = $message->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
        self::assertSame('success', $mail->level);
        self::assertSame('Orrery: Long Wait Recovered', $mail->subject);
        self::assertStringContainsString('"emails" queue on the "database" connection waits within its limit again, after a long wait of 420 seconds.', html_entity_decode((string) $mail->introLines[0], ENT_QUOTES | ENT_HTML5));
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
