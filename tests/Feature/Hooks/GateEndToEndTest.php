<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The commit deferral end to end, with the swap on.
 * Real hooks,
 * real rows and Laravel's real transaction manager. The scenarios of the
 * removed transaction gate keep their names; the earlier inventory
 * gives each one's class (P, D or C).
 */
#[FreshSchema]
final class GateEndToEndTest extends EndToEndTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * P. Laravel 13 runs C's throwing rollback callback when it removes C,
     * so B's own rollback callback never runs: B's item has no mark and is
     * counted; on Laravel 12 B's rollback mark explains the drop.
     *
     * Rejects: a nested item decided from a missing rollback mark (run, or
     * dropped without a count).
     */
    public function test_unseen_committed_descendant_with_the_swapped_connector(): void
    {
        $q = $this->q();

        [$sibling, $b] = $this->deferTransactionEvents(function () use ($q): array {
            $q->beginTransaction();
            $q->beginTransaction();
            $sibling = $this->push(new BasicJob);
            $siblingUuid = $this->uuidOfJob($sibling);
            $q->commit();

            $q->beginTransaction();
            $b = $this->push(new BasicJob);
            $bUuid = $this->uuidOfJob($b);

            // C begins and commits between two touchpoints: the gate never sees it.
            $q->beginTransaction();
            $this->record()->addCallbackForRollback(static function (): void {
                throw new RuntimeException('c rollback callback failed');
            });
            $q->commit();

            try {
                $q->rollBack();
            } catch (RuntimeException $error) {
                // Laravel 13 runs C's callback; Laravel 12 removes C without it.
                self::assertSame('c rollback callback failed', $error->getMessage());
            }

            self::assertSame(1, $q->transactionLevel());
            $q->commit();

            return [$siblingUuid, $bUuid];
        });

        self::assertCount(1, $this->qJobs(), "The host rollback removed B's insert, as without the package.");
        self::assertNull($this->row($b), "No mirror row exists for B's job.");
        self::assertSame('pending', $this->rowOrFail($sibling)->status, 'The sibling that committed before B began runs once.');
        self::assertSame(1, $this->mirrorCount());
        self::assertSame($this->removingCommittedChildrenRunsRollbackCallbacks() ? 1 : 0, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();
    }

    /**
     * D. An application after-commit callback of C's savepoint throws
     * before the deferral's callbacks: the root's walk stops, and its own
     * commit callback never runs. The end of the unit counts every item of
     * the root, and R7 creates each committed job's row at its reservation.
     *
     * Rejects: a stopped walk whose items disappear without a count, and
     * items run from an unproved commit.
     */
    public function test_throwing_after_commit_callback_mirrors_committed_jobs_once(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $root = $this->push(new BasicJob);
        $q->beginTransaction();
        $complete = $this->push(new BasicJob);
        $q->commit();
        $q->beginTransaction();
        $rolledBack = $this->push(new BasicJob);
        $rolledBackUuid = $this->uuidOfJob($rolledBack);
        $q->rollBack();
        $q->beginTransaction();
        $b = $this->push(new BasicJob);
        $q->beginTransaction();
        $c = $this->push(new BasicJob);
        self::afterCommitBeforeGate($this->record(), static function (): void {
            throw new RuntimeException('nested after commit callback failed');
        });
        $q->commit();
        $q->commit();

        self::applicationError(static fn () => $q->commit(), 'nested after commit callback failed');

        self::assertSame(0, $this->mirrorCount(), 'Nothing runs from a walk that stopped.');
        $this->endUnit();

        self::assertSame(0, $this->mirrorCount());
        self::assertSame(5, self::unverified(), 'The end of the unit counts the five items of the stopped walk.');
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();

        // A later observation repairs each committed job: R7 at its reservation.
        foreach (['root' => $root, 'complete sibling' => $complete, 'b' => $b, 'c' => $c] as $label => $id) {
            $job = $this->popJob();
            self::assertSame($id, (int) $job->getJobId());
            self::assertSame('reserved', $this->rowOrFail($this->uuidOfJob($id))->status, "R7 creates the row of the committed job {$label}.");
        }

        self::assertNull($this->row($rolledBackUuid), 'The rolled-back job has none.');
        self::assertSame(4, $this->mirrorCount(), 'Each committed job has its row once.');
    }

    /**
     * P: the tail rule.
     *
     * Rejects: running B's release before A's reservation.
     */
    public function test_reservation_then_release_from_inside_the_root_walk(): void
    {
        $q = $this->q();
        $j = $this->push(new BasicJob);
        $uuid = $this->uuidOfJob($j);
        self::assertSame('pending', $this->rowOrFail($uuid)->status);

        /** @var DatabaseJob|null $held */
        $held = null;

        $q->beginTransaction();
        $q->beginTransaction();
        $this->record()->addCallback(static function () use (&$held): void {
            self::assertInstanceOf(DatabaseJob::class, $held);
            $held->release(0);
        });
        $q->commit();

        $held = $this->popJob();
        self::assertSame(1, $q->transactionLevel(), "pop()'s transaction N committed into A.");
        $q->commit();

        $jobs = $this->qJobs();
        self::assertCount(1, $jobs);
        $replacement = (int) $jobs[0]->id;
        self::assertNotSame($j, $replacement);

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'No stale reservation: the release ran after the reservation.');
        self::assertSame($replacement, (int) $row->job_id);
        self::assertSame($j, (int) $row->prev_job_id);
        self::assertSame(1, (int) $row->releases);
        self::assertSame(1, (int) $row->attempts);
        self::assertSame(0, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();
    }

    /**
     * D. B's rollback callback throws once, so B stays the parent of the
     * later savepoint C: Laravel stages C through the stale B (and, with
     * D's rollback, removes the committed C through it). C's item has no
     * proof of its commit: it is counted, and R7 repairs C's row at its
     * reservation.
     *
     * Rejects: a missing mark treated as a rollback (dropped silently), and
     * C's item run without a proof.
     */
    #[DataProvider('staleParents')]
    public function test_stale_parent_cleanup_counts_c_and_r7_repairs_it(bool $withD): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $a = $this->push(new BasicJob);
        $q->beginTransaction();
        $b = $this->push(new BasicJob);
        $bUuid = $this->uuidOfJob($b);
        $calls = 0;
        $this->record()->addCallbackForRollback(static function () use (&$calls): void {
            if ($calls++ === 0) {
                throw new RuntimeException('b rollback callback failed once');
            }
        });

        self::applicationError(static fn () => $q->rollBack(), 'b rollback callback failed once');

        $q->beginTransaction();
        $c = $this->push(new BasicJob);
        $cUuid = $this->uuidOfJob($c);
        $q->commit();

        $dUuid = null;

        if ($withD) {
            $q->beginTransaction();
            $d = $this->push(new BasicJob);
            $dUuid = $this->uuidOfJob($d);
            // Laravel walks through the stale B and removes the committed C.
            $q->rollBack();
            self::assertSame(1, $q->transactionLevel());
        }

        $q->commit();

        self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($a))->status);
        self::assertNull($this->row($bUuid), "B's push leaves none: B's rollback mark explains it.");
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();

        if ($dUuid === null) {
            self::assertSame('pending', $this->rowOrFail($cUuid)->status, "C's commit mark proves it: C's job gets its row once.");
            self::assertSame(2, $this->mirrorCount());
            self::assertSame(0, self::unverified());

            return;
        }

        self::assertNull($this->row($dUuid), "D's push leaves none.");
        self::assertNull($this->row($cUuid), 'C was removed through the stale B: its item has no proof.');
        self::assertSame(1, $this->mirrorCount());
        self::assertSame(2, self::unverified(), 'C and D are counted: no true chain of links explains either drop.');

        // A later observation repairs C: R7 at its reservation.
        $this->popJob();
        $job = $this->popJob();
        self::assertSame($c, (int) $job->getJobId());
        self::assertSame('reserved', $this->rowOrFail($cUuid)->status, "R7 creates C's row at the reservation.");
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function staleParents(): array
    {
        return [
            'A, B, C, D' => [true],
            'A, B, C' => [false],
        ];
    }

    /**
     * P: an autocommit item raised during the walk joins the walking root's
     * tail.
     *
     * Rejects: an autocommit observation that runs at once while a root of
     * the instance is in its walk.
     */
    public function test_autocommit_push_during_a_root_walk_runs_after_the_root(): void
    {
        $q = $this->q();
        $autocommit = null;

        $q->beginTransaction();
        $a = $this->push(new BasicJob);
        $q->beginTransaction();
        $s = $this->push(new BasicJob);
        $this->record()->addCallback(function () use (&$autocommit, $q): void {
            self::assertSame(0, $q->transactionLevel(), 'The walk runs after the commit.');
            $autocommit = $this->push(new BasicJob);
        });
        $q->commit();
        $q->commit();

        self::assertIsInt($autocommit);
        $order = $this->mirror()->table('orrery_jobs')->orderBy('id')->pluck('job_id')->map(static fn ($id): int => (int) $id)->all();
        self::assertSame([$a, $s, $autocommit], $order, "The autocommit push runs after the root's own observations.");
        self::assertSame(0, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();
    }

    /**
     * P. B's application rollback callback comes first and throws, so B
     * gets no rollback mark: u's push under B is counted. u's reservation
     * ran in pop()'s own savepoint under B: Laravel 13 runs the rollback
     * callbacks of B's removed committed children, so that savepoint's
     * rollback mark explains the drop; Laravel 12 removes it silently, so
     * the reservation is counted too.
     *
     * Rejects: an item of the rolled-back B that runs, and a row decided by
     * the reused id instead of its own observation.
     */
    #[DataProvider('reusedIds')]
    public function test_reused_id_after_a_rolled_back_push_and_pop(bool $sameUuid): void
    {
        if ($this->testEnvironment()->driver !== 'sqlite') {
            self::markTestSkipped('SQLite reuses the id of a rolled-back insert; MySQL and Postgres do not.');
        }

        $q = $this->q();
        $u = strtolower((string) Str::uuid());
        $v = $sameUuid ? $u : strtolower((string) Str::uuid());

        $q->beginTransaction();
        $q->beginTransaction();
        // B's rollback hides itself: an application rollback callback before the gate's throws.
        self::rollbackCallbackBeforeGate($this->record(), static function (): void {
            throw new RuntimeException('b rollback callback failed');
        });
        $jU = (int) $this->hooks()->pushRaw(self::rawPayload($u, ['data' => []]));
        $this->popJob();
        self::applicationError(static fn () => $q->rollBack(), 'b rollback callback failed');
        self::assertSame(1, $q->transactionLevel());

        $jV = (int) $this->hooks()->pushRaw(self::rawPayload($v, ['data' => []]));
        self::assertSame($jU, $jV, 'SQLite reused the id of the rolled-back insert.');
        $this->popJob();
        $q->commit();

        $committed = $this->qJob($jV);
        self::assertNotNull($committed);
        $row = $this->rowOrFail($v);
        self::assertSame('reserved', $row->status, "v's row is reserved.");
        self::assertSame($committed->payload, $row->payload, "The row carries v's payload.");
        self::assertSame(json_decode((string) $committed->payload, true)['orreryGen'], $row->cycle_gen, "The row carries v's generation.");
        self::assertSame($jV, (int) $row->job_id);
        self::assertSame(ProcessContext::current()->identity(), $row->reserved_by, "v's verified reservation names its worker.");

        if (! $sameUuid) {
            self::assertNull($this->row($u), 'u never reaches the mirror.');
        }

        self::assertSame(1, $this->mirrorCount());
        self::assertSame($this->removingCommittedChildrenRunsRollbackCallbacks() ? 1 : 2, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function reusedIds(): array
    {
        return [
            'another job at the reused id' => [false],
            'the same uuid with another generation at the reused id' => [true],
        ];
    }

    /**
     * P. B's application rollback callback comes first and throws, so B
     * gets no rollback mark.
     *
     * Rejects: the release of the rolled-back savepoint applied at the
     * reused id.
     */
    public function test_two_releases_with_a_reused_id(): void
    {
        if ($this->testEnvironment()->driver !== 'sqlite') {
            self::markTestSkipped('SQLite reuses the id of a rolled-back insert; MySQL and Postgres do not.');
        }

        Carbon::setTestNow(Carbon::createFromTimestamp(1790000000));
        $q = $this->q();
        $j = $this->push(new BasicJob);
        $uuid = $this->uuidOfJob($j);
        $job = $this->popJob();
        self::assertSame('reserved', $this->rowOrFail($uuid)->status);

        $q->beginTransaction();
        self::afterCommitBeforeGate($this->record(), static function () use ($job): void {
            // A new root after the commit: SQLite gives it the same new id.
            $job->release(3600);
        });

        $q->beginTransaction();
        self::rollbackCallbackBeforeGate($this->record(), static function (): void {
            throw new RuntimeException('b rollback callback failed');
        });
        $job->release(10);
        $first = (int) $this->qJobs()[0]->id;
        self::applicationError(static fn () => $q->rollBack(), 'b rollback callback failed');
        $q->commit();

        $jobs = $this->qJobs();
        self::assertCount(1, $jobs);
        self::assertSame($first, (int) $jobs[0]->id, 'The second release got the same new id.');
        self::assertSame(1790003600, (int) $jobs[0]->available_at);

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status);
        self::assertSame($first, (int) $row->job_id, 'The replacement id.');
        self::assertSame($j, (int) $row->prev_job_id);
        self::assertSame(1, (int) $row->releases, 'One release.');
        self::assertSame(Clock::toDecimal(1790003600.0), Clock::toDecimal((float) $row->available_at), 'The committed available_at of the second release.');
        // The first release ran in its own savepoint under the rolled-back B:
        // that savepoint's rollback mark explains the drop where Laravel runs
        // the rollback callbacks of removed committed children (Laravel 13).
        self::assertSame($this->removingCommittedChildrenRunsRollbackCallbacks() ? 0 : 1, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();
    }

    /**
     * P. The application's after-commit callback of the release's own
     * transaction throws: the exception leaves deleteAndRelease(), so the
     * hook observes nothing, and nothing stays tracked.
     *
     * Rejects: a release hook that defers after the parent threw, and a
     * root that stays tracked after the throwing walk.
     */
    public function test_release_whose_after_commit_callback_throws_leaves_nothing_tracked(): void
    {
        $q = $this->q();
        $this->push(new BasicJob);
        $job = $this->popJob();
        $error = new RuntimeException('release after commit callback failed');
        $armed = true;

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$armed, $error): void {
            if ($armed && $event->connectionName === self::Q && self::isJobsInsert($event->sql) && $event->connection->transactionLevel() > 0) {
                $armed = false;
                $this->manager()->addCallback(static function () use ($error): void {
                    throw $error;
                });
            }
        });

        try {
            $job->release(0);
            self::fail('The after-commit callback error leaves the parent call.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught, 'The caller gets the same exception object.');
        }

        self::assertFalse($armed);
        self::assertSame(0, $q->transactionLevel());

        $q->beginTransaction();
        $later = $this->push(new BasicJob);
        $q->commit();

        self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($later))->status, 'A push in a later root has its row before the end of the unit.');
        self::assertSame(0, self::unverified(), 'The parent call threw, so the release raised nothing to count.');
        self::assertSame(0, self::rollbackUnverified());
        self::assertSame(0, $this->deferral()->open(), 'No root waits after the throwing walk.');

        $this->endUnit();
        self::assertSame(0, self::unverified());
        $this->assertNothingDeferred();
    }
}
