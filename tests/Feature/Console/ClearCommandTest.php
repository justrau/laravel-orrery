<?php

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Console\ClearCommand;
use Justrau\Orrery\Database\Models\Job;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Hooks\HooksTestCase;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Retention\Jobs\TaggedJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageStatements;

/**
 * orrery:clear:
 */
class ClearCommandTest extends HooksTestCase
{
    public function test_clear_removes_queue_rows_and_mirror_rows_for_connection(): void
    {
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(2, Job::query()->where('connection', 'database')->count());

        $this->artisan('orrery:clear', ['connection' => 'database', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, Job::query()->where('connection', 'database')->count());
    }

    public function test_clear_respects_queue_option_and_leaves_other_queues_intact(): void
    {
        Queue::push(new BasicJob);
        Queue::pushOn('low', new BasicJob);

        $this->artisan('orrery:clear', [
            'connection' => 'database',
            '--queue' => 'low',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'low')->count());
        $this->assertSame(1, Job::query()->where('queue', 'default')->count());
        $this->assertSame(0, Job::query()->where('queue', 'low')->count());
    }

    /**
     * Rejects: an empty connection or `--queue=` that reads as absent, so a clear meant for one
     * queue clears the connection's default queue instead. Each is refused with exit 1 before any delete.
     */
    public function test_an_empty_connection_or_queue_is_refused(): void
    {
        Queue::push(new BasicJob);

        foreach ([['connection' => ''], ['connection' => 'database', '--queue' => '']] as $arguments) {
            self::assertSame(1, Artisan::call('orrery:clear', $arguments + ['--force' => true]), (string) json_encode($arguments));
            self::assertStringContainsString('is empty', Artisan::output());
        }

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, Job::query()->where('connection', 'database')->count());
    }

    /**
     * Rejects: batches whose statements bind more than 999 variables, which SQLite builds before 3.32 refuse
     * (SQLITE_MAX_VARIABLE_NUMBER): a clear of 1,000 waiting jobs binds at most 999 per statement, on the queue's
     * connection and on the mirror.
     */
    public function test_clear_binds_at_most_999_variables_per_statement(): void
    {
        Queue::bulk(array_map(static fn (): BasicJob => new BasicJob, range(1, 1000)));
        self::assertSame(1000, DB::table('jobs')->count());
        $largest = 0;
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$largest): void {
            $largest = max($largest, count($query->bindings));
        });

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));

        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, Job::query()->where('connection', 'database')->count());
        self::assertGreaterThan(100, $largest, 'The statements were seen.');
        self::assertLessThanOrEqual(999, $largest);
    }

    /**
     * Rejects: deleting reserved, failed or completed rows.
     */
    public function test_clear_removes_only_waiting_jobs(): void
    {
        $failed = $this->pushedUuid(new TaggedJob(['orders'], true));
        $this->workAndLogFailures();
        $completed = $this->pushedUuid(new BasicJob);
        $this->workAndLogFailures();
        $reserved = $this->pushedUuid(new BasicJob);
        self::assertNotNull($this->queue()->pop(), 'a worker holds this job');
        $waiting = $this->pushedUuid(new TaggedJob(['orders']));
        $delayed = $this->pushedUuid(new BasicJob, 3600);

        self::assertSame(['failed', 'completed', 'reserved', 'pending', 'pending'], array_map(fn (string $uuid): string => $this->rowOrFail($uuid)->status, [$failed, $completed, $reserved, $waiting, $delayed]));
        self::assertSame(1, $this->mirror()->table('orrery_job_tags')->where('job_uuid', $waiting)->count());

        ClearCommand::useBatchForTesting(1);
        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame(1, DB::table('jobs')->count(), 'only the reserved job stays in the queue');
        self::assertNotNull(DB::table('jobs')->whereNotNull('reserved_at')->first());
        self::assertSame(1, DB::table('failed_jobs')->count(), 'Laravel still holds the failed job');
        self::assertSame(['failed', 'completed', 'reserved'], array_map(fn (string $uuid): string => $this->rowOrFail($uuid)->status, [$failed, $completed, $reserved]));
        self::assertNull($this->row($waiting));
        self::assertNull($this->row($delayed));
        self::assertSame(0, $this->mirror()->table('orrery_job_tags')->where('job_uuid', $waiting)->count(), 'the tags of a deleted row go too');
        self::assertSame(1, $this->mirror()->table('orrery_failed_tags')->where('job_uuid', $failed)->count(), 'the failed job keeps its failed tag');
        self::assertStringContainsString('Cleared 2 waiting job(s) and 2 mirror row(s)', $output);
        self::assertStringContainsString('unlike queue:clear', $output);
    }

    /**
     * Rejects: a job that a worker reserves after the selection is deleted anyway.
     */
    public function test_clear_rechecks_the_reservation_before_it_deletes(): void
    {
        $uuid = $this->pushedUuid(new BasicJob);
        $id = (int) DB::table('jobs')->value('id');

        // A worker reserves the job between the selection and the delete.
        DB::listen(function ($query) use ($id): void {
            if (str_contains($query->sql, 'reserved_at') && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'limit')) {
                DB::table('jobs')->where('id', $id)->whereNull('reserved_at')->update(['reserved_at' => time(), 'attempts' => 1]);
            }
        });

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));

        self::assertSame(1, DB::table('jobs')->where('id', $id)->count(), 'the reserved job stays');
        self::assertSame('pending', $this->rowOrFail($uuid)->status, 'and so does its mirror row');
    }

    /**
     * Rejects: a delete count that differs from the locked ids and still deletes the mirror rows of every locked id.
     */
    public function test_clear_reports_a_delete_count_that_differs_from_the_lock(): void
    {
        $reserved = $this->pushedUuid(new BasicJob);
        $waiting = $this->pushedUuid(new BasicJob);
        $id = (int) DB::table('jobs')->orderBy('id')->value('id');

        // The lock did not hold: the first job is reserved between the lock and the delete.
        DB::listen(function ($query) use ($id): void {
            $sql = strtolower($query->sql);

            if (str_starts_with($sql, 'select') && str_contains($sql, 'reserved_at') && ! str_contains($sql, 'limit')) {
                DB::table('jobs')->where('id', $id)->update(['reserved_at' => time(), 'attempts' => 1]);
            }
        });

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame([$id], DB::table('jobs')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        self::assertSame('pending', $this->rowOrFail($reserved)->status, 'the mirror row of the kept job stays');
        self::assertNull($this->row($waiting));
        self::assertStringContainsString('Cleared 1 waiting job(s) and 1 mirror row(s)', $output);
        self::assertStringContainsString('another number of jobs than it locked', $output);
    }

    /**
     * Rejects: an exit 0 when the package tables cannot be reached, so a script sees success for work that did not happen; and
     * jobs deleted while their unresolved bulk rows could not be checked: the probe fails first, so the batch is kept.
     * Still passes: a command that exits 1 on a mirror failure but does not name the job ids whose mirror rows stay.
     */
    #[FreshSchema]
    public function test_clear_exits_non_zero_when_the_mirror_delete_fails(): void
    {
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        // Only the package tables fail; the jobs table stays.
        $this->mirrorOutage();

        self::assertSame(1, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame(2, DB::table('jobs')->count(), 'the waiting jobs were kept: the probe failed before the delete');
        self::assertStringContainsString('Cleared 0 waiting job(s) and 0 mirror row(s)', $output);
        self::assertStringContainsString('Those jobs are still queued', $output);
    }

    /**
     * Rejects: a command-line clear without its audit (the event, with the actor `cli` and the counts), and a clear
     * inside an open transaction of the queue connection, whose batches would not commit one by one.
     */
    public function test_clear_is_recorded_and_refused_inside_a_transaction(): void
    {
        Queue::push(new BasicJob);
        $events = new \ArrayObject;
        \Illuminate\Support\Facades\Event::listen(\Justrau\Orrery\Events\OperatorActionPerformed::class, static function (\Justrau\Orrery\Events\OperatorActionPerformed $event) use ($events): void {
            $events[] = $event;
        });

        DB::connection()->beginTransaction();

        try {
            self::assertSame(1, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
            self::assertStringContainsString('inside a transaction', Artisan::output());
        } finally {
            DB::connection()->rollBack();
        }

        self::assertCount(0, $events, 'a refused clear did nothing');
        self::assertSame(1, DB::table('jobs')->count());

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        self::assertCount(1, $events);
        self::assertSame(['clear', ['connection' => 'database', 'queue' => 'default'], 'cli', 'done', 1], [$events[0]->action, $events[0]->target, $events[0]->actor, $events[0]->result, $events[0]->counts['deleted']]);
    }

    public function test_clear_refuses_a_queue_connection_without_the_database_driver(): void
    {
        $this->app['config']->set('queue.connections.sync', ['driver' => 'sync']);

        $this->artisan('orrery:clear', ['connection' => 'sync', '--force' => true])->assertExitCode(1);
    }

    /**
     * The scenario: a bulk push whose mirror rows stay unresolved (job_id NULL).
     *
     * Rejects: the job_id match alone, which leaves both rows `pending` forever while the output says 0 mirror rows.
     */
    public function test_clear_deletes_unresolved_bulk_mirror_rows(): void
    {
        $uuids = $this->unresolvedBulk([new TaggedJob(['orders']), new TaggedJob(['orders'])]);
        self::assertSame(2, $this->mirror()->table('orrery_job_tags')->whereIn('job_uuid', $uuids)->count());
        $statements = $this->recordStatements();

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, $this->mirrorCount(), 'both unresolved mirror rows are gone');
        self::assertSame(0, $this->mirror()->table('orrery_job_tags')->whereIn('job_uuid', $uuids)->count(), 'and their tags');
        self::assertStringContainsString('Cleared 2 waiting job(s) and 2 mirror row(s), 2 of them unresolved bulk rows', $output);
        self::assertNotSame([], array_filter($statements(), self::selectsPayload(...)), 'the control: the unresolved case reads the payloads');
    }

    /**
     * The bulk resolution marked the rows `unknown` / `bulk_unresolved`,
     * which clears job_id_unresolved; their jobs still wait.
     * orrery:clear matches them by uuid and generation, as unresolved rows.
     *
     * Rejects: a match on job_id_unresolved alone, which leaves the
     * marked rows behind after their jobs are deleted.
     */
    public function test_clear_deletes_bulk_rows_marked_unknown(): void
    {
        $uuids = $this->unresolvedBulk([new TaggedJob(['orders']), new TaggedJob(['orders'])]);

        // As BulkResolver::markUnresolvable() leaves them.
        $time = Clock::toDecimal(Clock::now());
        $this->mirror()->table('orrery_jobs')->whereIn('uuid', $uuids)->update([
            'status' => 'unknown',
            'job_id_unresolved' => false,
            'finished_at' => $time,
            'unknown_since' => $time,
            'unknown_reason' => BulkResolver::UNRESOLVED_REASON,
        ]);

        foreach ($uuids as $uuid) {
            $row = $this->rowOrFail($uuid);
            self::assertSame('unknown', $row->status);
            self::assertSame(BulkResolver::UNRESOLVED_REASON, $row->unknown_reason);
            self::assertFalse((bool) $row->job_id_unresolved);
        }

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, $this->mirrorCount(), 'both marked mirror rows are gone');
        self::assertSame(0, $this->mirror()->table('orrery_job_tags')->whereIn('job_uuid', $uuids)->count(), 'and their tags');
        self::assertStringContainsString('Cleared 2 waiting job(s) and 2 mirror row(s), 2 of them unresolved bulk rows', $output);
    }

    /**
     * Rejects: a probe taken once before the loop, which misses the unresolved rows that a bulk push committed before a later batch.
     */
    public function test_clear_matches_unresolved_rows_committed_before_a_later_batch(): void
    {
        $this->pushedUuid(new BasicJob);
        $this->pushedUuid(new BasicJob);
        $this->pushedUuid(new BasicJob);
        $second = (int) DB::table('jobs')->orderBy('id')->skip(1)->value('id');
        $database = $this->app->make(PackageSession::class)->databaseConnectionFor('database');
        $pushed = false;

        // Before the selection of the second batch, a bulk push commits its
        // jobs and its unresolved mirror rows. orrery:clear reads on the
        // operator session, so the check sees every package session of
        // the queue's database.
        PackageStatements::beforeEach($this->app, $database, function (string $sql, array $bindings) use (&$pushed, $second): void {
            $sql = strtolower($sql);

            // The selection (with its limit) after the second job; the lock of a batch has no limit.
            if ($pushed || ! str_contains($sql, 'reserved_at') || ! str_contains($sql, 'limit') || ! in_array($second, array_map('intval', $bindings), true)) {
                return;
            }

            $pushed = true;
            $this->unresolvedBulk([new BasicJob, new BasicJob]);
        });

        ClearCommand::useBatchForTesting(2);
        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertTrue($pushed, 'the bulk push ran between the batches');
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, $this->mirrorCount());
        self::assertStringContainsString('Cleared 5 waiting job(s) and 5 mirror row(s), 2 of them unresolved bulk rows', $output);
    }

    /**
     * Rejects: a match on uuid alone (a row whose cycle has another generation is not the deleted job's row).
     */
    public function test_clear_keeps_a_row_of_another_generation(): void
    {
        [$kept, $deleted] = $this->unresolvedBulk([new BasicJob, new BasicJob]);
        $this->mirror()->table('orrery_jobs')->where('uuid', $kept)->update(['cycle_gen' => str_repeat('a', 32)]);

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame('pending', $this->rowOrFail($kept)->status, 'the row of another generation stays');
        self::assertNull($this->row($deleted));
        self::assertStringContainsString('Cleared 2 waiting job(s) and 1 mirror row(s), 1 of them unresolved bulk rows', $output);
    }

    /**
     * Rejects: a mirror delete by uuid and generation without the re-checked predicate (job_id IS NULL, unresolved) in the mirror transaction.
     */
    public function test_clear_keeps_a_row_that_resolved_meanwhile(): void
    {
        [$resolved, $deleted] = $this->unresolvedBulk([new BasicJob, new BasicJob]);
        $done = false;

        // Between the probe and the mirror transaction, the row gets a job_id (here one that the clear does not delete).
        DB::connection()->beforeExecuting(function (string $sql) use (&$done, $resolved): void {
            if ($done || ! self::selectsPayload($sql)) {
                return;
            }

            $done = true;
            $this->mirror()->table('orrery_jobs')->where('uuid', $resolved)->update(['job_id' => 999999, 'job_id_unresolved' => false]);
        });

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertTrue($done, 'the row resolved after the probe');
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame('pending', $this->rowOrFail($resolved)->status, 'the resolved row stays');
        self::assertSame(999999, (int) $this->rowOrFail($resolved)->job_id);
        self::assertNull($this->row($deleted));
        self::assertStringContainsString('Cleared 2 waiting job(s) and 1 mirror row(s), 1 of them unresolved bulk rows', $output);
    }

    /**
     * Rejects: deleting by the unresolved set of the connection without the deleted ids (a reserved job keeps its row).
     */
    public function test_clear_keeps_unresolved_rows_of_reserved_jobs(): void
    {
        [$reserved, $waiting] = $this->unresolvedBulk([new BasicJob, new BasicJob]);
        $id = (int) DB::table('jobs')->orderBy('id')->value('id');
        DB::table('jobs')->where('id', $id)->update(['reserved_at' => time(), 'attempts' => 1]);

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame([$id], DB::table('jobs')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        self::assertSame('pending', $this->rowOrFail($reserved)->status, 'the row of the reserved job stays');
        self::assertTrue((bool) $this->rowOrFail($reserved)->job_id_unresolved);
        self::assertNull($this->row($waiting));
        self::assertStringContainsString('Cleared 1 waiting job(s) and 1 mirror row(s), 1 of them unresolved bulk rows', $output);
    }

    /**
     * Rejects: reading the payloads of every batch, also without unresolved rows.
     */
    public function test_clear_reads_no_payload_without_unresolved_rows(): void
    {
        $this->pushedUuid(new BasicJob);
        $this->pushedUuid(new BasicJob);
        $this->pushedUuid(new BasicJob);
        $statements = $this->recordStatements();

        ClearCommand::useBatchForTesting(2);
        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, $this->mirrorCount());
        self::assertNotSame([], $statements(), 'the recorder saw the run');
        self::assertSame([], array_values(array_filter($statements(), self::selectsPayload(...))), 'no statement selects payload');
        self::assertStringContainsString('Cleared 3 waiting job(s) and 3 mirror row(s), 0 of them unresolved bulk rows', $output);
    }

    /**
     * Rejects: a payload that cannot be decoded failing the command, or its row deleted without a match.
     */
    public function test_clear_warns_about_payloads_that_cannot_be_decoded(): void
    {
        [$broken, $deleted] = $this->unresolvedBulk([new BasicJob, new BasicJob]);
        DB::table('jobs')->orderBy('id')->limit(1)->update(['payload' => 'not json']);

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame('pending', $this->rowOrFail($broken)->status, 'its unresolved row stays');
        self::assertNull($this->row($deleted));
        self::assertStringContainsString('1 of them unresolved bulk rows', $output);
        self::assertStringContainsString('1 payload(s) could not be decoded', $output);
    }

    /**
     * Rejects: an exit 0 when the probe alone failed, and the reconciliation message for it; and a
     * batch deleted although its unresolved rows could not be checked: those rows can be matched only through
     * the payloads of jobs that are still queued, so "run orrery:clear again" could not work. The batch stays,
     * and a later clear with a working probe removes the jobs and their unresolved rows.
     * Still passes: a clear that also keeps the batches before the one whose probe failed (one batch here).
     */
    public function test_clear_exits_non_zero_when_only_the_probe_fails(): void
    {
        [$first] = $this->unresolvedBulk([new BasicJob, new BasicJob]);
        Watcher::failOperationsForTesting(['clear:mirror_probe']);

        self::assertSame(1, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        $output = Artisan::output();

        self::assertSame(2, DB::table('jobs')->count(), 'the waiting jobs were kept');
        self::assertSame('pending', $this->rowOrFail($first)->status, 'the unresolved rows were not checked');
        self::assertStringContainsString('The unresolved bulk mirror rows could not be checked', $output);
        self::assertStringContainsString('Run orrery:clear again', $output);
        self::assertStringNotContainsString('Reconciliation marks them unknown later', $output);

        Watcher::failOperationsForTesting([]);
        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        self::assertSame(0, DB::table('jobs')->count());
        self::assertNull($this->row($first), 'the second clear removed the unresolved row');
    }

    /**
     * Rejects: a clear that fails at once when SQLite answers SQLITE_BUSY to its batch: in WAL, a
     * deferred transaction that read first cannot write after another process committed. The batch runs again
     * (at most BUSY_ATTEMPTS times in all), and a busy answer to every attempt still fails the command.
     * Still passes: a retry that also repeats a batch after another error code (none is raised here).
     */
    public function test_a_busy_batch_runs_again(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('SQLITE_BUSY is a SQLite answer; a server engine never repeats the batch.');
        }

        $this->pushedUuid(new BasicJob);
        $this->pushedUuid(new BasicJob);
        $busy = 1;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$busy): void {
            if ($busy > 0 && preg_match('/^delete from "\w*jobs"/i', $sql) === 1) {
                $busy--;
                $pdo = new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
                $pdo->errorInfo = ['HY000', 5, 'database is locked'];

                throw new \Illuminate\Database\QueryException('testbench', $sql, [], $pdo);
            }
        });

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));
        self::assertSame(0, $busy);
        self::assertSame(0, DB::table('jobs')->count());

        $this->pushedUuid(new BasicJob);
        $busy = ClearCommand::BUSY_ATTEMPTS;
        $code = null;

        try {
            $code = Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]);
        } catch (\Illuminate\Database\QueryException) {
            // The command lets an error of the queue connection through (exit 1 on the console).
        }

        self::assertNotSame(0, $code);
        self::assertSame(0, $busy, 'every attempt ran');
        self::assertSame(1, DB::table('jobs')->count());
    }

    /**
     * On SQLite without WAL, the probe runs on the mirror session before the batch's transaction, so it never holds a
     * shared lock beside the queue transaction (a worker's commit would wait for the busy timeout). On MySQL and
     * Postgres it runs inside the transaction, after the lock.
     *
     * Rejects: a probe inside the queue transaction on SQLite, and a probe moved out of the transaction on a server engine.
     */
    public function test_probe_runs_outside_the_queue_transaction_on_sqlite_only(): void
    {
        $this->unresolvedBulk([new BasicJob, new BasicJob]);
        $queue = DB::connection();
        $levels = [];
        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(static function (string $sql) use ($queue, &$levels): void {
            if (str_contains(strtolower($sql), 'min(') && str_contains($sql, 'bulk_floor_id')) {
                $levels[] = $queue->transactionLevel();
            }
        });

        self::assertSame(0, Artisan::call('orrery:clear', ['connection' => 'database', '--force' => true]));

        $sqlite = $queue->getDriverName() === 'sqlite' && strtolower((string) array_values((array) $queue->selectOne('PRAGMA journal_mode'))[0]) !== 'wal';
        self::assertSame([$sqlite ? 0 : 1], $levels, 'one probe, at the expected transaction level');
        self::assertSame(0, $this->mirrorCount(), 'the unresolved rows were matched');
    }

    /**
     * A bulk push on the queue connection `database` whose mirror rows stay
     * unresolved (the bulk resolution fails). Returns the uuids in job order.
     *
     * @param  list<object>  $jobs
     * @return list<string>
     */
    private function unresolvedBulk(array $jobs): array
    {
        $before = (int) DB::table('jobs')->max('id');
        Watcher::failOperationsForTesting(['bulk:resolution']);

        try {
            Queue::bulk($jobs);
        } finally {
            Watcher::failOperationsForTesting([]);
        }

        $uuids = DB::table('jobs')->where('id', '>', $before)->orderBy('id')->pluck('payload')->map(static fn (mixed $payload): string => self::uuidOf((string) $payload))->all();
        self::assertCount(count($jobs), $uuids);

        foreach ($uuids as $uuid) {
            $row = $this->rowOrFail($uuid);
            self::assertTrue((bool) $row->job_id_unresolved, 'the bulk row is unresolved');
            self::assertNull($row->job_id);
        }

        return $uuids;
    }

    /**
     * Records the SQL of every statement on the queue connection, the
     * observer session and the mirror sessions, from now on.
     *
     * @return \Closure(): list<string>
     */
    private function recordStatements(): \Closure
    {
        $statements = new \ArrayObject;
        $record = static function (string $sql) use ($statements): void {
            $statements[] = $sql;
        };
        $sessions = $this->app->make(PackageSession::class);

        foreach ([DB::connection(), $sessions->for($sessions->databaseConnectionFor('database')), Container::getInstance()->make(PackageSession::class)->mirror()] as $connection) {
            $connection->beforeExecuting($record);
        }

        return static fn (): array => array_values($statements->getArrayCopy());
    }

    private static function selectsPayload(string $sql): bool
    {
        $sql = strtolower($sql);

        return str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'payload');
    }

    private function pushedUuid(object $job, int $delay = 0): string
    {
        $id = $delay > 0 ? Queue::later($delay, $job) : Queue::push($job);

        return self::uuidOf((string) DB::table('jobs')->where('id', $id)->value('payload'));
    }
}
