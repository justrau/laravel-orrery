<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\Feature\Hooks\HooksTestCase;
use Justrau\Orrery\Tests\Feature\Monitoring\Jobs\LateFinisherJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * "Outcome not observed" with real worker processes:
 * a worker killed at a barrier leaves an attempt
 * without an outcome, so the signal shows; a worker without a timeout
 * alarm (as without PCNTL) that finishes after another worker reclaimed
 * the job closes the gap with its late outcome, so the signal goes away.
 *
 * Both need a database that the worker and this process share.
 */
#[Group('process')]
#[Group('shared-database')]
final class StuckProcessTest extends HooksTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        Orrery::auth(static fn (): bool => true);
    }

    /**
     * Rejects: a gap that is not counted for an attempt whose worker was
     * killed (the signal would never show), and a signal that reads
     * something else than the stored gap (its facts).
     */
    public function test_a_worker_killed_at_a_barrier_gives_the_gap(): void
    {
        $this->queue();
        Queue::push(new LateFinisherJob);
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);

        $barrier = Barrier::forTest();
        $worker = WorkerProcess::start(
            ['queue:work', 'database', '--once', '--sleep=0', '--tries=5'],
            $this->testEnvironment(),
            $barrier->holdEnvironment(LateFinisherJob::POINT),
        );
        $worker->waitUntil(static fn (): bool => ! $worker->isRunning() || $barrier->arrived(LateFinisherJob::POINT.'.reached'), 60, 'the worker runs attempt 1');
        self::assertTrue($barrier->arrived(LateFinisherJob::POINT.'.reached'), $worker->describe());
        self::assertSame('reserved', $this->rowOrFail($uuid)->status);
        self::assertSame(1, (int) $this->rowOrFail($uuid)->attempts);

        // The worker dies with the job reserved: no outcome of attempt 1 is ever observed.
        $worker->stop(0.0);
        self::assertFalse($worker->isRunning());
        self::assertSame([], $this->stuckIds('outcome_not_observed'), 'a running attempt is not a gap');

        // The reservation expires, and this process reclaims the job as attempt 2, which releases it.
        DB::table('jobs')->update(['reserved_at' => time() - 3600]);
        $this->work();

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status);
        self::assertSame(2, (int) $row->attempts);
        self::assertSame(1, (int) $row->observed_gap);
        self::assertSame([$uuid], $this->stuckIds('outcome_not_observed'));
        self::assertContains(['signal' => 'outcome_not_observed', 'facts' => ['gap' => 1]], $this->detailSignals($uuid));
    }

    /**
     * Rejects: a late completion of an older attempt that is not counted
     * (the gap and the signal would stay although every attempt has an
     * outcome), and a late completion that moves the row to `completed`
     * while its newer attempt is pending.
     */
    public function test_a_late_finisher_without_a_timeout_alarm_closes_the_gap(): void
    {
        $this->queue();
        Queue::push(new LateFinisherJob);
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);

        $barrier = Barrier::forTest();
        $worker = WorkerProcess::start(
            ['queue:work', 'database', '--once', '--sleep=0', '--tries=5', '--timeout=0'],
            $this->testEnvironment(),
            $barrier->holdEnvironment(LateFinisherJob::POINT),
        );
        $worker->waitUntil(static fn (): bool => ! $worker->isRunning() || $barrier->arrived(LateFinisherJob::POINT.'.reached'), 60, 'the worker runs attempt 1');
        self::assertTrue($barrier->arrived(LateFinisherJob::POINT.'.reached'), $worker->describe());

        // Attempt 1 runs past its lease; this process reclaims the job as attempt 2, which releases it.
        DB::table('jobs')->update(['reserved_at' => time() - 3600]);
        $this->work();

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status);
        self::assertSame(2, (int) $row->attempts);
        self::assertSame(1, (int) $row->observed_gap, 'attempt 1 has no outcome so far');
        self::assertSame([$uuid], $this->stuckIds('outcome_not_observed'));

        // Attempt 1 finishes late.
        $barrier->arrive(LateFinisherJob::POINT.'.release');
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'the late completion does not finish the pending attempt');
        self::assertSame(1, (int) $row->late_outcomes);
        self::assertSame(0, (int) $row->observed_gap);
        self::assertSame([], $this->stuckIds('outcome_not_observed'));
        self::assertNotContains('outcome_not_observed', array_column($this->detailSignals($uuid), 'signal'));
    }

    /**
     * @return list<string>
     */
    private function stuckIds(string $signal): array
    {
        return array_column($this->actingAs(new User)->getJson('/orrery/api/jobs/stuck?signal='.$signal)->assertOk()->json('jobs'), 'id');
    }

    /**
     * @return list<array{signal: string, facts: array<string, mixed>}>
     */
    private function detailSignals(string $uuid): array
    {
        return $this->actingAs(new User)->getJson('/orrery/api/jobs/'.$uuid)->assertOk()->json('signals');
    }
}
