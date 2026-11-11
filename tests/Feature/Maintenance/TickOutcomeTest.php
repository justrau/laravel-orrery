<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Maintenance;

use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Maintenance\TickOutcome;
use Justrau\Orrery\Maintenance\TickReport;
use Justrau\Orrery\Tests\Support\Fixtures\FailingTickPhase;
use Justrau\Orrery\Tests\TestCase;

/**
 * orrery:tick stores the outcome of each finished run (TickOutcome): its
 * failed phases and the deadline skips in a row, for the monitoring ping.
 */
final class TickOutcomeTest extends TestCase
{
    /**
     * Rejects: a tick that stores no outcome, or one without its failed phase or its start and finish.
     */
    public function test_a_tick_stores_its_failed_phases(): void
    {
        $this->app->instance('orrery.test.failing-phase', new FailingTickPhase('test_failing'));
        $this->app->tag(['orrery.test.failing-phase'], TickCommand::PHASES_TAG);

        $this->artisan('orrery:tick')->assertExitCode(0);

        $outcome = $this->app->make(TickOutcome::class)->read();
        self::assertNotNull($outcome);
        self::assertSame(['test_failing'], $outcome['failed']);
        self::assertGreaterThanOrEqual($outcome['started_at'], $outcome['finished_at']);
        self::assertSame((float) $this->mirror()->table('orrery_settings')->where('key', 'observer_tick_at')->value('value'), $outcome['started_at']);
    }

    /**
     * Rejects: skip counts that do not add up over consecutive ticks, or that a run of the phase does not reset.
     */
    public function test_deadline_skips_count_in_a_row(): void
    {
        $outcomes = $this->app->make(TickOutcome::class);

        $outcomes->record(100.0, 101.0, new TickReport(['a'], [], [], ['late_phase', 'other']));
        $outcomes->record(160.0, 161.0, new TickReport(['a'], [], [], ['late_phase']));
        self::assertSame(['late_phase' => 2], $outcomes->read()['skip_streaks'] ?? null);

        $outcomes->record(220.0, 221.0, new TickReport(['a', 'late_phase'], [], [], []));
        self::assertSame([], $outcomes->read()['skip_streaks'] ?? null);
    }
}
