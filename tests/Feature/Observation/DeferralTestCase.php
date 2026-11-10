<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Observation;

use ArrayObject;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\InMemoryDropCounter;
use Justrau\Orrery\Observation\WatchRegistry;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * The fixtures of the commit deferral tests.
 *
 * The queue connection Q is the database connection `deferral_q`: a copy of
 * the testbench configuration with its own instance and PDO (on in-memory
 * SQLite, its own in-memory database), so a test can purge, reconnect or
 * replace its PDO without touching the harness tables. `deferral_b` is an
 * unrelated in-memory SQLite connection. No test runs DDL: the parent calls
 * run plain selects, so every test runs on every engine and schema mode.
 *
 * A hook is a labelled closure that calls the deferral exactly as the hooks
 * of DatabaseQueue will (CommitDeferral::around()): raise() is an insert hook
 * (no own transaction), pop() is a hook whose parent runs one transaction()
 * whose first statement is the locking read. The observation appends its
 * label to $this->ran when it runs. Every test uses the real transaction
 * manager of the installed Laravel version.
 *
 * Each item of raise() and pop() carries 1 byte, so the check that the
 * held bytes equal the held items (after each raise() and pop(), and in
 * the tear-down) proves the byte counter in every deferral test.
 */
abstract class DeferralTestCase extends TestCase
{
    protected const Q = 'deferral_q';

    protected const B = 'deferral_b';

    protected const INSERT = "select 'insert'";

    protected const POP = "select 'pop'";

    protected const RESERVE = "select 'reserve'";

    /** @var ArrayObject<int, string> */
    protected ArrayObject $ran;

    protected InMemoryDropCounter $drops;

    /** The process registry: its callbacks resolve the bound CommitDeferral. */
    protected WatchRegistry $registry;

    /** How often the registry's callbacks resolved the current store. */
    protected int $storeResolutions = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $config->set('database.connections.'.self::Q, $config->get('database.connections.'.TestEnvironment::PRIMARY));
        $config->set('database.connections.'.self::B, ['driver' => 'sqlite', 'url' => null, 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ran = new ArrayObject;
        $this->drops = new InMemoryDropCounter;
        $this->registry = new WatchRegistry(function (): CommitDeferral {
            $this->storeResolutions++;

            return $this->app->make(CommitDeferral::class);
        });
        $this->app->instance(WatchRegistry::class, $this->registry);
        $this->app->instance(CommitDeferral::class, $this->newDeferral(null, $this->registry));
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->assertHeldBytesFollowHeld();

            foreach ($this->app['db']->getConnections() as $connection) {
                try {
                    if ($connection->transactionLevel() > 0) {
                        $connection->rollBack(0);
                    }
                } catch (Throwable) {
                    // A broken transaction: the connection goes with the application.
                }
            }
        }

        parent::tearDown();
    }

    /**
     * A store. Without $registry, it has a registry of its own (a store
     * that the container does not bind).
     *
     * @param  (Closure(): DatabaseTransactionsManager)|null  $manager
     */
    protected function newDeferral(?Closure $manager = null, ?WatchRegistry $registry = null): CommitDeferral
    {
        return new CommitDeferral($this->drops, Watcher::instance(), $registry, $manager);
    }

    protected function deferral(): CommitDeferral
    {
        return $this->app->make(CommitDeferral::class);
    }

    protected function q(?string $name = null): Connection
    {
        /** @var Connection $connection */
        $connection = $this->app['db']->connection($name ?? self::Q);

        return $connection;
    }

    /**
     * An insert hook on $q: the parent runs one statement (or $parent), and
     * the observation appends $label.
     *
     * @param  (Closure(): mixed)|null  $parent
     */
    protected function raise(string $label, ?Connection $q = null, ?Closure $parent = null): mixed
    {
        $q ??= $this->q();

        $result = $this->deferral()->around($q, 0, $parent ?? static fn () => $q->select(self::INSERT), $this->observationFor($label), CommitDeferral::OPERATION, 1);
        $this->assertHeldBytesFollowHeld();

        return $result;
    }

    /**
     * Every item of raise() and pop() carries 1 byte.
     */
    protected function assertHeldBytesFollowHeld(): void
    {
        $deferral = $this->deferral();
        self::assertSame($deferral->held(), $deferral->heldBytes(), 'The held bytes follow the held items (1 byte each).');
    }

    /**
     * An insert hook on $q whose parent sends no statement. The hold-cap
     * tests raise 10,000 items with it: the cap is a count in PHP, and an
     * insert hook's parent statement changes neither the capture nor the
     * decision, so 10,000 server round trips would prove nothing more. The
     * item at the cap still goes through raise().
     */
    protected function raiseWithoutStatement(string $label, ?Connection $q = null): mixed
    {
        return $this->raise($label, $q, static fn (): null => null);
    }

    /**
     * A pop() or deleteAndRelease() hook on $q: the parent runs one
     * transaction() whose first statement is the locking read, then the
     * reserving statement; $inside runs between them.
     *
     * @param  (Closure(Connection): void)|null  $inside
     */
    protected function pop(string $label, ?Connection $q = null, ?Closure $inside = null): mixed
    {
        $q ??= $this->q();

        $result = $this->deferral()->around($q, 1, static fn () => $q->transaction(static function () use ($q, $inside): string {
            $q->select(self::POP);

            if ($inside !== null) {
                $inside($q);
            }

            $q->select(self::RESERVE);

            return 'job';
        }), $this->observationFor($label), CommitDeferral::OPERATION, 1);
        $this->assertHeldBytesFollowHeld();

        return $result;
    }

    /**
     * @return Closure(mixed): (Closure(): void)
     */
    protected function observationFor(string $label): Closure
    {
        $ran = $this->ran;

        return static fn (mixed $result): Closure => static function () use ($ran, $label): void {
            $ran[] = $label;
        };
    }

    /**
     * @return list<string>
     */
    protected function ran(): array
    {
        return array_values($this->ran->getArrayCopy());
    }

    protected function assertCounts(int $unverified, int $rollbackUnverified, string $message = ''): void
    {
        self::assertSame(
            ['unverified' => $unverified, 'rollback_unverified' => $rollbackUnverified],
            ['unverified' => $this->drops->unverifiedCount(), 'rollback_unverified' => $this->drops->rollbackUnverifiedCount()],
            $message,
        );
    }

    protected function manager(): DatabaseTransactionsManager
    {
        return $this->app->make('db.transactions');
    }

    /**
     * Laravel's current pending record of $q's name.
     */
    protected function record(?Connection $q = null): DatabaseTransactionRecord
    {
        $name = ($q ?? $this->q())->getName();
        $record = $this->manager()->getPendingTransactions()->filter(static fn ($record) => $record->connection === $name)->last();
        self::assertInstanceOf(DatabaseTransactionRecord::class, $record, 'The connection has a pending record.');

        return $record;
    }

    /**
     * Puts an application after-commit callback before every callback that
     * the record has.
     */
    protected static function afterCommitFirst(DatabaseTransactionRecord $record, Closure $callback): void
    {
        Closure::bind(static function () use ($record, $callback): void {
            array_unshift($record->callbacks, $callback);
        }, null, DatabaseTransactionRecord::class)();
    }

    /**
     * Puts an application rollback callback before every rollback callback
     * that the record has.
     */
    protected static function rollbackCallbackFirst(DatabaseTransactionRecord $record, Closure $callback): void
    {
        Closure::bind(static function () use ($record, $callback): void {
            array_unshift($record->callbacksForRollback, $callback);
        }, null, DatabaseTransactionRecord::class)();
    }

    /**
     * Runs $callback and asserts that it throws the application error.
     */
    protected static function catchApplicationError(Closure $callback, string $message): void
    {
        try {
            $callback();
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage(), 'The application error reaches the caller unchanged.');

            return;
        }

        self::fail("The application error '{$message}' was not thrown.");
    }

    /**
     * Laravel 13.39 and later roll the transactions manager back when a
     * COMMIT inside transaction() fails (laravel/framework#62206).
     */
    protected function failedCommitRollsTheManagerBack(): bool
    {
        return version_compare($this->app->version(), '13.39.0', '>=');
    }

    /**
     * Laravel 13 runs the rollback callbacks of the committed records that
     * the manager removes (as children of a rolled-back record, and of the
     * name at a rollback to level 0); Laravel 12 removes them silently. It
     * came in 13.21.0, so every supported 13.x has it (the floor 13.30.0;
     * read in its tag source and in the 12.69.0 and 12.71.0 sources).
     */
    protected function removingCommittedChildrenRunsRollbackCallbacks(): bool
    {
        return version_compare($this->app->version(), '13.0.0', '>=');
    }

    /**
     * Laravel 13 rolls the transactions manager back at a disconnect (a
     * purge or a reconnect); Laravel 12 only drops the PDOs. It came in
     * 13.17.0, so every supported 13.x has it (the floor 13.30.0; read in
     * its tag source and in the 12.69.0 source).
     */
    protected function disconnectRollsTheManagerBack(): bool
    {
        return version_compare($this->app->version(), '13.0.0', '>=');
    }

    /**
     * The callbacks that a connection instance holds.
     *
     * @return array{beforeStartingTransaction: int, beforeExecuting: int}
     */
    protected static function connectionCallbacks(Connection $connection): array
    {
        return Closure::bind(static fn (): array => [
            'beforeStartingTransaction' => count($connection->beforeStartingTransaction),
            'beforeExecuting' => count($connection->beforeExecutingCallbacks),
        ], null, Connection::class)();
    }
}
