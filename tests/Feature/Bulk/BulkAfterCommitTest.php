<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Bulk;

use ArrayObject;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\DatabaseQueue as OrreryDatabaseQueue;
use Justrau\Orrery\Queue\BulkCapture;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Tests\Feature\Bulk\Fixtures\RecordingBulkResolver;
use Justrau\Orrery\Tests\Feature\Bulk\Jobs\ClosureJob;
use Justrau\Orrery\Tests\Feature\Bulk\Jobs\NestedBulkJob;
use Justrau\Orrery\Tests\Feature\Bulk\Jobs\NestingAfterCommitJob;
use Justrau\Orrery\Tests\Feature\Bulk\Jobs\UnserializableJob;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * One bulk capture path for Laravel 12 and 13.
 *
 * The swapped bulk() calls parent::bulk() unchanged. Each call owns one
 * capture: the records that buildDatabaseRecord() builds during the call
 * are observed after the call; the payloads that the call classified but
 * did not build (Laravel 13's after-commit jobs) are expected by a pending
 * capture, whose flush runs right after Laravel's own after-commit insert,
 * with the C3 capture taken at the first deferred record.
 *
 * Each case asserts the rows, the P2 observations (the observe() calls and
 * their payloads), the history rows and the package statements (the
 * inserts into orrery_jobs), because an insertOrIgnore hides a double
 * observation in the final rows. The after-commit cases need a Laravel
 * release whose DatabaseQueue has partitionJobsByAfterCommit(); on another
 * release they skip with that reason.
 */
#[FreshSchema]
final class BulkAfterCommitTest extends BulkTestCase
{
    private const OTHER = 'hooks_other';

    private RecordingBulkResolver $resolver;

    /** @var ArrayObject<int, string> */
    private ArrayObject $mirrorInserts;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Another connection with its own PDO. On SQLite it is its own
        // in-memory database, so its transaction never locks Q's file.
        $config = $app['config'];
        $other = (array) $config->get('database.connections.'.self::Q);

        if (($other['driver'] ?? null) === 'sqlite') {
            $other['database'] = ':memory:';
            $other['url'] = null;
        }

        $config->set('database.connections.'.self::OTHER, $other);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = $this->app->make(RecordingBulkResolver::class);
        $this->app->instance(BulkResolver::class, $this->resolver);

        $inserts = new ArrayObject;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($inserts): void {
            $sql = strtolower(ltrim($event->sql));

            if (str_starts_with($sql, 'insert') && str_contains($sql, 'orrery_jobs')) {
                $inserts[] = $event->sql;
            }
        });
        $this->mirrorInserts = $inserts;
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $other = $this->app['db']->getConnections()[self::OTHER] ?? null;

            if ($other instanceof Connection && $other->transactionLevel() > 0) {
                $other->rollBack(0);
            }
        }

        parent::tearDown();
    }

    /**
     * New behaviour (preservation of the outcome of the deleted adapter):
     * the deferred insert is observed at the commit, after Laravel's insert.
     *
     * Rejects: observing the deferred insert at the bulk's return.
     */
    public function test_after_commit_bulk_inside_a_q_transaction_commit(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();

        $q->beginTransaction();
        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);

        self::assertSame([], $this->qJobs(), 'Laravel defers the insert to the commit.');
        self::assertSame([], $this->resolver->observed, 'Nothing is observed at the bulk\'s return.');
        self::assertSame(0, $this->mirrorCount());

        $q->commit();

        self::assertCount(2, $this->qJobs(), 'The commit inserted both jobs.');
        $this->assertPendingParity('The deferred insert at the commit.');
        $this->assertObservedOnce([$this->qPayloads()]);
        self::assertSame(2, $this->bulkRows());
        self::assertCount(2, $this->mirrorInserts, 'One P2 insert per job, nothing more.');
        self::assertSame(0, $this->historyRows(), 'A P2 row writes no history row.');
        self::assertSame(0, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();
    }

    /**
     * Rejects: observing the deferred insert at the bulk's return (its rows
     * would exist, or be counted, although Laravel never inserted the jobs).
     */
    public function test_after_commit_bulk_inside_a_q_transaction_rollback(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();

        $q->beginTransaction();
        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);
        $q->rollBack();

        self::assertSame([], $this->qJobs(), 'Laravel discarded the after-commit jobs.');
        self::assertSame(0, $this->mirrorCount());
        self::assertSame([], $this->resolver->observed, 'No observation was built.');
        self::assertCount(0, $this->mirrorInserts);
        self::assertSame(0, $this->historyRows());
        self::assertSame(0, self::unverified(), 'A rolled-back bulk that inserted nothing is no drop.');
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();

        $this->endUnit();
        self::assertSame(0, self::unverified(), 'The end of the unit finds no pending capture.');
    }

    /**
     * Laravel attaches its insert to the newest pending transaction, here on
     * another connection. Its commit runs the insert on Q while Q is still
     * in its transaction, so the observation waits for Q's root.
     *
     * Rejects: running the deferred observation at once.
     */
    #[DataProvider('qOutcomes')]
    public function test_after_commit_bulk_triggered_by_another_connection(bool $commit): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $other = $this->other();

        $q->beginTransaction();
        $other->beginTransaction();
        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);
        $other->commit();

        self::assertSame(1, $q->transactionLevel());
        self::assertCount(2, $this->qJobs(), 'Laravel inserted the jobs inside Q\'s open transaction.');
        self::assertSame(0, $this->mirrorCount(), 'The observation waits for Q\'s root.');
        self::assertCount(1, $this->resolver->observed, 'The observation was built after the deferred insert.');
        self::assertSame(1, $this->deferral()->held(), 'One deferred item waits for Q.');

        if ($commit) {
            $q->commit();
            $this->assertPendingParity('Q committed.');
            self::assertSame(2, $this->bulkRows());
            self::assertCount(2, $this->mirrorInserts);
            self::assertSame(0, self::rollbackUnverified());
        } else {
            $q->rollBack();
            self::assertSame([], $this->qJobs(), 'Q\'s rollback removed the inserted jobs.');
            self::assertSame(0, $this->mirrorCount());
            self::assertCount(0, $this->mirrorInserts);
            self::assertSame(1, self::rollbackUnverified(), 'The bulk item under the rolled-back root is counted once.');
        }

        $this->assertObservedOnce([$this->payloadsOf($this->resolver->observed[0])]);
        self::assertSame(0, self::unverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function qOutcomes(): array
    {
        return [
            'Q commits' => [true],
            'Q rolls back' => [false],
        ];
    }

    /**
     * Rejects: double capture (a record of one insert in both observations).
     */
    #[DataProvider('mixedTransactions')]
    public function test_mixed_bulk_observes_both_inserts_once(bool $inTransaction): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $jobs = [new BasicJob, (new BasicJob)->afterCommit(), new BasicJob, (new BasicJob)->afterCommit()];

        if ($inTransaction) {
            $q->beginTransaction();
            $this->bulk($jobs);
            self::assertCount(2, $this->qJobs(), 'The immediate jobs are inserted during the call.');
            $q->commit();
        } else {
            $this->bulk($jobs);
        }

        self::assertCount(4, $this->qJobs());
        $this->assertPendingParity('Both inserts.');
        self::assertSame(4, $this->bulkRows());
        self::assertCount(4, $this->mirrorInserts, 'One P2 insert per job.');
        self::assertSame(0, $this->historyRows());

        $observed = array_merge(...$this->resolver->observed);
        self::assertCount(4, $observed, 'Each payload is observed once.');
        self::assertEqualsCanonicalizing($this->qPayloads(), $observed);
        // In a transaction the two inserts are two observations; without one,
        // Laravel runs its callback during the call, so the call holds both.
        self::assertCount($inTransaction ? 2 : 1, $this->resolver->observed);
        self::assertSame(0, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function mixedTransactions(): array
    {
        return [
            'inside a Q transaction' => [true],
            'without a transaction' => [false],
        ];
    }

    /**
     * Two deferred bulks wait on two roots: A's on Q's root, B's on a root
     * of another connection. B's root commits first, so B's records are
     * built first.
     *
     * Rejects: a FIFO or a single queue-wide buffer, which gives B's records
     * to A.
     */
    public function test_two_deferred_bulks_on_two_roots_take_their_own_records(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $other = $this->other();

        $q->beginTransaction();
        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);
        $other->beginTransaction();
        $this->bulk([(new BasicJob)->afterCommit()]);

        $other->commit();
        $bPayloads = $this->qPayloads();
        self::assertCount(1, $bPayloads, 'The second root committed first and inserted B\'s job.');
        self::assertSame([$bPayloads], $this->resolver->observed, 'B\'s observation holds exactly B\'s record.');

        $q->commit();

        self::assertCount(3, $this->qJobs());
        $this->assertPendingParity('Each bulk observed its own records.');
        $aPayloads = array_values(array_diff($this->qPayloads(), $bPayloads));
        self::assertCount(2, $aPayloads);
        $this->assertObservedOnce([$bPayloads, $aPayloads]);
        self::assertCount(3, $this->mirrorInserts);
        self::assertSame(0, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * An after-commit push before the bulk is inserted by its own callback,
     * during the same commit walk and before the bulk's insert.
     *
     * Rejects: capturing every record built outside a capture (the push
     * would be a P2 row of the bulk too).
     */
    public function test_ordinary_push_before_an_after_commit_bulk_is_not_captured(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();

        $q->beginTransaction();
        $this->hooks()->push((new BasicJob)->afterCommit());
        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);
        self::assertSame([], $this->qJobs());
        $q->commit();

        $jobs = $this->qJobs();
        self::assertCount(3, $jobs);
        $this->assertPendingParity('The push and the bulk.');

        $push = $this->rowOrFail(self::uuidOf((string) $jobs[0]->payload));
        self::assertNull($push->bulk_floor_id, 'The push is a P1 row.');
        self::assertSame('push', $push->cycle_source);
        self::assertSame(2, $this->bulkRows());
        $this->assertObservedOnce([[(string) $jobs[1]->payload, (string) $jobs[2]->payload]]);
        self::assertCount(3, $this->mirrorInserts, 'One P1 insert and two P2 inserts.');
        self::assertSame(0, self::unverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * A QueryExecuted listener on the outer deferred insert runs a nested
     * bulk. The nested bulk takes its own record, and the outer flush still
     * has the outer records.
     *
     * Rejects: the outer records cleared by the nested bulk.
     */
    public function test_nested_bulk_during_an_outer_deferred_insert(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $nested = false;

        $q->beginTransaction();
        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$nested): void {
            if (! $nested && $event->connectionName === self::Q && self::isJobsInsert($event->sql)) {
                $nested = true;
                $this->bulk([new BasicJob], 'nested');
            }
        });

        $q->commit();

        self::assertTrue($nested);
        $jobs = $this->qJobs();
        self::assertCount(3, $jobs);
        $this->assertPendingParity('The outer and the nested bulk.');
        $outer = array_values(array_map(static fn (object $job): string => (string) $job->payload, array_filter($jobs, static fn (object $job): bool => $job->queue === 'default')));
        $inner = array_values(array_map(static fn (object $job): string => (string) $job->payload, array_filter($jobs, static fn (object $job): bool => $job->queue === 'nested')));
        self::assertCount(2, $outer);
        self::assertCount(1, $inner);
        $this->assertObservedOnce([$inner, $outer]);
        self::assertCount(3, $this->mirrorInserts);
        self::assertSame(0, self::unverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * Laravel's after-commit callback builds the records, then its insert
     * throws, so the walk stops and the flush never runs. The end of the
     * unit drops the pending capture and counts it.
     *
     * Rejects: stale records in a later observation, and a pending capture
     * that is never counted.
     */
    public function test_throwing_after_commit_callback_counts_the_deferred_records(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $error = new RuntimeException('deferred insert refused');
        $armed = false;

        $q->beginTransaction();
        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);
        $armed = true;

        $q->beforeExecuting(static function (string $query) use (&$armed, $error): void {
            if ($armed && self::isJobsInsert($query)) {
                $armed = false;

                throw $error;
            }
        });

        try {
            $q->commit();
            self::fail('The insert error leaves the commit.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught, 'The caller gets the same exception object.');
        }

        self::assertSame([], $this->qJobs());
        self::assertSame(0, $this->mirrorCount());
        self::assertSame([], $this->resolver->observed);
        self::assertSame(0, self::unverified(), 'Nothing is counted before the end of the unit.');

        $this->endUnit();

        self::assertSame(1, self::unverified(), 'The end of the unit counts the pending capture once.');

        $this->bulk([new BasicJob]);

        self::assertCount(1, $this->qJobs());
        $this->assertPendingParity('A later bulk.');
        $this->assertObservedOnce([$this->qPayloads()]);
        self::assertCount(1, $this->mirrorInserts, 'The later observation holds no stale record.');
        self::assertSame(1, self::unverified(), 'The later bulk adds no count.');
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * A bulk inside a nested unit of work (a sync job or an Artisan command)
     * that runs inside the caller's transaction: its after-commit insert
     * waits on a savepoint that the unit created and committed, so it
     * belongs to the caller's root. The end of the nested unit keeps the
     * pending capture, and the rows appear at the caller's commit.
     *
     * Rejects: a nested unit that drops the caller's pending capture
     * because its record (a committed savepoint) was not pending at the
     * unit's start.
     */
    #[DataProvider('nestedUnits')]
    public function test_bulk_in_a_nested_unit_waits_for_the_callers_root(string $unit): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $hooks = $this->hooks();
        // No $this: Artisan binds a command closure to the command.
        $bulk = function () use ($q, $hooks): void {
            $q->transaction(static function () use ($hooks): void {
                $hooks->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()], '', 'default');
            });
        };

        $q->beginTransaction();

        if ($unit === 'dispatchSync') {
            ClosureJob::$during = $bulk;
            ClosureJob::dispatchSync();
            self::assertNull(ClosureJob::$during, 'The sync job ran the bulk.');
        } else {
            // As artisan does: the command runs in its own unit of work.
            $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
            $finished = 0;
            $this->app['events']->listen(CommandFinished::class, static function () use (&$finished): void {
                $finished++;
            });
            Artisan::command('orrery-c8:bulk', $bulk);
            self::assertSame(0, Artisan::call('orrery-c8:bulk'));
            self::assertSame(1, $finished, 'The command ended its unit.');
        }

        self::assertSame(1, $q->transactionLevel());
        self::assertSame([], $this->qJobs(), 'The insert waits for the caller\'s commit.');
        self::assertSame(1, BulkCapture::pendingCount(), 'The end of the nested unit kept the capture.');
        self::assertSame(0, self::unverified());

        $q->commit();

        self::assertCount(2, $this->qJobs());
        $this->assertPendingParity('The rows appear at the caller\'s commit.');
        $this->assertObservedOnce([$this->qPayloads()]);
        self::assertSame(0, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nestedUnits(): array
    {
        return [
            'dispatchSync' => ['dispatchSync'],
            'Artisan::call' => ['Artisan::call'],
        ];
    }

    /**
     * The bulk's classification pushes an after-commit job: the bulk
     * classified its payload but never built it, so the capture becomes
     * pending; the push's own pushToDatabase() builds it at the commit.
     * The flush then finds no expected identity left and counts nothing.
     *
     * Rejects: a flush that counts a capture whose every identity was built
     * by its own push.
     */
    public function test_after_commit_push_from_a_classification_is_not_counted(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->bulk([new NestingAfterCommitJob]);
        $q->commit();

        $jobs = $this->qJobs();
        self::assertCount(2, $jobs, 'The bulk job and the pushed job.');
        $this->assertPendingParity('The bulk and the push.');
        self::assertSame(1, $this->bulkRows(), 'The bulk job is a P2 row.');
        self::assertCount(1, $this->resolver->observed);
        self::assertSame(0, self::unverified(), 'Nothing was dropped.');
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * As above, but an application callback between the push's insert and
     * the bulk's flush throws, so the walk stops and the end of the unit
     * drops the pending capture. Its only expected identity was built by its
     * own push, so the drop counts nothing; the bulk's own record (held by
     * the deferral's root, whose callback never ran) is counted once.
     *
     * Rejects: a drop that counts a capture whose every identity was built.
     */
    public function test_dropped_capture_without_expected_identities_counts_nothing(): void
    {
        $q = $this->q();
        $error = new RuntimeException('application callback failed');

        $q->beginTransaction();
        $this->bulk([new NestingAfterCommitJob]);
        $record = $this->record();
        \Closure::bind(static function () use ($record, $error): void {
            array_splice($record->callbacks, 1, 0, [static function () use ($error): void {
                throw $error;
            }]);
        }, null, $record::class)();

        try {
            $q->commit();
            self::fail('The application callback error leaves the commit.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }

        self::assertCount(2, $this->qJobs(), 'The bulk job and the pushed job are inserted.');
        self::assertSame(1, BulkCapture::pendingCount(), 'The flush never ran.');

        $this->endUnit();

        self::assertSame(0, BulkCapture::pendingCount());
        self::assertSame(1, self::unverified(), 'Only the bulk record held by the stopped root is counted.');
        $this->assertNothingDeferred();
    }

    /**
     * The parent throws after it built records (Laravel 13: after the
     * immediate insert, an after-commit payload cannot be created; Laravel
     * 12: the second payload throws before the insert). The built records
     * are not observed, so the bulk is counted once.
     *
     * Rejects: a parent error after built records that is not counted.
     */
    public function test_parent_error_after_built_records_is_counted(): void
    {
        $partitioned = method_exists(LaravelDatabaseQueue::class, 'partitionJobsByAfterCommit');

        try {
            $this->bulk([new BasicJob, (new UnserializableJob)->afterCommit()]);
            self::fail('The payload error leaves bulk().');
        } catch (\Exception $caught) {
            self::assertStringContainsString('Closure', $caught->getMessage());
        }

        self::assertCount($partitioned ? 1 : 0, $this->qJobs(), 'Laravel 13 inserted the immediate job before the error.');
        self::assertSame(0, $this->mirrorCount());
        self::assertSame(1, self::unverified(), 'The bulk is counted once.');
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * The parent throws before it builds any record (the first payload
     * cannot be created): nothing was inserted, so nothing is counted.
     *
     * Rejects: a count on every parent error.
     */
    public function test_parent_error_before_any_record_counts_nothing(): void
    {
        try {
            $this->bulk([new UnserializableJob, new BasicJob]);
            self::fail('The payload error leaves bulk().');
        } catch (\Exception $caught) {
            self::assertStringContainsString('Closure', $caught->getMessage());
        }

        self::assertSame([], $this->qJobs());
        self::assertSame(0, $this->mirrorCount());
        self::assertSame(0, self::unverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * More than BulkCapture::MAX_PENDING pending captures: the oldest is
     * dropped and counted once. Laravel still inserts its job at the
     * commit, unobserved (R7 creates its row at the reservation); the
     * others are observed.
     *
     * Rejects: an unbounded list of pending captures, and a dropped capture
     * that its later flush still observes or counts again.
     */
    public function test_more_pending_captures_than_the_limit_drop_the_oldest(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();

        $q->beginTransaction();

        for ($i = 0; $i <= BulkCapture::MAX_PENDING; $i++) {
            $this->bulk([(new BasicJob)->afterCommit()]);
        }

        self::assertSame(BulkCapture::MAX_PENDING, BulkCapture::pendingCount(), 'The oldest pending capture was dropped.');
        self::assertSame(1, self::unverified(), 'The dropped capture is counted once.');

        $q->commit();

        $jobs = $this->qJobs();
        self::assertCount(BulkCapture::MAX_PENDING + 1, $jobs, 'Laravel inserted every job.');
        self::assertNull($this->row(self::uuidOf((string) $jobs[0]->payload)), 'The oldest bulk is not observed.');
        self::assertSame(BulkCapture::MAX_PENDING, $this->bulkRows());
        self::assertCount(BulkCapture::MAX_PENDING, $this->resolver->observed);
        self::assertSame(0, BulkCapture::pendingCount());
        self::assertSame(1, self::unverified(), 'The dropped capture is not counted again at its flush.');
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * Contract (every leg): the swapped bulk() returns what Laravel's own
     * bulk() returns for the same jobs.
     *
     * Rejects: a bulk() that returns its own value (for example the
     * observation, or always true).
     */
    public function test_bulk_returns_the_parents_value(): void
    {
        $native = new LaravelDatabaseQueue($this->q(), 'jobs', 'default', 90, false);
        $native->setContainer($this->app);
        $native->setConnectionName(self::QUEUE);
        $q = $this->q();

        $sets = ['immediate' => static fn (): array => [new BasicJob, new BasicJob], 'empty' => static fn (): array => []];

        if (method_exists(LaravelDatabaseQueue::class, 'partitionJobsByAfterCommit')) {
            $sets['after commit'] = static fn (): array => [(new BasicJob)->afterCommit()];
            $sets['mixed'] = static fn (): array => [new BasicJob, (new BasicJob)->afterCommit()];
        }

        foreach ($sets as $name => $jobs) {
            $q->beginTransaction();
            $expected = $native->bulk($jobs(), '', 'default');
            $actual = $this->bulk($jobs());
            $q->rollBack();

            self::assertSame($expected, $actual, "The {$name} bulk returns the parent's value.");
        }

        // An insert that returns false (on every Laravel line).
        $falsy = $this->falseInsertConnection();
        $nativeFalse = new LaravelDatabaseQueue($falsy, 'jobs', 'default', 90, false);
        $nativeFalse->setContainer($this->app);
        $nativeFalse->setConnectionName(self::QUEUE);
        $ours = new OrreryDatabaseQueue($falsy, 'jobs', 'default', 90, false);
        $ours->setContainer($this->app);
        $ours->setConnectionName(self::QUEUE);

        self::assertFalse($nativeFalse->bulk([new BasicJob], '', 'default'), 'The parent returns the insert\'s false.');
        self::assertFalse($ours->bulk([new BasicJob], '', 'default'), 'The swapped bulk returns the parent\'s false.');

        // The parent throws: the caller gets the same exception object.
        $error = new RuntimeException('bulk insert refused');
        $armed = true;
        $q->beforeExecuting(static function (string $query) use (&$armed, $error): void {
            if ($armed && self::isJobsInsert($query)) {
                $armed = false;

                throw $error;
            }
        });

        try {
            $this->bulk([new BasicJob]);
            self::fail('The insert error leaves bulk().');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught, 'The caller gets the same exception object.');
        }

        $this->assertNothingDeferred();
    }

    /**
     * A connection on Q's PDO whose insert() runs the statement and returns
     * false.
     */
    private function falseInsertConnection(): Connection
    {
        $q = $this->q();
        $connection = new class($q->getPdo(), $q->getDatabaseName(), $q->getTablePrefix(), $q->getConfig()) extends Connection
        {
            public function insert($query, $bindings = []): bool
            {
                parent::insert($query, $bindings);

                return false;
            }
        };
        $connection->setQueryGrammar($q->getQueryGrammar());
        $connection->setPostProcessor($q->getPostProcessor());
        $connection->setEventDispatcher($this->app['events']);
        $connection->setTransactionManager($this->app['db.transactions']);

        return $connection;
    }

    /**
     * A QueryExecuted listener on the deferred INSERT changes Q's
     * transactions. The deferred insert has its own capture, taken at its
     * first record, so each case is a counted drop.
     *
     * Rejects: the level read at flush time, which runs the
     * observation at once.
     */
    #[DataProvider('listenerCases')]
    public function test_deferred_insert_listener_cases(string $case): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $other = $this->other();
        $fired = false;

        if ($case === 'born and ended around the insert') {
            // Q's root commits; its walk runs the insert at level 0, and the
            // application begins T before the INSERT and commits it after.
            $q->beginTransaction();
            $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);

            $q->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use (&$fired): void {
                if (! $fired && self::isJobsInsert($query) && $connection->transactionLevel() === 0) {
                    $fired = true;
                    $connection->beginTransaction();
                }
            });
            $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$fired): void {
                if ($fired && $event->connectionName === self::Q && self::isJobsInsert($event->sql) && $event->connection->transactionLevel() === 1) {
                    $event->connection->commit();
                }
            });

            $q->commit();

            self::assertTrue($fired);
            self::assertSame(0, $q->transactionLevel());
            self::assertCount(2, $this->qJobs(), 'T committed the insert.');
        } else {
            // Laravel attaches its insert to the other connection's newest
            // record; its commit runs the insert inside Q's open root.
            $q->beginTransaction();
            $other->beginTransaction();
            $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);

            $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$fired, $case): void {
                if (! $fired && $event->connectionName === self::Q && self::isJobsInsert($event->sql)) {
                    $fired = true;
                    $event->connection->rollBack();

                    if ($case === 'rolls back and replaces the root') {
                        $event->connection->beginTransaction();
                    }
                }
            });

            $other->commit();

            self::assertTrue($fired);
            self::assertSame($case === 'rolls back and replaces the root' ? 1 : 0, $q->transactionLevel());
            self::assertSame([], $this->qJobs(), 'The rollback removed the deferred insert.');

            if ($q->transactionLevel() > 0) {
                $q->rollBack();
            }
        }

        self::assertSame(0, $this->mirrorCount(), 'No mirror row from the deferred records.');
        self::assertCount(0, $this->mirrorInserts);
        self::assertCount(1, $this->resolver->observed, 'The observation was built once.');
        self::assertSame(1, self::unverified(), 'The deferred insert is counted once.');
        self::assertSame(0, self::rollbackUnverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listenerCases(): array
    {
        return [
            'rolls back the root' => ['rolls back the root'],
            'rolls back and replaces the root' => ['rolls back and replaces the root'],
            'born and ended around the insert' => ['born and ended around the insert'],
        ];
    }

    /**
     * Preservation (ported from the deleted adapter test): the mixed jobs
     * in a transaction on Q or on another connection.
     *
     * Rejects: the lexical capture only, which misses the after-commit group.
     */
    #[DataProvider('transactions')]
    public function test_mixed_immediate_and_after_commit_jobs_in_transactions(string $on, bool $commit): void
    {
        $this->requireLaravel13Path();
        $connection = $on === 'Q' ? $this->q() : $this->other();

        $connection->beginTransaction();
        $this->bulk([new BasicJob, (new BasicJob)->afterCommit(), new BasicJob, (new BasicJob)->afterCommit()]);

        if ($on === 'another connection') {
            self::assertCount(2, $this->qJobs(), 'The immediate jobs are inserted on Q at once.');
            $this->assertPendingParity('The immediate jobs, while the other transaction is open.');
        }

        if ($commit) {
            $connection->commit();
        } else {
            $connection->rollBack();
        }

        $expected = match (true) {
            $commit => 4,
            $on === 'Q' => 0,
            default => 2,
        };

        self::assertCount($expected, $this->qJobs(), 'Laravel inserted the committed jobs.');
        $this->assertPendingParity("A transaction on {$on} that ".($commit ? 'commits.' : 'rolls back.'));
        self::assertSame($expected, $this->bulkRows());
        self::assertCount($expected, $this->mirrorInserts);
        self::assertSame(0, self::unverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function transactions(): array
    {
        return [
            'Q, commit' => ['Q', true],
            'Q, rollback' => ['Q', false],
            'another connection, commit' => ['another connection', true],
            'another connection, rollback' => ['another connection', false],
        ];
    }

    /**
     * Preservation (ported): the outer bulk classifies a job whose tags()
     * runs an inner after-commit bulk; a third bulk follows. Three inserts
     * wait for the commit.
     *
     * Rejects: one global capture buffer.
     */
    public function test_two_overlapping_bulk_scopes(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();

        $q->beginTransaction();
        $this->bulk([(new NestedBulkJob(self::QUEUE, 2))->afterCommit(), (new BasicJob)->afterCommit()]);
        $this->bulk([(new BasicJob)->afterCommit()]);
        self::assertSame([], $this->qJobs(), 'Every insert waits for the commit.');
        $q->commit();

        self::assertCount(5, $this->qJobs());
        $this->assertPendingParity('Each insertion callback observed its own records.');
        self::assertSame(5, $this->bulkRows());
        self::assertCount(3, $this->resolver->observed, 'Three deferred inserts, three observations.');
        self::assertCount(5, $this->mirrorInserts);
        self::assertSame(0, self::unverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * Class D (ported): the first bulk's insert runs
     * at T's commit, which a query listener of the second bulk's insert
     * runs. T, open before the second bulk's call, ends inside it: the
     * second bulk is a counted drop, and R7 creates its row at the
     * reservation.
     *
     * Rejects: a registry lookup that gives the records to the wrong bulk,
     * and an item of a root that ended inside the call run at once.
     */
    public function test_deferred_callback_during_another_bulk_call(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $committed = false;

        $q->beginTransaction();
        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$committed): void {
            if (! $committed && $event->connectionName === self::Q && self::isJobsInsert($event->sql) && $event->connection->transactionLevel() > 0) {
                $committed = true;
                $event->connection->commit();
            }
        });

        $this->bulk([new BasicJob]);

        self::assertTrue($committed);
        self::assertSame(0, $q->transactionLevel());
        $jobs = $this->qJobs();
        self::assertCount(3, $jobs);
        self::assertSame(2, $this->bulkRows(), "The first bulk's records have their rows.");

        foreach (array_slice($jobs, 1) as $job) {
            self::assertSame((int) $job->id, (int) $this->rowOrFail(self::uuidOf((string) $job->payload))->job_id, 'Each row has its own job id.');
        }

        self::assertNull($this->row(self::uuidOf((string) $jobs[0]->payload)), 'The second bulk is dropped.');
        self::assertSame(1, self::unverified(), 'The second bulk is counted once.');
        self::assertSame(0, self::rollbackUnverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();

        $this->workHooks(3);
        self::assertSame(3, $this->mirror()->table('orrery_jobs')->where('status', 'completed')->count(), 'R7 creates the dropped row at the reservation.');
    }

    /**
     * Class D (ported): Laravel runs its callback during the call (no
     * pending transaction), and the insert's query listener begins a Q
     * transaction. The item is ambiguous and T's rollback counts it.
     *
     * Rejects: a level read only before the call (the rows run at once),
     * and an ambiguous item counted as a plain rollback.
     */
    public function test_query_listener_transaction_during_an_after_commit_bulk(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $began = false;

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$began): void {
            if (! $began && $event->connectionName === self::Q && self::isJobsInsert($event->sql)) {
                $began = true;
                $event->connection->beginTransaction();
            }
        });

        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);

        self::assertTrue($began);
        self::assertSame(1, $q->transactionLevel(), 'The insert\'s query listener began a Q transaction.');

        $q->rollBack();

        self::assertCount(2, $this->qJobs(), 'The insert committed in autocommit, before the listener\'s transaction.');
        self::assertSame(0, $this->bulkRows(), 'The ambiguous bulk is dropped.');
        self::assertSame(1, self::unverified(), 'The ambiguous bulk is counted once.');
        self::assertSame(0, self::rollbackUnverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();

        $this->workHooks(2);
        self::assertSame(2, $this->mirror()->table('orrery_jobs')->where('status', 'completed')->count(), 'R7 creates the rows at the reservations.');
    }

    /**
     * Preservation (ported).
     *
     * Rejects: attaching the rows to a transaction on another connection.
     */
    public function test_after_commit_bulk_without_a_transaction_and_a_foreign_transaction(): void
    {
        $this->requireLaravel13Path();
        $other = $this->other();
        $began = false;

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$began, $other): void {
            if (! $began && $event->connectionName === self::Q && self::isJobsInsert($event->sql)) {
                $began = true;
                $other->beginTransaction();
            }
        });

        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);

        self::assertTrue($began);
        self::assertSame(1, $other->transactionLevel(), 'The insert began a transaction on another connection.');
        $this->assertPendingParity('Before the other transaction ends.');

        $other->rollBack();

        self::assertCount(2, $this->qJobs());
        $this->assertPendingParity('The committed jobs keep their rows once.');
        self::assertSame(2, $this->bulkRows());
        self::assertCount(2, $this->mirrorInserts);
        self::assertSame(0, self::unverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * Preservation (ported): bulk() begins inside Q's transaction T, but
     * Laravel attaches the insert to the newest pending transaction, O on
     * another connection. T rolls back first; O's commit then runs the
     * insert on Q in autocommit.
     *
     * Rejects: attaching the bulk rows to the Q frame that was open when bulk() began.
     */
    public function test_after_commit_bulk_whose_entry_frame_rolls_back_before_its_insert(): void
    {
        $this->requireLaravel13Path();
        $q = $this->q();
        $other = $this->other();

        $q->beginTransaction();
        $other->beginTransaction();
        $this->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);
        $q->rollBack();
        self::assertSame([], $this->qJobs(), 'The insert waits for O.');

        $other->commit();

        self::assertCount(2, $this->qJobs(), 'O\'s commit inserted the jobs in autocommit.');
        $this->assertPendingParity('The rows belong to the insert\'s own statement, not to T.');
        self::assertSame(2, $this->bulkRows());
        self::assertSame(0, self::unverified());
        self::assertSame(0, $this->historyRows(), 'A bulk observation writes no history row.');
        $this->assertNothingDeferred();
    }

    /**
     * The observe() calls hold exactly these payload lists, in this order.
     *
     * @param  list<list<string>>  $expected
     */
    private function assertObservedOnce(array $expected): void
    {
        self::assertSame($expected, $this->resolver->observed, 'Each observation holds exactly its own records, once.');
    }

    /**
     * @param  list<string>  $payloads
     * @return list<string>
     */
    private function payloadsOf(array $payloads): array
    {
        return array_values($payloads);
    }

    /**
     * @return list<string>
     */
    private function qPayloads(): array
    {
        return array_values(array_map(static fn (object $job): string => (string) $job->payload, $this->qJobs()));
    }

    private function historyRows(): int
    {
        return $this->mirror()->table('orrery_job_history')->count();
    }

    private function other(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->app['db']->connection(self::OTHER);

        return $connection;
    }
}
