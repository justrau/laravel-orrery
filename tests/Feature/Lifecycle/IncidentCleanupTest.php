<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\RecordingIncidentCleanup;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * The retention of the stuck incidents in cycle compaction:
 * the freeze that merges a cycle deletes that cycle's incidents in its own
 * transaction, so a job keeps at most 51 × 5 = 255 incidents.
 */
final class IncidentCleanupTest extends CycleTestCase
{
    private const CURRENT = 51;

    protected function tearDown(): void
    {
        RecordingIncidentCleanup::$calls = [];

        parent::tearDown();
    }

    /**
     * Rejects: a merged cycle that keeps an incident (any delivery state),
     * a retained cycle or another job that loses one, and a deleteForJobs()
     * that deletes another job's incidents.
     */
    public function test_merged_cycle_incidents_are_deleted(): void
    {
        $uuid = $this->jobWithFiftySummaries();
        $other = $this->uuid();

        $this->incident($uuid, 1, 'outcome_not_observed');
        $this->incident($uuid, 1, 'repeated_releases', ['attempts_made' => 1, 'next_attempt_at' => Clock::toDecimal(self::T + 60)]);
        $this->incident($uuid, 1, 'unknown', ['attempts_made' => 3, 'delivery_failed_at' => Clock::toDecimal(self::T)]);
        $this->incident($uuid, 1, 'attempts_not_capped', ['attempts_made' => 1, 'delivered_at' => Clock::toDecimal(self::T)]);
        $this->incident($uuid, 2, 'outcome_not_observed');
        $this->incident($other, 1, 'outcome_not_observed');

        $this->startCycle($uuid);

        self::assertSame([], array_keys($this->incidents($uuid, 1)), 'the merged cycle keeps no incident');
        self::assertSame(['outcome_not_observed'], array_keys($this->incidents($uuid, 2)), 'a retained cycle keeps its incidents');
        self::assertSame(['outcome_not_observed'], array_keys($this->incidents($other, 1)));

        // Trim, forget and clear delete a job's incidents with the job.
        $this->app->make(IncidentCleanup::class)->deleteForJobs($this->mirror(), [$uuid]);
        self::assertSame(0, $this->mirror()->table(IncidentCleanup::TABLE)->where('job_uuid', $uuid)->count());
        self::assertSame(1, $this->mirror()->table(IncidentCleanup::TABLE)->where('job_uuid', $other)->count());
    }

    /**
     * Rejects: an exception on a mirror migrated before schema step 9
     * (S-12): no incident table there.
     */
    #[FreshSchema]
    public function test_missing_incidents_table_is_ignored(): void
    {
        $this->mirrorSchema()->drop(IncidentCleanup::TABLE);
        $uuid = $this->jobWithFiftySummaries();

        $outcome = $this->startCycle($uuid);

        self::assertSame('nativeRetry', $outcome->rule);
        self::assertSame(self::CURRENT + 1, (int) $this->rowOrFail($uuid)->cycle);
        self::assertSame(1, $this->mirror()->table(CycleSummaries::TABLE)->where('job_uuid', $uuid)->where('merged', true)->count());
        self::assertSame(0, Degradation::record()['dropped'] ?? 0);

        $this->app->make(IncidentCleanup::class)->deleteForJobs($this->mirror(), [$uuid]);
    }

    /**
     * Rejects: a delete that runs after the freeze transaction or before
     * the frozen summary, or outside it.
     */
    public function test_the_delete_runs_inside_the_freeze_transaction(): void
    {
        $this->app->singleton(IncidentCleanup::class, RecordingIncidentCleanup::class);
        $uuid = $this->jobWithFiftySummaries();
        $this->incident($uuid, 1, 'outcome_not_observed');
        $this->incident($uuid, 1, 'unknown');

        $this->startCycle($uuid);

        self::assertCount(1, RecordingIncidentCleanup::$calls);
        $call = RecordingIncidentCleanup::$calls[0];
        self::assertSame([$uuid, 1, Clock::toDecimal(self::T)], [$call['uuid'], $call['cycle'], Clock::toDecimal($call['at'])]);
        self::assertSame(2, $call['incidents'], 'the seam sees the incidents before the delete');
        self::assertTrue($call['in_transaction'], 'the mirror PDO is inside the freeze transaction');
        self::assertSame($this->app->make(PackageSession::class)->mirrorName(), $call['connection']);
        self::assertSame(self::CURRENT, $call['row_cycle'], 'the delete runs before the new cycle is written');
        self::assertTrue($call['summary_frozen'], 'the delete runs after the frozen summary was inserted');

        self::assertSame([], array_keys($this->incidents($uuid, 1)));
    }

    /**
     * Rejects: a compaction that leaves the merged cycles' incidents (the
     * bound of 255 per job): a job through 150 cycles, each with an incident
     * of every signal, keeps exactly the incidents of its 50 retained cycles
     * and of its current one.
     */
    public function test_150_cycles_keep_at_most_255_incidents(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 100], ['orreryGen' => self::gen(1)]);

        for ($cycle = 1; $cycle <= 150; $cycle++) {
            $outcome = $this->observe('reserve', $uuid, ['jobId' => 100 + $cycle, 'attempt' => 1], ['orreryGen' => self::gen($cycle)]);
            self::assertSame($cycle, (int) $this->rowOrFail($uuid)->cycle, "cycle {$cycle} started ({$outcome->rule})");

            foreach (StuckIncidents::SIGNALS as $signal) {
                $this->incident($uuid, $cycle, $signal);
            }

            $this->observe('fail', $uuid, ['jobId' => 100 + $cycle, 'attempt' => 1, 'exception' => new \RuntimeException('cycle '.$cycle)], ['orreryGen' => self::gen($cycle)]);
        }

        $cycles = $this->mirror()->table(IncidentCleanup::TABLE)->where('job_uuid', $uuid)->distinct()->orderBy('cycle')->pluck('cycle')->map(static fn (mixed $cycle): int => (int) $cycle)->all();

        self::assertSame(255, $this->mirror()->table(IncidentCleanup::TABLE)->where('job_uuid', $uuid)->count());
        self::assertSame(range(100, 150), $cycles, 'the merged cycles 1 to 99 have no incident');
        self::assertSame(CycleSummaries::INDIVIDUAL, $this->individualSummaries($uuid));
    }

    /**
     * A failed row in cycle 51 with 50 retained summaries (cycles 1 to 50).
     */
    private function jobWithFiftySummaries(): string
    {
        $uuid = $this->uuid();
        $gen = ['orreryGen' => self::G1];
        $this->observe('push', $uuid, ['jobId' => 100], $gen);
        $this->observe('reserve', $uuid, ['jobId' => 100, 'attempt' => 1], $gen);
        $this->observe('fail', $uuid, ['jobId' => 100, 'attempt' => 1, 'exception' => new \RuntimeException('cycle 51')], $gen);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['cycle' => self::CURRENT, 'retried_count' => self::CURRENT - 1]);

        for ($cycle = 1; $cycle < self::CURRENT; $cycle++) {
            $this->mirror()->table(CycleSummaries::TABLE)->insert([
                'job_uuid' => $uuid,
                'cycle' => $cycle,
                'cycle_gen' => str_pad(dechex($cycle), 32, '0', STR_PAD_LEFT),
                'outcome' => 'failed',
                'merged' => false,
                'attempts' => 1,
                'finished_at' => Clock::toDecimal(self::T - 1000 + $cycle),
            ]);
        }

        return $uuid;
    }

    private function startCycle(string $uuid): \Justrau\Orrery\Lifecycle\StoreOutcome
    {
        $outcome = $this->observe('reserve', $uuid, ['jobId' => 200, 'attempt' => 1], ['orreryGen' => self::G2]);
        self::assertSame('nativeRetry', $outcome->rule);
        self::assertSame(CycleSummaries::INDIVIDUAL, $this->individualSummaries($uuid));

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function incident(string $uuid, int $cycle, string $signal, array $values = []): void
    {
        $this->mirror()->table(IncidentCleanup::TABLE)->insert($values + [
            'job_uuid' => $uuid,
            'cycle' => $cycle,
            'signal' => $signal,
            'first_seen_at' => Clock::toDecimal(self::T - 100),
            'evidence' => '{}',
        ]);
    }

    private static function gen(int $cycle): string
    {
        return str_pad(dechex($cycle), 32, 'c', STR_PAD_LEFT);
    }

    /**
     * @return array<string, object> signal => row
     */
    private function incidents(string $uuid, int $cycle): array
    {
        return $this->mirror()->table(IncidentCleanup::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->orderBy('signal')->get()->keyBy('signal')->all();
    }
}
