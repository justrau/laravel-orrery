<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Reconciliation;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Console\ReconcileCommand;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFiles;

/**
 * The scans of the queue table for a native retry: each one
 * reads the whole queue table, so one tick runs a bounded number of them,
 * checks the budget before each one, and a failed scan does not hold the
 * cursor at its row.
 */
final class NativeRetryScanTest extends ReconciliationTestCase
{
    /**
     * Rejects: a tick that scans the queue table once for every due row of
     * its batches (no cap), and a cap that loses the position of the rows
     * that it left. Would still pass: a cap whose value differs from the
     * production one (the seam sets it).
     */
    public function test_a_tick_runs_at_most_its_scans_and_the_next_tick_resumes(): void
    {
        $uuids = $this->retriedFailures(5);
        Reconciliation::useNativeRetryScansPerTickForTesting(2);

        self::assertSame(array_slice($uuids, 0, 2), $this->scansOf(fn () => $this->runFailed()));
        self::assertSame(array_slice($uuids, 2, 2), $this->scansOf(fn () => $this->runFailed()));
        self::assertSame(array_slice($uuids, 4, 1), $this->scansOf(fn () => $this->runFailed()));

        foreach ($uuids as $uuid) {
            self::assertSame('failed', $this->rowOrFail($uuid)->status, 'A queued native retry keeps its row.');
        }
    }

    /**
     * Rejects: a scan before which the budget is not checked, so a tick
     * past its deadline still scans the queue table for every due row.
     * Would still pass: a check that runs after each scan instead of before
     * it, when only the first scan is late (here no scan may run).
     */
    public function test_an_exhausted_budget_runs_no_scan(): void
    {
        $this->retriedFailures(3);

        $scans = $this->scansOf(fn () => $this->reconciliation()->reconcileFailed(new TickBudget(Clock::monotonic() - 1)));

        self::assertSame([], $scans);
    }

    /**
     * Rejects: scans without a time share of their own: with the tick's 30 s left, scans that
     * take the share's time stop the next scans, so `operations`, the retentions and the alert phases after
     * this phase still run. The next pass resumes at the first row not scanned. Would still pass: a share
     * whose length differs from the production one by less than the time this test moves the clock.
     */
    public function test_the_scans_stop_at_their_time_share(): void
    {
        $uuids = $this->retriedFailures(3);
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'lower(payload) like')) {
                // Each scan reads a large queue table.
                Clock::advanceMonotonicForTesting(Reconciliation::NATIVE_RETRY_SECONDS);
            }
        });

        self::assertSame(array_slice($uuids, 0, 1), $this->scansOf(fn () => $this->reconciliation()->reconcileFailed(new TickBudget(Clock::monotonic() + 30))));
        self::assertSame(array_slice($uuids, 1, 1), $this->scansOf(fn () => $this->reconciliation()->reconcileFailed(new TickBudget(Clock::monotonic() + 30))), 'the next pass resumes at the first row not scanned');
    }

    /**
     * Rejects: a scan of the whole queue table without the row's queue: Laravel's queue:retry
     * pushes the job onto its recorded queue, so only that queue's jobs are read (Laravel's `jobs` table has
     * an index on `queue`). A job with the uuid on another queue is not the native retry, and the row is
     * removed. Would still pass: a scan that compares the queue with the database collation (case-folding on
     * MySQL) instead of byte-exact.
     */
    public function test_the_scan_reads_only_the_rows_queue(): void
    {
        [$uuid] = $this->retriedFailures(1);
        $this->queueTable()->update(['queue' => 'elsewhere']);

        $this->runFailed();

        self::assertNull($this->row($uuid), 'a job of another queue kept the row');
    }

    /**
     * Rejects: a failed scan that leaves the cursor before its row, so the
     * same scan fails first on every tick and the rows after it are never
     * scanned. Would still pass: a skip that also deletes the row (the
     * existing test_failed_native_retry_check_keeps_the_row rejects that).
     */
    public function test_a_failed_scan_moves_the_cursor_past_its_row(): void
    {
        [$first, $second] = $this->retriedFailures(2);
        $table = $this->app['config']->get('queue.connections.database.table');
        $this->app['config']->set('queue.connections.database.table', 'm2_missing_jobs');

        try {
            $this->runFailed();
        } finally {
            $this->app['config']->set('queue.connections.database.table', $table);
        }

        self::assertSame('failed', $this->rowOrFail($first)->status, 'A failed scan proves nothing.');
        self::assertSame([$second], $this->scansOf(fn () => $this->runFailed()), 'The next tick starts after the row of the failed scan.');
    }

    /**
     * D18 refuses the read of the queue's file after the presence read (the
     * HostFiles seam answers "no automatic reads" from then on). Each row
     * that needs a scan stays as it is, and the pass goes on with the rows
     * after it.
     *
     * Rejects: a refusal that ends the pass at its row as if the read had
     * failed (the cursor would stay at the first row and the pass would
     * report a failure), and a refusal that deletes the row. Would still
     * pass: a refusal that still counts against the scan cap (two rows
     * here, the cap is not reached).
     */
    public function test_a_d18_refusal_leaves_the_row_and_the_pass_goes_on(): void
    {
        [$first, $second] = $this->retriedFailures(2);
        $refuse = false;
        $this->onHook(static function (string $point) use (&$refuse): void {
            if ($point === Reconciliation::AFTER_ABSENCE_CHECK) {
                $refuse = true;
            }
        });
        HostFiles::useForTesting(null, static function (string $connection) use (&$refuse): ?bool {
            return $refuse ? false : null;
        });

        $checked = null;
        $scans = $this->scansOf(function () use (&$checked): void {
            $checked = $this->reconciliation()->reconcileFailed(new TickBudget(Clock::monotonic() + 30));
        });

        self::assertSame([], $scans, 'No queue table was read.');
        self::assertTrue($checked, 'A refusal is no failed read.');
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', Reconciliation::FAILED_CURSOR)->value('value'), 'The pass reached the end of the rows.');
        self::assertSame(['failed', 'failed'], [$this->rowOrFail($first)->status, $this->rowOrFail($second)->status], 'Both rows stay.');
    }

    /**
     * orrery:reconcile runs passes of the failed rows until the pass ends or
     * its time share does: the scan cap of one pass
     * never makes the command stop early.
     *
     * Rejects: a command that runs one pass and prints "Reconciled the
     * failed jobs." with rows left behind the cursor. Would still pass: a
     * command that loops past its time share (the next test bounds it).
     */
    public function test_the_command_goes_on_past_the_scan_cap(): void
    {
        $uuids = $this->retriedFailures(3);
        Reconciliation::useNativeRetryScansPerTickForTesting(1);

        $status = null;
        $scans = $this->scansOf(function () use (&$status): void {
            $status = Artisan::call('orrery:reconcile');
        });
        $output = Artisan::output();

        self::assertSame(0, $status, $output);
        self::assertSame($uuids, $scans, 'Every row was scanned once.');
        self::assertStringContainsString('Reconciled the failed jobs.', $output);
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', Reconciliation::FAILED_CURSOR)->value('value'));
    }

    /**
     * Rejects: a command that prints "Reconciled the failed jobs." when its
     * time share ended with rows left, and one that exits non-zero for it.
     * Would still pass: another wording of the hint that names the command.
     */
    public function test_the_command_says_when_it_stopped_early(): void
    {
        $this->retriedFailures(2);
        $this->onHook(static function (string $point): void {
            if ($point === Reconciliation::AFTER_ABSENCE_CHECK) {
                // The presence read used up the time share of the command.
                Clock::advanceMonotonicForTesting(ReconcileCommand::FAILED_SECONDS + 1);
            }
        });

        $status = Artisan::call('orrery:reconcile');
        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

        self::assertSame(0, $status, $output);
        self::assertStringNotContainsString('Reconciled the failed jobs.', $output);
        self::assertStringContainsString('run orrery:reconcile again to continue', $output);
        self::assertNotNull($this->mirror()->table('orrery_settings')->where('key', Reconciliation::FAILED_CURSOR)->value('value'));
    }

    /**
     * Failed rows whose native retry is queued, their records absent past
     * the grace: the next check of each one scans the queue table.
     *
     * @return list<string> the uuids in mirror id order
     */
    private function retriedFailures(int $count): array
    {
        $uuids = [];

        for ($i = 0; $i < $count; $i++) {
            $uuids[] = $this->failedJob()[0];
        }

        DB::table('failed_jobs')->delete();
        $this->queueTable()->delete();

        foreach ($uuids as $uuid) {
            $row = $this->rowOrFail($uuid);
            $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['retry_requested_at' => Clock::toDecimal((float) $row->failed_at + 1)]);
            $this->queueTable()->insert(['queue' => 'default', 'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\Retried', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]), 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        }

        // The first absence; the next check is past the grace.
        self::assertSame([], $this->scansOf(fn () => $this->runFailed()));
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);

        return $uuids;
    }

    /**
     * The uuids whose queue payloads $work scanned, in order.
     *
     * @return list<string>
     */
    private function scansOf(\Closure $work): array
    {
        $scans = [];
        $listening = true;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$scans, &$listening): void {
            if ($listening && str_contains($query->sql, 'lower(payload) like')) {
                $scans[] = trim((string) $query->bindings[0], '%');
            }
        });

        try {
            $work();
        } finally {
            $listening = false;
        }

        return $scans;
    }
}
