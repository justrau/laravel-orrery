<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use ArrayObject;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\SlackMessage as LegacySlackMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Events\StuckJobDetected;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Monitoring\Phases\StuckNotificationsPhase;
use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Monitoring\StuckNotifications;
use Justrau\Orrery\Monitoring\StuckSignals;
use Justrau\Orrery\Notifications\StuckJobDetected as StuckJobNotification;
use Justrau\Orrery\Notifications\StuckJobsSummary;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Lists\ListFixtures;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\ScriptedChannel;
use Justrau\Orrery\Tests\Support\Fixtures\Notifications\ThrowingStore;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

/**
 * The notifications of the stuck incidents:
 * sent by the tick to the long-wait routes (mail,
 * Slack, SMS), tracked per channel on the incident row apart from the
 * event, at most 3 rounds per incident, a cooldown of 5 minutes per
 * (connection, queue, signal, channel) with a summary of the suppressed
 * ones, the switch `orrery.stuck.notify`, and text that cannot carry
 * markup or line breaks of a job value.
 */
final class StuckNotificationsTest extends TestCase
{
    use ListFixtures {
        addRow as addListRow;
    }

    private const START = 1_800_000_000;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Hooks::$callback = null;

        parent::tearDown();
    }

    /**
     * Rejects: a stuck incident without a notification on a set route
     * (mail, a Slack webhook, a Slack channel, SMS), a channel state that is
     * not recorded on the row, and a Slack message of the wrong form for
     * its route (a webhook URL gets the legacy message, a
     * channel the Block Kit message).
     *
     * @param  array{0: string, 1: string}  $route  the channel and its route
     */
    #[DataProvider('routes')]
    public function test_a_stuck_incident_notifies_each_set_route_once(array $route): void
    {
        [$channel, $to] = $route;
        Notification::fake();
        $this->route($channel, $to);
        $this->incidentFor(1);
        $events = $this->recordEvents();

        $this->runIncidents();

        self::assertCount(1, $events);
        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 1);
        Notification::assertSentOnDemand(StuckJobNotification::class, static fn (StuckJobNotification $notification, array $channels, object $notifiable): bool => $channels === [$channel]
            && $notification->event->uuid === self::uuidOf(1)
            && $notification->event->evidence === ['gap' => 2]
            && $notifiable->routeNotificationFor($channel) === $to);
        self::assertSame([[$channel => StuckNotifications::SENT], null], $this->notifyState(1));

        if ($channel === 'slack') {
            $message = (new StuckJobNotification($events[0]))->toSlack((new AnonymousNotifiable)->route('slack', $to));
            self::assertInstanceOf(str_starts_with($to, 'https://') ? LegacySlackMessage::class : SlackMessage::class, $message);
        }
    }

    /**
     * @return array<string, array{array{0: string, 1: string}}>
     */
    public static function routes(): array
    {
        return [
            'mail' => [['mail', 'ops@example.test']],
            'a Slack webhook' => [['slack', 'https://hooks.slack.test/orrery']],
            'a Slack channel' => [['slack', '#orrery-ops']],
            'SMS' => [['vonage', '+15550100']],
        ];
    }

    /**
     * Rejects: a notification without a route (via() that names every
     * channel), and an incident left due when nothing is to send.
     */
    public function test_no_route_sends_no_notification(): void
    {
        Notification::fake();
        $this->incidentFor(1);
        $events = $this->recordEvents();

        $this->runIncidents();

        self::assertCount(1, $events, 'the event was dispatched');
        Notification::assertNothingSent();
        self::assertSame([[], null], $this->notifyState(1));
    }

    /**
     * Rejects: a notification that follows the event (a second dispatch of
     * the incident, after a process stopped before it recorded the
     * delivery, would send every channel again).
     */
    public function test_a_duplicate_dispatch_sends_no_second_notification(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->route('slack', '#orrery-ops');
        $this->incidentFor(1);
        $events = $this->recordEvents();

        $this->runIncidents();
        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 2);

        $this->mirror()->table(StuckIncidents::TABLE)->update(['delivered_at' => null, 'next_attempt_at' => null]);
        $this->runIncidents();

        self::assertCount(2, $events, 'the event went out twice');
        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 2);
    }

    /**
     * Rejects: a failed channel sent again with the event (a re-dispatch to
     * the application's listeners), a retry before its backoff of 60 s, a
     * retry of a channel that already sent, and one watched operation around
     * every channel (the failing Slack channel would skip the mail).
     */
    public function test_a_failed_channel_is_retried_after_its_backoff_without_a_second_event(): void
    {
        $sent = $this->scriptedChannels(slackFailures: 1);
        $this->route('slack', '#orrery-ops');
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $events = $this->recordEvents();

        $this->runIncidents();
        self::assertSame(['slack:failed', 'mail'], $sent->getArrayCopy());
        self::assertArrayHasKey('notification:slack', Degradation::record()['errors'] ?? [], 'the failed channel is recorded');
        self::assertSame([['slack' => StuckNotifications::retry(1), 'mail' => StuckNotifications::SENT], '1800000060.000000'], $this->notifyState(1));

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 30));
        $this->runIncidents();
        self::assertCount(2, $sent, 'the backoff holds the retry');

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 61));
        $this->runIncidents();
        self::assertSame(['slack:failed', 'mail', 'slack'], $sent->getArrayCopy(), 'Slack again, the mail not twice');
        self::assertCount(1, $events, 'no second event');
        self::assertSame([['slack' => StuckNotifications::SENT, 'mail' => StuckNotifications::SENT], null], $this->notifyState(1));
    }

    /**
     * Rejects: a round that sends "looks stuck" for a job that left the
     * state of its signal in the same cycle (it completed), and for an
     * incident older than MAX_AGE_SECONDS, whose round waited behind a
     * channel that was down for days. It would still pass a
     * round that ends such an incident one round later; the rounds are
     * asserted at once.
     */
    public function test_a_round_sends_nothing_for_a_finished_job_or_an_old_incident(): void
    {
        $sent = $this->scriptedChannels(slackFailures: 0);
        $this->route('slack', '#orrery-ops');
        $this->incidentFor(1);
        $this->incidentFor(2);
        $this->incidentFor(3);
        // The events were delivered; only the notification rounds are due.
        $this->mirror()->table(StuckIncidents::TABLE)->update(['delivered_at' => Clock::toDecimal(Clock::now())]);
        $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(1))->update(['status' => 'completed']);
        $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', self::uuidOf(2))->update(['first_seen_at' => Clock::toDecimal(Clock::now() - StuckNotifications::MAX_AGE_SECONDS - 1)]);

        $this->app->make(StuckNotificationsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));

        self::assertSame(['slack'], $sent->getArrayCopy(), 'only the incident of the job that is still stuck is sent');
        self::assertSame([null, null], $this->notifyState(1), 'the finished job got a channel state, or is still due');
        self::assertSame([null, null], $this->notifyState(2), 'the old incident got a channel state, or is still due');
        self::assertSame([['slack' => StuckNotifications::SENT], null], $this->notifyState(3));
    }

    /**
     * Rejects: more than 3 rounds for a channel that always fails, and a
     * last failure that leaves the incident due.
     */
    public function test_a_channel_is_tried_at_most_3_times(): void
    {
        $sent = $this->scriptedChannels(slackFailures: 99);
        $this->route('slack', '#orrery-ops');
        $this->incidentFor(1);
        $this->recordEvents();

        foreach ([0, 60, 360, 10_000, 20_000] as $seconds) {
            Carbon::setTestNow(Carbon::createFromTimestamp(self::START + $seconds));
            $this->runIncidents();
        }

        self::assertSame(['slack:failed', 'slack:failed', 'slack:failed'], $sent->getArrayCopy());
        self::assertSame([['slack' => StuckNotifications::FAILED], null], $this->notifyState(1));
    }

    /**
     * Rejects: a cache read or write outside the channel's watched send (a
     * throwing store would drop the channels after it).
     */
    public function test_a_throwing_cache_store_drops_no_channel(): void
    {
        $this->app['config']->set('cache.stores.orrery-throwing', ['driver' => 'orrery-throwing']);
        $this->app['config']->set('cache.default', 'orrery-throwing');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        Cache::clearResolvedInstances();
        // A plain closure: the package's tests never pass a static closure to an extend() creator.
        Cache::extend('orrery-throwing', fn ($app): \Illuminate\Cache\Repository => new \Illuminate\Cache\Repository(new ThrowingStore));
        self::assertInstanceOf(Store::class, $this->app->make('cache.store')->getStore());
        $sent = $this->scriptedChannels(slackFailures: 0);
        $this->route('slack', '#orrery-ops');
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame(['slack', 'mail'], $sent->getArrayCopy());
    }

    /**
     * Rejects: a notification per incident without a cooldown (30 stuck
     * jobs on one queue would send 30 messages per channel), suppressed
     * incidents that are lost (no summary), and a summary sent before the
     * cooldown ends or twice.
     */
    public function test_30_incidents_on_one_queue_send_one_notification_per_channel_then_a_summary(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->route('slack', '#orrery-ops');

        for ($n = 1; $n <= 30; $n++) {
            $this->incidentFor($n);
        }

        $this->recordEvents();

        $this->runIncidents();
        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 2);
        Notification::assertSentOnDemandTimes(StuckJobsSummary::class, 0);
        self::assertSame(29, $this->mirror()->table(StuckIncidents::TABLE)->where('notified', '{"slack":"suppressed","mail":"suppressed"}')->count());

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 299));
        $this->runIncidents();
        Notification::assertSentOnDemandTimes(StuckJobsSummary::class, 0);

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 301));
        $this->runIncidents();
        Notification::assertSentOnDemandTimes(StuckJobsSummary::class, 2);
        Notification::assertSentOnDemand(StuckJobsSummary::class, static fn (StuckJobsSummary $summary): bool => $summary->count === 29
            && $summary->queueName === 'default' && $summary->signal === StuckSignals::OUTCOME_NOT_OBSERVED);

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 700));
        $this->runIncidents();
        Notification::assertSentOnDemandTimes(StuckJobsSummary::class, 2);
        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 2);
    }

    /**
     * Rejects: a cooldown that also covers the next stuck job after it ends
     * without its count ("and N more" missing from the next message).
     */
    public function test_the_next_message_after_the_cooldown_names_the_suppressed_count(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->incidentFor(2);
        $this->recordEvents();
        $this->runIncidents();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 301));
        $this->incidentFor(3);
        $this->runIncidents();

        Notification::assertSentOnDemandTimes(StuckJobsSummary::class, 0);
        Notification::assertSentOnDemand(StuckJobNotification::class, static fn (StuckJobNotification $notification): bool => $notification->event->uuid === self::uuidOf(3) && $notification->more === 1);
    }

    /**
     * Rejects: a switch that also stops the event, one that the package's
     * notifications ignore, and one that only freezes the due rounds (they
     * would all send when it is turned on again).
     */
    public function test_notify_false_sends_no_notification_but_the_event_fires(): void
    {
        $this->app['config']->set('orrery.stuck.notify', false);
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->addRow(1, 'pending', 1, ['observed_gap' => 2]);
        $this->flushRows();
        // An incident that became due while the notifications were on.
        $this->incidentFor(2);
        $events = $this->recordEvents();

        $this->runIncidents();

        self::assertCount(2, $events);
        Notification::assertNothingSent();
        self::assertSame([null, null], $this->notifyState(1), 'the creation marks nothing to notify');
        self::assertSame([null, null], $this->notifyState(2), 'a due round ends, so turning it on again sends nothing old');

        $this->app['config']->set('orrery.stuck.notify', true);
        $this->runIncidents();
        Notification::assertNothingSent();
    }

    /**
     * Rejects: a switch that ends every due round in one statement (an
     * unbounded update on a large table), or one bounded by jobs instead of
     * rows: one run ends at most 1,000 rows.
     */
    public function test_notify_false_ends_the_due_rounds_in_chunks_of_1000(): void
    {
        $now = Clock::toDecimal(Clock::now());
        $rows = [];

        // 400 jobs with three incidents each: a bound by the jobs of the first 1,000 rows ends 1,002.
        for ($n = 1; $n <= 400; $n++) {
            foreach ([StuckSignals::OUTCOME_NOT_OBSERVED, StuckSignals::REPEATED_RELEASES, StuckSignals::ATTEMPTS_NOT_CAPPED] as $signal) {
                $rows[] = ['job_uuid' => self::uuidOf($n), 'cycle' => 1, 'signal' => $signal, 'first_seen_at' => $now, 'evidence' => '{}', 'attempts_made' => 1, 'delivered_at' => $now, 'notify_next_at' => $now];
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            $this->mirror()->table(StuckIncidents::TABLE)->insert($chunk);
        }

        $this->app['config']->set('orrery.stuck.notify', false);
        $due = fn (): int => $this->mirror()->table(StuckIncidents::TABLE)->whereNotNull('notify_next_at')->count();

        $this->runIncidents();
        self::assertSame(200, $due());
        $this->runIncidents();
        self::assertSame(0, $due());
    }

    /**
     * Rejects: a cooldown keyed without the signal (the second signal of a
     * job would be suppressed by the first).
     */
    public function test_two_signals_of_one_job_both_notify(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->mirror()->table(StuckIncidents::TABLE)->insert([
            'job_uuid' => self::uuidOf(1), 'cycle' => 1, 'signal' => StuckSignals::REPEATED_RELEASES,
            'first_seen_at' => Clock::toDecimal(Clock::now()), 'evidence' => '{"releases":21}', 'attempts_made' => 0,
            'notify_next_at' => Clock::toDecimal(Clock::now()),
        ]);
        $this->recordEvents();

        $this->runIncidents();

        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 2);
    }

    /**
     * Rejects: a round claim without its `notify_attempts = k` guard
     * (another tick claimed the round between the due read and the claim,
     * so both would send).
     */
    public function test_a_lost_round_claim_sends_nothing(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->recordEvents();
        Hooks::$callback = function (string $point): void {
            if ($point === StuckNotifications::BEFORE_CLAIM) {
                $this->mirror()->table(StuckIncidents::TABLE)->update(['notify_attempts' => 1]);
            }
        };

        $this->runIncidents();

        Notification::assertNothingSent();
    }

    /**
     * Rejects: notifications inside an earlier tick phase (a Slack channel
     * that hangs for 40 s would push the tick past its deadline before
     * `operations`, which would then be skipped), and a long-wait check
     * that the slow notifications skip: it runs after them
     * with its own share.
     */
    public function test_a_slow_failing_channel_delays_no_other_phase(): void
    {
        Clock::freezeMonotonicForTesting();
        $this->scriptedChannels(slackFailures: 99, slackSeconds: 40.0);
        $this->route('slack', '#orrery-ops');
        $this->incidentFor(1);
        $this->recordEvents();

        $report = $this->app->make(TickRunner::class)->run($this->app->tagged(TickCommand::PHASES_TAG));

        self::assertContains('operations', $report->ran());
        // long_wait runs after it (last, so that its own slow sends delay nothing), with its own share.
        self::assertContains('long_wait', $report->ran());
        self::assertSame([], $report->skipped(), 'no phase was skipped');
        self::assertSame(['stuck_notifications'], $report->overruns(), 'only the notifications ran past the deadline');
    }

    /**
     * Rejects: a failed channel tried again by every incident of the run
     * and by every tick (no backoff of the channel), and a round spent on
     * an incident whose only channel is down.
     */
    public function test_a_down_channel_is_tried_at_most_once_per_backoff_window(): void
    {
        $sent = $this->scriptedChannels(slackFailures: 99);
        $this->route('slack', '#orrery-ops');

        for ($n = 1; $n <= 3; $n++) {
            $this->incidentFor($n);
        }

        $this->recordEvents();
        $attempts = [];

        foreach ([0, 60, 120] as $seconds) {
            Carbon::setTestNow(Carbon::createFromTimestamp(self::START + $seconds));
            $before = count($sent);
            $this->runIncidents();
            $attempts[] = count($sent) - $before;
        }

        self::assertSame([1, 1, 0], $attempts, 'one Slack attempt per backoff window (60 s, then 300 s)');
        $rounds = $this->mirror()->table(StuckIncidents::TABLE)->orderBy('job_uuid')->pluck('notify_attempts')->map(static fn (mixed $made): int => (int) $made)->all();
        self::assertSame([2, 0, 0], $rounds, 'the incidents behind a down channel spent no round');
    }

    /**
     * Rejects: incidents that wait for a down channel counted against the
     * run's ROWS rounds: when ROWS of them are due before a newer incident,
     * every run spends its rows on moving them, and the newer incident's
     * Slack message waits for the backoff to end.
     *
     * Known limit: it would pass a run that reads twice the rows at once when the waiters fit in that (ROWS waiters are seeded, one read of 2 x ROWS would reach the new incident too).
     */
    public function test_incidents_that_wait_for_a_down_channel_take_no_round_of_the_run(): void
    {
        $sent = $this->scriptedChannels(slackFailures: 0);
        $this->route('slack', '#orrery-ops');
        $this->route('mail', 'ops@example.test');
        $waiters = StuckNotifications::ROWS;

        for ($n = 1; $n <= $waiters + 1; $n++) {
            $this->incidentFor($n);
        }

        // The first ROWS incidents are older and need only the mail, which is down for 300 s.
        $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', '!=', self::uuidOf($waiters + 1))->update([
            'notified' => json_encode(['slack' => StuckNotifications::SENT, 'mail' => StuckNotifications::retry(1)]),
            'notify_attempts' => 1,
            'notify_next_at' => Clock::toDecimal(self::START - 10),
        ]);
        $this->mirror()->table('orrery_settings')->insert(['key' => StuckNotifications::KEYS, 'value' => json_encode(['down' => ['mail' => ['until' => self::START + 300.0, 'n' => 2]]])]);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame(['slack'], $sent->getArrayCopy(), 'the new incident gets its Slack message in this run');
        self::assertSame(StuckNotifications::SENT, $this->notifyState($waiters + 1)[0]['slack'] ?? null);
        self::assertSame([StuckNotifications::retry(1), '1800000300.000000'], [$this->notifyState(1)[0]['mail'] ?? null, $this->notifyState(1)[1]], 'a waiter moves to the end of the backoff, its round unspent');
    }

    /**
     * Rejects: suppressed rounds counted against the run's ROWS rounds:
     * after a mass event on one queue, each run sends one
     * message and suppresses the rest of its rows, and the first message
     * of a newer incident on another queue waits until the backlog is
     * through.
     *
     * Known limit: it would pass a run that reads more than ROWS + 1 rows at once (one read would reach the new incident too).
     */
    public function test_suppressed_rounds_take_no_round_of_the_run(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $mass = StuckNotifications::ROWS + 1;

        for ($n = 1; $n <= $mass; $n++) {
            $this->incidentFor($n);
        }

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 1));
        $this->incidentFor($mass + 1, ['queue' => 'other']);
        $this->recordEvents();

        $this->app->make(StuckNotificationsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));

        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 2);
        self::assertSame(StuckNotifications::SENT, $this->notifyState($mass + 1)[0]['mail'] ?? null, 'the incident of the other queue got its message in this run');
        self::assertSame($mass - 1, $this->mirror()->table(StuckIncidents::TABLE)->where('notified', '{"mail":"suppressed"}')->count());
    }

    /**
     * Rejects: a down channel tried again by the next incident of the same
     * run when the incident needs another channel too (its round is spent
     * for the mail, and Slack waits).
     */
    public function test_a_down_channel_is_not_tried_again_in_the_same_run(): void
    {
        $sent = $this->scriptedChannels(slackFailures: 99);
        $this->route('slack', '#orrery-ops');
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->incidentFor(2);
        $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', self::uuidOf(2))->update(['signal' => StuckSignals::REPEATED_RELEASES]);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame(['slack:failed', 'mail', 'mail'], $sent->getArrayCopy());
        self::assertSame(StuckNotifications::retry(0), $this->notifyState(2)[0]['slack'] ?? null);
    }

    /**
     * Rejects: channels sent after the phase's share ended (a slow first
     * channel would hold the rest of the run), and an unreached channel left
     * without a later round.
     */
    public function test_channels_after_the_share_stay_retry(): void
    {
        Clock::freezeMonotonicForTesting();
        $sent = $this->scriptedChannels(slackFailures: 0, slackSeconds: StuckNotifications::SHARE_SECONDS + 1);
        $this->route('slack', '#orrery-ops');
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame(['slack'], $sent->getArrayCopy());
        self::assertSame([['slack' => StuckNotifications::SENT, 'mail' => StuckNotifications::retry(0)], '1800000060.000000'], $this->notifyState(1));
    }

    /**
     * Rejects: a round failure that stops the run (the later rounds, the
     * summaries and the prune would be skipped every tick while one
     * incident keeps failing).
     */
    public function test_a_failed_round_skips_neither_the_later_rounds_nor_the_summaries(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');

        for ($n = 1; $n <= 3; $n++) {
            $this->incidentFor($n);
        }

        // Another key whose cooldown ended with 2 held back: its summary is due.
        $this->cooldownRow('database', 'reports', StuckSignals::OUTCOME_NOT_OBSERVED, 'mail', self::START - 1, 2);
        $this->recordEvents();
        $rounds = 0;
        Hooks::$callback = static function (string $point) use (&$rounds): void {
            if ($point === StuckNotifications::BEFORE_CLAIM && ++$rounds === 1) {
                throw new RuntimeException('The first round fails.');
            }
        };

        $this->runIncidents();

        self::assertArrayHasKey(StuckNotifications::ROUND_OPERATION, Degradation::record()['errors'] ?? []);
        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 1);
        Notification::assertSentOnDemand(StuckJobNotification::class, static fn (StuckJobNotification $notification): bool => $notification->event->uuid === self::uuidOf(2));
        Notification::assertSentOnDemandTimes(StuckJobsSummary::class, 1);
        Notification::assertSentOnDemand(StuckJobsSummary::class, static fn (StuckJobsSummary $summary): bool => $summary->count === 2 && $summary->queueName === 'reports');
    }

    /**
     * Rejects: the cooldowns kept in the settings value (one key per queue,
     * signal and channel: a few hundred queues pass MySQL's TEXT of 64 KB),
     * and a prune that deletes every expired row at once.
     */
    public function test_the_cooldowns_are_rows_and_the_prune_is_bounded(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->route('slack', '#orrery-ops');

        for ($n = 1; $n <= 300; $n++) {
            $this->incidentFor($n, ['queue' => 'tenant-'.$n]);
        }

        $this->recordEvents();

        for ($run = 0; $run < 6; $run++) {
            $this->runIncidents();
        }

        self::assertSame(600, $this->mirror()->table(StuckNotifications::COOLDOWNS)->count(), 'one row per queue and channel');
        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 600);
        $value = (string) $this->mirror()->table('orrery_settings')->where('key', StuckNotifications::KEYS)->value('value');
        self::assertLessThan(200, strlen($value), 'the settings row holds only the channel backoffs');

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 301));
        $this->runIncidents();
        self::assertSame(100, $this->mirror()->table(StuckNotifications::COOLDOWNS)->count(), 'one run prunes at most 500');
        $this->runIncidents();
        self::assertSame(0, $this->mirror()->table(StuckNotifications::COOLDOWNS)->count());
    }

    /**
     * Rejects: a summary claim that does not check the row under its lock
     * (another tick that claimed the summary between this tick's read and
     * its claim would be sent a second time).
     */
    public function test_a_summary_that_another_tick_claimed_is_not_sent_again(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $id = $this->cooldownRow('database', 'reports', StuckSignals::OUTCOME_NOT_OBSERVED, 'mail', self::START - 1, 2);
        Hooks::$callback = function (string $point) use ($id): void {
            if ($point === StuckNotifications::BEFORE_SUMMARY) {
                // Another tick claims the summary first.
                $this->mirror()->table(StuckNotifications::COOLDOWNS)->where('key', $id)->update(['held' => 0, 'cooldown_until' => Clock::toDecimal(Clock::now() + 300)]);
            }
        };

        $this->runIncidents();

        Notification::assertNothingSent();
    }

    /**
     * Rejects: a state write that misses silently (a round longer than its
     * backoff lets a later tick claim the next round; this round's write
     * then matches no row).
     */
    public function test_a_state_write_that_a_later_claim_made_miss_is_recorded(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->recordEvents();
        Hooks::$callback = function (string $point): void {
            if ($point === StuckNotifications::BEFORE_RECORD) {
                $this->mirror()->table(StuckIncidents::TABLE)->update(['notify_attempts' => 2]);
            }
        };

        $this->runIncidents();

        self::assertArrayHasKey(StuckNotifications::STATE_LOST, Degradation::record()['errors'] ?? []);
    }

    /**
     * Rejects: a channel name stored uncut (Postgres refuses a value longer
     * than the column, and the one insert of a round failed for every
     * channel) and one insert for all channels (a bad channel would block
     * the others).
     */
    public function test_a_channel_name_longer_than_its_column_blocks_nothing(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $long = str_repeat('c', 200);
        $this->app->bind(StuckJobNotification::class, static fn ($app, array $parameters): StuckJobNotification => new class($parameters['event'], $parameters['more'] ?? 0, $long) extends StuckJobNotification
        {
            public function __construct(StuckJobDetected $event, int $more, private readonly string $long)
            {
                parent::__construct($event, $more);
            }

            public function via(mixed $notifiable): array
            {
                return ['mail', $this->long];
            }
        });
        $this->incidentFor(1);
        $this->recordEvents();

        $this->runIncidents();

        [$state] = $this->notifyState(1);
        self::assertSame(StuckNotifications::SENT, $state['mail'] ?? null);
        self::assertSame(StuckNotifications::SENT, $state[$long] ?? null);
        // The application's channel has no summary path, so no cooldown row: its name never reaches the column.
        $channels = $this->mirror()->table(StuckNotifications::COOLDOWNS)->orderBy('channel')->pluck('channel')->all();
        self::assertSame(['mail'], $channels);
    }

    /**
     * Rejects: a lost-state check by the update's affected rows (MySQL counts
     * the rows that it changed: a round that writes the stored values again
     * would be reported as lost).
     */
    public function test_an_unchanged_state_write_is_not_a_loss(): void
    {
        $this->app->bind(StuckJobNotification::class, static function (): never {
            throw new RuntimeException('The application binding is broken.');
        });
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        // Round 2: the build fails again, so the state and the next round stay what they are.
        $this->mirror()->table(StuckIncidents::TABLE)->update(['notified' => '{"mail":"retry:0"}', 'notify_attempts' => 1]);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame([['mail' => StuckNotifications::retry(0)], '1800000300.000000'], $this->notifyState(1));
        self::assertArrayNotHasKey(StuckNotifications::STATE_LOST, Degradation::record()['errors'] ?? []);
    }

    /**
     * Rejects: a give-back without its guard (another tick that reserved the
     * key during this tick's failed send would lose its reservation and see
     * this tick's count added to its own).
     */
    public function test_a_failed_send_keeps_a_later_reservation_of_its_key(): void
    {
        $id = StuckNotifications::keyId('database', 'default', StuckSignals::OUTCOME_NOT_OBSERVED, 'slack');
        $sent = new ArrayObject;
        $mirror = $this->mirror();
        $later = Clock::toDecimal(self::START + 999);
        $manager = $this->app->make(ChannelManager::class);
        // A plain closure: the package's tests never pass a static closure to an extend() creator. The
        // manager binds it to itself, so the values are captured, not read through $this or self.
        $manager->extend('slack', fn (): ScriptedChannel => new ScriptedChannel(function () use ($sent, $id, $mirror, $later): never {
            $sent[] = 'slack';
            // Another tick reserved the key meanwhile.
            $mirror->table(StuckNotifications::COOLDOWNS)->where('key', $id)->update(['cooldown_until' => $later, 'held' => 5]);

            throw new RuntimeException('The Slack channel is down.');
        }));
        $this->route('slack', '#orrery-ops');
        $this->incidentFor(1);
        $this->recordEvents();

        $this->runIncidents();

        $row = $this->mirror()->table(StuckNotifications::COOLDOWNS)->where('key', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame(['1800000999.000000', 5], [Clock::toDecimal((float) $row->cooldown_until), (int) $row->held]);
    }

    /**
     * Rejects: a summary give-back without its guard (a later reservation
     * of the key would be overwritten by this tick's failed summary).
     */
    public function test_a_failed_summary_keeps_a_later_reservation_of_its_key(): void
    {
        $id = $this->cooldownRow('database', 'reports', StuckSignals::OUTCOME_NOT_OBSERVED, 'mail', self::START - 1, 2);
        $mirror = $this->mirror();
        $later = Clock::toDecimal(self::START + 999);
        $manager = $this->app->make(ChannelManager::class);
        // A plain closure: the package's tests never pass a static closure to an extend() creator. The
        // manager binds it to itself, so the values are captured, not read through $this or self.
        $manager->extend('mail', fn (): ScriptedChannel => new ScriptedChannel(function () use ($id, $mirror, $later): never {
            $mirror->table(StuckNotifications::COOLDOWNS)->where('key', $id)->update(['cooldown_until' => $later, 'held' => 7]);

            throw new RuntimeException('The mail server is down.');
        }));
        $this->route('mail', 'ops@example.test');

        $this->runIncidents();

        $row = $this->mirror()->table(StuckNotifications::COOLDOWNS)->where('key', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame(['1800000999.000000', 7], [Clock::toDecimal((float) $row->cooldown_until), (int) $row->held]);
    }

    /**
     * Rejects: a reservation kept for a send that the share left out (the key
     * would be held back for 5 minutes although nothing was sent).
     */
    public function test_an_unsent_reservation_goes_back(): void
    {
        Clock::freezeMonotonicForTesting();
        $this->scriptedChannels(slackFailures: 0, slackSeconds: StuckNotifications::SHARE_SECONDS + 1);
        $this->route('slack', '#orrery-ops');
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->recordEvents();

        $this->runIncidents();

        // The given-back row is free (and the prune may delete it): no cooldown holds the mail key.
        self::assertSame(0, $this->mirror()->table(StuckNotifications::COOLDOWNS)->where('channel', 'mail')->where('cooldown_until', '>', Clock::toDecimal(Clock::now()))->count(), 'the mail key is free again');
    }

    /**
     * Rejects: a give-back inside the record (a record that fails after a
     * failed send would keep the reservation and lose the held-back count).
     */
    public function test_a_failed_record_keeps_the_give_back(): void
    {
        $this->scriptedChannels(slackFailures: 99);
        $this->route('slack', '#orrery-ops');
        $id = $this->cooldownRow('database', 'default', StuckSignals::OUTCOME_NOT_OBSERVED, 'slack', self::START - 1, 3);
        $this->incidentFor(1);
        $this->recordEvents();
        Hooks::$callback = static function (string $point): void {
            if ($point === StuckNotifications::BEFORE_RECORD) {
                throw new RuntimeException('The record fails.');
            }
        };

        $this->runIncidents();

        $row = $this->mirror()->table(StuckNotifications::COOLDOWNS)->where('key', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame([Clock::toDecimal(self::START - 1), 3], [Clock::toDecimal((float) $row->cooldown_until), (int) $row->held]);
        self::assertArrayHasKey(StuckNotifications::ROUND_OPERATION, Degradation::record()['errors'] ?? []);
    }

    /**
     * Rejects: a channel without a driver that reserves a cooldown (its key
     * would hold the next stuck jobs back although nothing is sent).
     */
    public function test_a_channel_without_a_driver_reserves_nothing(): void
    {
        $this->scriptedChannels(slackFailures: 0);
        $this->route('mail', 'ops@example.test');
        // laravel/vonage-notification-channel is not installed.
        $this->route('vonage', '+15550100');
        $this->incidentFor(1);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame(['mail'], $this->mirror()->table(StuckNotifications::COOLDOWNS)->pluck('channel')->all());
        self::assertSame(StuckNotifications::SKIPPED, $this->notifyState(1)[0]['vonage'] ?? null);
    }

    /**
     * Rejects: a driver check that lets an error other than
     * InvalidArgumentException out of the round (a creator that throws):
     * the round would be spent with no send for any channel, the mail
     * included. The failure is a failed try of that channel only.
     *
     * Known limit: it would pass a check that catches only RuntimeException.
     */
    public function test_a_channel_whose_driver_cannot_be_built_fails_only_its_own_try(): void
    {
        $sent = $this->scriptedChannels(slackFailures: 0);
        // A plain closure: the package's tests never pass a static closure to an extend() creator.
        $this->app->make(ChannelManager::class)->extend('slack', function (): never {
            throw new RuntimeException('The Slack channel cannot be built.');
        });
        $this->route('slack', '#orrery-ops');
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame(['mail'], $sent->getArrayCopy(), 'the mail still sends');
        self::assertSame([['slack' => StuckNotifications::retry(1), 'mail' => StuckNotifications::SENT], '1800000060.000000'], $this->notifyState(1));
        $errors = Degradation::record()['errors'] ?? [];
        self::assertArrayHasKey('notification:slack', $errors, 'the build failure is that channel\'s failed send');
        self::assertArrayNotHasKey(StuckNotifications::ROUND_OPERATION, $errors);
    }

    /**
     * Rejects: a channel whose route was removed left `retry:<n>` with empty
     * rounds until the last one.
     */
    public function test_a_channel_whose_route_was_removed_is_ended(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->mirror()->table(StuckIncidents::TABLE)->update(['notified' => '{"slack":"retry:1"}', 'notify_attempts' => 1]);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame([['slack' => StuckNotifications::SKIPPED, 'mail' => StuckNotifications::SENT], null], $this->notifyState(1));
    }

    /**
     * Rejects: an application's notification binding built outside a
     * watched operation (its exception would stop the tick and the other
     * incidents).
     */
    public function test_a_throwing_notification_binding_leaves_the_channels_to_retry(): void
    {
        $this->app->bind(StuckJobNotification::class, static function (): never {
            throw new RuntimeException('The application binding is broken.');
        });
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->incidentFor(2);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame([['mail' => StuckNotifications::retry(0)], '1800000060.000000'], $this->notifyState(1));
        self::assertSame([['mail' => StuckNotifications::retry(0)], '1800000060.000000'], $this->notifyState(2));
        self::assertArrayHasKey(StuckNotifications::BUILD_OPERATION, Degradation::record()['errors'] ?? []);
    }

    /**
     * Rejects: a third round before its backoff of 300 s (BACKOFF[2]), apart
     * from the channel's own backoff.
     */
    public function test_the_third_round_waits_300_seconds(): void
    {
        $sent = $this->scriptedChannels(slackFailures: 99);
        $this->route('slack', '#orrery-ops');
        $this->incidentFor(1);
        $this->recordEvents();
        $this->runIncidents();
        // The channel worked elsewhere meanwhile: its second failure is a first one again (down 60 s),
        // so only the incident's own backoff holds the third round.
        $this->mirror()->table('orrery_settings')->where('key', StuckNotifications::KEYS)->delete();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 60));
        $this->runIncidents();
        self::assertCount(2, $sent);

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 200));
        $this->runIncidents();
        self::assertCount(2, $sent, 'no third try at +200 s');

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 360));
        $this->runIncidents();
        self::assertCount(3, $sent);
    }

    /**
     * Rejects: a summary that is tried again forever when its channel keeps
     * failing.
     */
    public function test_a_failing_summary_is_tried_at_most_3_times(): void
    {
        $sent = new ArrayObject;
        $manager = $this->app->make(ChannelManager::class);
        // A plain closure: the package's tests never pass a static closure to an extend() creator.
        $manager->extend('mail', fn (): ScriptedChannel => new ScriptedChannel(function () use ($sent): void {
            $sent[] = 'mail';

            if (count($sent) > 1) {
                throw new RuntimeException('The mail server is down.');
            }
        }));
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->incidentFor(2);
        $this->recordEvents();
        $this->runIncidents();
        self::assertCount(1, $sent, 'the first message; the second incident is held back');

        foreach ([301, 400, 800, 1500, 3000] as $seconds) {
            Carbon::setTestNow(Carbon::createFromTimestamp(self::START + $seconds));
            $this->runIncidents();
        }

        self::assertCount(4, $sent, 'the message and 3 summary tries');
        self::assertSame(0, (int) $this->mirror()->table(StuckNotifications::COOLDOWNS)->sum('held'), 'the summary is dropped after its third try');
    }

    /**
     * Rejects: a summary loop that reads the channel backoffs once per run,
     * so a channel that failed one summary is tried again for every other
     * pending summary of the run, and an outage of minutes spends all
     * tries of every summary (their held-back counts are then dropped).
     */
    public function test_a_failed_summary_marks_its_channel_down_for_the_rest_of_the_run(): void
    {
        $sent = new ArrayObject;
        $manager = $this->app->make(ChannelManager::class);
        // A plain closure: the package's tests never pass a static closure to an extend() creator.
        $manager->extend('mail', fn (): ScriptedChannel => new ScriptedChannel(function () use ($sent): void {
            $sent[] = 'mail';

            throw new RuntimeException('The mail server is down.');
        }));
        $this->route('mail', 'ops@example.test');
        $this->cooldownRow('database', 'default', StuckSignals::OUTCOME_NOT_OBSERVED, 'mail', self::START - 1, 3);
        $this->cooldownRow('database', 'emails', StuckSignals::OUTCOME_NOT_OBSERVED, 'mail', self::START - 1, 2);

        $this->runIncidents();

        self::assertCount(1, $sent, 'the down channel was tried again in the same run');
        self::assertSame([1, 0], $this->mirror()->table(StuckNotifications::COOLDOWNS)->orderBy('queue')->pluck('summary_tries')->map(static fn (mixed $tries): int => (int) $tries)->all(), 'the second summary spent a try');
        self::assertSame(5, (int) $this->mirror()->table(StuckNotifications::COOLDOWNS)->sum('held'), 'a held-back count was lost');
    }

    /**
     * Rejects: a channel that only an application's own notification names
     * (no package route, so no summary path) that the cooldown holds back:
     * its count would be dropped as "route removed", and the incidents
     * after the first are never sent on it.
     */
    public function test_a_channel_without_a_summary_path_is_never_held_back(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        $this->app->bind(StuckJobNotification::class, static fn ($app, array $parameters): StuckJobNotification => new class($parameters['event'], $parameters['more'] ?? 0) extends StuckJobNotification
        {
            public function via(mixed $notifiable): array
            {
                return ['mail', 'telegram'];
            }
        });
        $this->incidentFor(1);
        $this->incidentFor(2);
        $this->recordEvents();

        $this->runIncidents();

        self::assertSame([['mail' => StuckNotifications::SENT, 'telegram' => StuckNotifications::SENT], ['mail' => StuckNotifications::SUPPRESSED, 'telegram' => StuckNotifications::SENT]], [$this->notifyState(1)[0], $this->notifyState(2)[0]]);
        self::assertSame(['mail'], $this->mirror()->table(StuckNotifications::COOLDOWNS)->pluck('channel')->all(), 'a channel without a summary path got a cooldown');
    }

    /**
     * Rejects: a down package channel that holds back the channel that only
     * the application's own notification names: the round waits for the
     * package channel before via() is built, so the application's channel
     * is never tried while Slack is down. It would still pass a
     * round that also tries the down Slack channel; the sends are asserted.
     */
    public function test_a_down_package_channel_does_not_hold_back_the_applications_channel(): void
    {
        $sent = $this->scriptedChannels(slackFailures: 99);
        $this->route('slack', '#orrery-ops');
        $this->app->bind(StuckJobNotification::class, static fn ($app, array $parameters): StuckJobNotification => new class($parameters['event'], $parameters['more'] ?? 0) extends StuckJobNotification
        {
            public function via(mixed $notifiable): array
            {
                return ['slack', 'mail'];
            }
        });
        $this->incidentFor(1);
        $this->recordEvents();

        $this->runIncidents();
        self::assertSame(['slack:failed', 'mail'], $sent->getArrayCopy());

        // Slack is down now; a new incident still gets the application's channel.
        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 10));
        $this->incidentFor(2);
        $this->runIncidents();

        self::assertSame(['slack:failed', 'mail', 'mail'], $sent->getArrayCopy(), "the down Slack channel held back the application's mail channel, or was tried again");
    }

    /**
     * Rejects: a mail whose job name becomes a link in the rendered HTML,
     * and a plain-text part that shows Markdown escapes.
     */
    public function test_the_rendered_mail_holds_no_link_of_a_job_value(): void
    {
        $event = new StuckJobDetected(self::uuidOf(1), 2, StuckSignals::OUTCOME_NOT_OBSERVED, 'database', 'emails', '[Reset](https://evil.test)', ['gap' => 2]);
        $html = (string) (new StuckJobNotification($event))->toMail(new AnonymousNotifiable)->render();

        self::assertStringContainsString('Reset', $html);
        self::assertStringNotContainsString('href="https://evil.test', $html);
        self::assertStringNotContainsString('\\_', (string) $this->app->make(\Illuminate\Mail\Markdown::class)->renderText('notifications::email', (new StuckJobNotification(new StuckJobDetected(self::uuidOf(1), 2, StuckSignals::OUTCOME_NOT_OBSERVED, 'database', 'emails', 'App\\Jobs\\Send_Mail', ['gap' => 2])))->toMail(new AnonymousNotifiable)->data()));
    }

    /**
     * Rejects: a channel marked `failed` without its own tries (round 3 of
     * the incident, the slow Slack channel ends the share, and the mail
     * would be failed untried).
     */
    public function test_a_channel_cut_off_by_the_share_keeps_its_tries(): void
    {
        Clock::freezeMonotonicForTesting();
        $this->scriptedChannels(slackFailures: 0, slackSeconds: StuckNotifications::SHARE_SECONDS + 1);
        $this->route('slack', '#orrery-ops');
        $this->route('mail', 'ops@example.test');
        $this->incidentFor(1);
        $this->mirror()->table(StuckIncidents::TABLE)->update(['notify_attempts' => 2, 'notified' => '{"slack":"retry:2"}']);
        $this->recordEvents();

        $this->runIncidents();

        [$state, $next] = $this->notifyState(1);
        self::assertSame(['slack' => StuckNotifications::SENT, 'mail' => StuckNotifications::retry(0)], $state);
        self::assertNotNull($next, 'the mail gets a later round');
    }

    /**
     * Rejects: a settings row KEYS deleted when its keys are empty (a later
     * round's lock would then hold no row: a lost write on Postgres, a gap
     * lock deadlock on MySQL).
     */
    public function test_the_keys_row_stays_when_it_is_empty(): void
    {
        $this->route('mail', 'ops@example.test');
        $this->scriptedChannels(slackFailures: 0);
        $this->incidentFor(1);
        $this->recordEvents();
        $this->runIncidents();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 400));

        $this->runIncidents();

        self::assertSame(StuckNotifications::EMPTY, $this->mirror()->table('orrery_settings')->where('key', StuckNotifications::KEYS)->value('value'));
    }

    /**
     * Rejects: a due order with ties left to the engine (the next run could
     * read the same 50 rows of one time and starve the others).
     */
    public function test_the_due_order_is_the_time_then_the_key(): void
    {
        Notification::fake();
        $this->route('mail', 'ops@example.test');
        // Inserted in the other order; one queue and signal, so the second one is held back.
        $this->incidentFor(2);
        $this->incidentFor(1);
        $this->recordEvents();
        $sql = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$sql): void {
            $sql[] = strtolower(str_replace(['"', '`'], '', $query->sql));
        });

        $this->runIncidents();

        Notification::assertSentOnDemandTimes(StuckJobNotification::class, 1);
        Notification::assertSentOnDemand(StuckJobNotification::class, static fn (StuckJobNotification $notification): bool => $notification->event->uuid === self::uuidOf(1));
        // The engine's index order gives the ties in key order on SQLite anyway: the statement must name them.
        $due = array_values(array_filter($sql, static fn (string $statement): bool => str_starts_with($statement, 'select') && str_contains($statement, 'orrery_incidents') && str_contains($statement, 'notify_attempts <')));
        self::assertNotSame([], $due);
        self::assertStringContainsString('order by notify_next_at asc, job_uuid asc, cycle asc, signal asc', $due[0]);
    }

    /**
     * Rejects: a mail line whose job value becomes a Markdown link, a legacy
     * Slack text whose `<!channel>` pings a channel, a Block Kit text in
     * mrkdwn, a name whose line or paragraph separator or bidi control
     * reaches the text, an SMS over 160 GSM characters, and a text without
     * the job, its queue and connection, or the signal's fact.
     */
    public function test_the_text_names_the_job_and_cannot_carry_markup(): void
    {
        $name = "[Reset](https://evil.test) <!channel>\u{2028}\u{202E}x";
        $event = new StuckJobDetected(self::uuidOf(1), 2, StuckSignals::OUTCOME_NOT_OBSERVED, 'database', 'emails', $name, ['gap' => 2]);
        $notification = new StuckJobNotification($event, 3);

        $summary = $notification->summary();
        self::assertStringContainsString('('.self::uuidOf(1).') on the "emails" queue of the "database" connection', $summary);
        self::assertStringContainsString('2 of its attempts ended without an observed outcome', $summary);
        self::assertStringContainsString('3 more stuck jobs', $summary);
        self::assertStringNotContainsString("\u{2028}", $summary);
        self::assertStringNotContainsString("\u{202E}", $summary);

        // The plain-text part shows the raw name, without Markdown escapes; the HTML part has no link.
        $message = $notification->toMail(new AnonymousNotifiable);
        $text = (string) $this->app->make(\Illuminate\Mail\Markdown::class)->renderText((string) $message->markdown, $message->data());
        self::assertStringContainsString('[Reset](https://evil.test) <!channel>', $text);
        self::assertStringNotContainsString('\\[', $text);
        $html = (string) $message->render();
        self::assertStringNotContainsString('href="https://evil.test', $html);
        self::assertStringNotContainsString('<!channel>', $html);
        self::assertStringContainsString('[Reset](https://evil.test)', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $webhook = $notification->toSlack((new AnonymousNotifiable)->route('slack', 'https://hooks.slack.test/orrery'));
        self::assertInstanceOf(LegacySlackMessage::class, $webhook);
        $text = (string) $webhook->attachments[0]->content;
        self::assertStringContainsString('&lt;!channel&gt;', $text);
        self::assertStringNotContainsString('<!channel>', $text);

        $blocks = $notification->toSlack((new AnonymousNotifiable)->route('slack', '#ops'));
        self::assertInstanceOf(SlackMessage::class, $blocks);
        self::assertStringNotContainsString('mrkdwn', (string) json_encode($blocks->toArray()));

        $sms = (new StuckJobNotification(new StuckJobDetected(self::uuidOf(1), 2, StuckSignals::OUTCOME_NOT_OBSERVED, 'database', 'émails[ops]', $name, ['gap' => 2]), 3))->smsText();
        self::assertSame(1, preg_match('/^[\x20-\x5A\x5F\x61-\x7A]*$/', $sms), 'GSM characters only: '.$sms);
        self::assertStringContainsString('+3 more', $sms);
        self::assertStringStartsWith('Orrery: stuck job', $sms, 'A prefix of GSM basic-set characters.');
        self::assertSame(160, strlen(StuckJobNotification::gsm(str_repeat('a', 300))), 'gsm() cuts any text to 160');

        $long = new StuckJobDetected(self::uuidOf(1), 2, StuckIncidents::UNKNOWN, 'database', str_repeat('q', 300), str_repeat('n', 512), ['reason' => Reconciliation::UNKNOWN_REASON]);
        $longNotification = new StuckJobNotification($long);
        self::assertStringNotContainsString(str_repeat('n', 201), $longNotification->summary());
        self::assertStringContainsString('the job left the jobs table without an observed outcome', $longNotification->summary());
        self::assertLessThanOrEqual(160, strlen($longNotification->smsText()));
        self::assertInstanceOf(BaseNotification::class, $long->toNotification());
    }

    private function route(string $channel, string $to): void
    {
        match ($channel) {
            'mail' => Orrery::routeMailNotificationsTo($to),
            'vonage' => Orrery::routeSmsNotificationsTo($to),
            default => Orrery::routeSlackNotificationsTo($to),
        };
    }

    /**
     * Replaces the slack and mail drivers with recording channels; Slack
     * takes $slackSeconds of the monotonic clock and fails its first
     * $slackFailures sends.
     *
     * @return ArrayObject<int, string>
     */
    private function scriptedChannels(int $slackFailures, float $slackSeconds = 0.0): ArrayObject
    {
        /** @var ArrayObject<int, string> $sent */
        $sent = new ArrayObject;
        $failures = new ArrayObject(['left' => $slackFailures]);
        $manager = $this->app->make(ChannelManager::class);
        // Plain closures: the package's tests never pass a static closure to an extend() creator.
        $manager->extend('slack', fn (): ScriptedChannel => new ScriptedChannel(function () use ($sent, $failures, $slackSeconds): void {
            if ($slackSeconds > 0) {
                // A slow channel: the monotonic clock moves on (the test froze it).
                Clock::advanceMonotonicForTesting($slackSeconds);
            }

            if ($failures['left'] > 0) {
                $failures['left']--;
                $sent[] = 'slack:failed';

                throw new RuntimeException('The Slack channel is down.');
            }

            $sent[] = 'slack';
        }));
        $manager->extend('mail', fn (): ScriptedChannel => new ScriptedChannel(function () use ($sent): void {
            $sent[] = 'mail';
        }));

        return $sent;
    }

    /**
     * @return ArrayObject<int, StuckJobDetected>
     */
    private function recordEvents(): ArrayObject
    {
        /** @var ArrayObject<int, StuckJobDetected> $events */
        $events = new ArrayObject;
        Event::listen(StuckJobDetected::class, static function (StuckJobDetected $event) use ($events): void {
            $events[] = $event;
        });

        return $events;
    }

    /**
     * One tick of the two phases: the incidents and their events, then the
     * notifications.
     */
    private function runIncidents(): void
    {
        $this->app->make(StuckIncidents::class)->run(new TickBudget(Clock::monotonic() + 30));
        $this->app->make(StuckNotificationsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));
    }

    /**
     * A pending job row $n without a signal (with $values), and its due
     * incident, as the creation inserts it (due to notify now).
     *
     * @param  array<string, mixed>  $values
     */
    private function incidentFor(int $n, array $values = []): void
    {
        $this->addRow($n, 'pending', $n, $values);
        $this->flushRows();
        $this->mirror()->table(StuckIncidents::TABLE)->insert([
            'job_uuid' => self::uuidOf($n),
            'cycle' => 1,
            'signal' => StuckSignals::OUTCOME_NOT_OBSERVED,
            'first_seen_at' => Clock::toDecimal(Clock::now()),
            'evidence' => '{"gap":2}',
            'attempts_made' => 0,
            'notify_next_at' => Clock::toDecimal(Clock::now()),
        ]);
    }

    /**
     * A cooldown row; returns its key.
     */
    private function cooldownRow(string $connection, string $queue, string $signal, string $channel, float $until, int $held, int $tries = 0): string
    {
        $id = StuckNotifications::keyId($connection, $queue, $signal, $channel);
        $this->mirror()->table(StuckNotifications::COOLDOWNS)->insert([
            'key' => $id, 'connection' => $connection, 'queue' => $queue, 'signal' => $signal, 'channel' => $channel,
            'cooldown_until' => Clock::toDecimal($until), 'held' => $held, 'summary_tries' => $tries,
        ]);

        return $id;
    }

    /**
     * The decoded `notified` and `notify_next_at` of job $n's incident.
     *
     * @return array{0: array<string, string>|null, 1: string|null}
     */
    private function notifyState(int $n): array
    {
        $row = $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', self::uuidOf($n))->first();
        self::assertInstanceOf(stdClass::class, $row);
        $state = $row->notified === null ? null : json_decode((string) $row->notified, true);

        return [$state, $row->notify_next_at === null ? null : Clock::toDecimal((float) $row->notify_next_at)];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function addRow(int $n, string $status, float $at, array $values = []): void
    {
        $this->addListRow($n, $status, $at, $values + ['observed_gap' => 0, 'releases' => 0, 'attempts' => 1, 'cycle' => 1]);
    }
}
