<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Observation;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Justrau\Orrery\Observation\CommitDeferral;

/**
 * What the commit deferral costs the application's transactions that never
 * push. The manager lookups are
 * counted through the deferral's manager seam, so a test sees every read of
 * the pending or committed lists.
 *
 * The volume loops run 1,000 times on SQLite (in memory or a file: a
 * fraction of a second) and 100 times on MySQL and Postgres, where each loop
 * costs server round trips (loops()). The rules are PHP and do not depend
 * on the engine; the server runs still prove them on the real driver.
 */
final class DeferralCostTest extends DeferralTestCase
{
    private int $lookups = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(CommitDeferral::class, $this->newDeferral(function (): DatabaseTransactionsManager {
            $this->lookups++;

            return $this->manager();
        }, $this->registry));
    }

    /**
     * Rejects: per-statement or per-transaction package work beyond the
     * counter increment and the cheap checks, and work done before the
     * cheap check returns.
     */
    public function test_transactions_without_a_push_cost_nothing(): void
    {
        $q = $this->q();

        // A worker: one pop() registers both connection callbacks.
        $initial = self::connectionCallbacks($q);
        $this->pop('first pop');
        self::assertSame(['first pop'], $this->ran());
        $before = self::connectionCallbacks($q);
        $baseline = $this->lookups;
        $resolutions = $this->storeResolutions;
        $callbacks = 0;

        foreach ([1, 5] as $statements) {
            for ($index = 0; $index < $this->loops(); $index++) {
                $q->transaction(function () use ($q, $statements, &$callbacks): void {
                    for ($statement = 0; $statement < $statements; $statement++) {
                        $q->select('select ?', [$statement]);
                    }

                    $callbacks += self::packageCallbacksOn($this->record());
                });
            }
        }

        self::assertSame($baseline, $this->lookups, 'No callback of the deferral read the manager.');
        self::assertSame($resolutions, $this->storeResolutions, 'No callback resolved the item store.');
        self::assertSame(0, $callbacks, 'No record of an application transaction got a package callback.');
        self::assertSame($before, self::connectionCallbacks($q), 'No connection callback was added.');
        self::assertSame(1, $before['beforeStartingTransaction'] - $initial['beforeStartingTransaction'], 'The deferral has one beforeStartingTransaction callback.');
        self::assertSame(1, $before['beforeExecuting'] - $initial['beforeExecuting'], 'The deferral has one beforeExecuting callback.');
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: an eager witness, registered at the first capture of any hook.
     */
    public function test_dispatch_only_process_has_no_statement_callback(): void
    {
        $q = $this->q();
        $before = self::connectionCallbacks($q)['beforeExecuting'];

        $this->raise('outside');
        $q->transaction(function (): void {
            $this->raise('inside');
        });

        self::assertSame(['outside', 'inside'], $this->ran());
        self::assertSame($before, self::connectionCallbacks($q)['beforeExecuting'], 'A process that only dispatches has no beforeExecuting callback of the deferral.');
        self::assertSame(1, $this->deferral()->watchedInstances());
    }

    /**
     * Rejects: deferring every pop because pop() uses transaction()
     * internally, or callbacks on records other than the parent's own.
     */
    public function test_pop_outside_a_transaction_holds_nothing(): void
    {
        $other = $this->q(self::B);
        $other->beginTransaction();
        $otherRecord = $this->record($other);
        $own = null;

        $this->pop('reserved', null, function (Connection $q) use (&$own): void {
            $own = $this->record($q);
        });

        self::assertSame(['reserved'], $this->ran(), 'The pop at level 0 runs at once.');
        self::assertInstanceOf(DatabaseTransactionRecord::class, $own);
        self::assertCount(1, $own->getCallbacks(), "The own record has only the witness's commit mark.");
        self::assertCount(0, $own->getCallbacksForRollback());
        self::assertSame(0, self::packageCallbacksOn($otherRecord), 'No callback on any other record.');
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());
        $other->rollBack();
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: a watch registry in unit-of-work state, which registers the
     * connection callbacks again in each worker loop.
     */
    public function test_worker_loop_registers_one_callback_per_connection(): void
    {
        $q = $this->q();
        $before = self::connectionCallbacks($q);

        for ($loop = 0; $loop < $this->loops(); $loop++) {
            $this->deferral()->beginUnit();
            $this->pop("pop {$loop}");
            $q->transaction(function () use ($loop): void {
                $this->raise("push {$loop}");
            });
            $this->deferral()->endUnit();
        }

        $after = self::connectionCallbacks($q);
        self::assertSame(1, $after['beforeStartingTransaction'] - $before['beforeStartingTransaction']);
        self::assertSame(1, $after['beforeExecuting'] - $before['beforeExecuting']);
        self::assertCount(2 * $this->loops(), $this->ran());
        self::assertSame(0, $this->deferral()->open());
        $this->assertCounts(0, 0);
    }

    /**
     * The item store is recreated for each of 1,000 units (100 on a server
     * engine; a scoped binding), and the process registry keeps the connection callbacks.
     *
     * Rejects: callbacks registered per store (they stack up), and a
     * callback that captures the store that registered it (the stale root
     * of the current store is never seen).
     */
    public function test_recreated_stores_share_one_callback_per_connection(): void
    {
        $q = $this->q();
        $write = $this->q(self::Q.'::write');
        $before = self::connectionCallbacks($q);

        for ($loop = 0; $loop < $this->loops(); $loop++) {
            $store = $this->newDeferral(null, $this->registry);
            $this->app->instance(CommitDeferral::class, $store);
            $token = $store->beginUnit();
            $this->pop("pop {$loop}");
            $q->transaction(function () use ($loop): void {
                $this->raise("push {$loop}");
            });
            $store->endUnit($token);
        }

        $after = self::connectionCallbacks($q);
        self::assertSame(1, $after['beforeStartingTransaction'] - $before['beforeStartingTransaction']);
        self::assertSame(1, $after['beforeExecuting'] - $before['beforeExecuting']);
        self::assertCount(2 * $this->loops(), $this->ran());
        $this->assertCounts(0, 0);

        // The registry's callbacks decide for the current store: its stale root
        // is found when the write instance begins at level 0.
        $this->raise('watch the write instance', $write);
        $q->beginTransaction();
        $this->raise('stale');
        $pdo = $q->getPdo();
        $q->setPdo($pdo);
        $write->beginTransaction();

        $this->assertCounts(1, 0);
        self::assertSame(0, $this->deferral()->open());
        $write->rollBack();
        $pdo->rollBack();
    }

    /**
     * The count of a volume loop: 1,000 on SQLite, 100 on a server engine.
     */
    private function loops(): int
    {
        return $this->testEnvironment()->isServer() ? 100 : 1000;
    }

    private static function packageCallbacksOn(DatabaseTransactionRecord $record): int
    {
        return count($record->getCallbacks()) + count($record->getCallbacksForRollback());
    }
}
