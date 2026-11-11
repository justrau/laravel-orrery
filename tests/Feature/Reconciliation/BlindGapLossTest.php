<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Reconciliation;

use ArrayObject;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Justrau\Orrery\Events\StuckJobDetected;
use Justrau\Orrery\Lifecycle\CycleStart;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\BlindGaps;
use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Storage\Clock;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A job whose `jobs` row left during a recorded blind gap (BlindGaps): the
 * reconciliation marks it `unknown` with the reason `blind_gap`, which raises
 * no stuck incident, no StuckJobDetected and no notification. Every other
 * absence keeps the reason `absent` and alerts as before.
 */
final class BlindGapLossTest extends ReconciliationTestCase
{
    /**
     * Rejects: a job lost in a blind gap that becomes `unknown` with the reason `absent` (and so floods the stuck
     * notifications after an off period), a reason given already at the first absence that a later present check
     * keeps, and a blind-gap loss that raises an incident or an event.
     */
    public function test_a_job_lost_in_a_blind_gap_is_unknown_with_its_reason_and_raises_nothing(): void
    {
        $this->runIncidents();
        [$uuid, $jobId] = $this->pushed();
        $pushed = Clock::now();
        $this->sweptAt($pushed + 10);
        $this->gap($pushed + 60, $pushed + 1800);
        // A worker that did not observe ran the job during the gap.
        $this->queueTable()->where('id', $jobId)->delete();

        $this->moveClock(1810);
        $events = $this->recordEvents();
        $this->runActive();
        self::assertSame(['pending', Reconciliation::BLIND_GAP_REASON], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->unknown_reason], 'the first absence carries the reason');

        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runActive();
        $row = $this->rowOrFail($uuid);
        self::assertSame(['unknown', Reconciliation::BLIND_GAP_REASON], [$row->status, $row->unknown_reason]);

        $this->runIncidents();
        self::assertSame(0, $this->mirror()->table(StuckIncidents::TABLE)->count(), 'no incident, so no notification');
        self::assertCount(0, $events, 'no StuckJobDetected');
    }

    /**
     * Rejects: a rule that suppresses every loss once any gap is recorded: a loss outside the gap's reach still
     * becomes `absent`, raises its incident and dispatches StuckJobDetected.
     */
    public function test_a_real_loss_outside_any_gap_still_alerts(): void
    {
        $this->runIncidents();
        [$uuid, $jobId] = $this->pushed();
        // An old gap, followed by a complete sweep that started after it.
        $this->gap(Clock::now() - 7200, Clock::now() - 3600);
        $this->sweptAt(Clock::now() - 60);
        $this->queueTable()->where('id', $jobId)->delete();

        $events = $this->recordEvents();
        $this->runActive();
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runActive();
        self::assertSame(['unknown', Reconciliation::UNKNOWN_REASON], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->unknown_reason]);

        $this->runIncidents();
        self::assertSame([[$uuid, StuckIncidents::UNKNOWN]], $this->mirror()->table(StuckIncidents::TABLE)->get(['job_uuid', 'signal'])->map(static fn (object $row): array => [$row->job_uuid, $row->signal])->all());
        $this->app->make(StuckIncidents::class)->deliverEvents(new TickBudget(Clock::monotonic() + 30));
        self::assertCount(1, $events, 'StuckJobDetected as before');
    }

    /**
     * Rejects, at each edge of the rule: a row observed at or after the gap's end (it was seen again), a pre-gap
     * sweep more than GAP_SECONDS before the gap (the job may have left before the gap), a complete sweep that
     * started after the gap's end (this is not the first check after the gap), and a missing sweep.
     *
     * @param  array{activity: float, swept: ?float}  $case  times relative to the gap's start (from = 1000, to = 3000)
     */
    #[DataProvider('edges')]
    public function test_the_edges_of_a_blind_gap_loss(array $case, string $reason): void
    {
        $base = Clock::now();
        [$uuid, $jobId] = $this->pushed();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['activity_at' => Clock::toDecimal($base + $case['activity'])]);

        if ($case['swept'] !== null) {
            $this->sweptAt($base + $case['swept']);
        }

        $this->gap($base + 1000, $base + 3000);
        $this->queueTable()->where('id', $jobId)->delete();
        $this->moveClock(3100);

        $this->runActive();
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runActive();

        self::assertSame(['unknown', $reason], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->unknown_reason]);
    }

    /**
     * @return array<string, array{0: array{activity: float, swept: ?float}, 1: string}>
     */
    public static function edges(): array
    {
        return [
            'observed just before the end' => [['activity' => 2999.0, 'swept' => 900.0], Reconciliation::BLIND_GAP_REASON],
            'observed at the end' => [['activity' => 3000.0, 'swept' => 900.0], Reconciliation::UNKNOWN_REASON],
            'swept GAP_SECONDS before the gap' => [['activity' => 0.0, 'swept' => 1000.0 - BlindGaps::GAP_SECONDS], Reconciliation::BLIND_GAP_REASON],
            'swept longer before the gap' => [['activity' => 0.0, 'swept' => 1000.0 - BlindGaps::GAP_SECONDS - 0.5], Reconciliation::UNKNOWN_REASON],
            'swept just before the end' => [['activity' => 0.0, 'swept' => 2999.0], Reconciliation::BLIND_GAP_REASON],
            'a sweep that started at the end' => [['activity' => 0.0, 'swept' => 3000.0], Reconciliation::UNKNOWN_REASON],
            'no sweep before the gap' => [['activity' => 0.0, 'swept' => null], Reconciliation::UNKNOWN_REASON],
        ];
    }

    /**
     * Rejects: a sweep that started before the gap's end and goes on after it, so a row that it checks a second
     * time after the gap (present at the first check, lost after) reads as a loss of the gap.
     */
    public function test_a_sweep_that_started_before_the_gap_starts_again(): void
    {
        Reconciliation::useActiveBatchForTesting(1);
        $base = Clock::now();
        [$first, $firstId] = $this->pushed();
        [$second] = $this->pushed();
        $this->sweptAt($base - 10);
        // The sweep that started before the gap had checked the first row.
        $this->mirror()->table('orrery_settings')->insert(['key' => Reconciliation::CURSOR_PREFIX.'database', 'value' => json_encode([
            'after' => (int) $this->rowOrFail($first)->id, 'started_at' => Clock::toDecimal($base),
        ])]);
        $this->gap($base + 60, $base + 1800);
        $this->queueTable()->where('id', $firstId)->delete();
        $this->moveClock(1810);

        $this->runActive();

        self::assertSame(Reconciliation::BLIND_GAP_REASON, $this->rowOrFail($first)->unknown_reason, 'the new sweep checks the first row again, once');
        self::assertNull($this->rowOrFail($second)->absent_since);
        $cursor = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', Reconciliation::CURSOR_PREFIX.'database')->value('value'), true);
        self::assertGreaterThanOrEqual($base + 1800, (float) $cursor['started_at'], 'the sweep started after the gap');
    }

    /**
     * Rejects: a reason that outlives the absence: a job that comes back is present again with no reason, and its
     * later loss (after the first sweep after the gap) is `absent`.
     */
    public function test_a_job_that_comes_back_behaves_as_before(): void
    {
        [$uuid, $jobId] = $this->pushed();
        $this->sweptAt(Clock::now() + 10);
        $this->gap(Clock::now() + 60, Clock::now() + 1800);
        $row = (array) $this->queueTable()->where('id', $jobId)->first();
        $this->queueTable()->where('id', $jobId)->delete();
        $this->moveClock(1810);
        $this->runActive();
        self::assertSame(Reconciliation::BLIND_GAP_REASON, $this->rowOrFail($uuid)->unknown_reason);

        $this->queueTable()->insert($row);
        $this->moveClock(60);
        $this->runActive();
        self::assertSame(['pending', null, null], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->absent_since, $this->rowOrFail($uuid)->unknown_reason]);

        $this->queueTable()->where('id', $jobId)->delete();
        $this->moveClock(60);
        $this->runActive();
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runActive();
        self::assertSame(['unknown', Reconciliation::UNKNOWN_REASON], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->unknown_reason]);
    }

    /**
     * The off switch end to end through orrery:tick: a pending job, Orrery off while a worker runs it (its jobs
     * row goes, nothing observes), then on: the first tick records the blind gap, and a tick after the grace marks
     * the job unknown with the reason `blind_gap`, with no incident, no event and no mail.
     *
     * Rejects: the flood of stuck notifications after an off period.
     */
    public function test_the_off_switch_end_to_end(): void
    {
        $this->app['config']->set('mail.default', 'array');
        $this->app['config']->set('orrery.notifications.mail.to', 'ops@example.com');
        [$uuid, $jobId] = $this->pushed();
        $this->tick();
        $events = $this->recordEvents();

        // Off for 20 minutes: a worker that does not observe runs the job.
        $this->queueTable()->where('id', $jobId)->delete();
        $this->moveClock(1200);
        $this->tick();
        self::assertNotSame([], BlindGaps::read(), 'the first tick after the off period records the gap');

        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->tick();
        $this->moveClock(60);
        $this->tick();

        self::assertSame(['unknown', Reconciliation::BLIND_GAP_REASON], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->unknown_reason]);
        self::assertSame(0, $this->mirror()->table(StuckIncidents::TABLE)->count());
        self::assertCount(0, $events);
    }

    /**
     * Rejects: an anchor on the gap's `from` only: the scheduler stopped 10 minutes before the workers, so the gap
     * starts at their last job, long after the last sweep, and every lost job would alert. The gap's window start
     * (the last tick) anchors the rule while `from` lies within the longest grace after it.
     */
    #[DataProvider('anchors')]
    public function test_the_gap_is_anchored_at_its_window_start(float $from, ?float $start, int $retryAfter, string $reason): void
    {
        $this->app['config']->set('queue.connections.database.retry_after', $retryAfter);
        $base = Clock::now();
        [$uuid, $jobId] = $this->pushed();
        $this->sweptAt($base);
        $this->gap($base + $from, $base + 2400, $start === null ? null : $base + $start);
        $this->moveClock(2500);

        $this->lose($jobId, max(Reconciliation::MIN_GRACE_SECONDS, $retryAfter));

        self::assertSame(['unknown', $reason], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->unknown_reason]);
    }

    /**
     * @return array<string, array{0: float, 1: ?float, 2: int, 3: string}>
     */
    public static function anchors(): array
    {
        return [
            'scheduler stopped 10 min before the workers' => [600.0, 0.0, 90, Reconciliation::BLIND_GAP_REASON],
            'the same gap without its window start' => [600.0, null, 90, Reconciliation::UNKNOWN_REASON],
            'from beyond the longest grace: anchored at from' => [700.0, 0.0, 90, Reconciliation::UNKNOWN_REASON],
            'from within a longer retry_after' => [700.0, 0.0, 900, Reconciliation::BLIND_GAP_REASON],
        ];
    }

    /**
     * Workers that drain their last jobs one by one after the last tick (evidence at a + 200, a + 450, a + 700),
     * through orrery:tick: the walk records the gap from a + 700 with its window start a, and the lost job is a
     * blind-gap loss.
     *
     * Rejects: a staggered queue:restart drain that turns every lost job into an alert.
     */
    public function test_a_staggered_drain_is_still_a_blind_gap_loss(): void
    {
        $this->app['config']->set('queue.connections.database.retry_after', 900);
        [$uuid, $jobId] = $this->pushed();
        $this->tick();
        $base = Clock::now();

        foreach ([200.0, 450.0, 700.0] as $offset) {
            $this->mirror()->table('orrery_jobs')->insert([
                'uuid' => strtolower((string) Str::uuid()), 'connection' => 'database', 'queue' => 'default', 'name' => 'App\\Jobs\\Drain',
                'status' => 'completed', 'payload' => '{}', 'payload_bytes' => 2, 'pushed_at' => Clock::toDecimal($base),
                'reserved_at' => Clock::toDecimal($base + $offset - 1), 'finished_at' => Clock::toDecimal($base + $offset), 'updated_at' => Clock::toDecimal($base + $offset),
            ]);
        }

        $this->queueTable()->where('id', $jobId)->delete();
        $this->moveClock(2400);
        $this->tick();

        $gaps = BlindGaps::anchored();
        self::assertCount(1, $gaps);
        self::assertEqualsWithDelta([$base + 700, $base + 2400, $base], $gaps[0], 0.001);

        $this->moveClock(900);
        $this->tick();
        self::assertSame(['unknown', Reconciliation::BLIND_GAP_REASON], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->unknown_reason]);
    }

    /**
     * Rejects: a restart whenever any gap is recorded: a sweep that started after the newest gap's end goes on from
     * its cursor.
     */
    public function test_a_sweep_that_started_after_the_gap_goes_on(): void
    {
        Reconciliation::useActiveBatchForTesting(1);
        $base = Clock::now();
        [$first, $firstId] = $this->pushed();
        [$second] = $this->pushed();
        $this->gap($base - 3000, $base - 1200);
        $this->sweptAt($base - 1100);
        $this->mirror()->table('orrery_settings')->insert(['key' => Reconciliation::CURSOR_PREFIX.'database', 'value' => json_encode([
            'after' => (int) $this->rowOrFail($first)->id, 'started_at' => Clock::toDecimal($base - 60),
        ])]);
        $this->queueTable()->where('id', $firstId)->delete();

        $this->runActive();

        self::assertNull($this->rowOrFail($first)->absent_since, 'the first row was checked earlier in this sweep: not again');
        $cursor = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', Reconciliation::CURSOR_PREFIX.'database')->value('value'), true);
        self::assertSame(Clock::toDecimal($base - 60), $cursor['started_at'] ?? null, 'the sweep keeps its start');
        self::assertNull($this->rowOrFail($second)->absent_since);
    }

    /**
     * Rejects: a reason that outlives the absence it belongs to: the release fast path, a new job id (Rules::revision)
     * and a new cycle (CycleStart) clear `absent_since`, and the reason with it in the same write; and a first
     * absence without a reason that keeps an old one.
     */
    public function test_the_reason_goes_with_its_absence(): void
    {
        // The release fast path.
        [$released] = $this->pushed();
        $job = $this->queue()->pop();
        self::assertNotNull($job);
        $this->mirror()->table('orrery_jobs')->where('uuid', $released)->update(['absent_since' => Clock::toDecimal(Clock::now()), 'unknown_reason' => Reconciliation::BLIND_GAP_REASON]);
        $job->release(0);
        self::assertSame(['pending', null, null], [$this->rowOrFail($released)->status, $this->rowOrFail($released)->absent_since, $this->rowOrFail($released)->unknown_reason]);

        // A first absence outside any gap writes no reason over an old one.
        $this->mirror()->table('orrery_jobs')->where('uuid', $released)->update(['unknown_reason' => Reconciliation::BLIND_GAP_REASON]);
        $this->queueTable()->delete();
        $this->runActive();
        self::assertNotNull($this->rowOrFail($released)->absent_since);
        self::assertNull($this->rowOrFail($released)->unknown_reason);
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runActive();
        self::assertSame(['unknown', Reconciliation::UNKNOWN_REASON], [$this->rowOrFail($released)->status, $this->rowOrFail($released)->unknown_reason]);

        // A new cycle.
        self::assertArrayHasKey('unknown_reason', CycleStart::columns(2, null, 5, 'native', Clock::now()));
        self::assertNull(CycleStart::columns(2, null, 5, 'native', Clock::now())['unknown_reason']);
    }

    /**
     * Rejects: Rules::revision keeping the reason when a reservation of another job id clears `absent_since`.
     */
    public function test_a_new_job_id_clears_the_reason(): void
    {
        [$uuid, $first] = $this->pushed();
        self::assertNotNull($this->queue()->pop());
        $row = (array) $this->queueTable()->where('id', $first)->first();
        unset($row['id']);
        $replacement = (int) $this->queueTable()->insertGetId(['reserved_at' => null] + $row);
        $this->queueTable()->where('id', $first)->delete();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['absent_since' => Clock::toDecimal(Clock::now()), 'unknown_reason' => Reconciliation::BLIND_GAP_REASON]);

        self::assertNotNull($this->queue()->pop());

        $row = $this->rowOrFail($uuid);
        self::assertSame(['reserved', $replacement, null, null], [$row->status, (int) $row->job_id, $row->absent_since, $row->unknown_reason]);
    }

    private function tick(): void
    {
        self::assertSame(0, Artisan::call('orrery:tick'), Artisan::output());
    }

    /**
     * A recorded gap; $start is the start of the window whose walk found it (null: a gap written without it).
     */
    private function gap(float $from, float $to, ?float $start = null): void
    {
        $this->mirror()->table('orrery_settings')->upsert([['key' => BlindGaps::KEY, 'value' => json_encode([
            'checked_through' => $to, 'gaps' => [$start === null ? [$from, $to] : [$from, $to, $start]],
        ], JSON_PRESERVE_ZERO_FRACTION)]], ['key'], ['value']);
    }

    /**
     * The first absence and the grace after it.
     */
    private function lose(int $jobId, float $grace = Reconciliation::MIN_GRACE_SECONDS): void
    {
        $this->queueTable()->where('id', $jobId)->delete();
        $this->runActive();
        $this->moveClock($grace);
        $this->runActive();
    }

    private function sweptAt(float $at): void
    {
        $this->mirror()->table('orrery_settings')->upsert([['key' => Reconciliation::SWEPT_PREFIX.'database', 'value' => Clock::toDecimal($at)]], ['key'], ['value']);
    }

    private function runIncidents(): void
    {
        $this->app->make(StuckIncidents::class)->run(new TickBudget(Clock::monotonic() + 30));
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
}
