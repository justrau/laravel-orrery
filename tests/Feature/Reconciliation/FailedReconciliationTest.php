<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Reconciliation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\OffGridTime;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The reconciliation of failed rows with the failed-job table:
 * `reconcile_failed`. The failer is `database-uuids` on the
 * queue's connection, and queue:work logs each failure through the
 * package's CapturingUuidFailedJobProvider, so every failed row is bound to
 * its record (provider_id and provider_target). The check is by (id, uuid).
 */
final class FailedReconciliationTest extends ReconciliationTestCase
{
    /**
     * Rejects: failed rows that outlive their provider records.
     */
    public function test_native_forget_and_flush_remove_the_rows(): void
    {
        [$forgotten] = $this->failedJob($this->job(['orders']));
        [$flushedA] = $this->failedJob();
        [$flushedB] = $this->failedJob();
        self::assertSame(1, $this->jobRows($forgotten)['orrery_failed_tags'], 'the failed row has its failed tag');
        self::assertGreaterThan(0, $this->jobRows($forgotten)['orrery_job_history']);

        self::assertSame(0, Artisan::call('queue:forget', ['id' => $forgotten]));
        $this->runFailedPastGrace();

        self::assertNull($this->row($forgotten), 'queue:forget removed the record, so the row goes');
        self::assertSame(array_fill_keys(ForgetService::JOB_TABLES, 0), $this->jobRows($forgotten), 'with its tags, failed tags, history and summaries');
        self::assertSame(['failed', 'failed'], [$this->rowOrFail($flushedA)->status, $this->rowOrFail($flushedB)->status], 'the other records exist');

        self::assertSame(0, Artisan::call('queue:flush'));
        $this->runFailedPastGrace();

        self::assertNull($this->row($flushedA));
        self::assertNull($this->row($flushedB));
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->count());
    }

    /**
     * Rejects: removing a row whose native retry is still pending before its reservation.
     */
    public function test_native_retry_then_tick_before_the_reservation(): void
    {
        [$uuid, $occurrence] = $this->failedJob();

        self::assertSame(0, Artisan::call('queue:retry', ['id' => [$uuid]]));
        self::assertNull($this->providerRecord($uuid), 'queue:retry deleted the record');
        self::assertSame(1, $this->queueTable()->count(), 'and pushed the job again');

        $this->runFailedPastGrace();

        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', $occurrence, 1], [$row->status, (int) $row->occurrence_id, (int) $row->cycle], 'the retry is still in the queue: the row stays');

        // The reservation starts the next cycle (R5), and the job completes.
        $this->work();
        $this->runFailed();

        $row = $this->rowOrFail($uuid);
        self::assertSame(['completed', 2], [$row->status, (int) $row->cycle]);
        self::assertCount(1, $this->summaries($uuid), 'the failed cycle keeps its summary');
    }

    /**
     * Rejects: a failed row that stays after its native retry left the queue unobserved.
     */
    public function test_native_retry_with_a_missed_reservation_is_removed_when_its_job_is_gone(): void
    {
        [$uuid] = $this->failedJob();
        self::assertSame(0, Artisan::call('queue:retry', ['id' => [$uuid]]));

        // A worker ran the retried job while the mirror missed every observation.
        $this->queueTable()->delete();
        $this->runFailedPastGrace();

        self::assertNull($this->row($uuid), 'the record and the queued job are gone, so the row goes (§6.4, §8.2)');
    }

    /**
     * Rejects: a removal claim that is not bound to the retry state that the check read.
     */
    public function test_native_retry_during_the_check_keeps_the_row(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        // An earlier check saw no record, past the grace (for example while
        // the provider insert was not committed yet).
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['absent_since' => Clock::toDecimal(Clock::now() - Reconciliation::MIN_GRACE_SECONDS - 1)]);

        // The native retry lands after the candidate selection, before the provider read.
        $retried = 0;
        Hooks::$callback = function (string $point) use ($uuid, &$retried): void {
            if ($point === Reconciliation::BEFORE_ABSENCE_CHECK && $retried++ === 0) {
                self::assertSame(0, Artisan::call('queue:retry', ['id' => [$uuid]]));
            }
        };

        try {
            $this->runFailed();
        } finally {
            Hooks::$callback = null;
        }

        self::assertSame(1, $retried);
        self::assertNull($this->providerRecord($uuid), 'queue:retry deleted the record');
        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', $occurrence], [$row->status, (int) $row->occurrence_id], 'the guarded delete was bound to the retry state that the check read');
        self::assertNotNull($row->provider_id);
        self::assertNotNull($row->retry_requested_at);

        // The next check sees the request and the queued job.
        $this->runFailed();
        self::assertSame('failed', $this->rowOrFail($uuid)->status);

        $this->work();
        self::assertSame(['completed', 2], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->cycle]);
    }

    /**
     * Rejects: a removal at the first absence.
     */
    public function test_absent_record_is_removed_only_after_the_grace(): void
    {
        [$uuid] = $this->failedJob();
        DB::table('failed_jobs')->delete();

        $first = self::now();
        $this->runFailed();
        self::assertSame(['failed', $first], [$this->rowOrFail($uuid)->status, $this->absentSince($uuid)], 'the first absence only records its time');

        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS - 1);
        $this->runFailed();
        self::assertSame(['failed', $first], [$this->rowOrFail($uuid)->status, $this->absentSince($uuid)]);

        $this->moveClock(1);
        $this->runFailed();
        self::assertNull($this->row($uuid));
    }

    /**
     * Rejects: a grace check on absent_since as SQLite returns it: SQLite 3.45
     * on x86-64 stores 1796604698.689484 as the float above it, so the check
     * at the exact end of the grace kept the row. Other engines keep the
     * decimal; there the test checks only the boundary. The test asserts the
     * guarded delete, not its effect: the guard binds the decimal, which
     * SQLite 3.45 converts to the same neighbour as the stored value, but a
     * SQLite that converts exactly (the test's machine) does not. Still
     * passes: a check that is exact only for times before 2^31 s.
     */
    public function test_an_absence_stored_off_the_float_grid_ends_at_the_grace(): void
    {
        $start = '1796604698.689484';
        Carbon::setTestNow(Carbon::createFromFormat('U.u', $start));
        [$uuid] = $this->failedJob();
        DB::table('failed_jobs')->delete();

        $this->runFailed();
        self::assertSame($start, $this->absentSince($uuid));
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['absent_since' => OffGridTime::stored($this->mirror(), $start, 1)]);

        $deletes = [];
        $this->app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$deletes): void {
            if (preg_match('/^delete from [`"]?\w*orrery_jobs[`"]? /i', $query->sql) === 1) {
                $deletes[] = $query->bindings;
            }
        });

        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runFailed();

        self::assertCount(1, $deletes, 'the removal runs at the exact end of the grace');
        self::assertContains($start, $deletes[0], 'guarded by the absence that the check read');
    }

    /**
     * Rejects: an absence check of a record whose insert is not committed:
     * a failure logged inside an application transaction on the failed-record
     * connection binds nothing before the commit (MySQL: the binding waits
     * for the commit; Postgres: nothing is bound inside a host transaction),
     * so reconciliation never checks the row; after the commit a
     * MySQL row is bound and its record present, a Postgres row stays
     * unbound and is never checked.
     */
    public function test_uncommitted_provider_record_is_not_absent(): void
    {
        [$application, $uuid] = $this->failInsideAnApplicationTransaction();

        try {
            $this->holdThroughTheGrace($uuid);
            $application->commit();
        } finally {
            if ($application->transactionLevel() > 0) {
                $application->rollBack();
            }
        }

        $id = (int) DB::connection('testbench_app')->table('failed_jobs')->where('uuid', $uuid)->value('id');
        self::assertGreaterThan(0, $id);
        $bound = $this->rowOrFail($uuid)->provider_id;
        self::assertSame($this->testEnvironment()->driver === 'mysql' ? $id : null, $bound === null ? null : (int) $bound, 'MySQL binds at the commit; Postgres binds nothing inside a host transaction');

        $this->runFailedPastGrace();
        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', null], [$row->status, $row->absent_since], 'the committed record is present, or the unbound row is not checked');
        self::assertGreaterThan(0, $this->jobRows($uuid)['orrery_job_history'], 'the history stays');
    }

    /**
     * Rejects: a rolled-back failed-record insert that becomes bound, or
     * that absence removes: the row stays unbound and is never checked.
     */
    public function test_rolled_back_provider_record_is_never_present(): void
    {
        [$application, $uuid] = $this->failInsideAnApplicationTransaction();

        try {
            $this->holdThroughTheGrace($uuid);
        } finally {
            $application->rollBack();
        }

        self::assertNull($this->rowOrFail($uuid)->provider_id, 'no committed record: nothing is bound');

        $this->runFailedPastGrace();
        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', null, null], [$row->status, $row->provider_id, $row->absent_since], 'an unbound row is never checked');
    }

    /**
     * A job fails inside an application transaction on the failed-record
     * connection, which stays open. Two sessions: the package must not see
     * the open transaction (a SQLite file locks the whole database, so the
     * queue could not run meanwhile).
     *
     * @return array{0: \Illuminate\Database\Connection, 1: string, 2: int}
     */
    private function failInsideAnApplicationTransaction(): array
    {
        $this->skipUnlessEngine('mysql', 'pgsql');

        $this->copyConnection('testbench_app');
        $this->useFailer('database-uuids', 'failed_jobs', 'testbench_app');
        /** @var \Illuminate\Database\Connection $application */
        $application = DB::connection('testbench_app');
        $application->beginTransaction();

        try {
            [$uuid, $occurrence] = $this->failedJob(bound: false);
        } catch (\Throwable $error) {
            $application->rollBack();

            throw $error;
        }

        return [$application, $uuid, $occurrence];
    }

    /**
     * While the insert stays uncommitted: two checks, the second after the
     * full grace, keep the row and its dependents.
     */
    private function holdThroughTheGrace(string $uuid): void
    {
        self::assertNull($this->providerRecord($uuid), 'the failed record is not committed');
        $rows = $this->jobRows($uuid);
        $before = (array) $this->rowOrFail($uuid);

        $this->runFailedPastGrace();

        self::assertEquals($before, (array) $this->rowOrFail($uuid), 'the row is unchanged after the full grace');
        self::assertSame($rows, $this->jobRows($uuid), 'and so are its dependents');
        self::assertNull($before['provider_id'], 'nothing is bound before the commit');
    }

    /**
     * Rejects: a native retry check that matches only `"uuid":"<lower-case uuid>"`, or any text match.
     *
     * @param  \Closure(string): string  $payload
     */
    #[DataProvider('queuedPayloads')]
    public function test_native_retry_check_uses_the_payload_identity(\Closure $payload, bool $kept): void
    {
        [$uuid] = $this->failedJob();
        $row = $this->rowOrFail($uuid);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['retry_requested_at' => Clock::toDecimal((float) $row->failed_at + 1)]);
        DB::table('failed_jobs')->delete();
        $this->queueTable()->delete();
        $this->queueTable()->insert(['queue' => 'default', 'payload' => $payload($uuid), 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);

        $this->runFailedPastGrace();

        self::assertSame($kept, $this->row($uuid) !== null);
    }

    /**
     * Rejects: a native retry check whose failed read counts as "no job queued".
     */
    public function test_failed_native_retry_check_keeps_the_row(): void
    {
        [$uuid] = $this->failedJob();
        $row = $this->rowOrFail($uuid);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['retry_requested_at' => Clock::toDecimal((float) $row->failed_at + 1)]);
        DB::table('failed_jobs')->delete();
        $this->runFailed();

        // The queue table cannot be read at the check past the grace.
        $table = $this->app['config']->get('queue.connections.database.table');
        $this->app['config']->set('queue.connections.database.table', 'b20_missing_jobs');
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);

        try {
            $this->runFailed();
        } finally {
            $this->app['config']->set('queue.connections.database.table', $table);
        }

        self::assertSame('failed', $this->rowOrFail($uuid)->status, 'a read that failed proves nothing');
        self::assertArrayHasKey(Reconciliation::HEALTH_NOT_CHECKED, $this->healthErrors());
    }

    /**
     * @return array<string, array{0: \Closure(string): string, 1: bool}>
     */
    public static function queuedPayloads(): array
    {
        $job = ['displayName' => 'App\\Jobs\\Legacy', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []];

        return [
            'legacy id, lower case' => [static fn (string $uuid): string => json_encode(['id' => $uuid] + $job), true],
            'legacy id, upper case' => [static fn (string $uuid): string => json_encode(['id' => strtoupper($uuid)] + $job), true],
            'uuid, upper case' => [static fn (string $uuid): string => json_encode(['uuid' => strtoupper($uuid)] + $job), true],
            'uuid, with spaces' => [static fn (string $uuid): string => json_encode(['uuid' => $uuid] + $job, JSON_PRETTY_PRINT), true],
            'undecodable' => [static fn (string $uuid): string => '{"uuid":"'.$uuid.'"', true],
            'only inside another object' => [static fn (string $uuid): string => json_encode(['uuid' => '0b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'data' => ['uuid' => $uuid, 'id' => $uuid]] + $job), false],
        ];
    }

    /**
     * Rejects: an absence check of a row without a bound record: a row
     * whose provider_id is null is never checked (it ages out by
     * trim.failed), and neither is a row that an operation holds.
     */
    public function test_unbound_and_held_rows_are_never_removed(): void
    {
        [$unbound] = $this->failedJob();
        [$held] = $this->failedJob();
        $this->mirror()->table('orrery_jobs')->where('uuid', $unbound)->update(['provider_id' => null]);
        $this->mirror()->table('orrery_jobs')->where('uuid', $held)->update(['operation_id' => str_repeat('a', 32)]);
        DB::table('failed_jobs')->delete();

        $this->runFailed();
        $this->moveClock(3600);
        $this->runFailed();

        self::assertSame(['failed', null, null], [$this->rowOrFail($unbound)->status, $this->rowOrFail($unbound)->provider_id, $this->rowOrFail($unbound)->absent_since]);
        self::assertSame(['failed', null], [$this->rowOrFail($held)->status, $this->rowOrFail($held)->absent_since]);
    }

    /**
     * Rejects: an absence check on the read replica.
     */
    public function test_stale_read_replica_never_proves_absence(): void
    {
        $environment = $this->testEnvironment();

        if ($environment->driver !== 'sqlite' || $environment->inMemory()) {
            self::markTestSkipped('This test needs SQLite files (a stale copy as the read replica): run it through bin/test sqlite --sqlite-file.');
        }

        // The failer's connection reads from a copy taken before the failures, and writes to the live file.
        $live = (string) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.database');
        $stale = $this->sqliteSnapshot($live, 'stale-provider.sqlite');
        $this->copyConnection('testbench_split', ['read' => ['database' => $stale], 'write' => ['database' => $live]]);
        $this->useFailer('database-uuids', 'failed_jobs', 'testbench_split');
        [$uuid, $occurrence] = $this->failedJob();
        [$gone] = $this->failedJob();
        self::assertSame(0, DB::connection('testbench_split')->table('failed_jobs')->count(), 'the replica lags: it has no record');
        self::assertSame(2, $this->providerRecords(), 'the writer has both');
        DB::table('failed_jobs')->where('uuid', $gone)->delete();

        $this->runFailedPastGrace();

        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', $occurrence], [$row->status, (int) $row->occurrence_id], 'the writer has the record');
        self::assertNotNull($row->provider_id);
        self::assertSame(1, $this->mirror()->table('orrery_jobs')->count(), 'only the row whose record the writer lost goes');
    }

    /**
     * Rejects: checking a row of another provider_target
     * and a check by id alone. The rows of one batch are read in one
     * statement by (id, uuid): a record with the row's id and another uuid
     * is absent, and its row goes after the grace. A row bound to another
     * target (queue.failed.table changed, a record of the same id and uuid
     * planted in the new table) is reported as not checked (target drift)
     * and never deleted; when the configuration returns and the record is
     * gone, the row goes.
     */
    #[FreshSchema]
    public function test_reconcile_failed_checks_by_uuid_in_batches(): void
    {
        Reconciliation::useFailedBatchForTesting(2);
        [$kept] = $this->failedJob();
        [$swapped] = $this->failedJob();
        [$drifted, $occurrence] = $this->failedJob();
        $reads = 0;
        Hooks::$callback = static function (string $point) use (&$reads): void {
            if ($point === Reconciliation::BEFORE_ABSENCE_CHECK) {
                $reads++;
            }
        };

        // The record of $swapped keeps its id but gets another uuid: not the bound record.
        DB::table('failed_jobs')->where('uuid', $swapped)->update(['uuid' => '0b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d']);
        $this->runFailedPastGrace();

        self::assertSame(4, $reads, 'one presence read per batch of two rows, in each of the two checks');
        self::assertSame('failed', $this->rowOrFail($kept)->status, 'the exact record exists');
        self::assertNull($this->row($swapped), 'a record with the same id and another uuid is not the bound record');
        self::assertSame('failed', $this->rowOrFail($drifted)->status);
        Hooks::$callback = null;

        // queue.failed.table changes on the same connection name; the new table holds a record with the same id and uuid.
        $before = $this->jobRows($drifted);
        $id = $this->providerId($drifted);
        $this->createFailedTable('failed_jobs_new', true);
        DB::table('failed_jobs_new')->insert((array) DB::table('failed_jobs')->where('id', $id)->first());
        DB::table('failed_jobs')->where('id', $id)->delete();
        $this->useFailer('database-uuids', 'failed_jobs_new');
        $this->runFailedPastGrace();

        $row = $this->rowOrFail($drifted);
        self::assertSame(['failed', $occurrence, null], [$row->status, (int) $row->occurrence_id, $row->absent_since], 'a row of another target is not checked');
        self::assertSame($before, $this->jobRows($drifted));
        self::assertSame(1, DB::table('failed_jobs_new')->where('id', $id)->count(), 'the planted record stays');
        self::assertArrayHasKey(Reconciliation::HEALTH_NOT_CHECKED, $this->healthErrors(), 'the health events report the drift');

        // The configuration returns, and the record is gone there: the row goes.
        $this->useFailer('database-uuids', 'failed_jobs');
        $this->runFailedPastGrace();
        self::assertNull($this->row($drifted));
    }

    /**
     * Rejects: a failed presence read that counts as "absent" or moves the
     * cursor: the rows keep their state, the cursor stays, and the health
     * events record reconcile:not_checked.
     */
    public function test_failed_presence_read_changes_nothing(): void
    {
        [$uuid] = $this->failedJob();
        DB::table('failed_jobs')->delete();
        $before = (array) $this->rowOrFail($uuid);
        Hooks::$callback = static function (string $point): void {
            if ($point === Reconciliation::BEFORE_ABSENCE_CHECK) {
                throw new \RuntimeException('The failed-job table cannot be read (test).');
            }
        };

        try {
            $this->runFailed();
            $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);
            $this->runFailed();
        } finally {
            Hooks::$callback = null;
        }

        self::assertEquals($before, (array) $this->rowOrFail($uuid), 'a read that failed proves nothing');
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', Reconciliation::FAILED_CURSOR)->value('value'), 'the cursor did not move past the row');
        self::assertArrayHasKey(Reconciliation::HEALTH_NOT_CHECKED, $this->healthErrors());
    }

    /**
     * Rejects: a traversal that stops at the first batch.
     */
    public function test_the_keyset_reaches_every_batch(): void
    {
        Reconciliation::useFailedBatchForTesting(2);
        $uuids = [];

        for ($job = 0; $job < 5; $job++) {
            $uuids[] = $this->failedJob()[0];
        }

        DB::table('failed_jobs')->delete();
        $this->runFailed();
        self::assertSame(5, $this->mirror()->table('orrery_jobs')->whereNotNull('absent_since')->count(), 'one tick checks every batch while its budget lasts');
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);
        $this->runFailed();

        self::assertSame(0, $this->mirror()->table('orrery_jobs')->count(), 'and the next tick past the grace removes every row');
    }
    /**
     * Rejects: a tick that walks every failed row while the deadline lasts, or a budget that loses its position.
     */
    public function test_a_tick_checks_at_most_its_row_budget_and_resumes_from_the_cursor(): void
    {
        Reconciliation::useFailedBatchForTesting(2);
        Reconciliation::useFailedRowsPerTickForTesting(3);

        for ($job = 0; $job < 5; $job++) {
            $this->failedJob();
        }

        $ids = $this->mirror()->table('orrery_jobs')->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        DB::table('failed_jobs')->delete();

        $this->runFailed();
        $absent = static fn ($test): array => $test->mirror()->table('orrery_jobs')->whereNotNull('absent_since')->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        self::assertSame(array_slice($ids, 0, 3), $absent($this), 'the first tick checks the first three rows only');
        self::assertSame((string) $ids[2], (string) $this->mirror()->table('orrery_settings')->where('key', Reconciliation::FAILED_CURSOR)->value('value'), 'the cursor keeps its position');

        $this->runFailed();

        self::assertSame($ids, $absent($this), 'the next tick resumes after the cursor');
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', Reconciliation::FAILED_CURSOR)->value('value'), 'the end of the rows clears the cursor');
        self::assertSame(5, $this->mirror()->table('orrery_jobs')->count(), 'the first absence removes nothing (the grace)');
    }

    /**
     * Rejects: a stored cursor that stays when the failer no longer has a record table:
     * orrery:reconcile then repeated its passes for its whole time share and said "run it again". The pass
     * ends and clears the cursor.
     * Still passes: a pass that clears the cursor and also changes the rows (none is checked here).
     */
    public function test_a_failer_without_a_record_table_clears_the_cursor(): void
    {
        Reconciliation::useFailedBatchForTesting(2);
        Reconciliation::useFailedRowsPerTickForTesting(2);

        for ($job = 0; $job < 3; $job++) {
            $this->failedJob();
        }

        $this->runFailed();
        self::assertNotNull($this->mirror()->table('orrery_settings')->where('key', Reconciliation::FAILED_CURSOR)->value('value'), 'the first tick stored its position');

        $this->app['config']->set('queue.failed', ['driver' => 'null']);
        $this->app->forgetInstance('queue.failer');
        $this->runFailed();

        self::assertNull($this->mirror()->table('orrery_settings')->where('key', Reconciliation::FAILED_CURSOR)->value('value'));
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->whereNotNull('absent_since')->count());
    }
}
