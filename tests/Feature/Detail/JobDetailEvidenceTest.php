<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Detail;

use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Lifecycle\CycleStart;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Runtime\WorkerEvidence;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;
use Symfony\Component\HttpFoundation\Response;

/**
 * The effective tries and timeout of the job detail with per-attempt worker
 * evidence from two workers,
 * through the lifecycle store's own
 * observations.
 *
 * Worker A (--tries=2 --timeout=11) reserves and releases attempt 1. Worker
 * B's reservation of attempt 2 is not observed; B (--tries=5 --timeout=22)
 * fails attempt 2. The current cycle shows B's values from the failure
 * observation; the next cycle starts, and the frozen cycle keeps B's
 * values, while the new cycle has no evidence.
 */
final class JobDetailEvidenceTest extends ControllerTest
{
    use BuildsObservations;

    private const UUID = '5d3e6c1a-8b2f-4e7d-9c0a-00000000e001';

    private const A = 'host-a:100:1789990000';

    /**
     * Rejects: a detail that shows worker A's values (a heartbeat or an
     * earlier reservation) for B's failure, a frozen cycle that reads the
     * row after the next cycle starts, and a new cycle that inherits the old
     * evidence.
     */
    public function test_the_failing_workers_values_for_the_current_and_the_frozen_cycle(): void
    {
        $store = $this->app->make(JobStore::class);
        $store->observe(self::observation('push', self::UUID, ['jobId' => 10]));
        $store->observe(self::observation('reserve', self::UUID, ['jobId' => 10, 'attempt' => 1, 'workerId' => self::A, 'inWorker' => true, 'worker' => new WorkerEvidence(2, 11, 'database', 'default', false)]));
        $store->observe(self::observation('release', self::UUID, ['jobId' => 10, 'attempt' => 1, 'newJobId' => 11]));
        $store->observe(self::observation('fail', self::UUID, ['jobId' => 11, 'attempt' => 2, 'inWorker' => true, 'worker' => new WorkerEvidence(5, 22, 'database', 'default', false), 'at' => self::T + 5]));

        $row = $this->mirror()->table(JobStore::TABLE)->where('uuid', self::UUID)->first();
        self::assertNotNull($row);
        self::assertSame('failed', $row->status);
        // B's reservation was not observed: the row counts 1 reservation, and the failure names attempt 2.
        self::assertSame(1, (int) $row->attempts);
        self::assertSame(2, (int) $row->last_outcome_attempt);
        self::assertSame(2, (int) $row->worker_evidence_attempt);

        $current = $this->detail()->json('cycles.0');
        self::assertSame(['tries' => 5, 'tries_source' => 'worker', 'timeout' => 22, 'timeout_source' => 'worker'], $current['effective']);
        self::assertSame(['kind' => 'attempt_limit', 'deadline' => null, 'tries' => 5, 'tries_source' => 'worker', 'other_stops' => [
            'max_exceptions' => null, 'fail_on_timeout' => false, 'timeout' => 22, 'timeout_source' => 'worker',
        ]], $current['enforced_rule']);

        self::assertSame('other', $current['terminal']['kind']);
        self::assertSame(2, $current['terminal']['facts']['attempt'], 'the failing attempt, not the observed reservations');
        self::assertSame(1, $current['attempts'], 'the counter counts observed reservations only');

        // The next cycle starts as a retry finalisation does: the summary freezes the failing cycle.
        $mirror = $this->mirror();
        $at = self::T + 60;
        $this->app->make(CycleSummaries::class)->freeze($mirror, $row, $at);
        $mirror->table(JobStore::TABLE)->where('uuid', self::UUID)->update([
            'status' => 'pending', 'job_id' => 12, 'worker_tries' => null, 'worker_timeout' => null, 'worker_evidence_attempt' => null,
            'finished_at' => null, 'failed_at' => null, 'retried_count' => 1, 'updated_at' => Clock::toDecimal($at),
        ] + CycleStart::columns(2, self::G2, 12, 'dashboard', $at));

        $cycles = $this->detail()->json('cycles');
        self::assertSame([2, 1], array_column($cycles, 'cycle'));
        self::assertSame(['tries' => null, 'tries_source' => 'unknown', 'timeout' => null, 'timeout_source' => 'unknown'], $cycles[0]['effective']);
        self::assertSame('unknown', $cycles[0]['enforced_rule']['kind']);
        self::assertSame(['tries' => 5, 'tries_source' => 'worker', 'timeout' => 22, 'timeout_source' => 'worker'], $cycles[1]['effective'], "B's frozen values");
        self::assertSame(['kind' => 'other', 'facts' => [
            'attempt' => 2, 'tries' => 5, 'tries_source' => 'worker', 'deadline' => null, 'deadline_passed' => null,
            'max_exceptions' => null, 'fail_on_timeout' => false, 'timeout' => 22, 'timeout_source' => 'worker',
        ]], $cycles[1]['terminal'], 'a failure without an attempt exception: conditions, not a cause');
    }

    /**
     * Rejects: worker A's values for a failure that happened outside a
     * worker (the store clears the evidence; the detail shows unknown).
     */
    public function test_a_failure_outside_a_worker_is_unknown(): void
    {
        $store = $this->app->make(JobStore::class);
        $store->observe(self::observation('push', self::UUID, ['jobId' => 10]));
        $store->observe(self::observation('reserve', self::UUID, ['jobId' => 10, 'attempt' => 1, 'workerId' => self::A, 'inWorker' => true, 'worker' => new WorkerEvidence(2, 11, 'database', 'default', false)]));
        $store->observe(self::observation('fail', self::UUID, ['jobId' => 10, 'attempt' => 1, 'inWorker' => false]));

        self::assertSame(['tries' => null, 'tries_source' => 'unknown', 'timeout' => null, 'timeout_source' => 'unknown'], $this->detail()->json('cycles.0.effective'));
    }

    /**
     * @return TestResponse<Response>
     */
    private function detail(): TestResponse
    {
        return $this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::UUID)->assertOk();
    }
}
