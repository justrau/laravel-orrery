<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Listeners\ObserveJobFailed;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Feature\Lifecycle\Jobs\ScriptedJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cycle summaries: written once when the next cycle starts,
 * late evidence by cycle_gen, frozen worker values and compaction.
 */
final class CycleSummaryTest extends CycleTestCase
{
    /**
     * Rejects: a summary written at the failure.
     */
    public function test_one_attempt_failure_has_one_exception_on_the_row_and_in_the_frozen_summary(): void
    {
        $queue = $this->queue();
        Queue::push(new FailingJob);
        $pushed = $this->onlyJobsRow();
        $uuid = self::uuidOf($pushed['payload']);
        $this->workOnce();

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status);
        self::assertSame(1, (int) $row->exceptions, 'JobFailed and JobExceptionOccurred both finished');
        self::assertCount(0, $this->summaries($uuid), 'no summary exists before the next cycle');

        $this->nativeRetry();
        self::assertCount(0, $this->summaries($uuid), 'the republish does not start a cycle');
        self::assertSame('failed', $this->rowOrFail($uuid)->status);

        self::assertNotNull($queue->pop(), 'the first reservation of the native retry');

        $summaries = $this->summaries($uuid);
        self::assertCount(1, $summaries, 'exactly one summary, written when cycle 2 started');
        self::assertSame(1, (int) $summaries[0]['cycle']);
        self::assertSame('failed', $summaries[0]['outcome']);
        self::assertSame(1, (int) $summaries[0]['exceptions']);
        self::assertSame($pushed['data']['orreryGen'], $summaries[0]['cycle_gen']);
        self::assertSame((int) $row->occurrence_id, (int) $summaries[0]['occurrence_id']);
        self::assertSame(\RuntimeException::class, $summaries[0]['exception_class']);
        self::assertSame('push', $summaries[0]['started_source']);

        $row = $this->rowOrFail($uuid);
        self::assertSame(2, (int) $row->cycle);
        self::assertSame('reserved', $row->status);
        self::assertSame(0, (int) $row->exceptions, 'the counters of cycle 2 start at 0');
    }

    /**
     * Rejects: late evidence applied to the current row.
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_late_exception_after_the_next_cycle_updates_the_frozen_summary(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $queue = $this->queue();
        Queue::push(new FailingJob);
        $pushed = $this->onlyJobsRow();
        $uuid = self::uuidOf($pushed['payload']);

        $barrier = Barrier::forTest();
        $worker = WorkerProcess::start(
            ['queue:work', 'database', '--once', '--sleep=0', '--tries=1'],
            $this->testEnvironment(),
            $barrier->holdEnvironment(ObserveJobFailed::AFTER),
        );
        $worker->waitUntil(static fn (): bool => ! $worker->isRunning() || $barrier->arrived(ObserveJobFailed::AFTER.'.reached'), 60, 'it observed the failure');
        self::assertTrue($barrier->arrived(ObserveJobFailed::AFTER.'.reached'), $worker->describe());

        $failed = $this->rowOrFail($uuid);
        self::assertSame('failed', $failed->status);
        self::assertSame(0, (int) $failed->exceptions, 'the worker holds before JobExceptionOccurred');

        // Another process starts the next cycle: a native republish and its first reservation.
        $queue->pushRaw((string) $failed->payload, 'default');
        self::assertNotNull($queue->pop());

        $current = $this->snapshot($uuid);
        self::assertSame(2, (int) $current['cycle']);
        $summaries = $this->summaries($uuid);
        self::assertCount(1, $summaries);
        self::assertSame(0, (int) $summaries[0]['exceptions']);

        $barrier->arrive(ObserveJobFailed::AFTER.'.release');
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $summaries = $this->summaries($uuid);
        self::assertSame(1, (int) $summaries[0]['exceptions'], 'the late exception updates the frozen summary of cycle 1');
        self::assertSame(1, (int) $summaries[0]['last_exception_attempt']);
        self::assertSame($pushed['data']['orreryGen'], $summaries[0]['cycle_gen']);
        // Laravel logs the failed job only after the package listener returns, so
        // the package's provider binds the failed occurrence's record to the row,
        // which keeps that occurrence across R5. Only those
        // two columns may change; every lifecycle column of the current cycle
        // stays as it was.
        $provider = array_flip(['provider_id', 'provider_target']);
        $after = $this->snapshot($uuid);
        self::assertSame(array_diff_key($current, $provider), array_diff_key($after, $provider), 'the current row is unchanged');
        self::assertSame($current['occurrence_id'], $after['occurrence_id']);
        self::assertSame(1, $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('dedupe_key', 'exception:1:1')->count());
    }

    /**
     * Rejects: frozen values from a heartbeat.
     *
     * @param  list<string>  $command
     */
    #[Group('process')]
    #[Group('shared-database')]
    #[DataProvider('workerCommands')]
    public function test_frozen_summary_keeps_the_failing_attempt_worker_values(array $command, int $tries, int $timeout): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $queue = $this->queue();
        Queue::push(new ScriptedJob);
        $pushed = $this->onlyJobsRow();
        self::assertNull($pushed['data']['maxTries'] ?? null, 'the payload has no tries');
        self::assertNull($pushed['data']['timeout'] ?? null, 'the payload has no timeout');
        $uuid = self::uuidOf($pushed['payload']);

        $worker = WorkerProcess::start($command, $this->testEnvironment());
        $worker->waitUntil(fn (): bool => ! $worker->isRunning() || DB::table('failed_jobs')->count() === 1, 60, 'it failed the job and logged the failure');
        $worker->stop();

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status, $worker->describe());
        self::assertSame($tries, (int) $row->worker_tries);
        self::assertSame($timeout, (int) $row->worker_timeout);

        // A later heartbeat of another worker must not change the frozen values.
        $this->mirror()->table('orrery_worker_heartbeats')->update(['options' => json_encode(['tries' => 99, 'timeout' => 99])]);

        $this->nativeRetry();
        self::assertNotNull($queue->pop());

        $summary = $this->summaries($uuid)[0] ?? null;
        self::assertNotNull($summary, 'the first reservation of the retry froze cycle 1');
        self::assertNull($summary['max_tries']);
        self::assertSame($tries, (int) $summary['effective_tries']);
        self::assertSame('worker', $summary['effective_tries_source']);
        self::assertSame($timeout, (int) $summary['effective_timeout']);
        self::assertSame('worker', $summary['effective_timeout_source']);
    }

    /**
     * @return array<string, array{0: list<string>, 1: int, 2: int}>
     */
    public static function workerCommands(): array
    {
        return [
            'queue:work --once' => [['queue:work', 'database', '--once', '--sleep=0', '--tries=4', '--timeout=20'], 4, 20],
            // Laravel's Listener passes --tries to its queue:work --once child, but not --timeout.
            'a queue:listen child' => [['queue:listen', 'database', '--tries=5', '--timeout=33', '--sleep=0'], 5, 60],
        ];
    }

    /**
     * Rejects: no compaction, or a second merged row.
     */
    public function test_compaction_keeps_fifty_individual_summaries_and_one_merged_row(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 1], ['orreryGen' => self::generation(1)]);

        for ($cycle = 1; $cycle <= 53; $cycle++) {
            $payload = ['orreryGen' => self::generation($cycle)];
            $outcome = $this->observe('reserve', $uuid, ['jobId' => $cycle, 'attempt' => 1], $payload);
            self::assertSame($cycle === 1 ? 'reserve' : 'nativeRetry', $outcome->rule, "the reservation of cycle {$cycle}");
            $this->observe('fail', $uuid, ['jobId' => $cycle, 'attempt' => 1, 'exception' => new \RuntimeException("cycle {$cycle}")], $payload);

            $merged = $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->where('merged', true)->count();
            self::assertLessThanOrEqual(1, $merged, "one merged row at most after cycle {$cycle}");
            self::assertLessThanOrEqual(CycleSummaries::INDIVIDUAL, $this->individualSummaries($uuid), "at most 50 individual summaries after cycle {$cycle}");
        }

        $row = $this->rowOrFail($uuid);
        self::assertSame(53, (int) $row->cycle);
        self::assertSame(52, (int) $row->retried_count);
        self::assertSame(50, $this->individualSummaries($uuid));

        $merged = $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->where('merged', true)->first();
        self::assertNotNull($merged);
        self::assertSame(1, (int) $merged->first_cycle);
        self::assertSame(2, (int) $merged->cycle, 'the last merged cycle');
        self::assertSame(2, (int) $merged->merged_cycles);
        self::assertSame(1, (int) $merged->merged_retries, 'cycle 2 was a retry, cycle 1 the push');
        self::assertSame(2, (int) $merged->attempts, 'summed counters');
        self::assertNull($merged->outcome, 'the merged row has no outcome');
        self::assertNull($merged->cycle_gen);

        $retained = $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->where('merged', false)->where('cycle', '>', 1)->count();
        self::assertSame((int) $row->retried_count, (int) $merged->merged_retries + $retained + 1, 'retried_count = the folded retries + the retained retries + the current cycle');
        self::assertSame(range(3, 52), $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->where('merged', false)->orderBy('cycle')->pluck('cycle')->map(static fn (mixed $c): int => (int) $c)->all());
    }

    private static function generation(int $cycle): string
    {
        return str_pad(dechex($cycle), 32, '0', STR_PAD_LEFT);
    }
}
