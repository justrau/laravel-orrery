<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Feature\Lifecycle\Jobs\ScriptedJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Native retries and cycles (R5, the ambiguity, generations).
 */
final class NativeRetryCycleTest extends CycleTestCase
{
    /** 51 native retries make the 51st summary, which compacts cycle 1. */
    private const CYCLES_TO_COMPACT = 51;

    /**
     * Rejects: a republish that moves the failed row back to pending.
     */
    public function test_native_queue_retry_keeps_the_row_failed_until_its_first_reservation(): void
    {
        $queue = $this->queue();
        Queue::push(new FailingJob);
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);
        $this->workOnce();

        $failed = $this->snapshot($uuid);
        self::assertSame('failed', $failed['status']);

        $this->nativeRetry();

        self::assertSame(1, $this->mirrorCount(), 'the retry keeps the uuid: one mirror row');
        $intent = ['retry_requested_at' => true, 'updated_at' => true];
        self::assertNotNull($this->rowOrFail($uuid)->retry_requested_at, 'JobRetryRequested records the intent only');
        self::assertSame(array_diff_key($failed, $intent), array_diff_key($this->snapshot($uuid), $intent), 'the republish changes no state before its first reservation');

        $job = $queue->pop();
        self::assertNotNull($job);

        $row = $this->rowOrFail($uuid);
        self::assertSame('reserved', $row->status);
        self::assertSame(2, (int) $row->cycle);
        self::assertSame('native', $row->cycle_source);
        self::assertSame(1, (int) $row->retried_count);
        self::assertNotNull($row->retried_at);
        self::assertSame((int) $job->getJobId(), (int) $row->job_id);
        self::assertNull($row->finished_at);
        self::assertSame(0, DB::table('failed_jobs')->count(), 'the native retry deleted the provider record');
    }

    /**
     * Rejects: an R5 without the queue payload's generation.
     */
    public function test_native_retry_parity_after_the_first_reservation(): void
    {
        $queue = $this->queue();
        Queue::push(new FailingJob);
        $original = $this->onlyJobsRow();
        $uuid = self::uuidOf($original['payload']);
        $this->workOnce();
        self::assertSame('failed', $this->rowOrFail($uuid)->status);

        // The failed payload carries an old package retry token.
        $record = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($record);
        $payload = json_decode((string) $record->payload, true);
        $payload['orreryRetry'] = ['cycle' => 2, 'token' => str_repeat('d', 64)];
        DB::table('failed_jobs')->where('uuid', $uuid)->update(['payload' => json_encode($payload)]);

        $this->nativeRetry();
        self::assertNotNull($queue->pop(), 'the first reservation (R5)');

        $jobs = $this->onlyJobsRow();
        $row = $this->rowOrFail($uuid);
        self::assertSame(2, (int) $row->cycle);
        self::assertSame($jobs['data']['orreryGen'], $row->cycle_gen, "the mirror row's generation is the queue row's");
        self::assertNotSame($original['data']['orreryGen'], $row->cycle_gen);
        self::assertSame($jobs['payload'], $row->payload, "the mirror row's payload is the queue row's");
        self::assertSame(strlen($jobs['payload']), (int) $row->payload_bytes);
        self::assertArrayNotHasKey('orreryRetry', $jobs['data'], 'the stale token is removed with the swap on');
        self::assertSame($jobs['id'], (int) $row->cycle_first_job_id);
        self::assertSame(1, (int) $row->attempts);

        $summary = $this->summaries($uuid)[0] ?? null;
        self::assertNotNull($summary);
        self::assertSame($original['data']['orreryGen'], $summary['cycle_gen'], 'the summary keeps the generation of cycle 1');
    }

    /**
     * The native retry at the outcome: with the swap off
     * only outcomes are mirrored, so the completion of the retried job starts
     * cycle 2 and completes it.
     *
     * Rejects: an outcome of a native retry that stays unmatched on the
     * failed row, a new cycle without the frozen summary of cycle 1, and a
     * completion that keeps the attempts at 0.
     */
    #[FreshSchema]
    public function test_swap_off_native_retry_starts_a_cycle_at_its_outcome(): void
    {
        $this->queue();
        ScriptedJob::createScriptTable('throw');
        $this->beforeApplicationDestroyed(static fn () => ScriptedJob::dropScriptTable());

        Queue::push(new ScriptedJob(tries: 1));
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);
        self::assertSame(0, $this->mirrorCount(), 'a swap-off push is not mirrored');
        $this->workOnce();

        $failed = $this->rowOrFail($uuid);
        self::assertSame(['failed', 1, 1], [$failed->status, (int) $failed->cycle, (int) $failed->attempts]);

        $this->nativeRetry();
        self::assertNotNull($this->rowOrFail($uuid)->retry_requested_at);
        $retriedJobId = $this->onlyJobsRow()['id'];
        self::assertGreaterThan((int) $failed->job_id, $retriedJobId);

        ScriptedJob::script('complete');
        $this->workOnce();

        $row = $this->rowOrFail($uuid);
        self::assertSame('completed', $row->status, 'the outcome of the native retry was not mirrored');
        self::assertSame(2, (int) $row->cycle);
        self::assertSame('native', $row->cycle_source);
        self::assertSame($retriedJobId, (int) $row->job_id);
        self::assertSame(1, (int) $row->attempts);
        self::assertSame(1, (int) $row->observed_attempts);
        self::assertSame(1, (int) $row->retried_count);
        self::assertNull($row->reserved_at, 'the reservation of the new cycle was not observed: its time is unknown');
        self::assertSame(0, (int) $row->exceptions, 'the counters reset');

        $summary = $this->summaries($uuid)[0] ?? null;
        self::assertNotNull($summary, 'cycle 1 was frozen into its summary');
        self::assertSame(1, (int) $summary['cycle']);
        self::assertSame(1, (int) $summary['exceptions']);
    }

    /**
     * Rejects: a failure of a native retry that stays unmatched (no new
     * occurrence), and one that reuses the occurrence of cycle 1.
     */
    #[FreshSchema]
    public function test_swap_off_native_retry_that_fails_again_is_a_new_occurrence(): void
    {
        $this->queue();
        ScriptedJob::createScriptTable('throw');
        $this->beforeApplicationDestroyed(static fn () => ScriptedJob::dropScriptTable());

        Queue::push(new ScriptedJob(tries: 1));
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);
        $this->workOnce();
        $first = (int) $this->rowOrFail($uuid)->occurrence_id;

        $this->nativeRetry();
        $this->workOnce();

        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', 2, 1], [$row->status, (int) $row->cycle, (int) $row->attempts]);
        self::assertNotSame($first, (int) $row->occurrence_id, 'the second failure is a new occurrence');
        self::assertSame(1, DB::table('failed_jobs')->count());
    }

    /**
     * Rejects: an R5 at n >= 2.
     */
    #[DataProvider('swapModes')]
    public function test_native_retry_with_a_missed_first_reservation_is_unmatched_in_both_modes(bool $instrumented): void
    {
        $uuid = $this->uuid();
        $this->failCycleOne($uuid, $instrumented);
        $before = $this->snapshot($uuid);

        // The native republish is job 20. Its reservation at attempt 1 was
        // missed; the reclaim at attempt 2 is the first observation.
        $unmatched = self::unmatched();
        $outcome = $this->observe('reserve', $uuid, ['jobId' => 20, 'attempt' => 2, 'instrumented' => $instrumented], ['orreryGen' => $instrumented ? self::G2 : self::G1]);

        self::assertSame(1, (int) $this->rowOrFail($uuid)->cycle, 'no new cycle from an observation at n >= 2');
        self::assertSame($before, $this->snapshot($uuid), 'the row stays failed in cycle 1');
        self::assertFalse($outcome->applied);
        self::assertSame($unmatched + 1, self::unmatched(), 'the ambiguity counts one unmatched observation');
        self::assertSame([], $this->summaries($uuid));
    }

    /**
     * Rejects: matching an older cycle's outcome by uuid.
     */
    #[DataProvider('swapModes')]
    public function test_old_attempt_four_outcome_after_cycle_two_reserved_is_rejected_in_both_modes(bool $instrumented): void
    {
        $uuid = $this->uuid();
        $g1 = ['orreryGen' => self::G1];
        $this->observe('push', $uuid, ['jobId' => 10, 'instrumented' => $instrumented], $g1);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 3, 'instrumented' => $instrumented], $g1);
        $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 3, 'instrumented' => $instrumented, 'exception' => new \RuntimeException('cycle 1')], $g1);

        $r5 = $this->observe('reserve', $uuid, ['jobId' => 20, 'attempt' => 1, 'instrumented' => $instrumented], ['orreryGen' => $instrumented ? self::G2 : self::G1]);
        self::assertSame('nativeRetry', $r5->rule);
        $before = $this->snapshot($uuid);
        $summaries = $this->summaries($uuid);

        foreach (['exception', 'complete', 'fail'] as $kind) {
            $outcome = $this->observe($kind, $uuid, ['jobId' => 10, 'attempt' => 4, 'instrumented' => $instrumented, 'exception' => new \RuntimeException('old attempt 4')], $g1);
            self::assertFalse($outcome->applied, "the old {$kind} is rejected for the row");
        }

        self::assertSame($before, $this->snapshot($uuid), 'cycle 2 is unchanged');
        self::assertSame(2, (int) $before['cycle']);

        if ($instrumented) {
            // O: the retained summary of cycle 1 takes the evidence of its own generation.
            $summary = $this->summaries($uuid)[0];
            self::assertSame(1, (int) $summary['exceptions'] - (int) $summaries[0]['exceptions'], 'the old exception updates the summary of its generation');
            self::assertSame(4, (int) $summary['last_outcome_attempt']);
        } else {
            self::assertSame($summaries, $this->summaries($uuid), 'without generations the old outcomes are unmatched');
        }
    }

    /**
     * Rejects: id matching when both generations are known.
     */
    public function test_reused_job_id_of_an_older_generation_is_rejected(): void
    {
        $uuid = $this->uuid();
        $this->failCycleOne($uuid, true);
        $this->observe('reserve', $uuid, ['jobId' => 20, 'attempt' => 1], ['orreryGen' => self::G2]);
        $before = $this->snapshot($uuid);
        self::assertSame(2, (int) $before['cycle']);

        $unmatched = self::unmatched();

        // Job id 20 of cycle 2, reused by an observation of generation G1 (cycle 1) and of an unknown generation.
        foreach ([self::G1, str_repeat('e', 32)] as $generation) {
            foreach (['exception', 'complete', 'release'] as $kind) {
                $outcome = $this->observe($kind, $uuid, ['jobId' => 20, 'attempt' => 1, 'newJobId' => 21, 'delay' => 0], ['orreryGen' => $generation]);
                self::assertFalse($outcome->applied, "a {$kind} of another generation at a reused id");
            }
        }

        self::assertSame($before, $this->snapshot($uuid), 'the current cycle is unchanged whatever the id');
        self::assertSame($unmatched + 3, self::unmatched(), 'the unknown generation is unmatched; G1 is a summarised generation');
    }

    /**
     * Rejects: an R5 without the id-order condition.
     */
    #[Group('process')]
    #[Group('shared-database')]
    #[FreshSchema]
    public function test_delayed_first_reservation_after_compaction_is_rejected(): void
    {
        [$uuid, $worker, $barrier] = $this->holdAFirstReservationAcrossCompaction(['queue:work', 'database', '--once', '--sleep=0', '--tries=5'], 'complete');

        $before = $this->snapshot($uuid);
        $summaries = $this->summaries($uuid);

        $barrier->arrive(DatabaseQueue::AFTER_POP.'.release');
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $after = $this->snapshot($uuid);
        self::assertSame(self::CYCLES_TO_COMPACT + 1, (int) $after['cycle'], 'R5 rejected the delayed reservation by the id order');
        self::assertSame($before, $after, 'the current cycle, the provider identity and the retry count are unchanged');
        self::assertSame($summaries, $this->summaries($uuid), 'no summary changes');
        self::assertSame(2, $this->workerUnmatched($worker), 'the delayed reservation and its completion are unmatched');
    }

    /**
     * Rejects: evidence of a compacted cycle applied to the row or the merged summary.
     */
    #[Group('process')]
    #[Group('shared-database')]
    #[FreshSchema]
    public function test_compacted_generation_observations_are_unmatched(): void
    {
        [$uuid, $worker, $barrier] = $this->holdAFirstReservationAcrossCompaction(['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=5'], 'held_worker');

        $before = $this->snapshot($uuid);
        $summaries = $this->summaries($uuid);

        $barrier->arrive(DatabaseQueue::AFTER_POP.'.release');
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->count(), 'the held worker released, reserved, threw, released, reserved and completed');

        self::assertSame($before, $this->snapshot($uuid), 'the current cycle is unchanged');
        self::assertSame($summaries, $this->summaries($uuid), 'no summary changes, the merged row included');
        // Three reservations, two releases, one exception and one completion.
        self::assertSame(7, $this->workerUnmatched($worker), 'each observation of the compacted generation is unmatched');
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function swapModes(): array
    {
        return ['swap on' => [true], 'swap off' => [false]];
    }

    /**
     * A row that failed in cycle 1 (job 10, generation G1, attempt 1).
     */
    private function failCycleOne(string $uuid, bool $instrumented): void
    {
        $g1 = ['orreryGen' => self::G1];
        $this->observe('push', $uuid, ['jobId' => 10, 'instrumented' => $instrumented], $g1);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1, 'instrumented' => $instrumented], $g1);
        $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 1, 'instrumented' => $instrumented, 'exception' => new \RuntimeException('cycle 1')], $g1);
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
    }

    /**
     * A worker reserves the job's first publication and holds before the
     * package observes it. The test then reclaims that publication, fails
     * it, and runs 51 native retry cycles, so the held generation's summary
     * is compacted. The worker's mode after its release is $mode.
     *
     * @param  list<string>  $command
     * @return array{0: string, 1: WorkerProcess, 2: Barrier}
     */
    private function holdAFirstReservationAcrossCompaction(array $command, string $mode): array
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $this->queue();
        ScriptedJob::createScriptTable('fail');
        $this->beforeApplicationDestroyed(static fn () => ScriptedJob::dropScriptTable());

        Queue::push(new ScriptedJob(tries: 5));
        $first = $this->onlyJobsRow();
        $uuid = self::uuidOf($first['payload']);

        $barrier = Barrier::forTest();
        $worker = WorkerProcess::start($command, $this->testEnvironment(), $barrier->holdEnvironment(DatabaseQueue::AFTER_POP));
        $worker->waitUntil(static fn (): bool => ! $worker->isRunning() || $barrier->arrived(DatabaseQueue::AFTER_POP.'.reached'), 60, 'it reserved the first publication');
        self::assertTrue($barrier->arrived(DatabaseQueue::AFTER_POP.'.reached'), $worker->describe());
        self::assertSame('pending', $this->rowOrFail($uuid)->status, 'the held reservation is not observed yet');

        // The reservation expires: this process reclaims the first publication and fails it.
        Carbon::setTestNow(Carbon::now()->addSeconds(600));
        $this->workOnce();
        self::assertSame('failed', $this->rowOrFail($uuid)->status);

        for ($retry = 1; $retry <= self::CYCLES_TO_COMPACT; $retry++) {
            $this->nativeRetry();
            $this->workOnce();
        }

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status);
        self::assertSame(self::CYCLES_TO_COMPACT + 1, (int) $row->cycle);
        self::assertSame(self::CYCLES_TO_COMPACT, (int) $row->retried_count);
        self::assertSame(50, $this->individualSummaries($uuid));
        self::assertSame(0, $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->where('cycle_gen', $first['data']['orreryGen'])->count(), "the held generation's summary is compacted");

        ScriptedJob::script($mode);
        Carbon::setTestNow();

        return [$uuid, $worker, $barrier];
    }

    private function workerUnmatched(WorkerProcess $worker): int
    {
        return (int) $this->mirror()->table('orrery_health_events')
            ->where('process', 'like', '%:'.$worker->pid().':%')
            ->sum('unmatched_observations');
    }
}
