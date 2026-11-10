<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Reconciliation;

use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Reconciliation\Jobs\HeldJob;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * Reconciliation against an in-flight completion (an ordering
 * race): the tick runs in a worker process that holds at
 * `reconcile.after_absence_check`, after it read that the row's job is
 * absent. A queue worker then reserves the replacement job and holds inside
 * it, and completes it after the tick ended.
 */
final class ReconciliationRaceTest extends ReconciliationTestCase
{
    private const RUNNING = 'reconcile_race.job_running';

    /**
     * Rejects: U1 without the unchanged `job_id` and `cycle` predicate.
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_reconciliation_against_an_in_flight_completion(): void
    {
        $this->needsSharedDatabase();

        [$uuid, $first] = $this->pushed('database', new HeldJob(self::RUNNING));
        self::assertNotNull($this->queue()->pop(), 'a worker reserved the job');

        // Its release was never observed: the replacement row waits in the
        // queue, and the first absence of the old id is past the grace.
        $row = (array) $this->queueTable()->where('id', $first)->first();
        unset($row['id']);
        $replacement = (int) $this->queueTable()->insertGetId(['reserved_at' => null, 'available_at' => time() - 1] + $row);
        $this->queueTable()->where('id', $first)->delete();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['absent_since' => Clock::toDecimal(microtime(true) - 3600)]);

        $tick = WorkerProcess::start(['orrery:tick'], $this->testEnvironment(), $this->holds()->holdEnvironment(Reconciliation::AFTER_ABSENCE_CHECK));
        $this->awaitHold($tick, Reconciliation::AFTER_ABSENCE_CHECK);
        self::assertSame(['reserved', $first], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->job_id], 'the tick read the absence of the old id');

        // A worker reserves the replacement and runs it.
        $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--tries=5'], $this->testEnvironment(), $this->holds()->holdEnvironment(self::RUNNING));
        $this->awaitHold($worker, self::RUNNING);
        $row = $this->rowOrFail($uuid);
        self::assertSame(['reserved', $replacement], [$row->status, (int) $row->job_id], 'the replacement reservation moved the row (R3)');

        $this->release(Reconciliation::AFTER_ABSENCE_CHECK);
        self::assertSame(0, $tick->waitForExit(60), $tick->describe());

        $row = $this->rowOrFail($uuid);
        self::assertSame(['reserved', $replacement, null, null], [$row->status, (int) $row->job_id, $row->unknown_since, $row->unknown_reason], 'the running job is never marked unknown');

        $this->release(self::RUNNING);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $row = $this->rowOrFail($uuid);
        self::assertSame(['completed', $replacement, null], [$row->status, (int) $row->job_id, $row->unknown_since]);
    }
}
