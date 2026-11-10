<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Worker;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\WatchRegistry;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use LogicException;
use PDO;
use Throwable;

/**
 * The fixtures of the end-to-end hook tests of the commit deferral.
 *
 * The queue connection `hooks` uses the database connection Q = `hooks_q`,
 * a copy of the testbench configuration with its own PDO. On SQLite, Q has
 * its own database (a file, or its own in-memory database) with a `jobs`
 * table, so an open transaction on Q never blocks a package write to the
 * mirror. On MySQL and Postgres, Q uses the harness database and its `jobs`
 * table.
 *
 * The deferral watches Q (its beforeStartingTransaction callback) before a
 * test registers its own callbacks. Every hook,
 * event and transaction is real: the swapped or native DatabaseQueue,
 * Laravel's transaction manager, and the real queue event classes.
 */
abstract class EndToEndTestCase extends HooksTestCase
{
    protected const Q = 'hooks_q';

    protected const QUEUE = 'hooks';

    protected const TRANSACTION_EVENTS = [TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class];

    private ?string $file = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $env = $this->testEnvironment();
        $q = (array) $config->get('database.connections.'.TestEnvironment::PRIMARY);

        if ($env->driver === 'sqlite' && ! $env->inMemory()) {
            $this->file ??= SqliteFiles::create();
            $q['database'] = $this->file;
            $q['url'] = null;
        }

        $config->set('database.connections.'.self::Q, $q);
        $config->set('queue.connections.'.self::QUEUE, [
            'driver' => 'database',
            'connection' => self::Q,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->testEnvironment()->driver === 'sqlite') {
            // Q has its own SQLite database: a file, or its own in-memory database.
            $this->q()->getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedSmallInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        // The package's connection callbacks come first.
        $this->app->make(WatchRegistry::class)->watch($this->q(), 0);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->rollBackQ();

            try {
                PackageSession::releaseAll();
            } catch (Throwable) {
            }
        }

        $file = $this->file;
        $this->file = null;

        try {
            parent::tearDown();
        } finally {
            if ($file !== null) {
                SqliteFiles::remove($file);
            }
        }
    }

    /**
     * Ends every transaction that a test left open on Q: Laravel's and one
     * opened on the PDO itself.
     */
    private function rollBackQ(): void
    {
        $instances = [];
        $current = $this->app['db']->getConnections()[self::Q] ?? null;

        if ($current instanceof Connection) {
            $instances[] = $current;
        }

        foreach ($instances as $connection) {
            try {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack(0);
                }
            } catch (Throwable) {
            }

            try {
                $pdo = $connection->getRawPdo();

                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
            }
        }
    }

    protected function q(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->app['db']->connection(self::Q);

        return $connection;
    }

    protected function hooks(): LaravelDatabaseQueue
    {
        $queue = $this->queue(self::QUEUE);

        if ($queue->getDatabase() !== $this->q()) {
            throw new LogicException('The queue object holds the connection instance of Q.');
        }

        return $queue;
    }

    /**
     * A push through the queue connection `hooks`; returns the jobs.id.
     */
    protected function push(object $job): int
    {
        return (int) $this->hooks()->push($job);
    }

    protected function popJob(): DatabaseJob
    {
        $job = $this->hooks()->pop();
        self::assertInstanceOf(DatabaseJob::class, $job, 'pop() reserved a job.');

        return $job;
    }

    /**
     * The worker's JobPopped event of a job popped in process.
     */
    protected function popped(DatabaseJob $job): void
    {
        $this->app['events']->dispatch(new JobPopped(self::QUEUE, $job));
    }

    /**
     * The worker's JobAttempted event of a job run in process.
     */
    protected function attempted(DatabaseJob $job): void
    {
        $this->app['events']->dispatch(new JobAttempted(self::QUEUE, $job));
    }

    protected function workHooks(int $times = 1): void
    {
        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');

        for ($i = 0; $i < $times; $i++) {
            $worker->runNextJob(self::QUEUE, 'default', $this->workerOptions());
        }
    }

    /**
     * @return list<object>
     */
    protected function qJobs(): array
    {
        return $this->q()->table('jobs')->orderBy('id')->get()->all();
    }

    protected function qJob(int $id): ?object
    {
        return $this->q()->table('jobs')->where('id', $id)->first();
    }

    protected function uuidOfJob(int $id): string
    {
        $row = $this->qJob($id);
        self::assertNotNull($row, "The jobs row {$id} exists.");

        return self::uuidOf((string) $row->payload);
    }

    protected function deferral(): CommitDeferral
    {
        return $this->app->make(CommitDeferral::class);
    }

    protected function manager(): DatabaseTransactionsManager
    {
        return $this->app->make('db.transactions');
    }

    /**
     * Laravel's current pending record of Q.
     */
    protected function record(): DatabaseTransactionRecord
    {
        $record = $this->manager()->getPendingTransactions()->filter(static fn ($record) => $record->connection === self::Q)->last();
        self::assertInstanceOf(DatabaseTransactionRecord::class, $record, 'Q has a pending record.');

        return $record;
    }

    protected function skipOnInMemory(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('In-memory SQLite cannot be cloned, so the observer session is Q itself and reads its uncommitted rows (Spec B §3.1, §3.7): run it on file SQLite, MySQL or Postgres.');
        }
    }

    /**
     * Puts an application after-commit callback before every callback that
     * the record has, so it runs before the deferral's callbacks on that
     * record.
     */
    protected static function afterCommitBeforeGate(DatabaseTransactionRecord $record, \Closure $callback): void
    {
        \Closure::bind(static function () use ($record, $callback): void {
            array_unshift($record->callbacks, $callback);
        }, null, DatabaseTransactionRecord::class)();
    }

    /**
     * Puts an application rollback callback before every rollback callback
     * that the record has, so it runs before the deferral's rollback
     * callback.
     */
    protected static function rollbackCallbackBeforeGate(DatabaseTransactionRecord $record, \Closure $callback): void
    {
        \Closure::bind(static function () use ($record, $callback): void {
            array_unshift($record->callbacksForRollback, $callback);
        }, null, DatabaseTransactionRecord::class)();
    }

    /**
     * Runs $callback and returns the application error that it throws.
     */
    protected static function applicationError(callable $callback, string $message): \RuntimeException
    {
        try {
            $callback();
        } catch (\RuntimeException $error) {
            self::assertSame($message, $error->getMessage(), 'The application error reaches the caller unchanged.');

            return $error;
        }

        self::fail("The application error '{$message}' was not thrown.");
    }

    /**
     * Runs $callback with the given events deferred (all events when null).
     *
     * @param  list<class-string>|null  $events
     */
    protected function deferEvents(callable $callback, ?array $events = null): mixed
    {
        return $this->app['events']->defer($callback, $events);
    }

    /**
     * Runs $callback with the transaction events of every connection deferred.
     */
    protected function deferTransactionEvents(callable $callback): mixed
    {
        return $this->deferEvents($callback, self::TRANSACTION_EVENTS);
    }

    /**
     * The first `insert into` the jobs table of Q.
     */
    protected static function isJobsInsert(string $sql): bool
    {
        return str_starts_with(strtolower(ltrim($sql)), 'insert into') && str_contains($sql, 'jobs');
    }

    /**
     * The deferral holds no tracked root, and no item waits.
     */
    protected function assertNothingDeferred(): void
    {
        self::assertSame(0, $this->deferral()->open(), 'No root is tracked.');
        self::assertSame(0, $this->deferral()->held(), 'No item waits for a commit.');
    }

    /**
     * Ends the unit of work of the test's process, as the terminating
     * callback does at the end of a request, and begins the next one.
     */
    protected function endUnit(): void
    {
        $this->app->terminate();
    }

    /**
     * Laravel 13 runs the rollback callbacks of the committed records that
     * the manager removes; Laravel 12 removes them silently (the boundary of
     * CommitDeferralTest). Every supported 13.x has it: the floor 13.30 is
     * above 13.21, where it came.
     */
    protected function removingCommittedChildrenRunsRollbackCallbacks(): bool
    {
        return version_compare($this->app->version(), '13.0.0', '>=');
    }

    protected static function rollbackUnverified(): int
    {
        return Degradation::record()['rollback_unverified'] ?? 0;
    }
}
