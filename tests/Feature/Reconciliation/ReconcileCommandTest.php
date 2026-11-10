<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Reconciliation;

use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Runtime\Hooks;

/**
 * The exit status of orrery:reconcile when a read of the application's
 * tables fails: the command names what it did not check and
 * exits 1, and a failing batch runs once, not until the batch limit.
 */
final class ReconcileCommandTest extends ReconciliationTestCase
{
    /**
     * Rejects: a sweep loop that takes a stored cursor as progress, so a
     * failing read of the queue table repeats up to the batch limit, and a
     * command that prints success and exits 0 when it checked nothing.
     * Would still pass: a command that stops the sweep but keeps the rows of
     * the other connections unchecked (one connection here).
     */
    public function test_a_failed_read_of_the_queue_table_stops_the_sweep_and_exits_one(): void
    {
        [$uuid] = $this->pushed();
        // A sweep in progress: its cursor is stored.
        $this->mirror()->table('orrery_settings')->insert([
            'key' => Reconciliation::CURSOR_PREFIX.'database',
            'value' => json_encode(['after' => 0, 'started_at' => self::now()]),
        ]);
        $this->app['config']->set('queue.connections.database.table', 'm1_missing_jobs');
        $reads = 0;
        Hooks::$callback = static function (string $point) use (&$reads): void {
            if ($point === Reconciliation::BEFORE_ABSENCE_CHECK) {
                $reads++;
            }
        };

        $status = Artisan::call('orrery:reconcile');
        $output = Artisan::output();

        self::assertSame(1, $status, $output);
        self::assertSame(1, $reads, 'The failing batch runs once.');
        self::assertStringContainsString('[database]', $output);
        self::assertStringNotContainsString('Reconciled the waiting and reserved jobs of [database]', $output);
        self::assertSame('pending', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a command that prints "Reconciled the failed jobs." and
     * exits 0 after the read of the failed-job records failed. Would still
     * pass: a command that reports the failure but also retried the read
     * (one batch here).
     */
    public function test_a_failed_read_of_the_failed_records_exits_one(): void
    {
        [$uuid] = $this->failedJob();
        Hooks::$callback = static function (string $point): void {
            if ($point === Reconciliation::BEFORE_ABSENCE_CHECK) {
                throw new \RuntimeException('The failed-job table cannot be read (test).');
            }
        };

        $status = Artisan::call('orrery:reconcile');
        $output = Artisan::output();

        self::assertSame(1, $status, $output);
        self::assertStringNotContainsString('Reconciled the failed jobs.', $output);
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a command that exits 1 when every read succeeded.
     */
    public function test_a_clean_run_exits_zero(): void
    {
        $this->failedJob();
        $this->pushed();

        $status = Artisan::call('orrery:reconcile');
        $output = Artisan::output();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Reconciled the failed jobs.', $output);
    }
}
