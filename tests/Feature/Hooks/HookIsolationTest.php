<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Tests\Feature\Hooks\Jobs\EffectJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\MirrorOutage;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * Isolation of the hooks and the new listeners;
 * they replace the probes WorkerIsolationProbeTest,
 * LongRunningJobProbeTest and TransactionControlProbeTest). Each scenario
 * compares its host outcome with HostOutcomeControlTest's fixed outcome.
 * The worker runs are real queue:work processes on a shared database.
 */
#[Group('process')]
#[FreshSchema]
final class HookIsolationTest extends HooksTestCase
{
    use HostOutcomeScenarios;

    private const WORK = ['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHostTables();
        $this->queue();
    }

    /**
     * Rejects: a push hook that throws to the caller.
     */
    public function test_push_during_an_outage_does_not_throw_and_the_business_transaction_commits(): void
    {
        $outage = $this->mirrorOutage();

        try {
            self::assertSame(self::PUSH_OUTCOME, $this->pushInBusinessTransaction());
        } finally {
            $outage->end();
        }

        self::assertSame(0, $this->mirrorCount(), 'The observation was dropped during the outage.');
        self::assertGreaterThanOrEqual(1, self::dropped());
    }

    /**
     * Rejects: a mirror error in pop() that fails the job.
     */
    #[Group('shared-database')]
    public function test_reserve_during_an_outage_does_not_burn_an_attempt(): void
    {
        $this->pushReserveScenario();
        $this->workDuringAnOutage();

        self::assertSame(self::RESERVE_OUTCOME, $this->workOutcome(), 'The job ran once and was not failed.');
    }

    /**
     * Rejects: a listener error that changes the host outcome.
     */
    #[Group('shared-database')]
    public function test_release_completion_and_failure_during_an_outage_keep_the_host_outcome(): void
    {
        $this->pushWorkScenario();
        $this->workDuringAnOutage();

        self::assertSame(self::WORK_OUTCOME, $this->workOutcome());
    }

    /**
     * Rejects: a stale state without a health window.
     */
    #[Group('shared-database')]
    public function test_after_recovery_the_state_is_true_or_unknown_with_a_health_window(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $outage = $this->mirrorOutage();

        try {
            Queue::connection('database')->push(new EffectJob('late'));
        } finally {
            $outage->end();
        }

        self::assertSame(0, $this->mirrorCount(), 'The push was dropped during the outage.');
        Degradation::flush();

        $windows = $this->mirror()->table('orrery_health_events')->where('process', 'like', '%:'.getmypid().':%')->get();
        self::assertCount(1, $windows, 'The outage has its health window.');
        self::assertGreaterThanOrEqual(1, (int) $windows[0]->dropped);

        $worker = WorkerProcess::start(self::WORK, $this->testEnvironment());
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $rows = $this->mirror()->table('orrery_jobs')->get();
        self::assertCount(1, $rows, 'The reservation created the row (R7).');
        self::assertSame('completed', $rows[0]->status, 'After recovery the state is true.');
    }

    /**
     * Rejects: a watcher around the parent pop().
     */
    public function test_real_queue_errors_propagate_unchanged(): void
    {
        self::assertSame(self::POP_ERROR, $this->popWithoutTheJobsTable(), "pop() throws Laravel's own error.");
    }

    /**
     * Runs the queue with a real worker while the mirror is down, then ends
     * the outage.
     */
    private function workDuringAnOutage(): void
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        // The rename method in every mirror mode: it reaches the worker.
        $outage = MirrorOutage::begin($this->app, $env, MirrorOutage::RENAME);

        try {
            $worker = WorkerProcess::start(self::WORK, $env);
            self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        } finally {
            $outage->end();
        }
    }
}
