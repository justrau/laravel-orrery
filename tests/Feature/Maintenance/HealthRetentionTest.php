<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Maintenance;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Maintenance\Phases\HealthRetentionPhase;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\TestCase;

/**
 * The health-event retention: closed windows that ended
 * before now - `health.retention_days`, and open windows whose start and
 * last error are both older (a process that ended without its
 * terminating flush).
 */
final class HealthRetentionTest extends TestCase
{
    private const NOW = 1_790_000_040.5;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a cutoff that still follows the metric snapshots (gone), a
     * retention that deletes an open window with a recent error, an open
     * window of an ended process that stays for ever (after 500 of them
     * /api/health returns only those), and an ignored
     * `health.retention_days`.
     */
    public function test_windows_older_than_the_retention_days_are_deleted(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
        $this->app['config']->set('orrery.health.retention_days', 2);
        $this->window('old', self::NOW - 3 * 86_400, self::NOW - 2 * 86_400 - 60);
        $this->window('recent', self::NOW - 3 * 86_400, self::NOW - 2 * 86_400 + 60);
        $this->window('open', self::NOW - 30 * 86_400, null, self::NOW - 60);
        $this->window('abandoned', self::NOW - 30 * 86_400, null, self::NOW - 3 * 86_400);
        $this->window('abandoned-without-error', self::NOW - 30 * 86_400, null);
        $this->window('open-recent', self::NOW - 60, null);

        $this->app->make(HealthRetentionPhase::class)->run(new TickBudget(Clock::monotonic() + 30));

        self::assertSame(['open', 'open-recent', 'recent'], $this->mirror()->table('orrery_health_events')->orderBy('process')->pluck('process')->all());

        // The default is 7 days.
        $this->app['config']->set('orrery.health.retention_days', null);
        self::assertEqualsWithDelta(self::NOW - 7 * 86_400, $this->app->make(HealthRetentionPhase::class)->cutoff(), 0.001);
    }

    /**
     * Rejects: a terminating flush that leaves the window of the ending
     * process open (an open window covers every later range of
     * /api/health), a heartbeat flush that closes a window without a
     * recovery, and a terminating flush that drops the record of a process
     * that goes on (Octane, a test).
     */
    public function test_a_terminating_flush_ends_the_window(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
        Degradation::error('test:recorded', 'a recorded error');

        Degradation::flush();
        self::assertNull($this->mirror()->table('orrery_health_events')->value('ended_at'), 'a heartbeat flush closed the window');

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW + 5, 'UTC'));
        Degradation::error('test:recorded', 'a second error');
        Degradation::flush(terminating: true);

        self::assertSame(1, $this->mirror()->table('orrery_health_events')->count());
        self::assertSame(Clock::toDecimal(self::NOW + 5), Clock::toDecimal((float) $this->mirror()->table('orrery_health_events')->value('ended_at')));
        self::assertNotNull(Degradation::record(), 'the record of a process that goes on was dropped');

        // A long-lived process (Octane) with a later event writes the same row open again.
        Degradation::error('test:recorded', 'a third error');
        Degradation::flush();
        self::assertSame(1, $this->mirror()->table('orrery_health_events')->count());
        self::assertNull($this->mirror()->table('orrery_health_events')->value('ended_at'), 'a later event did not open the row again');
    }

    /**
     * Rejects: a terminating flush that returns early because an earlier
     * flush of the same process (the end of orrery:tick, a heartbeat) wrote
     * the record open and nothing changed since: the row stays open for
     * ever, and every overrunning tick adds one open window. It would still
     * pass a flush that writes the end again on each later terminating
     * flush; the last assertion counts the writes.
     */
    public function test_a_terminating_flush_ends_a_window_that_an_earlier_flush_wrote_open(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
        Degradation::error('tick_overrun:test', 'a recorded error');
        Degradation::flush();
        self::assertNull($this->mirror()->table('orrery_health_events')->value('ended_at'));

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW + 5, 'UTC'));
        Degradation::flush(terminating: true);

        self::assertSame(1, $this->mirror()->table('orrery_health_events')->count());
        self::assertSame(Clock::toDecimal(self::NOW + 5), Clock::toDecimal((float) $this->mirror()->table('orrery_health_events')->value('ended_at')), 'the terminating flush left the row open');

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW + 9, 'UTC'));
        Degradation::flush(terminating: true);
        self::assertSame(Clock::toDecimal(self::NOW + 5), Clock::toDecimal((float) $this->mirror()->table('orrery_health_events')->value('ended_at')), 'a second terminating flush without an event wrote again');
    }

    private function window(string $process, float $started, ?float $ended, ?float $lastError = null): void
    {
        $this->mirror()->table('orrery_health_events')->insert([
            'context' => 'worker',
            'process' => $process,
            'started_at' => Clock::toDecimal($started),
            'ended_at' => $ended === null ? null : Clock::toDecimal($ended),
            'dropped' => 0,
            'unmatched_observations' => 0,
            'unverified' => 0,
            'last_error_operation' => $lastError === null ? null : 'test:error',
            'last_error_at' => $lastError === null ? null : Clock::toDecimal($lastError),
        ]);
    }
}
