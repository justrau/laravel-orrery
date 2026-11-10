<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Listeners\ObserveJobException;
use Justrau\Orrery\Tests\Feature\Lifecycle\Jobs\ScriptedJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * Outcome accounting and the observed gap (and its ordering
 * races), on every engine. Swap on, with one generation.
 */
final class OutcomeAccountingTest extends CycleTestCase
{
    /**
     * Rejects: the gap formula without late_outcomes.
     */
    public function test_outcomes_two_then_one_then_reserve_three(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 2]);

        $this->observe('exception', $uuid, ['jobId' => 10, 'attempt' => 2]);
        $row = $this->rowOrFail($uuid);
        self::assertSame(1, (int) $row->observed_attempts);
        self::assertSame(2, (int) $row->last_outcome_attempt);
        self::assertSame(1, (int) $row->observed_gap, 'attempt 1 has no observed outcome yet');

        $this->observe('exception', $uuid, ['jobId' => 10, 'attempt' => 1]);
        $row = $this->rowOrFail($uuid);
        self::assertSame(1, (int) $row->late_outcomes, 'the outcome of attempt 1 is late');
        self::assertSame(0, (int) $row->observed_gap);
        self::assertSame(1, $this->historyRows($uuid, 'late_outcome'));
        self::assertSame(1, (int) $row->exceptions, 'the late exception is not higher than the high-water column');

        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 3]);
        $row = $this->rowOrFail($uuid);
        self::assertSame('reserved', $row->status);
        self::assertSame(3, (int) $row->attempts);
        self::assertSame(1, (int) $row->late_outcomes);
        self::assertSame(1, (int) $row->observed_attempts);
        self::assertSame(0, (int) $row->observed_gap, 'attempt 3 is running, attempts 1 and 2 have outcomes');

        $before = $this->snapshot($uuid);
        $this->observe('exception', $uuid, ['jobId' => 10, 'attempt' => 1]);
        self::assertSame($before, $this->snapshot($uuid), 'the same late outcome again changes nothing');
        self::assertSame(1, $this->historyRows($uuid, 'late_outcome'));
    }

    /**
     * Rejects: accounting only for observations whose transition applies.
     */
    public function test_late_completion_and_late_failure_after_attempt_two_completed(): void
    {
        foreach ([['complete', 'fail'], ['fail', 'complete']] as [$first, $second]) {
            $uuid = $this->uuid();
            $this->observe('push', $uuid, ['jobId' => 10]);
            $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);
            $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 2]);
            $this->observe('complete', $uuid, ['jobId' => 10, 'attempt' => 2]);
            self::assertSame(1, (int) $this->rowOrFail($uuid)->observed_gap, 'attempt 1 has no observed outcome yet');

            $outcome = $this->observe($first, $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => new \RuntimeException('late')]);
            self::assertFalse($outcome->applied, "a late {$first} is not a transition");

            $row = $this->rowOrFail($uuid);
            self::assertSame('completed', $row->status, "the row stays completed after a late {$first}");
            self::assertSame(1, (int) $row->late_outcomes, "a late {$first} is a late outcome");
            self::assertSame(0, (int) $row->observed_gap);
            self::assertNull($row->exception_class, 'a rejected failure writes no state column');

            $this->observe($second, $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => new \RuntimeException('late')]);
            $row = $this->rowOrFail($uuid);
            self::assertSame('completed', $row->status);
            self::assertSame(1, (int) $row->late_outcomes, 'the same attempt is late once');
            self::assertSame(0, (int) $row->observed_gap);
            self::assertSame(0, $this->historyRows($uuid, 'failed'), 'no failure history row');
        }
    }

    /**
     * Rejects: a gap that grows with a duplicate.
     */
    public function test_outcomes_one_two_one_never_raise_the_gap_above_the_truth(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);

        // Each step: the observation and the true number of ended attempts without an outcome.
        $steps = [
            ['reserve', ['jobId' => 10, 'attempt' => 1], 0],
            ['release', ['jobId' => 10, 'attempt' => 1, 'newJobId' => 11, 'delay' => 0], 0],
            ['reserve', ['jobId' => 11, 'attempt' => 2], 0],
            ['exception', ['jobId' => 11, 'attempt' => 2], 0],
            ['exception', ['jobId' => 10, 'attempt' => 1], 0],
            ['exception', ['jobId' => 11, 'attempt' => 2], 0],
        ];

        foreach ($steps as $i => [$kind, $fields, $truth]) {
            $this->observe($kind, $uuid, $fields);
            self::assertLessThanOrEqual($truth, (int) $this->rowOrFail($uuid)->observed_gap, "step {$i} ({$kind} of attempt {$fields['attempt']})");
        }

        $row = $this->rowOrFail($uuid);
        self::assertSame(2, (int) $row->observed_attempts, 'the release of attempt 1 and the exception of attempt 2');
        self::assertSame(1, (int) $row->late_outcomes, 'the documented error source: outcome 1 again after outcome 2');
        self::assertSame(2, (int) $row->last_outcome_attempt);
        self::assertSame(1, (int) $row->releases);
        self::assertSame(1, (int) $row->exceptions);
    }

    /**
     * Rejects: a fast release whose gap reads the counters that the same statement changed before it (MySQL evaluates SET from left to right).
     */
    public function test_fast_release_keeps_the_gap_of_a_missed_attempt(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);

        // Attempt 1 was never observed: the first reservation is attempt 2.
        self::assertSame('reserve', $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 2])->rule);
        self::assertSame(1, (int) $this->rowOrFail($uuid)->observed_gap);

        self::assertSame('release', $this->observe('release', $uuid, ['jobId' => 10, 'attempt' => 2, 'newJobId' => 11, 'delay' => 0])->rule);
        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status);
        self::assertSame(1, (int) $row->observed_attempts, 'the release is the outcome of attempt 2');
        self::assertSame(2, (int) $row->last_outcome_attempt);
        self::assertSame(1, (int) $row->observed_gap, 'attempt 1 still has no observed outcome');

        self::assertSame('reserve', $this->observe('reserve', $uuid, ['jobId' => 11, 'attempt' => 3])->rule);
        self::assertSame(1, (int) $this->rowOrFail($uuid)->observed_gap, 'attempt 3 is running');
    }

    /**
     * Rejects: subtracting the running attempt when its exception was already counted.
     */
    #[Group('process')]
    #[Group('shared-database')]
    #[FreshSchema]
    public function test_exception_while_reserved_is_not_subtracted_twice(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $this->queue();
        ScriptedJob::createScriptTable('throw');
        $this->beforeApplicationDestroyed(static fn () => ScriptedJob::dropScriptTable());

        Queue::push(new ScriptedJob(tries: 5));
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);

        // Attempt 1 ended without any observation (a worker that died).
        DB::table('jobs')->update(['attempts' => 1]);

        $barrier = Barrier::forTest();
        $worker = WorkerProcess::start(
            ['queue:work', 'database', '--once', '--sleep=0', '--tries=5'],
            $this->testEnvironment(),
            $barrier->holdEnvironment(ObserveJobException::AFTER),
        );
        $worker->waitUntil(static fn (): bool => ! $worker->isRunning() || $barrier->arrived(ObserveJobException::AFTER.'.reached'), 60, 'it observed the exception of attempt 2');
        self::assertTrue($barrier->arrived(ObserveJobException::AFTER.'.reached'), $worker->describe());

        $row = $this->rowOrFail($uuid);
        self::assertSame('reserved', $row->status, 'the job is still reserved: the release follows the exception');
        self::assertSame(2, (int) $row->attempts);
        self::assertSame(1, (int) $row->observed_attempts);
        self::assertSame(2, (int) $row->last_outcome_attempt);
        self::assertSame(1, (int) $row->observed_gap, 'only attempt 1 has no observed outcome');

        $barrier->arrive(ObserveJobException::AFTER.'.release');
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'the worker released attempt 2');
        self::assertSame(1, (int) $row->observed_attempts, 'the release of attempt 2 is a duplicate outcome');
        self::assertSame(1, (int) $row->observed_gap);
    }

    /**
     * Rejects: counters without the high-water columns.
     */
    public function test_duplicate_completion_exception_and_timeout_count_once(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);

        foreach (['exception', 'exception', 'timeout', 'timeout', 'complete', 'complete', 'exception'] as $kind) {
            $this->observe($kind, $uuid, ['jobId' => 10, 'attempt' => 1]);
        }

        $row = $this->rowOrFail($uuid);
        self::assertSame('completed', $row->status);
        self::assertSame(1, (int) $row->observed_attempts);
        self::assertSame(1, (int) $row->last_outcome_attempt);
        self::assertSame(0, (int) $row->late_outcomes);
        self::assertSame(1, (int) $row->exceptions);
        self::assertSame(1, (int) $row->timeouts);
        self::assertSame(0, (int) $row->observed_gap);
        self::assertSame(1, $this->historyRows($uuid, 'exception'));
        self::assertSame(1, $this->historyRows($uuid, 'timeout'));
        self::assertSame(0, $this->historyRows($uuid, 'late_outcome'));

        $counters = $this->counters();
        self::assertSame(1, $counters['queue:database:default']['throughput'] ?? null, 'one completion is counted once');
    }

    /**
     * Rejects: a late release that moves the row back to pending.
     */
    public function test_late_release_after_a_reclaim_is_a_late_outcome(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 2]);
        $this->observe('exception', $uuid, ['jobId' => 10, 'attempt' => 2]);

        $outcome = $this->observe('release', $uuid, ['jobId' => 10, 'attempt' => 1, 'newJobId' => 11, 'delay' => 0]);
        self::assertFalse($outcome->applied);

        $row = $this->rowOrFail($uuid);
        self::assertSame('reserved', $row->status, 'the late release does not move the row back to pending');
        self::assertSame(10, (int) $row->job_id);
        self::assertSame(2, (int) $row->attempts);
        self::assertNull($row->prev_job_id);
        self::assertSame(0, (int) $row->releases);
        self::assertSame(1, (int) $row->late_outcomes, 'the release of attempt 1 after the outcome of attempt 2 is late');
        self::assertSame(0, (int) $row->observed_gap);
        self::assertSame(1, $this->historyRows($uuid, 'late_outcome'));

        // Before any outcome of attempt 2, the release of attempt 1 is its first counted outcome.
        $first = $this->uuid();
        $this->observe('push', $first, ['jobId' => 10]);
        $this->observe('reserve', $first, ['jobId' => 10, 'attempt' => 1]);
        $this->observe('reserve', $first, ['jobId' => 10, 'attempt' => 2]);

        $outcome = $this->observe('release', $first, ['jobId' => 10, 'attempt' => 1, 'newJobId' => 11, 'delay' => 0]);
        self::assertSame('accounted', $outcome->rule);
        self::assertFalse($outcome->applied, 'the release is not a transition, only an outcome');

        $row = $this->rowOrFail($first);
        self::assertSame('reserved', $row->status);
        self::assertSame(2, (int) $row->attempts);
        self::assertSame(0, (int) $row->releases);
        self::assertSame(1, (int) $row->observed_attempts);
        self::assertSame(0, (int) $row->late_outcomes);
        self::assertSame(0, (int) $row->observed_gap, 'attempt 1 has its outcome, attempt 2 is running');
    }
}
