<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\FailureSpikes;
use Justrau\Orrery\Monitoring\Phases\LongWaitPhase;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Notifications\FailureSpikeDetected;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\TestCase;

/**
 * The failure-spike alert: a known minute of the last five whose failures
 * (all queues) reach `notifications.failure_spike.per_minute` sends one
 * notice, then each channel waits the cooldown.
 */
final class FailureSpikeTest extends TestCase
{
    /** A minute boundary. */
    private const M = 1_790_000_040;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('orrery.notifications.failure_spike.per_minute', 3);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Orrery::routeMailNotificationsTo('ops@example.test');
        $this->at(self::M + 10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a threshold compared with > (a minute at the threshold would
     * not alert), an alert for a minute below it, a second notice for the
     * same minute, a notice without its count, minute or largest groups
     * (largest first, at most three), and a spike check outside the
     * long-wait phase.
     */
    public function test_a_minute_at_the_threshold_alerts_once_and_one_below_does_not(): void
    {
        // Without a cooldown, so only the threshold and the claimed minute hold notices back.
        $this->app['config']->set('orrery.notifications.failure_spike.cooldown', 0);
        $at = self::M - 180;
        $this->failures($at + 1, 'App\\Jobs\\Sync', 'Boom', 2);
        $this->failures($at + 2, 'App\\Jobs\\Mail', 'Timeout', 1);
        $this->failures(self::M - 120 + 5, 'App\\Jobs\\Sync', 'Boom', 2);
        $this->countSamples();

        $this->app->make(LongWaitPhase::class)->run(new TickBudget(Clock::monotonic() + LongWaitPhase::SHARE_SECONDS));

        Notification::assertSentOnDemandTimes(FailureSpikeDetected::class, 1);
        Notification::assertSentOnDemand(FailureSpikeDetected::class, static function (FailureSpikeDetected $notice) use ($at): bool {
            return $notice->count === 3 && $notice->minute === $at
                && $notice->groups === [
                    ['name' => 'App\\Jobs\\Sync', 'exception_class' => 'RuntimeException', 'count' => 2],
                    ['name' => 'App\\Jobs\\Mail', 'exception_class' => 'RuntimeException', 'count' => 1],
                ];
        });

        // The claimed minute holds the second notice back.
        $this->spikes()->run(self::M + 20);
        Notification::assertSentOnDemandTimes(FailureSpikeDetected::class, 1);
    }

    /**
     * Rejects: a notice for every spike minute while the channel cools
     * down, or a cooldown that never ends.
     */
    public function test_the_cooldown_holds_the_next_spike_back(): void
    {
        $this->failures(self::M - 120, 'App\\Jobs\\Sync', 'Boom', 3);
        $this->countSamples();
        $this->spikes()->run(Clock::now());
        Notification::assertSentOnDemandTimes(FailureSpikeDetected::class, 1);

        // A spike two minutes later: inside the 30-minute cooldown.
        $this->at(self::M + 130);
        $this->failures(self::M + 60, 'App\\Jobs\\Sync', 'Boom', 4);
        $this->countSamples();
        $this->spikes()->run(Clock::now());
        Notification::assertSentOnDemandTimes(FailureSpikeDetected::class, 1);

        // After the cooldown: the next spike alerts.
        $later = self::M + 31 * 60;
        $this->at($later + 10);
        $this->failures($later - 60, 'App\\Jobs\\Sync', 'Boom', 3);
        $this->countSamples();
        $this->spikes()->run(Clock::now());
        Notification::assertSentOnDemandTimes(FailureSpikeDetected::class, 2);
    }

    /**
     * Rejects: an unknown minute read as a count (the failures of a minute
     * that was not counted would alert from the mirror rows, or never).
     */
    public function test_an_unknown_minute_triggers_nothing(): void
    {
        $this->failures(self::M - 120, 'App\\Jobs\\Sync', 'Boom', 5);

        // No counted minutes yet: the minute is unknown.
        $this->spikes()->run(Clock::now());
        Notification::assertNothingSent();

        $this->countSamples();
        $this->spikes()->run(Clock::now());
        Notification::assertSentOnDemandTimes(FailureSpikeDetected::class, 1);
    }

    /**
     * Rejects: a spike check that runs without a threshold (the default is off).
     */
    public function test_no_threshold_turns_the_alert_off(): void
    {
        $this->app['config']->set('orrery.notifications.failure_spike.per_minute', null);
        $this->failures(self::M - 120, 'App\\Jobs\\Sync', 'Boom', 50);
        $this->countSamples();

        $this->spikes()->run(Clock::now());

        Notification::assertNothingSent();
    }

    /**
     * Rejects: a notice text without the count, the minute or the groups,
     * or one that carries a message.
     */
    public function test_the_notice_names_the_count_the_minute_and_the_groups(): void
    {
        $notice = new FailureSpikeDetected(57, 1_790_000_040, [['name' => 'App\\Jobs\\Sync', 'exception_class' => 'RuntimeException', 'count' => 40]]);
        $mail = $notice->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

        self::assertSame('Orrery: Failure Spike Detected', $mail->subject);
        self::assertStringContainsString('57 jobs failed in the minute of 2026-09-21 14:14 UTC. Largest groups: 40 x App\\Jobs\\Sync (RuntimeException).', html_entity_decode((string) $mail->introLines[0], ENT_QUOTES | ENT_HTML5));
        self::assertSame('Orrery: 57 jobs failed in the minute of 2026-09-21 14:14 UTC.', $notice->smsText());
    }

    private function spikes(): FailureSpikes
    {
        return $this->app->make(FailureSpikes::class);
    }

    private function countSamples(): void
    {
        $this->app->make(SampleCounts::class)->run();
    }

    private function at(int $at): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($at, 'UTC'));
    }

    private function failures(int $failedAt, string $name, string $message, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->mirror()->table('orrery_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'name' => $name,
                'status' => 'failed',
                'payload' => '{}',
                'payload_bytes' => 2,
                'pushed_at' => Clock::toDecimal((float) $failedAt - 30),
                'failed_at' => Clock::toDecimal((float) $failedAt),
                'finished_at' => Clock::toDecimal((float) $failedAt),
                'exception_class' => 'RuntimeException',
                'exception_message' => $message,
                'failure_key' => GroupKey::of('RuntimeException', $message, $name),
                'updated_at' => Clock::toDecimal((float) $failedAt),
            ]);
        }
    }
}
