<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Reconciliation;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\OffGridTime;

/**
 * The reconciliation of active rows (rule U1).
 */
final class ActiveReconciliationTest extends ReconciliationTestCase
{
    /**
     * Rejects: one jobs read that binds every id of a full batch: 1,000 variables, above the 999 of
     * SQLite before 3.32, so a full batch failed on every tick there. Each read binds at most BINDINGS ids, and
     * every row of the batch is still checked.
     * Still passes: a chunk size above 999 on an engine whose limit is higher (the bound is checked only here).
     */
    public function test_a_full_batch_reads_the_jobs_in_chunks(): void
    {
        [$uuid] = $this->pushed();
        $template = (array) $this->rowOrFail($uuid);
        unset($template['id']);
        $rows = [];

        for ($n = 1; $n < Reconciliation::ACTIVE_BATCH; $n++) {
            $rows[] = ['uuid' => sprintf('00000000-0000-4000-8000-%012d', $n), 'job_id' => 900000 + $n] + $template;
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }

        $reads = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\QueryExecuted::class, static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$reads): void {
            // Any quoting (MySQL backticks) and any table prefix.
            if (preg_match('/from [`"]?\w*jobs[`"]? where [`"]?id[`"]? in/i', $query->sql) === 1) {
                $reads[] = count($query->bindings);
            }
        });

        $this->runActive();

        self::assertNotSame([], $reads);
        self::assertLessThanOrEqual(999, max($reads), 'SQLite before 3.32 takes at most 999 variables');
        self::assertSame(Reconciliation::ACTIVE_BATCH, array_sum($reads), 'every row of the batch is checked');
        self::assertSame(Reconciliation::ACTIVE_BATCH - 1, $this->mirror()->table('orrery_jobs')->whereNotNull('absent_since')->count(), 'the copies are absent, the pushed job is present');
    }

    /**
     * Rejects: marking `unknown` at the first absence.
     */
    public function test_second_absence_after_the_grace_marks_unknown(): void
    {
        [$uuid, $jobId] = $this->pushed();
        [$present] = $this->pushed();
        // The job left the queue, and its outcome was never observed.
        $this->queueTable()->where('id', $jobId)->delete();

        $first = self::now();
        $this->runActive();

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'the first absence only records its time');
        self::assertSame($first, $this->absentSince($uuid));

        // A later check inside the grace of max(retry_after = 90, 600) s changes nothing.
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS - 1);
        $this->runActive();
        self::assertSame(['pending', $first], [$this->rowOrFail($uuid)->status, $this->absentSince($uuid)]);

        $this->moveClock(1);
        $marked = self::now();
        $this->runActive();

        $row = $this->rowOrFail($uuid);
        self::assertSame(['unknown', 'absent', $jobId, 1], [$row->status, $row->unknown_reason, (int) $row->job_id, (int) $row->cycle]);
        self::assertSame([$marked, $marked], [Clock::toDecimal((float) $row->unknown_since), Clock::toDecimal((float) $row->finished_at)]);

        $control = $this->rowOrFail($present);
        self::assertSame(['pending', null], [$control->status, $control->absent_since], 'a present job is never absent');
    }

    /**
     * Rejects: a reconciliation that checks only the configured database queue connections, so the active rows
     * of a connection that was removed (or given another driver) stay pending for ever: a row whose job no
     * configured queue table holds is absent, and unknown after the grace with its own reason
     * `connection_removed`; a configured connection's present row stays. Would still pass: a check that reads
     * only the job ids of the configured tables (see the rename and id-collision tests).
     */
    public function test_rows_of_a_removed_connection_become_unknown_after_the_grace(): void
    {
        [$uuid, $jobId] = $this->pushed();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['connection' => 'removed']);
        $this->queueTable()->where('id', $jobId)->delete();
        [$present] = $this->pushed();

        $this->runActive();
        self::assertSame('pending', $this->rowOrFail($uuid)->status, 'the first absence only records its time');
        self::assertNotNull($this->absentSince($uuid));

        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runActive();

        $row = $this->rowOrFail($uuid);
        self::assertSame(['unknown', Reconciliation::REMOVED_REASON, $jobId], [$row->status, $row->unknown_reason, (int) $row->job_id]);
        self::assertSame(['pending', null], [$this->rowOrFail($present)->status, $this->rowOrFail($present)->absent_since]);
    }

    /**
     * Rejects: a grace check on absent_since as SQLite returns it. SQLite 3.45
     * on x86-64 stores 1796604698.689484 as the float above it, so the check
     * at the exact end of the grace read 599.99999976 s and the row stayed
     * pending (CI). Other engines keep the decimal; there the test checks
     * only the boundary. Still passes: a check that is exact only for times
     * before 2^31 s.
     */
    public function test_an_absence_stored_off_the_float_grid_ends_at_the_grace(): void
    {
        $start = '1796604698.689484';
        Carbon::setTestNow(Carbon::createFromFormat('U.u', $start));
        [$uuid, $jobId] = $this->pushed();
        $this->queueTable()->where('id', $jobId)->delete();

        $this->runActive();
        self::assertSame($start, $this->absentSince($uuid));
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['absent_since' => OffGridTime::stored($this->mirror(), $start, 1)]);

        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runActive();

        $row = $this->rowOrFail($uuid);
        self::assertSame(['unknown', Reconciliation::UNKNOWN_REASON, $jobId], [$row->status, $row->unknown_reason, (int) $row->job_id]);
    }

    /**
     * Rejects: a check of the rows of an unconfigured connection that reads no queue table: a
     * renamed queue connection on the same jobs table keeps its waiting jobs, so their rows are present and
     * never become unknown. Would still pass: a check that matches by job id only (see the id-collision test).
     */
    public function test_rows_of_a_renamed_connection_whose_jobs_wait_stay_pending(): void
    {
        [$uuid] = $this->pushed();
        // The connection `database` was renamed: the mirror row keeps its old name, and the job still waits in
        // the jobs table that the configured connection `database` reads.
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['connection' => 'renamed-away']);

        for ($check = 0; $check < 3; $check++) {
            $this->runActive();
            $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);
        }

        $row = $this->rowOrFail($uuid);
        self::assertSame(['pending', null, null], [$row->status, $row->absent_since, $row->unknown_reason], 'a job that a configured jobs table holds is present');
    }

    /**
     * Rejects: a presence check of a removed connection's row by job id only: a configured jobs table whose row
     * with that id holds another job (another database, or a reused id) does not hold the row's job.
     */
    public function test_a_removed_connections_row_is_absent_when_its_job_id_holds_another_job(): void
    {
        [$uuid, $jobId] = $this->pushed();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['connection' => 'removed']);
        // The id now holds another job's payload.
        $this->queueTable()->where('id', $jobId)->update(['payload' => json_encode(['uuid' => '0b9a4c8e-5a3f-4d1e-9c2b-7f6e5d4c3b2a', 'displayName' => 'Other'])]);

        $this->runActive();
        self::assertNotNull($this->absentSince($uuid), 'the job id holds another job');

        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runActive();

        self::assertSame(['unknown', Reconciliation::REMOVED_REASON], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->unknown_reason]);
    }

    /**
     * Rejects: a removed connection's rows that only the tick checks, and a check that gives up
     * on every table when one cannot be read. A queue connection on a SQLite file in rollback mode refuses
     * automatic reads (D18): the tick still reads the other table, so a row found there loses its first
     * absence, while a row that only the file can hold keeps its state; `orrery:reconcile` reads the file as
     * an operator action, and a row that no table holds becomes unknown there after the grace. Would still
     * pass: a command that also reads a table whose read fails in the tick for another reason (none here).
     */
    public function test_the_tick_skips_only_an_unreadable_table_and_the_command_reads_it(): void
    {
        $file = $this->rollbackModeQueue();
        // A row whose job waits in the readable jobs table, with a first absence from an earlier check.
        [$readable] = $this->pushed();
        $this->mirror()->table('orrery_jobs')->where('uuid', $readable)->update(['connection' => 'removed', 'absent_since' => self::now()]);
        // A row whose job waits only in the rollback-mode file.
        [$inFile, $fileJobId] = $this->pushed();
        $job = (array) $this->queueTable()->where('id', $fileJobId)->first();
        $this->queueTable()->where('id', $fileJobId)->delete();
        \Illuminate\Support\Facades\DB::connection('rollback_file')->table('jobs')->insert($job);
        $this->mirror()->table('orrery_jobs')->where('uuid', $inFile)->update(['connection' => 'removed']);

        for ($check = 0; $check < 2; $check++) {
            $this->runActive();
            $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);
        }

        self::assertSame(['pending', null], [$this->rowOrFail($readable)->status, $this->rowOrFail($readable)->absent_since], 'the readable table settles its row');
        self::assertSame(['pending', null], [$this->rowOrFail($inFile)->status, $this->rowOrFail($inFile)->absent_since], 'a row that only the unread file can hold keeps its state');

        // The command reads the file: the job is there.
        self::assertSame(0, \Illuminate\Support\Facades\Artisan::call('orrery:reconcile'), \Illuminate\Support\Facades\Artisan::output());
        self::assertNull($this->rowOrFail($inFile)->absent_since);

        // The job left the file: two operator checks across the grace make the row unknown.
        \Illuminate\Support\Facades\DB::connection('rollback_file')->table('jobs')->delete();
        \Illuminate\Support\Facades\Artisan::call('orrery:reconcile');
        self::assertNotNull($this->rowOrFail($inFile)->absent_since);
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);
        $status = \Illuminate\Support\Facades\Artisan::call('orrery:reconcile');
        $output = \Illuminate\Support\Facades\Artisan::output();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Reconciled the waiting and reserved jobs of removed queue connections.', $output);
        self::assertSame(['unknown', Reconciliation::REMOVED_REASON], [$this->rowOrFail($inFile)->status, $this->rowOrFail($inFile)->unknown_reason]);
        self::assertFileExists($file);
    }

    /**
     * Rejects: a removed-rows check without a rotating cursor, so rows whose jobs still wait at the head of the
     * batch hold it, and a row behind them is never checked. Would still pass: a cursor that skips the rows
     * of one batch once (the next rotation checks them).
     */
    public function test_the_removed_rows_check_rotates_past_waiting_rows(): void
    {
        Reconciliation::useActiveBatchForTesting(2);
        $uuids = [];

        for ($job = 0; $job < 3; $job++) {
            [$uuids[], $ids[]] = $this->pushed();
        }

        $this->mirror()->table('orrery_jobs')->update(['connection' => 'removed']);
        // The first two jobs still wait; the third left the queue.
        $this->queueTable()->where('id', $ids[2])->delete();

        $this->reconciliation()->reconcileRemoved();
        self::assertNull($this->absentSince($uuids[2]), 'the first batch holds the two waiting rows');
        self::assertSame((string) $this->rowOrFail($uuids[1])->id, (string) $this->mirror()->table('orrery_settings')->where('key', Reconciliation::REMOVED_CURSOR)->value('value'));

        $result = $this->reconciliation()->reconcileRemoved();
        self::assertNotNull($this->absentSince($uuids[2]), 'the row behind the waiting rows was checked');
        self::assertTrue($result['wrapped']);
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', Reconciliation::REMOVED_CURSOR)->value('value'), 'the end of the rows clears the cursor');
    }

    /**
     * Rejects: a removed-rows check that reads the active rows of every configured connection on each tick to find
     * none (`NOT IN` is no index condition on Postgres and SQLite, so a backlog of waiting jobs is read
     * on every tick), and a seek that misses a removed name that sorts between configured ones. Would still pass: a
     * seek that also reads one more name than it needs.
     */
    public function test_the_removed_rows_check_reads_no_active_row_without_a_removed_connection(): void
    {
        for ($job = 0; $job < 3; $job++) {
            [$uuids[], $ids[]] = $this->pushed();
        }

        $statements = [];
        $this->app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$statements): void {
            if (str_contains($query->sql, 'orrery_jobs')) {
                $statements[] = $query->sql;
            }
        });

        $result = $this->reconciliation()->reconcileRemoved();

        self::assertSame([0, true], [$result['checked'], $result['wrapped']]);
        self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'status'))), 'the check read the active rows without a removed connection');
        self::assertCount(2, $statements, 'one seek per distinct connection name, and one that finds the end');

        // A removed name between configured ones is found.
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuids[0])->update(['connection' => 'aaa']);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuids[1])->update(['connection' => 'zzz']);
        $this->queueTable()->delete();
        $result = $this->reconciliation()->reconcileRemoved();
        self::assertSame(2, $result['checked']);
        self::assertNotNull($this->absentSince($uuids[0]));
        self::assertNotNull($this->absentSince($uuids[1]));
        self::assertNull($this->absentSince($uuids[2]), 'a row of a configured connection was checked as removed');
    }

    /**
     * Rejects: a removed-rows check that reads the payloads of the whole batch at once, or never checks its
     * budget: it reads REMOVED_CHUNK rows per read, and a budget that ends after the first chunk stops the
     * batch there, with the cursor after the first chunk and the second chunk not applied. Would still pass:
     * a chunk size that differs from the production one by a few rows.
     */
    public function test_the_removed_rows_check_reads_in_chunks_within_its_budget(): void
    {
        $count = Reconciliation::REMOVED_CHUNK + 5;

        for ($job = 0; $job < $count; $job++) {
            $this->pushed();
        }

        $this->mirror()->table('orrery_jobs')->update(['connection' => 'removed']);
        $this->queueTable()->delete();
        $reads = 0;
        $this->onHook(static function (string $point) use (&$reads): void {
            if ($point === Reconciliation::BEFORE_ABSENCE_CHECK) {
                $reads++;
                // The first read uses up the budget.
                Clock::advanceMonotonicForTesting(11);
            }
        });

        $result = $this->reconciliation()->reconcileRemoved(new \Justrau\Orrery\Maintenance\TickBudget(Clock::monotonic() + 10));

        self::assertSame(1, $reads, 'one read of the first chunk, then the budget stops the batch');
        self::assertSame([Reconciliation::REMOVED_CHUNK, true, false], [$result['checked'], $result['stopped'], $result['wrapped']]);
        self::assertSame(Reconciliation::REMOVED_CHUNK, $this->mirror()->table('orrery_jobs')->whereNotNull('absent_since')->count());
        $lastOfChunk = (int) $this->mirror()->table('orrery_jobs')->orderBy('id')->skip(Reconciliation::REMOVED_CHUNK - 1)->value('id');
        self::assertSame((string) $lastOfChunk, (string) $this->mirror()->table('orrery_settings')->where('key', Reconciliation::REMOVED_CURSOR)->value('value'));
    }

    /**
     * Rejects: an absent_since that survives a later check that finds the job.
     */
    public function test_a_present_job_clears_its_first_absence(): void
    {
        [$uuid, $jobId] = $this->pushed();
        $row = (array) $this->queueTable()->where('id', $jobId)->first();
        $this->queueTable()->where('id', $jobId)->delete();

        $this->runActive();
        self::assertNotNull($this->absentSince($uuid));

        // The read before saw no row (for example a restore that brings it back).
        $this->queueTable()->insert($row);
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);
        $this->runActive();

        self::assertSame(['pending', null], [$this->rowOrFail($uuid)->status, $this->absentSince($uuid)]);
    }

    /**
     * Rejects: a first absence of an earlier job id that counts for the next one.
     */
    public function test_a_new_job_id_restarts_the_absence_check(): void
    {
        [$uuid, $first] = $this->pushed();
        self::assertNotNull($this->queue()->pop());

        // A missed release: the replacement waits, and the old id is gone.
        $row = (array) $this->queueTable()->where('id', $first)->first();
        unset($row['id']);
        $replacement = (int) $this->queueTable()->insertGetId(['reserved_at' => null] + $row);
        $this->queueTable()->where('id', $first)->delete();

        $this->runActive();
        self::assertNotNull($this->absentSince($uuid), 'the first absence of the old id');

        // Past the grace, a worker reserves the replacement (R3), and its outcome is lost.
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 100);
        self::assertNotNull($this->queue()->pop());
        self::assertSame(['reserved', $replacement, null], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->job_id, $this->absentSince($uuid)]);
        $this->queueTable()->where('id', $replacement)->delete();

        $check = self::now();
        $this->runActive();

        self::assertSame(['reserved', $check], [$this->rowOrFail($uuid)->status, $this->absentSince($uuid)], 'the absence of the new id starts its own grace');
    }

    /**
     * Rejects: a swap-on release (the fast L1 statement) that keeps the absence time of the old id.
     */
    public function test_a_release_clears_the_absence_of_the_old_id(): void
    {
        [$uuid, $first] = $this->pushed();
        $job = $this->queue()->pop();
        self::assertNotNull($job);

        // A check saw the old id absent after the release committed and before its observation applied.
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['absent_since' => Clock::toDecimal(Clock::now() - Reconciliation::MIN_GRACE_SECONDS - 1)]);

        $job->release(0);

        $row = $this->rowOrFail($uuid);
        self::assertSame(['pending', $first, 1], [$row->status, (int) $row->prev_job_id, (int) $row->releases], 'the swap-on release applied');
        self::assertNotSame($first, (int) $row->job_id);
        self::assertNull($row->absent_since, 'the new id starts without an absence');

        // The new id is gone too: its first absence only records its time.
        $this->queueTable()->delete();
        $this->runActive();
        self::assertSame('pending', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: an absence check for a row without a resolved id.
     */
    public function test_unresolved_and_null_job_ids_are_never_unknown(): void
    {
        [$released, $releasedId] = $this->pushed();
        [$bulk, $bulkId] = $this->pushed();
        // A swap-off release has no new id, and an unresolved bulk row has no proven id.
        $this->mirror()->table('orrery_jobs')->where('uuid', $released)->update(['job_id' => null]);
        $this->mirror()->table('orrery_jobs')->where('uuid', $bulk)->update(['job_id_unresolved' => true]);
        $this->queueTable()->whereIn('id', [$releasedId, $bulkId])->delete();

        for ($check = 0; $check < 3; $check++) {
            $this->runActive();
            $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);
        }

        foreach ([$released, $bulk] as $uuid) {
            $row = $this->rowOrFail($uuid);
            self::assertSame(['pending', null, null], [$row->status, $row->absent_since, $row->unknown_since], "{$uuid} is never checked");
        }
    }

    /**
     * Rejects: an `unknown` row that no later observation can revise.
     */
    public function test_missed_release_with_a_delayed_replacement_becomes_unknown_then_recovers(): void
    {
        [$uuid, $first] = $this->pushed();
        self::assertNotNull($this->queue()->pop(), 'a worker reserves the job');
        self::assertSame(['reserved', $first, 1], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->job_id, (int) $this->rowOrFail($uuid)->attempts]);

        // The release was never observed: Laravel deleted the reserved row and
        // inserted its replacement, delayed by an hour, with the same payload.
        $row = (array) $this->queueTable()->where('id', $first)->first();
        unset($row['id']);
        $replacement = (int) $this->queueTable()->insertGetId(['reserved_at' => null, 'available_at' => (int) Clock::now() + 3600] + $row);
        $this->queueTable()->where('id', $first)->delete();

        // Both sweeps run before the replacement is due.
        $this->runActive();
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS);
        $this->runActive();
        $row = $this->rowOrFail($uuid);
        self::assertSame(['unknown', 'absent', $first], [$row->status, $row->unknown_reason, (int) $row->job_id]);

        $during = null;
        Event::listen(JobProcessing::class, function () use ($uuid, &$during): void {
            $row = $this->rowOrFail($uuid);
            $during = [$row->status, (int) $row->job_id, $row->unknown_since];
        });

        $this->moveClock(3600);
        $this->work();

        self::assertSame(['reserved', $replacement, null], $during, 'the replacement\'s reservation revises the unknown row (R3)');
        $row = $this->rowOrFail($uuid);
        self::assertSame(['completed', $replacement, null, null, null], [$row->status, (int) $row->job_id, $row->unknown_since, $row->unknown_reason, $row->absent_since]);
    }

    /**
     * Rejects: a time from a partial sweep.
     */
    public function test_last_verified_follows_completed_sweeps(): void
    {
        Reconciliation::useActiveBatchForTesting(2);

        for ($job = 0; $job < 3; $job++) {
            $this->pushed();
        }

        self::assertNull($this->reconciliation()->lastVerified('database'), 'no sweep ran');

        $start = self::now();
        $this->runActive();
        self::assertNull($this->reconciliation()->lastVerified('database'), 'the first tick checked two of three rows');

        $this->moveClock(60);
        $this->runActive();
        self::assertSame($start, Clock::toDecimal((float) $this->reconciliation()->lastVerified('database')), 'the sweep is complete; it verified from its start');

        $this->moveClock(60);
        $next = self::now();
        $this->runActive();
        self::assertSame($start, Clock::toDecimal((float) $this->reconciliation()->lastVerified('database')), 'a partial sweep keeps the last complete one');

        $this->moveClock(60);
        $this->runActive();
        self::assertSame($next, Clock::toDecimal((float) $this->reconciliation()->lastVerified('database')));
    }

    /**
     * A second `database` queue connection on a SQLite file in rollback mode (its jobs table empty): the tick
     * may not read it (D18), an operator action may.
     */
    private function rollbackModeQueue(): string
    {
        $file = sys_get_temp_dir().'/orrery-rollback-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($file);
        $this->beforeApplicationDestroyed(static function () use ($file): void {
            foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
                @unlink($file.$suffix);
            }
        });
        $config = $this->app['config'];
        $config->set('database.connections.rollback_file', ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000, 'journal_mode' => 'delete']);
        $config->set('queue.connections.rollback_queue', ['driver' => 'database', 'connection' => 'rollback_file', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        \Illuminate\Support\Facades\DB::connection('rollback_file')->getSchemaBuilder()->create('jobs', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        return $file;
    }
}
