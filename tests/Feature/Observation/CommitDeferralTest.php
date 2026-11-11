<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Observation;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\DropCounter;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use Justrau\Orrery\Tests\Support\FailingCommitPdo;
use Justrau\Orrery\Tests\Support\LostAckCommitPdo;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;
use WeakReference;

/**
 * The commit deferral on the real transactions manager of the installed
 * Laravel: the P tests of the scenario
 * inventory, the D and G rows that the deferral decides, and the acceptance
 * tests that C3 names for the class. Each test asserts what ran, in which
 * order, and both counters (`unverified`, `rollback_unverified`).
 *
 * A version-exact test asserts which Laravel path it took
 * (failedCommitRollsTheManagerBack(), disconnectRollsTheManagerBack()).
 */
final class CommitDeferralTest extends DeferralTestCase
{
    /**
     * Rejects: holding an item at level 0 for a commit that never comes.
     */
    public function test_autocommit_runs_at_once(): void
    {
        $result = $this->raise('autocommit');

        self::assertSame(['autocommit'], $this->ran());
        self::assertIsArray($result, "The hook returns the parent's result.");
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: running the items of a rolled-back savepoint, or deciding an
     * item at a savepoint commit instead of its root's.
     */
    public function test_commit_and_rollback_at_each_level(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('1-commit');
        self::assertSame([], $this->ran(), 'An item waits for its root.');
        $q->commit();

        $q->beginTransaction();
        $this->raise('1-rollback');
        $q->rollBack();

        self::assertSame(['1-commit'], $this->ran());
        $this->assertCounts(0, 1, 'A rolled-back root counts its item in rollback_unverified.');

        $q->beginTransaction();
        $this->raise('2-root');
        $q->beginTransaction();
        $this->raise('2-commit');
        $q->commit();
        self::assertSame(['1-commit'], $this->ran(), 'A savepoint commit runs nothing.');
        $q->beginTransaction();
        $this->raise('2-rollback');
        $q->rollBack();
        $q->commit();

        self::assertSame(['1-commit', '2-root', '2-commit'], $this->ran());

        $q->beginTransaction();
        $q->beginTransaction();
        $q->beginTransaction();
        $this->raise('3-commit');
        $q->commit();
        $q->beginTransaction();
        $this->raise('3-rollback');
        $q->rollBack();
        $q->commit();
        $q->beginTransaction();
        $q->beginTransaction();
        $this->raise('3-in-rolled-back-2');
        $q->commit();
        $q->rollBack();
        $q->commit();

        self::assertSame(['1-commit', '2-root', '2-commit', '3-commit'], $this->ran());
        $this->assertCounts(0, 1, 'A savepoint rollback is decided silently by its marks.');
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());
    }

    /**
     * Rejects: dropping the committed sibling with the rolled-back one.
     */
    public function test_committed_sibling_survives_a_rolled_back_sibling(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $q->beginTransaction();
        $this->raise('committed sibling');
        $q->commit();
        $q->beginTransaction();
        $this->raise('rolled-back sibling');
        $q->rollBack();
        $q->commit();

        self::assertSame(['committed sibling'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: a rollback of one savepoint that drops the items of the root
     * or of a later sibling, or a run order other than raise order.
     */
    public function test_savepoint_rollback_drops_only_its_items(): void
    {
        $q = $this->q();

        $q->transaction(function () use ($q): void {
            $this->raise('outer');

            try {
                $q->transaction(function (): void {
                    $this->raise('inner rolled back');

                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
            }

            $q->transaction(function (): void {
                $this->raise('inner committed');
            });
            $this->raise('outer again');
            self::assertSame([], $this->ran());
        });

        self::assertSame(['outer', 'inner committed', 'outer again'], $this->ran());
        $this->assertCounts(0, 0);
        self::assertSame(0, $this->deferral()->open());
    }

    /**
     * Rejects: an owner cached per connection, so an item raised in a new
     * root after an earlier root committed waits for the old root.
     */
    public function test_item_of_a_new_root_runs_at_its_commit(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('a');
        $q->commit();

        $q->beginTransaction();
        $this->raise('b');
        self::assertSame(['a'], $this->ran(), "B's item waits for B.");
        $q->commit();

        self::assertSame(['a', 'b'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: a deferral whose catch-alls swallow the TimeoutExceededException
     * that Laravel's worker throws into the running code when
     * Worker::$killOnTimeout is false, in the build of an observation, in an
     * item that runs at once, and in an item that runs at its root's commit
     * (the job then runs on past its timeout). It would still pass a deferral
     * that rethrows the timeout but loses the count of the root's other
     * items: the last case asserts the count.
     */
    public function test_a_worker_timeout_passes_through_the_deferral(): void
    {
        $q = $this->q();
        $timeout = new TimeoutExceededException('The job has timed out.');
        $throws = static fn (): never => throw $timeout;

        $cases = [
            'build' => fn () => $this->deferral()->around($q, 0, static fn (): null => null, $throws),
            'at once' => fn () => $this->deferral()->around($q, 0, static fn (): null => null, static fn (): Closure => $throws),
            'at commit' => function () use ($q, $throws): void {
                $q->beginTransaction();
                $this->deferral()->around($q, 0, static fn (): null => null, static fn (): Closure => $throws, CommitDeferral::OPERATION, 1);
                $this->raise('after');
                $q->commit();
            },
        ];

        foreach ($cases as $case => $call) {
            try {
                $call();
                self::fail("The deferral swallowed the worker timeout ({$case}).");
            } catch (TimeoutExceededException $caught) {
                self::assertSame($timeout, $caught, $case);
            }
        }

        self::assertSame([], $this->ran());
        self::assertSame(0, $this->deferral()->open());
        $this->assertCounts(1, 0, "The root's item after the timeout is counted.");
    }

    /**
     * Rejects: running at once at level > 0.
     */
    public function test_root_rollback_drops_every_item(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('root');
        $q->beginTransaction();
        $this->raise('committed savepoint');
        $q->commit();
        $q->beginTransaction();
        $this->raise('rolled-back savepoint');
        $q->rollBack();
        $q->rollBack();

        self::assertSame([], $this->ran());
        $this->assertCounts(0, 3, 'Every item of a rolled-back root counts in rollback_unverified.');
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function otherConnectionOrders(): array
    {
        return [
            'begins first, commits first' => [true, true],
            'begins first, commits last' => [true, false],
            'begins last, commits first' => [false, true],
            'begins last, commits last' => [false, false],
        ];
    }

    /**
     * Rejects: DB::afterCommit() or the manager's addCallback(), which attach
     * to the newest pending record of any connection.
     */
    #[DataProvider('otherConnectionOrders')]
    public function test_other_connection_decides_nothing(bool $otherBeginsFirst, bool $otherCommitsFirst): void
    {
        $q = $this->q();
        $other = $this->q(self::B);

        if ($otherBeginsFirst) {
            $other->beginTransaction();
            $q->beginTransaction();
        } else {
            $q->beginTransaction();
            $other->beginTransaction();
        }

        $this->raise('q');

        if ($otherCommitsFirst) {
            $other->commit();
            self::assertSame([], $this->ran(), 'The other connection commit runs no item of Q.');
            $q->commit();
        } else {
            $q->commit();
            self::assertSame(['q'], $this->ran(), 'The item runs at the Q commit while the other connection is open.');
            $other->commit();
        }

        self::assertSame(['q'], $this->ran());

        // The counter-example: Q rolls back while B (begun after Q) commits.
        $laravel = [];
        $q->beginTransaction();
        $other->beginTransaction();
        $this->raise('q rolled back');
        DB::afterCommit(static function () use (&$laravel): void {
            $laravel[] = 'q rolled back';
        });
        $q->rollBack();
        $other->commit();

        self::assertSame(['q'], $this->ran(), 'The deferral drops the rolled-back item.');
        self::assertSame(['q rolled back'], $laravel, "Laravel's afterCommit() ran for a rolled-back item: why the deferral never uses it.");
        $this->assertCounts(0, 1);
    }

    /**
     * Rejects: no tail rule (the autocommit item runs before the walking
     * root's item), or a tail keyed by the connection name.
     */
    public function test_tail_rule_is_per_instance(): void
    {
        $q = $this->q();
        $b = $this->q(self::B);
        $write = $this->q(self::Q.'::write');
        self::assertNotSame($q, $write);
        self::assertSame($q->getName(), $write->getName(), 'The write instance has the name of the manager records of Q.');

        $q->beginTransaction();
        $this->raise('a');
        $q->beginTransaction();
        $this->record()->addCallback(function () use ($b, $write): void {
            $this->raise('q autocommit');
            self::assertSame([], $this->ran(), 'The Q item waits behind the walking root A.');

            $this->raise('b autocommit', $b);
            self::assertSame(['b autocommit'], $this->ran(), 'Another connection has no walking root: its item runs at once.');

            $this->raise('write autocommit', $write);
            self::assertSame(['b autocommit', 'write autocommit'], $this->ran(), 'Another instance of the same name has no walking root.');
        });
        $q->commit();
        $q->commit();

        self::assertSame(['b autocommit', 'write autocommit', 'a', 'q autocommit'], $this->ran());
        $this->assertCounts(0, 0);
        self::assertSame(0, $this->deferral()->held());
    }

    /**
     * Rejects: running an autocommit item at once while a root of the same
     * instance runs its commit walk.
     */
    public function test_autocommit_item_in_a_walk_joins_the_tail(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('a');
        $q->beginTransaction();
        $this->raise('nested');
        $this->record()->addCallback(function (): void {
            $this->raise('autocommit');
        });
        $q->commit();
        $q->commit();

        self::assertSame(['a', 'nested', 'autocommit'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nestedRootVariants(): array
    {
        return [
            'B commits inside the walk' => ['commits'],
            'B rolls back inside the walk' => ['rolls back'],
            'B stays open while A runs' => ['stays open'],
        ];
    }

    /**
     * The GateOrderTest sequence: a committed savepoint's callback begins,
     * raises in and ends root B inside A's walk.
     *
     * Rejects: running a root that committed inside a walk at once (the
     * probe NestedRootWalkProbeTest gives ['b', 'a']), or untracking the
     * walking root at the nested begin.
     */
    #[DataProvider('nestedRootVariants')]
    public function test_root_committed_in_a_walk_runs_after_the_walking_root(string $variant): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('a');
        $q->beginTransaction();
        $this->record()->addCallback(function () use ($q, $variant): void {
            $q->beginTransaction();
            $this->raise('b');

            match ($variant) {
                'commits' => $q->commit(),
                'rolls back' => $q->rollBack(),
                'stays open' => null,
            };

            self::assertSame([], $this->ran(), 'Inside the walk of A, neither item has run.');
            self::assertSame(1, $this->deferral()->open() - ($variant === 'stays open' ? 1 : 0), 'A is still tracked.');
            $this->assertHeldBytesFollowHeld();
        });
        $q->commit();
        $q->commit();

        if ($variant === 'stays open') {
            self::assertSame(['a'], $this->ran(), 'A committed: its item runs while B is still open.');
            self::assertSame(1, $q->transactionLevel());
            $q->commit();
        }

        self::assertSame($variant === 'rolls back' ? ['a'] : ['a', 'b'], $this->ran());
        $this->assertCounts(0, $variant === 'rolls back' ? 1 : 0);
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function callbackOrders(): array
    {
        return [
            'the package callback first' => [false],
            'the application callback first' => [true],
        ];
    }

    /**
     * An after-commit callback of root A begins, raises in and commits root
     * B (TransactionGateTest's after_commit_callback_that_begins_another_transaction).
     *
     * Rejects: running A's item twice, or after B's.
     */
    #[DataProvider('callbackOrders')]
    public function test_after_commit_callback_that_begins_another_root(bool $applicationFirst): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('first root');
        $callback = function () use ($q): void {
            $q->beginTransaction();
            $this->raise('second root');
            $q->commit();
        };

        if ($applicationFirst) {
            self::afterCommitFirst($this->record(), $callback);
        } else {
            $this->record()->addCallback($callback);
        }

        $q->commit();
        $this->deferral()->endUnit();

        self::assertSame(['first root', 'second root'], $this->ran());
        $this->assertCounts(0, 0);
        self::assertSame(0, $this->deferral()->open());
    }

    /**
     * Rejects: holding items beyond the process limit, or keeping the
     * dropped item's slot after the commit.
     */
    public function test_more_than_10000_held_items_are_counted(): void
    {
        $q = $this->q();
        $limit = CommitDeferral::MAX_HELD;
        self::assertSame(10000, $limit);

        $q->beginTransaction();

        for ($index = 0; $index < $limit; $index++) {
            $this->raiseWithoutStatement('item');
        }

        $this->raise('item');

        self::assertSame($limit, $this->deferral()->held());
        $this->assertCounts(1, 0, 'The 10,001st item is dropped and counted.');
        self::assertSame(1, $this->drops->overBudgetCount(CommitDeferral::COUNT_CAP), 'The drop has the reason of the count cap.');
        self::assertSame(0, $this->drops->overBudgetCount(CommitDeferral::BYTE_BUDGET));

        $q->commit();

        self::assertCount($limit, $this->ran());
        self::assertSame(0, $this->deferral()->held(), 'The commit gives every slot back.');

        $this->raise('autocommit');
        self::assertCount($limit + 1, $this->ran());
        self::assertSame(0, $this->deferral()->held(), 'An item that runs at once takes no slot.');
    }

    /**
     * The hold cap is a process limit:
     * a scoped flush (a worker loop) in the middle of a leaked transaction
     * does not give the process a new 10,000 items. The test uses the
     * module's own binding of the store.
     *
     * Rejects: a scoped store, whose cap counts per store, so 10,001 items
     * across a flush are all held.
     */
    public function test_the_hold_cap_spans_stores(): void
    {
        $this->app->forgetInstance(CommitDeferral::class);
        $q = $this->q();
        $q->beginTransaction();

        for ($index = 0; $index < CommitDeferral::MAX_HELD; $index++) {
            if ($index === 5000) {
                $this->app->forgetScopedInstances();
            }

            $this->raiseWithoutStatement('item');
        }

        $this->raise('item');

        self::assertSame(CommitDeferral::MAX_HELD, $this->deferral()->held());
        self::assertSame(1, Degradation::record()['unverified'] ?? 0, 'The 10,001st item of the process is dropped and counted.');
        $q->rollBack();
    }

    /**
     * Laravel keeps a stale parent after a throwing savepoint rollback
     * callback: the next savepoint C gets the rolled-back B as its parent.
     *
     * Rejects: following Laravel's parent pointer to decide C, or running
     * B's item.
     */
    public function test_sibling_after_a_throwing_savepoint_rollback_runs_once(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $q->beginTransaction();
        $bRecord = $this->record();
        $this->raise('b');
        $bRecord->addCallbackForRollback(static function (): void {
            throw new RuntimeException('b rollback callback failed');
        });

        self::catchApplicationError(static fn () => $q->rollBack(), 'b rollback callback failed');

        $q->beginTransaction();
        self::assertSame($bRecord, $this->record()->parent, 'Laravel gives C the stale parent B.');
        $this->raise('c');
        $q->commit();
        $this->raise('root');
        $q->commit();

        self::assertSame(['c', 'root'], $this->ran(), "C's item runs once, and B's never runs.");
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: savepoints decided by the package's rollback callback (the
     * probe's RootDeferral), which an earlier throwing application rollback
     * callback never lets run.
     */
    public function test_application_rollback_callback_first_never_runs_the_rolled_back_items(): void
    {
        $q = $this->q();

        // A savepoint whose application rollback callback comes first and throws.
        $q->beginTransaction();
        $q->beginTransaction();
        $this->raise('committed sibling');
        $q->commit();
        $q->beginTransaction();
        $q->afterRollBack(static function (): void {
            throw new RuntimeException('application rollback callback failed');
        });
        $this->raise('rolled-back nested');

        self::catchApplicationError(static fn () => $q->rollBack(), 'application rollback callback failed');
        self::assertSame(1, $q->transactionLevel());
        $q->commit();

        self::assertSame(['committed sibling'], $this->ran());
        $this->assertCounts(1, 0, 'The rolled-back savepoint has neither mark: its item is counted.');

        // From level 3 to level 1, the level-3 callback throws after the package's.
        $q->beginTransaction();
        $this->raise('outer before');
        $q->beginTransaction();
        $this->raise('level 2');
        $q->beginTransaction();
        $this->raise('level 3');
        $this->record()->addCallbackForRollback(static function (): void {
            throw new RuntimeException('level 3 rollback callback failed');
        });

        self::catchApplicationError(static fn () => $q->rollBack(1), 'level 3 rollback callback failed');
        self::assertSame(1, $q->transactionLevel());
        $this->raise('outer after');
        $q->commit();

        self::assertSame(['committed sibling', 'outer before', 'outer after'], $this->ran());
        $this->assertCounts(2, 0, "Level 2 never got its rollback mark: its item is counted; level 3's is not.");

        // A partial rollback whose callback throws after the package's.
        $q->beginTransaction();
        $q->beginTransaction();
        $this->raise('inner');
        $this->record()->addCallbackForRollback(static function (): void {
            throw new RuntimeException('rollback callback failed');
        });
        self::catchApplicationError(static fn () => $q->rollBack(), 'rollback callback failed');
        $this->raise('outer');
        $q->commit();

        self::assertSame(['committed sibling', 'outer before', 'outer after', 'outer'], $this->ran());
        $this->assertCounts(2, 0);

        // A root whose application rollback callback comes first and throws:
        // the root stays pending at level 0, and the end of the unit counts it.
        $q->beginTransaction();
        $q->afterRollBack(static function (): void {
            throw new RuntimeException('application root rollback callback failed');
        });
        $this->raise('rolled-back root');

        self::catchApplicationError(static fn () => $q->rollBack(), 'application root rollback callback failed');
        self::assertSame(0, $q->transactionLevel());
        $this->deferral()->endUnit();

        self::assertSame(['committed sibling', 'outer before', 'outer after', 'outer'], $this->ran());
        $this->assertCounts(3, 0);
        self::assertSame(0, $this->deferral()->open());
    }

    /**
     * Rejects: a mark on the innermost record only, so the committed level 3
     * of a rolled-back level 2 runs.
     */
    public function test_committed_child_of_a_rolled_back_savepoint_is_dropped(): void
    {
        $q = $this->q();

        $q->transaction(function () use ($q): void {
            try {
                $q->transaction(function () use ($q): void {
                    $q->transaction(function (): void {
                        $this->raise('level 3');
                    });

                    throw new RuntimeException('level 2');
                });
            } catch (RuntimeException) {
            }

            $this->raise('root');
        });

        self::assertSame(['root'], $this->ran());
        $this->assertCounts(0, 0);

        // A committed descendant C that holds no item and whose rollback
        // callback throws. Laravel 13 runs C's rollback callbacks when it
        // removes C, and the throw skips B's; Laravel 12 removes C silently.
        $q->beginTransaction();
        $q->beginTransaction();
        $this->raise('sibling before b');
        $q->commit();
        $q->beginTransaction();
        $this->raise('b');
        $q->beginTransaction();
        $q->afterRollBack(static function (): void {
            throw new RuntimeException('c rollback callback failed');
        });
        $q->commit();

        try {
            $q->rollBack();
        } catch (RuntimeException $error) {
            self::assertTrue($this->removingCommittedChildrenRunsRollbackCallbacks(), 'Only Laravel 13 runs the rollback callbacks of a removed committed record.');
            self::assertSame('c rollback callback failed', $error->getMessage());
        }

        self::assertSame(1, $q->transactionLevel());
        $q->commit();

        self::assertSame(['root', 'sibling before b'], $this->ran());
        // Laravel 13: B got no rollback mark, so its item is counted; 12: B's mark explains it.
        $this->assertCounts($this->removingCommittedChildrenRunsRollbackCallbacks() ? 1 : 0, 0);
    }

    /**
     * Rejects: deciding a root at a statement inside its walk, or running an
     * item twice.
     */
    public function test_application_callbacks_in_a_walk_change_nothing(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('a');
        $q->beginTransaction();
        $this->raise('b');
        self::afterCommitFirst($this->record(), function () use ($q): void {
            $q->select('select 1');
            $q->beginTransaction();
            $this->raise('n');
            $q->commit();
        });
        $q->commit();
        $q->commit();
        $this->deferral()->endUnit();

        self::assertSame(['a', 'b', 'n'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: running the items of a root whose walk an application
     * callback stopped before the package's callback, or losing them
     * silently; holding the later autocommit items of the instance behind
     * the stopped walk until the end of the unit.
     */
    #[DataProvider('callbackOrders')]
    public function test_throwing_application_callback_in_the_walk(bool $applicationFirst): void
    {
        $q = $this->q();
        $committedEvents = 0;
        $this->app['events']->listen(TransactionCommitted::class, static function () use (&$committedEvents): void {
            $committedEvents++;
        });

        $q->beginTransaction();
        $this->raise('root');
        $q->beginTransaction();
        $this->raise('complete chain');
        $q->commit();
        $throwing = static function (): void {
            throw new RuntimeException('after commit failed');
        };

        if ($applicationFirst) {
            self::afterCommitFirst($this->record(), $throwing);
        } else {
            $this->record()->addCallback($throwing);
        }

        $events = $committedEvents;
        self::catchApplicationError(static fn () => $q->commit(), 'after commit failed');

        self::assertSame($events, $committedEvents, 'The root TransactionCommitted never fires.');
        self::assertSame(0, $q->transactionLevel(), 'The database commit succeeded.');

        if ($applicationFirst) {
            self::assertSame([], $this->ran(), 'The walk stopped before the package callback.');
            $this->raise('autocommit after the stopped walk');
            self::assertSame(['autocommit after the stopped walk'], $this->ran(), 'The stopped walk is not on the stack: the later autocommit item runs at once.');
            $this->assertCounts(2, 0, 'The release counts the two undecided items.');
            $this->deferral()->endUnit();

            self::assertSame(['autocommit after the stopped walk'], $this->ran());
            $this->assertCounts(2, 0, 'The end of the unit counts nothing again.');
        } else {
            self::assertSame(['root', 'complete chain'], $this->ran());
            $this->deferral()->endUnit();
            $this->assertCounts(0, 0);
        }

        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());
    }

    /**
     * A long-running unit (a command, a daemon) whose first walk an
     * application callback stopped.
     *
     * Rejects: holding the items of every later root of the instance behind
     * the stopped walk until the end of the unit (they reach the count cap
     * and are lost); running the stopped root's items, or running an item
     * twice when the unit ends.
     */
    public function test_a_stopped_walk_releases_the_later_roots(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('stopped root');
        self::afterCommitFirst($this->record(), static fn () => throw new RuntimeException('after commit failed'));
        self::catchApplicationError(static fn () => $q->commit(), 'after commit failed');

        self::assertSame(1, $this->deferral()->open(), 'The stopped root is still tracked.');

        $q->transaction(function (): void {
            $this->raise('later root');
        });

        self::assertSame(['later root'], $this->ran(), 'The later root runs at its own commit.');
        $this->assertCounts(1, 0, "The release counts the stopped root's item.");
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());

        // A walk that is still on the stack keeps its hold: the item raised
        // in its callback waits for the root's own items.
        $q->beginTransaction();
        $this->raise('walking root');
        self::afterCommitFirst($this->record(), function (): void {
            $this->raise('raised in the walk');
        });
        $q->commit();

        self::assertSame(['later root', 'walking root', 'raised in the walk'], $this->ran());
        $this->deferral()->endUnit();

        self::assertSame(['later root', 'walking root', 'raised in the walk'], $this->ran());
        $this->assertCounts(1, 0);
        self::assertSame(0, $this->deferral()->open());
    }

    /**
     * Rejects: a deferral that depends on TransactionCommitted listeners.
     */
    public function test_throwing_transaction_listener_changes_nothing(): void
    {
        $q = $this->q();
        $thrown = false;
        $this->app['events']->listen(TransactionCommitted::class, static function () use (&$thrown): void {
            if (! $thrown) {
                $thrown = true;

                throw new RuntimeException('committed listener failed');
            }
        });

        $q->beginTransaction();
        $q->beginTransaction();
        $this->raise('inner');

        self::catchApplicationError(static fn () => $q->commit(), 'committed listener failed');
        self::assertSame([], $this->ran());

        $q->commit();

        self::assertSame(['inner'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: a WeakMap of records, so the record of a root whose walk
     * stopped is gone before the end of the unit can count it.
     */
    public function test_end_of_unit_counts_a_root_whose_walk_stopped(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('lost');
        self::afterCommitFirst($this->record(), static function (): void {
            throw new RuntimeException('application after-commit callback');
        });

        self::catchApplicationError(static fn () => $q->commit(), 'application after-commit callback');
        gc_collect_cycles();

        self::assertSame([], $this->ran());
        self::assertSame(1, $this->deferral()->open(), 'The deferral still holds the root whose walk stopped.');
        $this->assertCounts(0, 0, 'A nested begin does not end a stopped walk.');

        $q->beginTransaction();
        $q->commit();
        self::assertSame(1, $this->deferral()->open());

        $this->deferral()->endUnit();

        self::assertSame([], $this->ran());
        $this->assertCounts(1, 0, 'One item whose root committed but whose callback never ran.');
        self::assertSame(0, $this->deferral()->open());
    }

    /**
     * Rejects: an end of the unit that keeps roots of an idle or purged
     * instance, or one that needs the connection object to count them.
     */
    public function test_end_of_unit_drops_roots_of_an_idle_instance(): void
    {
        $old = $this->q();
        $old->beginTransaction();
        $this->raise('old uncommitted', $old);
        $old->beginTransaction();
        $this->raise('old nested', $old);

        $this->app['db']->purge(self::Q);
        $reference = WeakReference::create($old);
        unset($old);
        gc_collect_cycles();
        self::assertNull($reference->get(), 'Nothing keeps the old connection object.');

        $this->deferral()->endUnit();

        self::assertSame([], $this->ran());
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());

        if ($this->disconnectRollsTheManagerBack()) {
            $this->assertCounts(0, 2, "Laravel 13: the purge's manager rollback.");
        } else {
            $this->assertCounts(2, 0, 'Laravel 12: the end of the unit counts the pending root of the freed instance.');
        }

        $new = $this->q();
        $new->beginTransaction();
        $this->raise('later', $new);
        $new->commit();

        self::assertSame(['later'], $this->ran());
    }

    /**
     * Rejects: an end of a nested unit that drops the roots of its caller
     * (open at its start, or in their walk), or a start that records only
     * the watched instances.
     */
    public function test_end_of_unit_leaves_nothing_tracked(): void
    {
        $q = $this->q();
        $deferral = $this->deferral();

        // A job's transaction runs a command (a nested unit) after a push.
        $deferral->beginUnit();
        $q->beginTransaction();
        $this->raise('job push');
        $deferral->beginUnit();
        $this->raise('command push');
        $deferral->endUnit();
        self::assertSame([], $this->ran(), "The command's end drops nothing of the job.");
        $q->commit();
        $deferral->endUnit();

        self::assertSame(['job push', 'command push'], $this->ran());

        // The command's push is the first use of the connection instance.
        $first = $this->q(self::Q.'::write');
        $first->beginTransaction();
        $deferral->beginUnit();
        $this->raise('first use', $first);
        $deferral->endUnit();
        $first->commit();

        self::assertSame(['job push', 'command push', 'first use'], $this->ran());

        // A committed savepoint's callback runs a sync job (a nested unit) in the walk of a root that holds a push.
        $q->beginTransaction();
        $this->raise('walk push');
        $q->beginTransaction();
        $this->record()->addCallback(function () use ($deferral): void {
            $deferral->beginUnit();
            $this->raise('sync job push');
            $deferral->endUnit();
        });
        $q->commit();
        $q->commit();

        self::assertSame(['job push', 'command push', 'first use', 'walk push', 'sync job push'], $this->ran());

        // A transaction leaked past the end of a job.
        $deferral->beginUnit();
        $q->beginTransaction();
        $this->raise('leaked');
        $deferral->endUnit();

        $this->assertCounts(1, 0, 'The leaked root is counted at the end of its unit.');
        self::assertSame(0, $deferral->open());
        self::assertSame(0, $deferral->held());
        $q->rollBack();
        $this->assertCounts(1, 0, 'The later rollback counts nothing twice.');
    }

    /**
     * Rejects: a stale rule keyed by the connection name, which drops the
     * open root of another instance when one instance begins at level 0.
     */
    public function test_stale_rule_drops_only_the_instances_own_roots(): void
    {
        $q = $this->q();
        $write = $this->q(self::Q.'::write');

        $q->beginTransaction();
        $this->raise('A');
        $this->raise('watch the write instance', $write);

        $write->beginTransaction();
        self::assertSame(1, $this->deferral()->open(), "The level-0 begin of the write instance leaves Q's open root A.");
        $this->assertCounts(0, 0);

        // Q commits first: the manager keeps its records by name, so a rollback
        // of the write instance first would roll A back in the manager too.
        $q->commit();
        $write->rollBack();

        self::assertSame(['watch the write instance', 'A'], $this->ran());
        $this->assertCounts(0, 0);

        // A purge inside A's walk (GateTest: a_replacement_transaction_open_through_the_old_root_witness_keeps_its_label).
        $old = $q;
        $new = null;
        $old->beginTransaction();
        $this->raise('old A', $old);
        $old->beginTransaction();
        $this->record($old)->addCallback(function () use (&$new): void {
            $this->app['db']->purge(self::Q);
            $new = $this->q();
            $this->raise('watch the new instance', $new);
            $new->beginTransaction();
            $this->raise('new B', $new);
        });
        $old->commit();
        $old->commit();

        self::assertInstanceOf(Connection::class, $new);
        self::assertSame(1, $new->transactionLevel(), 'B is still open.');
        self::assertSame(1, $this->deferral()->open(), "The new instance's level-0 begin left B tracked.");
        $new->commit();

        self::assertSame(['watch the write instance', 'A', 'watch the new instance', 'new B'], $this->ran());
        $this->assertCounts(1, 0, 'Old A: the purge in its walk took its PDO (the root check).');
    }

    /**
     * Rejects: a stale rule that looks at the beginning instance only.
     */
    public function test_stale_root_of_another_instance_of_the_name(): void
    {
        $q = $this->q();
        $write = $this->q(self::Q.'::write');
        $this->raise('watch the write instance', $write);

        // setPdo() lowers the level to 0 and leaves A pending, on every Laravel line.
        $q->beginTransaction();
        $this->raise('A');
        $pdo = $q->getPdo();
        $q->setPdo($pdo);
        self::assertSame(0, $q->transactionLevel());
        self::assertTrue($pdo->inTransaction());

        $write->beginTransaction();

        $this->assertCounts(1, 0, 'The level-0 begin of the write instance finds the stale root of Q.');
        self::assertSame(0, $this->deferral()->open());

        $write->commit();
        $pdo->rollBack();

        self::assertSame(['watch the write instance'], $this->ran());
        $this->assertCounts(1, 0);
    }

    /**
     * Version-exact. Laravel 12 and 13.30–13.38 leave a failed COMMIT's record
     * of transaction() pending at level 0: the stale rule drops it. From
     * 13.39 the manager's rollback drops it.
     *
     * Rejects: no stale rule (on Laravel 12 the output becomes
     * ['attempt 1', 'attempt 2']; on 13.39+ the manual case keeps its item
     * pending and runs it at the next commit).
     */
    public function test_failed_commit_attempt_is_stale(): void
    {
        $q = $this->q();
        $pdo = FailingCommitPdo::forConfig($q->getConfig(), 1);
        $q->setPdo($pdo);
        $attempt = 0;

        $q->transaction(function () use ($q, &$attempt): void {
            $attempt++;
            $q->select('select 1');
            $this->raise("attempt {$attempt}");
        }, 2);

        self::assertSame(2, $attempt);
        self::assertSame(1, $pdo->failedCommits);
        self::assertSame(['attempt 2'], $this->ran());

        if ($this->failedCommitRollsTheManagerBack()) {
            $this->assertCounts(0, 1, "13.39+: the manager's rollback decided attempt 1.");
        } else {
            $this->assertCounts(1, 0, 'Before 13.39: the stale rule decided attempt 1.');
        }

        // A manual commit() whose COMMIT fails leaves the record pending at level 1
        // on every line; the application then resets the level with setPdo().
        $failing = FailingCommitPdo::forConfig($q->getConfig(), 1);
        $q->setPdo($failing);
        $q->beginTransaction();
        $this->raise('manual');

        try {
            $q->commit();
            self::fail('The COMMIT fails.');
        } catch (PDOException) {
        }

        self::assertSame(1, $q->transactionLevel(), 'A failed manual COMMIT keeps the level.');
        $q->setPdo($failing);
        $q->transaction(function (): void {
            $this->raise('next');
        });

        self::assertSame(['attempt 2', 'next'], $this->ran(), 'The failed manual commit never runs its item.');
        $this->assertCounts($this->failedCommitRollsTheManagerBack() ? 1 : 2, $this->failedCommitRollsTheManagerBack() ? 1 : 0);
        self::assertSame(0, $this->deferral()->open());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function purgePoints(): array
    {
        $cases = [];

        foreach (['before the insert', 'after the insert at level 1', 'at level 2', 'in the walk before the root witness', 'in the walk after the root witness'] as $point) {
            foreach (['no deferral', 'transaction events deferred', 'all events deferred'] as $mode) {
                $cases["{$point}, {$mode}"] = [$point, $mode];
            }
        }

        return $cases;
    }

    /**
     * The purge points of PurgedConnectionTest, version-exact. The test
     * keeps the old PDO alive, so the PDO identity check alone decides the
     * walk cases.
     *
     * Rejects: a root callback without the PDO check (the "in the walk
     * before the root witness" cases run their items), and running an
     * uncommitted item at any point.
     */
    #[DataProvider('purgePoints')]
    public function test_purge_points(string $point, string $mode): void
    {

        $old = $this->q();
        $oldPdo = $old->getPdo();
        $purge = function () use ($mode): void {
            $work = function (): void {
                $this->app['db']->purge(self::Q);
                $new = $this->q();
                $new->beginTransaction();
                $new->commit();
            };

            match ($mode) {
                'no deferral' => $work(),
                'transaction events deferred' => $this->app['events']->defer($work, [TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class]),
                'all events deferred' => $this->app['events']->defer($work),
            };
        };

        $old->beginTransaction();

        match ($point) {
            'before the insert' => (function () use ($purge): void {
                $purge();
                $this->raise('a', $this->q());
            })(),
            'after the insert at level 1' => (function () use ($old, $purge): void {
                $this->raise('a', $old);
                $purge();
            })(),
            'at level 2' => (function () use ($old, $purge): void {
                $this->raise('a', $old);
                $old->beginTransaction();
                $this->raise('s', $old);
                $purge();
            })(),
            'in the walk before the root witness' => (function () use ($old, $purge): void {
                $this->raise('a', $old);
                $old->beginTransaction();
                $this->raise('s', $old);
                $this->record($old)->addCallback($purge);
                $old->commit();
                $old->commit();
            })(),
            'in the walk after the root witness' => (function () use ($old, $purge): void {
                $this->raise('a', $old);
                $this->record($old)->addCallback($purge);
                $old->commit();
            })(),
        };

        $this->deferral()->endUnit();

        [$ran, $unverified, $rollback] = match ($point) {
            'before the insert', 'in the walk after the root witness' => [['a'], 0, 0],
            'after the insert at level 1' => [[], $this->disconnectRollsTheManagerBack() ? 0 : 1, $this->disconnectRollsTheManagerBack() ? 1 : 0],
            'at level 2' => [[], $this->disconnectRollsTheManagerBack() ? 0 : 2, $this->disconnectRollsTheManagerBack() ? 2 : 0],
            'in the walk before the root witness' => [[], 2, 0],
        };

        self::assertSame($ran, $this->ran());
        $this->assertCounts($unverified, $rollback);
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());
        self::assertInstanceOf(PDO::class, $oldPdo);

        // The new instance works normally.
        $new = $this->q();
        $new->beginTransaction();
        $this->raise('n', $new);
        $new->commit();
        self::assertContains('n', $this->ran());
    }

    /**
     * Version-exact: Laravel 13's reconnect rolls the manager back; on 12
     * the next level-0 begin finds the roots stale.
     *
     * Rejects: keeping the roots of the old PDO.
     */
    public function test_reconnect_drops_its_items(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('before the reconnect');
        $q->beginTransaction();
        $this->raise('nested before the reconnect');

        $this->app['db']->reconnect(self::Q);
        self::assertSame(0, $q->transactionLevel());

        $q->beginTransaction();
        $this->raise('after the reconnect');
        $q->commit();
        $this->deferral()->endUnit();

        self::assertSame(['after the reconnect'], $this->ran());
        $this->assertCounts($this->disconnectRollsTheManagerBack() ? 0 : 2, $this->disconnectRollsTheManagerBack() ? 2 : 0);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function aliasOutcomes(): array
    {
        return ['A commits' => [true], 'A rolls back' => [false]];
    }

    /**
     * A commit of the `q::write` instance stages the open records of Q's
     * name (CommitProofTest).
     *
     * Rejects: a root callback that trusts its own call as the commit
     * proof of Q's open root.
     */
    #[DataProvider('aliasOutcomes')]
    public function test_alias_instance_commit_does_not_prove_an_open_root(bool $commits): void
    {
        $q = $this->q();
        $write = $this->q(self::Q.'::write');

        $q->beginTransaction();
        $this->raise('A');
        $write->beginTransaction();
        $write->commit();

        self::assertSame(1, $q->transactionLevel(), 'A is still open.');
        self::assertSame([], $this->ran(), "The write instance's commit runs nothing of A.");
        $this->assertCounts(1, 0, 'The root check: Q is still in a transaction.');

        $commits ? $q->commit() : $q->rollBack();
        $this->deferral()->endUnit();

        self::assertSame([], $this->ran());
        $this->assertCounts(1, 0);

        // The same inside the walk of an earlier root X of Q.
        $q->beginTransaction();
        $this->raise('X');
        $this->record($q)->addCallback(function () use ($q, $write): void {
            $q->beginTransaction();
            $this->raise('A in the walk');
            $write->beginTransaction();
            $write->commit();
            self::assertSame(1, $q->transactionLevel());
        });
        $q->commit();
        $q->rollBack();
        $this->deferral()->endUnit();

        self::assertSame(['X'], $this->ran());
        $this->assertCounts(2, 0);
    }

    /**
     * Rejects: a root callback without the inTransaction() check: the
     * manager commit after setPdo() reset the level, while the PDO keeps
     * the open transaction.
     */
    public function test_set_pdo_inside_a_transaction_proves_nothing(): void
    {
        $q = $this->q();
        $pdo = null;

        $q->transaction(function () use ($q, &$pdo): void {
            $this->raise('A');
            $pdo = $q->getPdo();
            $q->setPdo($pdo);
        });

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertTrue($pdo->inTransaction(), 'The PDO still has its open transaction.');
        self::assertSame([], $this->ran());
        $this->assertCounts(1, 0);

        $pdo->rollBack();
        $this->deferral()->endUnit();
        $this->assertCounts(1, 0);
    }

    /**
     * The contrived ghost rows of the inventory (class G, accepted by design):
     * a root whose application rollback callback throws first
     * stays pending at level 0, and transaction() then commits it in the
     * manager, with the same idle PDO. Reconciliation marks such a row
     * `unknown` later.
     *
     * Rejects: an implementation that throws into the host or counts the
     * ghost twice in this sequence (it pins the accepted behaviour).
     */
    public function test_level_zero_commit_after_a_throwing_rollback_callback_is_a_known_ghost(): void
    {
        $q = $this->q();

        $q->transaction(function () use ($q): void {
            $q->afterRollBack(static function (): void {
                throw new RuntimeException('application rollback callback failed');
            });
            $this->raise('A');

            self::catchApplicationError(static fn () => $q->rollBack(), 'application rollback callback failed');
            self::assertSame(0, $q->transactionLevel());
        });
        $this->deferral()->endUnit();

        self::assertSame(['A'], $this->ran(), 'The ghost: the item of a rolled-back root runs.');
        $this->assertCounts(0, 0);
        self::assertSame(0, $this->deferral()->open());
    }

    /**
     * Rejects: reading an empty pending list at level > 0 as autocommit, or
     * reporting the missing record on every call.
     */
    public function test_level_without_a_pending_record_is_counted_and_reported_once(): void
    {
        $q = $this->q();
        $reports = $this->recordReports();
        $real = $this->manager();

        // The manager misses the begin.
        $q->setTransactionManager(new DatabaseTransactionsManager);
        $q->beginTransaction();
        $q->setTransactionManager($real);
        $this->raise('unexplained');

        // No manager at all.
        $q->unsetTransactionManager();

        try {
            $q->beginTransaction();
            $this->raise('without a manager');
            $q->commit();
            $q->commit();
        } finally {
            $q->setTransactionManager($real);
        }

        self::assertSame([], $this->ran());
        $this->assertCounts(2, 0);
        self::assertCount(1, array_filter($reports->getArrayCopy(), static fn (Throwable $error): bool => str_contains($error->getMessage(), 'deferral.no_root')), 'One report per process.');

        $this->raise('autocommit');
        self::assertSame(['autocommit'], $this->ran(), 'Level 0 is autocommit.');
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function staleParentVariants(): array
    {
        return ['A, B, C, D' => [true], 'A, B, C' => [false]];
    }

    /**
     * B's rollback callback throws once, so Laravel keeps B as the parent
     * of C and D. D's rollback then walks through B and removes the
     * committed C (GateWitnessTest's stale_parent_cleanup case, class D):
     * C committed into A, but no commit mark proves it. Laravel 13 runs C's
     * rollback callbacks there; that mark comes through the stale parent and
     * explains nothing. D's own rollback mark also comes through the stale
     * parent, so D is counted too: an over-count, never a ghost.
     *
     * Rejects: a rollback mark that explains a drop whatever its parent (on
     * Laravel 13, C would be dropped silently).
     */
    #[DataProvider('staleParentVariants')]
    public function test_stale_parent_cleanup_counts_the_committed_record(bool $withD): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('a');
        $q->beginTransaction();
        $this->raise('b');
        $calls = 0;
        $this->record()->addCallbackForRollback(static function () use (&$calls): void {
            if ($calls++ === 0) {
                throw new RuntimeException('b rollback callback failed once');
            }
        });

        self::catchApplicationError(static fn () => $q->rollBack(), 'b rollback callback failed once');

        $q->beginTransaction();
        $this->raise('c');
        $q->commit();

        if ($withD) {
            $q->beginTransaction();
            $this->raise('d');
            $q->rollBack();
            self::assertSame(1, $q->transactionLevel());
        }

        $q->commit();

        self::assertSame($withD ? ['a'] : ['a', 'c'], $this->ran());
        $this->assertCounts($withD ? 2 : 0, 0);
    }

    /**
     * Two levels under a stale parent: B's rollback callback throws once, so
     * C (and its child D) get the stale B as an ancestor. C and D commit into
     * A; E's rollback then walks through B and removes C and D. On Laravel
     * 13 both get rollback marks, and D's parent C is a chain record, but
     * C's own parent is the stale B: the link from the root is not true.
     *
     * In the second case D rolls back by itself while C (under the stale
     * B) stays open: D's link to C is true, C's link to the root is not, so
     * D's item is counted too (an over-count, never a ghost).
     *
     * Rejects: a rollback mark that explains a drop when only its last link
     * is true (D's item would be dropped silently).
     */
    #[DataProvider('twoLevelCases')]
    public function test_two_level_stale_parent_counts_the_committed_records(bool $directRollback): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('a');
        $q->beginTransaction();
        $bRecord = $this->record();
        $this->raise('b');
        $calls = 0;
        $bRecord->addCallbackForRollback(static function () use (&$calls): void {
            if ($calls++ === 0) {
                throw new RuntimeException('b rollback callback failed once');
            }
        });

        self::catchApplicationError(static fn () => $q->rollBack(), 'b rollback callback failed once');

        $q->beginTransaction();
        self::assertSame($bRecord, $this->record()->parent, 'C gets the stale parent B.');
        $q->beginTransaction();
        $this->raise('d');

        if ($directRollback) {
            $q->rollBack();
            $q->commit();
        } else {
            $q->commit();
            $q->commit();
            $q->beginTransaction();
            $q->rollBack();
        }

        self::assertSame(1, $q->transactionLevel());
        $q->commit();

        self::assertSame(['a'], $this->ran());
        $this->assertCounts(1, 0, 'No true chain of links explains the drop of D: counted, never silent.');
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function twoLevelCases(): array
    {
        return ['C and D removed through B' => [false], 'D rolls back under the open C' => [true]];
    }

    /**
     * Rejects: a deferral that recognises only the framework's own manager
     * and connection classes.
     */
    public function test_subclassed_manager_and_connection_defer_normally(): void
    {
        $manager = new class extends DatabaseTransactionsManager
        {
            public function commit($connection, $levelBeingCommitted, $newTransactionLevel)
            {
                return parent::commit($connection, $levelBeingCommitted, $newTransactionLevel);
            }
        };
        $this->app->instance('db.transactions', $manager);

        $this->app['db']->extend('deferral_sub', static function (array $config, string $name): Connection {
            return new class(new PDO('sqlite::memory:'), ':memory:', '', ['name' => $name, 'driver' => 'sqlite']) extends SQLiteConnection
            {
                public function commit()
                {
                    parent::commit();
                }
            };
        });
        $this->app['config']->set('database.connections.deferral_sub', ['driver' => 'deferral_sub', 'database' => ':memory:', 'prefix' => '']);

        $sub = $this->q('deferral_sub');
        $sub->setTransactionManager($manager);

        $sub->beginTransaction();
        $this->raise('root', $sub);
        $sub->beginTransaction();
        $this->raise('nested', $sub);
        $sub->commit();
        self::assertSame([], $this->ran());
        $sub->commit();

        self::assertSame(['root', 'nested'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: a deferral keyed by the queue connection's configuration
     * name, so a transaction on another instance decides the hook's item.
     */
    public function test_hook_follows_its_own_connection_instance(): void
    {
        $q = $this->q();
        $write = $this->q(self::Q.'::write');

        $q->beginTransaction();
        $this->raise('write autocommit', $write);
        self::assertSame(['write autocommit'], $this->ran(), "Q's open transaction does not hold the write instance's autocommit item.");

        $this->raise('q');
        $q->commit();

        self::assertSame(['write autocommit', 'q'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: waiting for a transaction of the package's own connection.
     */
    public function test_mirror_transaction_is_not_waited_for(): void
    {
        $mirror = $this->q(self::B);
        $q = $this->q();

        $mirror->beginTransaction();
        $mirror->select('select 1');

        $this->raise('autocommit');
        self::assertSame(['autocommit'], $this->ran());

        $q->beginTransaction();
        $this->raise('in q');
        $q->commit();

        self::assertSame(['autocommit', 'in q'], $this->ran(), 'The Q root does not wait for the mirror transaction.');
        self::assertSame(1, $mirror->transactionLevel());
        $mirror->rollBack();
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: an observation or a count after a parent exception, or a
     * wrapped exception.
     */
    public function test_parent_exception_defers_nothing(): void
    {
        $q = $this->q();
        $errors = [new RuntimeException('before the statement'), new RuntimeException('after commit failed'), new RuntimeException('inside the host transaction')];

        $parents = [
            static fn () => throw $errors[0],
            static fn () => $q->transaction(static function () use ($q, $errors): string {
                $q->select(self::POP);
                $q->afterCommit(static fn () => throw $errors[1]);

                return 'result';
            }),
            static function () use ($q, $errors): never {
                $q->select(self::INSERT);

                throw $errors[2];
            },
        ];

        foreach ($parents as $index => $parent) {
            if ($index === 2) {
                $q->beginTransaction();
            }

            try {
                $this->deferral()->around($q, $index === 1 ? 1 : 0, $parent, $this->observationFor("label {$index}"));
                self::fail('The parent throws.');
            } catch (RuntimeException $caught) {
                self::assertSame($errors[$index], $caught, 'The caller gets the same exception object.');
            }
        }

        $q->rollBack();
        $this->deferral()->endUnit();

        self::assertSame([], $this->ran());
        $this->assertCounts(0, 0);
        self::assertSame(0, $this->deferral()->open());
    }

    /**
     * Rejects: a package error in the build, the decision or the run that
     * reaches the host, or one failed item that stops the others.
     */
    public function test_package_error_in_after_never_reaches_the_host(): void
    {
        $q = $this->q();

        // The run of the first item throws at the commit.
        $q->beginTransaction();
        $this->deferral()->around($q, 0, static fn () => $q->select(self::INSERT), static fn () => static fn () => throw new RuntimeException('observation failed'), CommitDeferral::OPERATION, 1);
        $this->raise('second');
        $q->commit();

        self::assertSame(['second'], $this->ran(), 'The second item still runs.');
        self::assertSame(CommitDeferral::OPERATION, Degradation::record()['last_error_operation'] ?? null);

        // The build throws.
        $result = $this->deferral()->around($q, 0, static fn () => 'parent result', static fn () => throw new RuntimeException('build failed'));
        self::assertSame('parent result', $result);
        self::assertSame(CommitDeferral::OPERATION.'.build', Degradation::record()['last_error_operation'] ?? null);

        // The manager fails after the capture: the decision throws.
        $failing = false;
        $real = $this->manager();
        $deferral = $this->newDeferral(static function () use (&$failing, $real): DatabaseTransactionsManager {
            if ($failing) {
                throw new RuntimeException('manager lookup failed');
            }

            return $real;
        });
        $result = $deferral->around($q, 0, static function () use (&$failing): string {
            $failing = true;

            return 'parent result';
        }, $this->observationFor('never'));

        self::assertSame('parent result', $result);
        self::assertSame('deferral.after', Degradation::record()['last_error_operation'] ?? null);
        self::assertSame(['second'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: work of the deferral that the open breaker does not skip,
     * or a breaker that changes the host call.
     */
    public function test_open_breaker_records_nothing_and_counts_a_drop(): void
    {
        $q = $this->q();

        $result = $this->deferral()->around($q, 0, static function () use ($q): string {
            $q->select(self::INSERT);
            Watcher::instance()->run('test:lost', static function (): never {
                throw new SiblingFileUnavailable('test.sqlite', 'the test session is lost');
            });

            return 'result';
        }, $this->observationFor('skipped'));

        self::assertTrue(Watcher::instance()->breakerOpen(), 'The breaker opened inside the parent call.');
        self::assertSame('result', $result, "The host gets the parent's result unchanged.");
        self::assertSame([], $this->ran());
        self::assertSame(2, Degradation::record()['dropped'] ?? null, 'The failed work and the skipped observation are dropped.');
        self::assertSame(0, $this->deferral()->held());

        // An item held while the breaker opens is skipped at its commit and counted dropped.
        Watcher::advanceMonotonicForTesting(31);
        self::assertFalse(Watcher::instance()->breakerOpen());
        $q->beginTransaction();
        $this->raise('held');
        Watcher::instance()->run('test:lost', static function (): never {
            throw new SiblingFileUnavailable('test.sqlite', 'the test session is lost');
        });
        $q->commit();

        self::assertSame([], $this->ran());
        self::assertSame(0, $this->deferral()->held());

        Watcher::advanceMonotonicForTesting(31);
        $q->beginTransaction();
        $this->raise('after the recovery');
        $q->commit();

        self::assertSame(['after the recovery'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: a deferral that reads transaction or query events.
     */
    public function test_deferred_events_change_nothing(): void
    {
        $q = $this->q();
        $real = $this->manager();

        $this->app['events']->defer(function () use ($q): void {
            $q->beginTransaction();
            $this->raise('root');
            $q->beginTransaction();
            $this->raise('rolled back');
            $q->rollBack();
            $q->beginTransaction();
            $this->raise('replacement savepoint');
            $q->commit();
            $q->commit();

            // A replaced root.
            $q->beginTransaction();
            $this->raise('a');
            $q->commit();
            $q->beginTransaction();
            $this->raise('b');
            $q->rollBack();
        });

        self::assertSame(['root', 'replacement savepoint', 'a'], $this->ran());
        $this->assertCounts(0, 1);

        // A replacement root that the manager never sees.
        $this->app['events']->defer(function () use ($q, $real): void {
            $q->setTransactionManager(new DatabaseTransactionsManager);

            try {
                $q->beginTransaction();
            } finally {
                $q->setTransactionManager($real);
            }

            $this->raise('unseen');
            $q->setTransactionManager(new DatabaseTransactionsManager);

            try {
                $q->rollBack();
            } finally {
                $q->setTransactionManager($real);
            }
        }, [TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class, QueryExecuted::class]);

        self::assertSame(['root', 'replacement savepoint', 'a'], $this->ran());
        $this->assertCounts(1, 1);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function outcomes(): array
    {
        return ['T commits' => [true], 'T rolls back' => [false]];
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function ambiguousCases(): array
    {
        return [
            'T commits' => [true, false],
            'T rolls back' => [false, false],
            'savepoint T commits' => [true, true],
            'savepoint T rolls back' => [false, true],
        ];
    }

    /**
     * A QueryExecuted listener begins T after the hook's statement ran
     * outside T (StatementOwnerTest's later_query_listener_transaction_is_not_the_owner).
     * T is a root (the statement ran in autocommit) or a savepoint of the
     * host's root R (the statement ran in R).
     *
     * Rejects: reading the level only after the call (the rollback of root
     * T would count rollback_unverified, as if the statement were inside T),
     * and dropping an ambiguous item silently when its savepoint rolls back
     * (the statement may have committed with R).
     */
    #[DataProvider('ambiguousCases')]
    public function test_ambiguous_level_change_is_counted_on_rollback(bool $commits, bool $savepoint): void
    {
        $q = $this->q();
        $began = false;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$began, $savepoint): void {
            if (! $began && $event->sql === self::INSERT && $event->connection->transactionLevel() === ($savepoint ? 1 : 0)) {
                $began = true;
                $event->connection->beginTransaction();
            }
        });

        if ($savepoint) {
            $q->beginTransaction();
        }

        $this->raise('later');
        self::assertSame($savepoint ? 2 : 1, $q->transactionLevel());
        self::assertSame([], $this->ran(), 'The ambiguous item waits for T.');

        $commits ? $q->commit() : $q->rollBack();

        if ($savepoint) {
            $q->commit();
        }

        self::assertSame($commits ? ['later'] : [], $this->ran());
        $this->assertCounts($commits ? 0 : 1, 0);
    }

    /**
     * An application beforeExecuting callback begins T before the hook's
     * statement, which runs inside T.
     *
     * Rejects: deciding the item at the capture point (level 0), which runs
     * it at once although T can roll back.
     */
    #[DataProvider('outcomes')]
    public function test_before_executing_transaction_defers_the_push(bool $commits): void
    {
        $q = $this->q();
        $began = false;
        $q->beforeExecuting(static function (mixed $query, mixed $bindings, Connection $connection) use (&$began): void {
            if (! $began && $query === self::INSERT) {
                $began = true;
                $connection->beginTransaction();
            }
        });

        $this->raise('t');
        self::assertSame(1, $q->transactionLevel(), 'The application callback began T before the statement.');
        self::assertSame([], $this->ran());

        $commits ? $q->commit() : $q->rollBack();

        self::assertSame($commits ? ['t'] : [], $this->ran());
        $this->assertCounts($commits ? 0 : 1, 0, 'The level rose during the call: a rollback counts the ambiguous item as unverified.');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bornAndEndedCases(): array
    {
        $cases = [];

        foreach (['insert', 'pop'] as $hook) {
            foreach (['rollback', 'commit', 'replaced'] as $end) {
                $cases["{$hook}, {$end}"] = [$hook, $end];
            }
        }

        return $cases;
    }

    /**
     * A beforeExecuting callback begins T, and a QueryExecuted listener ends
     * T before the parent returns.
     *
     * Rejects: accepting the chain alone as proof:
     * without the begin counter the item runs.
     */
    #[DataProvider('bornAndEndedCases')]
    public function test_transaction_born_and_ended_inside_the_call(string $hook, string $end): void
    {
        $q = $this->q();
        $sql = $hook === 'insert' ? self::INSERT : self::POP;
        $began = false;
        $ended = false;
        $q->beforeExecuting(static function (mixed $query, mixed $bindings, Connection $connection) use (&$began, $sql): void {
            if (! $began && $query === $sql) {
                $began = true;
                $connection->beginTransaction();
            }
        });
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$ended, $sql, $end): void {
            if ($ended || $event->sql !== $sql) {
                return;
            }

            $ended = true;

            if ($end === 'commit') {
                $event->connection->commit();
            } else {
                $event->connection->rollBack();
            }

            if ($end === 'replaced') {
                $event->connection->beginTransaction();
            }
        });

        $result = $hook === 'insert' ? $this->raise('x') : $this->pop('x');

        self::assertNotNull($result, "The host gets the parent's result.");
        self::assertSame($end === 'replaced' ? 1 : 0, $q->transactionLevel());

        if ($end === 'replaced') {
            $q->commit();
        }

        $this->deferral()->endUnit();

        self::assertSame([], $this->ran());
        $this->assertCounts(1, 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ownTransactionCases(): array
    {
        return ['rolled back' => ['rolled back'], 'committed' => ['committed'], 'rolled back and replaced' => ['replaced']];
    }

    /**
     * A QueryExecuted listener on the reserving statement acts on the
     * parent's own transaction, with no extra begin.
     *
     * Rejects: the begin count as the commit witness, or trusting a normal
     * return of the parent (the rolled-back reservation runs).
     */
    #[DataProvider('ownTransactionCases')]
    public function test_listener_rolls_back_the_parents_own_transaction(string $case): void
    {
        $q = $this->q();
        $acted = false;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$acted, $case): void {
            if ($acted || $event->sql !== self::RESERVE || $case === 'committed') {
                return;
            }

            $acted = true;
            $event->connection->rollBack();

            if ($case === 'replaced') {
                $event->connection->beginTransaction();
            }
        });

        $result = $this->pop('reserved');

        self::assertSame('job', $result, "The parent's return value is unchanged.");
        self::assertSame(0, $q->transactionLevel());
        self::assertSame($case === 'committed' ? ['reserved'] : [], $this->ran());
        $this->assertCounts($case === 'committed' ? 0 : 1, 0);

        // Inside a host transaction the parent's own transaction is a savepoint.
        $q->beginTransaction();
        $this->pop('inside');
        self::assertSame($case === 'committed' ? ['reserved'] : [], $this->ran());
        $q->commit();

        self::assertSame($case === 'committed' ? ['reserved', 'inside'] : ['inside'], $this->ran());
    }

    /**
     * Inside a host transaction R, a QueryExecuted listener rolls back the
     * pop's own savepoint. Laravel's transaction() then sees level 1 and
     * commits R itself, so the call returns at level 0.
     *
     * Rejects: accepting the parent's own savepoint without checking that
     * it is still pending or staged as committed (the rolled-back
     * reservation would run at once).
     */
    public function test_listener_rolls_back_the_parents_own_savepoint(): void
    {
        $q = $this->q();
        $acted = false;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$acted): void {
            if (! $acted && $event->sql === self::RESERVE && $event->connection->transactionLevel() === 2) {
                $acted = true;
                $event->connection->rollBack();
            }
        });

        $q->beginTransaction();
        $result = $this->pop('reserved');

        self::assertSame('job', $result);
        self::assertSame(0, $q->transactionLevel(), "transaction() committed the host's R after the listener's rollback.");
        self::assertSame([], $this->ran());
        $this->assertCounts(1, 0);
    }

    /**
     * A pop() inside a host transaction whose own savepoint the host then
     * rolls back with its parent savepoint.
     *
     * Rejects: a reservation item without the parent's own savepoint in its
     * chain.
     */
    public function test_own_savepoint_joins_the_chain(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $q->beginTransaction();
        $this->pop('in a rolled-back savepoint');
        $q->rollBack();
        $this->pop('in the root');
        $q->commit();

        self::assertSame(['in the root'], $this->ran());
        $this->assertCounts(0, 0);
    }

    /**
     * A QueryExecuted listener rolls back savepoint S1 and begins S2 during
     * the parent's insert; S2 then commits with its root.
     *
     * Rejects: comparing only the levels before and after the call.
     */
    public function test_listener_replaces_the_savepoint_during_the_call(): void
    {
        $q = $this->q();
        $acted = false;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$acted): void {
            if (! $acted && $event->sql === self::INSERT && $event->connection->transactionLevel() === 2) {
                $acted = true;
                $event->connection->rollBack();
                $event->connection->beginTransaction();
            }
        });

        $q->beginTransaction();
        $this->raise('root');
        $q->beginTransaction();
        $this->raise('x');
        self::assertSame(2, $q->transactionLevel());
        $q->commit();
        $q->commit();

        self::assertSame(['root'], $this->ran());
        $this->assertCounts(1, 0);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function rollbackDuringTheCall(): array
    {
        return ['T rolls back' => [false], 'T rolls back and the write instance begins' => [true]];
    }

    /**
     * A QueryExecuted listener rolls back the host transaction T during the
     * parent's insert. In the second case it then begins a transaction on
     * the `::write` instance of the same name: the pending list has one
     * record again and Q's begin counter did not move, so only the chain
     * check sees that T ended.
     *
     * Rejects: running at once because the level is 0 after the call, and
     * comparing only the length of the pending list (the chain-prefix check
     * removed).
     */
    #[DataProvider('rollbackDuringTheCall')]
    public function test_listener_rolls_back_the_transaction_during_the_call(bool $aliasBegins): void
    {
        $q = $this->q();
        $write = $this->q(self::Q.'::write');
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($q, $write, $aliasBegins): void {
            if ($event->sql === self::INSERT && $event->connection === $q && $q->transactionLevel() > 0) {
                $q->rollBack();

                if ($aliasBegins) {
                    $write->beginTransaction();
                }
            }
        });

        $q->beginTransaction();
        $this->raise('x');

        self::assertSame(0, $q->transactionLevel());
        self::assertSame($aliasBegins ? 1 : 0, $write->transactionLevel());

        if ($aliasBegins) {
            $write->commit();
        }

        $this->deferral()->endUnit();

        self::assertSame([], $this->ran());
        $this->assertCounts(1, 0);
    }

    /**
     * Rejects: a capture outside the isolation boundary, and an unverified
     * capture read as autocommit.
     */
    public function test_failing_capture_never_changes_the_host_call(): void
    {
        $q = $this->q();
        $failing = true;
        $real = $this->manager();
        $deferral = $this->newDeferral(static function () use (&$failing, $real): DatabaseTransactionsManager {
            if ($failing) {
                throw new RuntimeException('getPendingTransactions() failed');
            }

            return $real;
        });
        $calls = 0;

        $q->beginTransaction();
        $result = $deferral->around($q, 0, static function () use ($q, &$calls): string {
            $calls++;
            $q->select(self::INSERT);

            return 'inserted';
        }, $this->observationFor('x'));

        self::assertSame('inserted', $result);
        self::assertSame(1, $calls, 'The parent ran once.');
        $this->assertCounts(1, 0, 'One drop: the unverified sentinel.');
        $failing = false;
        $q->commit();
        self::assertSame([], $this->ran());

        // The parent throws: the same exception instance, nothing counted.
        $failing = true;
        $error = new RuntimeException('parent failed');
        $q->beginTransaction();

        try {
            $deferral->around($q, 0, static fn () => throw $error, $this->observationFor('y'));
            self::fail('The parent throws.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }

        $failing = false;
        $q->rollBack();
        $this->assertCounts(1, 0);
        self::assertSame(0, $deferral->open());
    }

    /**
     * A transaction() whose COMMIT acknowledgement is lost (LostAckCommitPdo),
     * version-exact: before 13.39 the end of the unit counts the pending
     * root (`unverified`), from 13.39 the manager's rollback does
     * (`rollback_unverified`). The manual idioms count on every line.
     *
     * Rejects: dropping a stale or still-open root silently, an event or
     * the PDO state as the evidence, and any exemption of a rollback path
     * from the count.
     */
    public function test_lost_commit_acknowledgement_is_counted(): void
    {
        $q = $this->q();
        $deferral = $this->deferral();

        $deferral->beginUnit();
        $q->setPdo(LostAckCommitPdo::forConfig($q->getConfig()));

        try {
            $q->transaction(function (): void {
                $this->raise('acknowledgement lost');
            });
            self::fail('The lost acknowledgement throws.');
        } catch (PDOException) {
        }

        $deferral->endUnit();

        self::assertSame([], $this->ran());
        $this->assertCounts($this->failedCommitRollsTheManagerBack() ? 0 : 1, $this->failedCommitRollsTheManagerBack() ? 1 : 0);

        // The manual idiom with catch: beginTransaction(); push; commit() catch { rollBack(); }.
        $this->app['db']->purge(self::Q);
        $q = $this->q();
        $q->setPdo(LostAckCommitPdo::forConfig($q->getConfig()));
        $deferral->beginUnit();
        $q->beginTransaction();
        $this->raise('manual with catch');

        try {
            $q->commit();
        } catch (PDOException) {
            $q->rollBack();
        }

        $deferral->endUnit();
        $base = $this->failedCommitRollsTheManagerBack() ? [0, 1] : [1, 0];
        $this->assertCounts($base[0], $base[1] + 1, 'The rollback after the failed COMMIT counts in rollback_unverified.');

        // The manual idiom without catch: the exception ends the unit with the root pending.
        $q->setPdo(LostAckCommitPdo::forConfig($q->getConfig()));
        $deferral->beginUnit();
        $q->beginTransaction();
        $this->raise('manual without catch');

        try {
            $q->commit();
        } catch (PDOException) {
            // The unit ends with the exception.
        }

        $deferral->endUnit();
        $this->assertCounts($base[0] + 1, $base[1] + 1, 'The end of the unit counts the pending root.');

        // A failed rollback.
        $q->setPdo(LostAckCommitPdo::forConfig($q->getConfig())->disarm());
        $q->beginTransaction();
        $this->raise('failed rollback');
        $q->getPdo()->rollBack();

        try {
            $q->rollBack();
        } catch (Throwable) {
            // The rollback failed: there is no active transaction.
        }

        $deferral->endUnit();
        $this->assertCounts($base[0] + 1, $base[1] + 2, 'A failed rollback counts in rollback_unverified.');

        // A transaction() whose closure throws after a push.
        self::catchApplicationError(fn () => $q->transaction(function (): void {
            $this->raise('closure throws');

            throw new RuntimeException('closure failed');
        }), 'closure failed');
        $this->assertCounts($base[0] + 1, $base[1] + 3);

        // A manual commit() inside a nested transaction() closure that then throws:
        // the closure's rollback reaches the outer root.
        $q->beginTransaction();
        self::catchApplicationError(fn () => $q->transaction(function () use ($q): void {
            $this->raise('committed by the closure');
            $q->commit();

            throw new RuntimeException('closure failed after its commit');
        }), 'closure failed after its commit');
        $deferral->endUnit();

        self::assertSame(0, $q->transactionLevel());
        self::assertSame([], $this->ran(), 'No item ever ran or replayed.');
        $this->assertCounts($base[0] + 1, $base[1] + 4);
        self::assertSame(0, $deferral->open());
    }

    /**
     * Inside A's walk, an application callback (before the package's) runs
     * root B whose own walk a throwing callback stops; the application
     * catches the error. B is then tracked after A and is not pending.
     *
     * Rejects: searching the walking root after A left the tracked roots,
     * which hands A's items to B's stopped walk until the end of the unit.
     */
    public function test_items_of_a_root_never_go_to_a_later_stopped_walk(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->raise('a');
        self::afterCommitFirst($this->record(), function () use ($q): void {
            try {
                $q->beginTransaction();
                $this->raise('b');
                self::afterCommitFirst($this->record(), static fn () => throw new RuntimeException('b walk stopped'));
                $q->commit();
            } catch (RuntimeException) {
                // The application catches the error of B's walk.
            }
        });
        $q->commit();

        self::assertSame(['a'], $this->ran(), "A's item runs at A's commit.");
        $this->deferral()->endUnit();

        self::assertSame(['a'], $this->ran());
        $this->assertCounts(1, 0, "B's stopped walk is counted at the end of the unit.");
        self::assertSame(0, $this->deferral()->open());
    }

    /**
     * Rejects: tracking a root or marking a savepoint for an item that the
     * limit drops (the callbacks would stay on the records).
     */
    public function test_item_over_the_limit_leaves_no_callback(): void
    {
        $q = $this->q();

        $q->beginTransaction();

        for ($index = 0; $index < CommitDeferral::MAX_HELD; $index++) {
            $this->raiseWithoutStatement('item');
        }

        $q->beginTransaction();
        $savepoint = $this->record();
        $this->raise('over the limit');

        self::assertSame([], $savepoint->getCallbacks(), 'The savepoint has no commit mark.');
        self::assertSame([], $savepoint->getCallbacksForRollback(), 'The savepoint has no rollback mark.');
        $this->assertCounts(1, 0);
        $q->commit();
        $q->commit();

        self::assertCount(CommitDeferral::MAX_HELD, $this->ran());

        // A full store also adds nothing to a new root.
        $other = $this->q(self::B);
        $q->beginTransaction();

        for ($index = 0; $index < CommitDeferral::MAX_HELD; $index++) {
            $this->raiseWithoutStatement('again');
        }

        $other->beginTransaction();
        $root = $this->record($other);
        $this->raise('over the limit on another connection', $other);

        self::assertSame([], $root->getCallbacks());
        self::assertSame([], $root->getCallbacksForRollback());
        $this->assertCounts(2, 0);
        $other->rollBack();
        $q->rollBack();
    }

    /**
     * Rejects: a decision that throws after the root left the tracked roots
     * and loses its items without a count.
     */
    public function test_failing_decision_counts_its_items(): void
    {
        $drops = new class implements DropCounter
        {
            public int $throws = 1;

            private int $unverified = 0;

            public function unverified(int $items = 1): void
            {
                if ($this->throws-- > 0) {
                    throw new RuntimeException('the counter failed once');
                }

                $this->unverified += $items;
            }

            public function rollbackUnverified(int $items = 1): void {}

            public function overBudget(string $reason, int $items = 1): void
            {
                $this->unverified($items);
            }

            public function unverifiedCount(): int
            {
                return $this->unverified;
            }
        };
        $deferral = new CommitDeferral($drops, Watcher::instance());
        $q = $this->q();
        $write = $this->q(self::Q.'::write');

        $q->beginTransaction();
        $deferral->around($q, 0, static fn () => $q->select(self::INSERT), $this->observationFor('one'));
        $deferral->around($q, 0, static fn () => $q->select(self::INSERT), $this->observationFor('two'));

        // The write instance's commit runs A's root check, which fails: the first count throws.
        $write->beginTransaction();
        $write->commit();

        self::assertSame(2, $drops->unverifiedCount(), 'The undecided items are counted after the failure.');
        self::assertSame(0, $deferral->open());
        $q->rollBack();
        self::assertSame([], $this->ran());
    }

    /**
     * Rejects: an end of a unit that ends only the innermost unit, or one
     * whose token ends a unit again.
     */
    public function test_unit_token_ends_its_unit_and_the_units_inside_it(): void
    {
        $q = $this->q();
        $deferral = $this->deferral();

        $q->beginTransaction();
        $this->raise('outer root');
        $outer = $deferral->beginUnit();
        $deferral->beginUnit();
        $q->beginTransaction();
        $q->commit();
        $q->commit();
        $q->beginTransaction();
        $this->raise('leaked in the inner unit');

        $deferral->endUnit($outer);
        $this->assertCounts(1, 0, 'The outer token also ends the inner unit, and its leaked root is counted.');
        $q->rollBack();

        $q->beginTransaction();
        $this->raise('later');
        $deferral->endUnit($outer);
        self::assertSame(1, $deferral->open(), 'An ended token does nothing.');
        $q->commit();

        self::assertSame(['outer root', 'later'], $this->ran());
        $this->assertCounts(1, 0);
    }

    /**
     * A token is never handed out twice: after endUnit($t), a new unit gets
     * a new token, so a stale endUnit($t) leaves the new unit alone.
     *
     * Rejects: tokens that are positions in the unit stack (the new unit
     * gets $t again, and the stale end drops its root).
     */
    public function test_an_ended_token_never_ends_a_later_unit(): void
    {
        $q = $this->q();
        $deferral = $this->deferral();

        $first = $deferral->beginUnit();
        $deferral->endUnit($first);
        $second = $deferral->beginUnit();

        $q->beginTransaction();
        $this->raise('in the new unit');
        $deferral->endUnit($first);

        self::assertSame(1, $deferral->open(), "The stale token leaves the new unit's root tracked.");
        self::assertNotSame($first, $second, 'The new unit has a new token.');
        $q->commit();

        self::assertSame(['in the new unit'], $this->ran());
        $this->assertCounts(0, 0);
        $deferral->endUnit($second);
    }
}
