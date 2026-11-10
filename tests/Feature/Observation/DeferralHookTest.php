<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Observation;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Justrau\Orrery\Observation\WatchRegistry;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Registration\Modules\ObservationModule;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\HealthEvents;
use Justrau\Orrery\Tests\Feature\Hooks\EndToEndTestCase;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\LostAckCommitPdo;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * The swapped queue hooks on the commit deferral: real
 * push, pop and release calls, application listeners and callbacks, the
 * real transactions manager, and the units of work of ObservationModule
 * (jobs, commands and the process). Each test asserts the jobs rows, the
 * mirror rows and both counters of the degradation record.
 */
#[FreshSchema]
final class DeferralHookTest extends EndToEndTestCase
{
    /**
     * A second queue database connection Q2 and its queue connection. On
     * file SQLite, Q2 has its own file, so a transaction that a job leaves
     * open on Q2 never blocks the worker's writes to Q; on MySQL and
     * Postgres it uses the harness database.
     */
    private const Q2 = 'hooks_q2';

    private const QUEUE2 = 'hooks2';

    private ?string $file2 = null;

    protected function getPackageProviders($app): array
    {
        ThrowingCommandStartModule::$armed = false;
        Modules::addForTesting(ThrowingCommandStartModule::class, ObservationModule::class);

        return parent::getPackageProviders($app);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $q2 = (array) $config->get('database.connections.'.self::Q);

        if ($this->testEnvironment()->driver === 'sqlite' && ! $this->testEnvironment()->inMemory()) {
            $this->file2 ??= SqliteFiles::create();
            $q2['database'] = $this->file2;
        }

        $config->set('database.connections.'.self::Q2, $q2);
        $config->set('queue.connections.'.self::QUEUE2, ['connection' => self::Q2] + (array) $config->get('queue.connections.'.self::QUEUE));
    }

    protected function setUp(): void
    {
        parent::setUp();

        DeferralHookJob::$scenarios = [];
        DeferralSyncJob::$runs = 0;

        if ($this->file2 !== null) {
            $this->app['db']->connection(self::Q2)->getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedSmallInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        // Artisan::call() raises the command events in a unit test too.
        $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

        // Not static: Laravel binds a closure command to the command object.
        Artisan::command('orrery-test:noop', function (): void {
        });
        Artisan::command('orrery-test:push {connection}', function (string $connection): void {
            app('queue')->connection($connection)->push(new BasicJob);
        });
    }

    protected function tearDown(): void
    {
        DeferralHookJob::$scenarios = [];
        DeferralSyncJob::$runs = 0;

        if ($this->app !== null) {
            $q2 = $this->app['db']->getConnections()[self::Q2] ?? null;

            if ($q2 instanceof Connection && $q2->transactionLevel() > 0) {
                try {
                    $q2->rollBack(0);
                } catch (Throwable) {
                }
            }

            $this->app['db']->purge(self::Q2);
        }

        $file = $this->file2;
        $this->file2 = null;

        try {
            parent::tearDown();
        } finally {
            if ($file !== null) {
                SqliteFiles::remove($file);
            }
        }
    }

    /**
     * A synchronous QueryExecuted listener rolls back T before the parent's
     * insert returns: the record that was open before the call ended during
     * it.
     *
     * Rejects: running at once because the level is 0 after the call.
     */
    public function test_listener_rolls_back_the_transaction_during_the_insert(): void
    {
        $q = $this->q();
        $this->onFirstJobsInsert(static function (Connection $connection): void {
            $connection->rollBack();
        });

        $q->beginTransaction();
        $id = $this->push(new BasicJob);

        self::assertGreaterThan(0, $id, "The host gets the parent's id.");
        self::assertSame(0, $q->transactionLevel());
        self::assertSame([], $this->qJobs(), 'The insert rolled back with T.');
        self::assertSame(0, $this->mirrorCount());
        $this->assertCounts(1, 0);
        $this->assertNothingDeferred();
    }

    /**
     * The listener rolls back the savepoint S1 of the insert and begins S2,
     * which then commits with its root: the level is the same before and
     * after the call, but the chain is not.
     *
     * Rejects: comparing only the levels (S2's commit would mirror the
     * rolled-back insert).
     */
    public function test_listener_replaces_the_savepoint_during_the_insert(): void
    {
        $q = $this->q();
        $this->onFirstJobsInsert(static function (Connection $connection): void {
            $connection->rollBack();
            $connection->beginTransaction();
        });

        $q->beginTransaction();
        $q->beginTransaction();
        $this->push(new BasicJob);
        self::assertSame(2, $q->transactionLevel());
        $q->commit();
        $q->commit();

        self::assertSame([], $this->qJobs(), 'The insert rolled back with S1.');
        self::assertSame(0, $this->mirrorCount());
        $this->assertCounts(1, 0);
        $this->assertNothingDeferred();
    }

    /**
     * The GateOrderTest sequence: a savepoint callback of root A begins root
     * B, which commits during A's walk; inside B's callback neither item has
     * run. In the second case the savepoint callback throws after B
     * committed: A's walk stops, and the end of the unit runs B's decided
     * item and counts A's.
     *
     * Rejects: cleaning a "stopped" walk at a nested begin,
     * and B's item run before A's.
     */
    #[DataProvider('walks')]
    public function test_nested_root_in_a_walk_keeps_the_outer_root(bool $throws): void
    {
        $q = $this->q();
        $seen = null;
        $b = null;

        $q->beginTransaction();
        $a = $this->push(new BasicJob);
        $q->beginTransaction();
        $this->record()->addCallback(function () use ($q, &$seen, &$b, $throws): void {
            $q->beginTransaction();
            $b = $this->push(new BasicJob);
            $this->record()->addCallback(function () use (&$seen): void {
                $seen = $this->mirrorCount();
            });
            $q->commit();

            if ($throws) {
                throw new RuntimeException('savepoint callback failed');
            }
        });
        $q->commit();

        if ($throws) {
            self::applicationError(static fn () => $q->commit(), 'savepoint callback failed');
        } else {
            $q->commit();
        }

        self::assertSame(0, $seen, "Inside B's callback, neither item has run.");
        self::assertIsInt($b);

        if (! $throws) {
            self::assertSame([$a, $b], $this->mirroredJobIds(), 'A, then B.');
            $this->assertCounts(0, 0);
            $this->assertNothingDeferred();

            return;
        }

        self::assertSame([], $this->mirroredJobIds(), 'Nothing runs from a stopped walk before its unit ends.');
        self::assertSame(1, $this->deferral()->open(), 'A stays tracked: a nested begin does not clean it.');

        $this->endUnit();

        self::assertSame([$b], $this->mirroredJobIds(), "The end of the unit runs B's decided item.");
        $this->assertCounts(1, 0);
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function walks(): array
    {
        return ['the walk completes' => [false], 'a callback stops the walk' => [true]];
    }

    /**
     * Root A holds an item; a committed savepoint's callback begins root B
     * and pushes in it during A's walk. B commits (its item joins A's tail),
     * rolls back (only A's item runs), or stays open while A runs (B's item
     * runs at B's commit).
     *
     * Rejects: the order of the probe NestedRootWalkProbeTest (['b', 'a']),
     * and a root committed inside a walk run at once.
     */
    #[DataProvider('innerRoots')]
    public function test_root_committed_inside_a_walk_runs_after_the_walking_root(string $inner): void
    {
        $q = $this->q();
        $seen = null;
        $b = null;

        $q->beginTransaction();
        $a = $this->push(new BasicJob);
        $q->beginTransaction();
        $this->record()->addCallback(function () use ($q, $inner, &$seen, &$b): void {
            $q->beginTransaction();
            $b = $this->push(new BasicJob);

            if ($inner === 'commit') {
                $this->record()->addCallback(function () use (&$seen): void {
                    $seen = $this->mirrorCount();
                });
                $q->commit();
            } elseif ($inner === 'rollback') {
                $q->rollBack();
            }
        });
        $q->commit();
        $q->commit();
        self::assertIsInt($b);

        if ($inner === 'commit') {
            self::assertSame(0, $seen, "Nothing ran during B's callback.");
            self::assertSame([$a, $b], $this->mirroredJobIds());
            $this->assertCounts(0, 0);
        } elseif ($inner === 'rollback') {
            self::assertSame([$a], $this->mirroredJobIds(), "Only A's item runs.");
            $this->assertCounts(0, 1);
        } else {
            self::assertSame(1, $q->transactionLevel(), 'B is still open.');
            self::assertSame([$a], $this->mirroredJobIds(), 'A runs while B stays open.');
            $q->commit();
            self::assertSame([$a, $b], $this->mirroredJobIds(), "B's item runs at B's commit.");
            $this->assertCounts(0, 0);
        }

        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function innerRoots(): array
    {
        return ['B commits' => ['commit'], 'B rolls back' => ['rollback'], 'B stays open while A runs' => ['open']];
    }

    /**
     * A lost COMMIT acknowledgement (LostAckCommitPdo) and the rollback and
     * unit paths around it. The job row exists or not, as the host decided;
     * no mirror row exists, the item is counted once, and nothing replays.
     * Version-exact: before Laravel 13.39 a failed COMMIT inside
     * transaction() leaves its record pending (the stale rule or the end of
     * the unit counts it as unverified); from 13.39 the manager rolls it
     * back (rollback_unverified).
     *
     * Rejects: dropping a stale or still-open root silently, an event or a
     * PDO state as the evidence, any exemption of a rollback path from the
     * count, and a unit end that drops the roots that were open or in their
     * walk at its start (the Artisan::call() and dispatchSync() cases).
     */
    #[DataProvider('lostCommits')]
    public function test_lost_commit_acknowledgement_is_counted(string $case): void
    {
        $this->skipOnInMemory();
        $q = $this->q();
        $rollsBack = version_compare($this->app->version(), '13.39.0', '>=');

        [$jobs, $mirrored, $unverified, $rollback] = match ($case) {
            'transaction()' => $this->lostCommitInTransaction(false),
            'transaction() inside Event::defer()' => $this->lostCommitInTransaction(true),
            'manual commit() with catch' => $this->manualCommitWithCatch(),
            'manual commit() without catch, in a job' => $this->manualCommitWithoutCatch(),
            'a transaction leaked past the end of a job' => $this->leakedTransaction(),
            'Artisan::call() inside a job transaction' => $this->artisanInJobTransaction(),
            "Artisan::call() whose command is the connection's first queue use" => $this->firstUseInCommand(),
            'Artisan::call() and dispatchSync() inside a walk' => $this->commandsInsideAWalk(),
            'manual commit() inside a transaction() closure that throws' => $this->manualCommitInsideAClosure(),
            'a failed rollback' => $this->failedRollback(),
            'a transaction() closure that throws after a push' => $this->closureThrowsAfterPush(),
        };

        if (str_starts_with($case, 'transaction()')) {
            // Before 13.39 the record stays pending (unverified); from 13.39 the manager rolls it back.
            [$unverified, $rollback] = $rollsBack ? [0, 1] : [1, 0];
        }

        self::assertSame(0, $q->transactionLevel(), 'The host ended every transaction of Q.');
        self::assertSame($jobs, $this->jobsRows(), 'The jobs rows that the host committed.');
        self::assertSame($mirrored, $this->mirrorCount(), 'The mirror rows.');
        $this->assertCounts($unverified, $rollback);
        $this->assertNothingDeferred();

        // No later replay: another root of Q commits, and the unit ends.
        $q->beginTransaction();
        $q->commit();
        $this->endUnit();
        self::assertSame($mirrored, $this->mirrorCount(), 'Nothing replays.');
        $this->assertCounts($unverified, $rollback);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lostCommits(): array
    {
        $cases = [];

        foreach ([
            'transaction()',
            'transaction() inside Event::defer()',
            'manual commit() with catch',
            'manual commit() without catch, in a job',
            'a transaction leaked past the end of a job',
            'Artisan::call() inside a job transaction',
            "Artisan::call() whose command is the connection's first queue use",
            'Artisan::call() and dispatchSync() inside a walk',
            'manual commit() inside a transaction() closure that throws',
            'a failed rollback',
            'a transaction() closure that throws after a push',
        ] as $case) {
            $cases[$case] = [$case];
        }

        return $cases;
    }

    /**
     * A beforeExecuting callback begins T just before the hook's insert
     * (at level 0 for a push; inside deleteAndRelease()'s own transaction
     * for a release), and a QueryExecuted listener ends T before the parent
     * returns: T was born and ended inside the call. Nothing proves the
     * outcome of the insert: the hook's item is dropped and counted.
     *
     * Rejects: accepting an empty chain as proof,
     * and a begin counter that ignores a transaction born inside the call.
     */
    #[DataProvider('bornAndEnded')]
    public function test_transaction_born_and_ended_inside_the_call(string $hook, string $outcome): void
    {
        $q = $this->q();
        $job = null;

        if ($hook === 'release') {
            $this->push(new BasicJob);
            $job = $this->popJob();
        }

        $uuid = $job === null ? null : self::uuidOf($job->getRawBody());
        $began = false;
        $ended = false;

        $q->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use (&$began): void {
            if (! $began && self::isJobsInsert($query)) {
                $began = true;
                $connection->beginTransaction();
            }
        });
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$ended, $outcome): void {
            if ($ended || $event->connectionName !== self::Q || ! self::isJobsInsert($event->sql)) {
                return;
            }

            $ended = true;

            if ($outcome === 'commit') {
                $event->connection->commit();
            } else {
                $event->connection->rollBack();
            }

            if ($outcome === 'replaced') {
                $event->connection->beginTransaction();
                $event->connection->commit();
            }
        });

        if ($job === null) {
            $this->push(new BasicJob);
        } else {
            $job->release(0);
        }

        self::assertTrue($began && $ended);
        self::assertSame(0, $q->transactionLevel());
        self::assertCount($outcome === 'commit' ? 1 : 0, $this->qJobs(), 'The jobs row exists only when T committed.');
        $this->assertCounts(1, 0);
        $this->assertNothingDeferred();

        if ($uuid !== null) {
            self::assertSame('reserved', $this->rowOrFail($uuid)->status, 'The release is not mirrored.');

            return;
        }

        self::assertSame(0, $this->mirrorCount(), 'The push is not mirrored.');

        if ($outcome === 'commit') {
            $popped = $this->popJob();
            self::assertSame('reserved', $this->rowOrFail(self::uuidOf($popped->getRawBody()))->status, 'R7 creates the row at the reservation.');
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bornAndEnded(): array
    {
        $cases = [];

        foreach (['push', 'release'] as $hook) {
            foreach (['rollback', 'commit', 'replaced'] as $outcome) {
                $cases["{$hook}, {$outcome}"] = [$hook, $outcome];
            }
        }

        return $cases;
    }

    /**
     * Inside a host transaction, the manager's getPendingTransactions()
     * throws: the hooks still insert their jobs rows and return the
     * parent's values, each parent runs once, and each hook counts one
     * drop. When the parent itself throws, the host gets the same exception
     * object, and nothing is counted.
     *
     * Rejects: a capture outside the isolation boundary, and an
     * unverified capture read as autocommit.
     */
    public function test_failing_capture_never_changes_the_host_call(): void
    {
        $q = $this->q();
        $this->push(new BasicJob);
        $job = $this->popJob();
        $real = $this->manager();
        $throwing = new ThrowingPendingManager;
        $this->app->instance('db.transactions', $throwing);

        try {
            $q->beginTransaction();
            $throwing->armed = true;
            $id = $this->push(new BasicJob);
            $job->release(0);
            $throwing->armed = false;
            $q->commit();
        } finally {
            $throwing->armed = false;
            $this->app->instance('db.transactions', $real);
        }

        $jobs = $this->qJobs();
        self::assertCount(2, $jobs, 'Each parent ran once: the push and the release inserted one row each.');
        self::assertSame((int) $jobs[0]->id, $id, "The host gets the parent's id.");
        self::assertGreaterThan(0, $throwing->calls, 'The capture read the throwing manager.');
        self::assertSame('reserved', $this->rowOrFail(self::uuidOf($job->getRawBody()))->status, 'The release is not mirrored.');
        self::assertNull($this->row(self::uuidOf((string) $jobs[0]->payload)), 'The push is not mirrored.');
        $this->assertCounts(2, 0);

        // The parent throws: the same exception object, and nothing counted.
        $error = new RuntimeException('insert refused');
        $q->beforeExecuting(static function (string $query) use ($error): void {
            if (self::isJobsInsert($query)) {
                throw $error;
            }
        });

        foreach (['push' => fn () => $this->push(new BasicJob), 'release' => fn () => $this->popJob()->release(0)] as $hook => $call) {
            try {
                $call();
                self::fail("The insert error leaves {$hook}.");
            } catch (RuntimeException $caught) {
                self::assertSame($error, $caught, 'The caller gets the same exception object.');
            }
        }

        $this->assertCounts(2, 0);
        $this->assertNothingDeferred();
    }

    /**
     * A QueryExecuted listener on the parent's own statement (pop()'s
     * reservation update, the release insert) acts on the parent's own
     * transaction, with no extra begin: it rolls it back, commits it, or
     * rolls it back and begins a new root that the parent's transaction()
     * then commits. A normal return proves nothing: only the commit mark of
     * the own record does.
     *
     * Rejects: the begin count as a commit witness.
     */
    #[DataProvider('ownTransactions')]
    public function test_listener_rolls_back_the_parents_own_transaction(string $hook, string $outcome): void
    {
        $q = $this->q();
        $this->push(new BasicJob);
        $uuid = $this->uuidOfJob((int) $this->qJobs()[0]->id);
        $job = $hook === 'release' ? $this->popJob() : null;
        $statement = $hook === 'pop' ? 'update' : 'insert into';
        $acted = false;

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$acted, $outcome, $statement): void {
            if ($acted || $event->connectionName !== self::Q || ! str_starts_with(strtolower(ltrim($event->sql)), $statement) || ! str_contains($event->sql, 'jobs')) {
                return;
            }

            $acted = true;

            if ($outcome === 'committed') {
                $event->connection->commit();
            } else {
                $event->connection->rollBack();
            }

            if ($outcome === 'replaced') {
                $event->connection->beginTransaction();
            }
        });

        if ($job === null) {
            $popped = $this->hooks()->pop();
            self::assertInstanceOf(DatabaseJob::class, $popped, "The host gets the parent's job.");
        } else {
            $job->release(0);
        }

        self::assertTrue($acted);
        self::assertSame(0, $q->transactionLevel());
        $row = $this->rowOrFail($uuid);

        if ($outcome === 'committed') {
            self::assertSame($hook === 'pop' ? 'reserved' : 'pending', $row->status, 'The committed own transaction is observed once.');
            $this->assertCounts(0, 0);
        } else {
            self::assertSame($hook === 'pop' ? 'pending' : 'reserved', $row->status, 'The mirror does not change.');
            $this->assertCounts(1, 0);
        }

        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ownTransactions(): array
    {
        $cases = [];

        foreach (['pop', 'release'] as $hook) {
            foreach (['rolled back', 'committed', 'replaced'] as $outcome) {
                $cases["{$hook}, {$outcome}"] = [$hook, $outcome];
            }
        }

        return $cases;
    }

    /**
     * 1,000 worker loops (50 on MySQL and Postgres, where each loop is a
     * full job lifecycle of server round trips; a registry in scoped state
     * adds callbacks from the second loop on), each pushing inside a
     * transaction, with the worker's scoped flush between them: each
     * connection instance gets one beforeStartingTransaction and one
     * beforeExecuting callback of the package.
     *
     * Rejects: a watch registry in scoped state, which registers
     * the callbacks again after every flush.
     */
    public function test_worker_loop_registers_one_callback_per_connection(): void
    {
        $q = $this->q();
        $before = self::callbackCounts($q);
        $loops = $this->testEnvironment()->isServer() ? 50 : 1000;

        for ($loop = 0; $loop < $loops; $loop++) {
            $q->beginTransaction();
            $this->push(new BasicJob);
            $q->commit();
            $this->app->forgetScopedInstances();
            $this->workHooks();
        }

        self::assertSame([], $this->qJobs(), 'Every job ran.');
        self::assertSame($loops, $this->mirror()->table('orrery_jobs')->where('status', 'completed')->count());
        self::assertSame(['beforeStartingTransaction' => $before['beforeStartingTransaction'], 'beforeExecuting' => $before['beforeExecuting'] + 1], self::callbackCounts($q), 'One witness at the first pop(); the begin callback came with the first watch.');
        self::assertSame(1, $this->app->make(WatchRegistry::class)->instances());
        $this->assertCounts(0, 0);
    }

    /**
     * A QueryExecuted listener pushes a job during the insert of a
     * pushRaw() and during the insert of a release: the owned-insert flag
     * is consumed on entry, so each nested push is observed as its own
     * push, and the outer observation still runs.
     *
     * Rejects: an instance counter that suppresses every insert during the
     * call.
     */
    public function test_owned_insert_flag_lets_a_nested_push_through(): void
    {
        $nested = [];
        $armed = false;

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$nested, &$armed): void {
            if ($armed && $event->connectionName === self::Q && self::isJobsInsert($event->sql)) {
                $armed = false;
                $nested[] = $this->push(new BasicJob);
            }
        });

        // The release first: Laravel's insertGetId() reads lastInsertId()
        // after the nested insert (except on Postgres), so the outer call can
        // return the nested id (StatementOwnershipTest); each row is named by
        // its uuid.
        $this->push(new BasicJob);
        $released = $this->popJob();
        $releasedUuid = self::uuidOf($released->getRawBody());
        $armed = true;
        $released->release(0);
        self::assertCount(1, $nested);

        $raw = self::rawPayload(strtolower((string) Str::uuid()));
        $armed = true;
        $this->hooks()->pushRaw($raw);
        self::assertCount(2, $nested);

        foreach ($nested as $id) {
            self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($id))->status, 'The nested push is observed once.');
        }

        $release = $this->rowOrFail($releasedUuid);
        self::assertSame('pending', $release->status, 'The release is observed.');
        self::assertSame(1, (int) $release->releases);
        self::assertSame('pending', $this->rowOrFail(self::uuidOf($raw))->status, 'The pushRaw() is observed.');
        self::assertSame(4, $this->mirrorCount());
        $this->assertCounts(0, 0);
        $this->assertNothingDeferred();
    }
    /**
     * During job A's release hook, a listener on the hook's delete releases
     * another job record directly, or runs a nested deleteAndRelease() of a
     * job without a uuid. Only the release of A's own record belongs to the
     * hook: A's row gets the id of A's own new jobs row. The nested hook's
     * transaction was born and ended inside A's call, so there A's release
     * is a counted drop, and no other insert claims A's row.
     *
     * Rejects: a release() that claims the hook's insert for any record (the
     * hook would name the other insert's id). The nested case is a smoke
     * test: with the identity check, the nested release never claims A's
     * insert, so the save and restore of the uuid-less branch cannot be
     * observed by a test.
     */
    #[DataProvider('foreignReleases')]
    public function test_only_the_hooks_own_record_owns_the_release(string $case): void
    {
        $a = $this->push(new BasicJob);
        $other = $case === 'another record' ? $this->push(new BasicJob) : (int) $this->hooks()->pushRaw(self::rawPayload(null));
        $aUuid = $this->uuidOfJob($a);
        $jobA = $this->popJob();
        $jobOther = $this->popJob();
        self::assertSame([$a, $other], [(int) $jobA->getJobId(), (int) $jobOther->getJobId()]);
        $queue = $this->hooks();
        $done = false;

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$done, $case, $queue, $jobOther): void {
            if ($done || $event->connectionName !== self::Q || ! str_starts_with(strtolower(ltrim($event->sql)), 'delete')) {
                return;
            }

            $done = true;

            if ($case === 'another record') {
                $queue->release('default', $jobOther->getJobRecord(), 0);
            } else {
                $queue->deleteAndRelease('default', $jobOther, 0);
            }
        });

        $jobA->release(0);

        self::assertTrue($done);
        $own = null;

        foreach ($this->qJobs() as $row) {
            if (strtolower((string) (json_decode((string) $row->payload, true)['uuid'] ?? '')) === $aUuid) {
                $own = (int) $row->id;
            }
        }

        self::assertNotNull($own, "A's release inserted its row.");
        $row = $this->rowOrFail($aUuid);

        if ($case !== 'another record') {
            // The nested deleteAndRelease() ran its own transaction inside A's
            // call: A's release has no proof and is dropped and counted.
            self::assertSame('reserved', $row->status, "A's release is not mirrored, and nothing else claimed A's row.");
            self::assertSame($a, (int) $row->job_id);
            self::assertSame(0, (int) $row->releases);
            $this->assertCounts(1, 0);
            $this->assertNothingDeferred();

            return;
        }

        self::assertSame('pending', $row->status);
        self::assertSame($own, (int) $row->job_id, "A's row names A's own new jobs row.");
        self::assertSame(1, (int) $row->releases);
        $this->assertCounts(0, 0);
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignReleases(): array
    {
        return ['a listener releases another record' => ['another record'], 'a nested deleteAndRelease() without a uuid' => ['nested without uuid']];
    }

    /**
     * Inside command A, which holds a root with a push, command B runs; an
     * application CommandStarting listener before the package's throws for
     * B, so B begins no unit. B's CommandFinished then ends nothing, and
     * A's push is mirrored at its commit.
     *
     * Rejects: command units on a stack, where B's end pops A's unit and
     * drops A's root.
     */
    public function test_command_whose_start_listener_threw_ends_no_other_unit(): void
    {
        $thrown = false;
        [$database, $queue] = [self::Q, self::QUEUE];

        // Not static: Laravel binds a closure command to the command object.
        Artisan::command('orrery-test:outer', function () use (&$thrown, $database, $queue): void {
            $q = app('db')->connection($database);
            $q->beginTransaction();
            app('queue')->connection($queue)->push(new BasicJob);
            ThrowingCommandStartModule::$armed = true;

            try {
                Artisan::call('orrery-test:noop');
            } catch (RuntimeException $error) {
                $thrown = $error->getMessage() === ThrowingCommandStartModule::MESSAGE;
            } finally {
                ThrowingCommandStartModule::$armed = false;
            }

            $q->commit();
        });

        Artisan::call('orrery-test:outer');

        self::assertTrue($thrown, "The application listener's error left the inner command.");
        self::assertSame(1, $this->mirrorCount(), "A's push is mirrored at its commit.");
        $this->assertCounts(0, 0);
        $this->assertNothingDeferred();
    }

    /**
     * The held items of the deferral are process state: one store for the
     * process, whose units end them (by design). A
     * scoped flush (a worker loop) between the pushes of one leaked
     * transaction changes nothing: the end of the process counts each one.
     *
     * Rejects: a scoped store, whose flushed stores keep their items
     * uncounted (2, not 3, with an end of the first and the current store).
     */
    public function test_terminating_ends_every_store_that_holds_a_root(): void
    {
        $q = $this->q();
        $q->beginTransaction();
        $this->push(new BasicJob);
        $this->app->forgetScopedInstances();
        $this->push(new BasicJob);
        $this->app->forgetScopedInstances();
        $this->push(new BasicJob);

        $this->endUnit();

        $this->assertCounts(3, 0);
        $q->rollBack();
    }

    /**
     * Octane runs each request in a sandbox: a clone of the base
     * application, set as the container instance. The hooks resolve the
     * deferral through the sandbox, and the terminating callbacks use the
     * base application. A push under a root that never commits is counted
     * when the sandbox terminates.
     *
     * Rejects: a scoped store (the sandbox's own store is never ended), and
     * a process singleton that is first resolved inside the sandbox.
     */
    public function test_an_octane_sandbox_ends_its_own_store(): void
    {
        $base = $this->app;
        $q = $this->q();
        $base->forgetScopedInstances();
        $sandbox = clone $base;
        Container::setInstance($sandbox);

        try {
            $q->beginTransaction();
            $this->push(new BasicJob);
            $sandbox->terminate();

            $this->assertCounts(1, 0);
        } finally {
            Container::setInstance($base);
            $q->rollBack();
        }
    }

    /**
     * Rejects: drop counts that stay in memory, or that the health window
     * folds into one counter (the DropCounter adapter on Degradation).
     */
    public function test_unverified_count_reaches_the_health_events(): void
    {
        $q = $this->q();
        $this->onFirstJobsInsert(static function (Connection $connection): void {
            $connection->rollBack();
        });

        $q->beginTransaction();
        $this->push(new BasicJob);
        $q->beginTransaction();
        $this->push(new BasicJob);
        $q->rollBack();

        $this->endUnit();

        $window = $this->mirror()->table(HealthEvents::TABLE)->orderByDesc('id')->first();
        self::assertNotNull($window, 'The terminating flush wrote the window.');
        self::assertSame([1, 1], [(int) $window->unverified, (int) $window->rollback_unverified]);
    }

    /**
     * @return array{int, int, int, int} jobs rows, mirror rows, unverified, rollback_unverified
     */
    private function lostCommitInTransaction(bool $deferEvents): array
    {
        $q = $this->q();
        $q->setPdo(LostAckCommitPdo::forConfig($q->getConfig()));
        $commit = fn () => $q->transaction(fn () => $this->push(new BasicJob));

        try {
            if ($deferEvents) {
                $this->deferEvents($commit);
            } else {
                $commit();
            }

            self::fail('The lost acknowledgement throws.');
        } catch (PDOException) {
        }

        // A later level-0 begin: the stale rule decides a pending record (before 13.39).
        $q->beginTransaction();
        $q->rollBack();

        return [1, 0, 0, 0];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function manualCommitWithCatch(): array
    {
        $q = $this->q();
        $q->setPdo(LostAckCommitPdo::forConfig($q->getConfig()));
        $q->beginTransaction();
        $this->push(new BasicJob);

        try {
            $q->commit();
            self::fail('The lost acknowledgement throws.');
        } catch (PDOException) {
            $q->rollBack();
        }

        // The COMMIT reached the server: the job row exists.
        return [1, 0, 0, 1];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function manualCommitWithoutCatch(): array
    {
        $this->runInJob(function (Connection $q): void {
            $q->setPdo(LostAckCommitPdo::forConfig($q->getConfig()));
            $q->beginTransaction();
            $this->app['queue']->connection(self::QUEUE2)->push(new BasicJob);
            $q->commit();
        }, self::Q2);

        // The job failed with the exception; its unit's end counted the pending
        // root. The job's own row is failed; the push has none.
        $this->rollBackQ2();

        return [1, 1, 1, 0];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function leakedTransaction(): array
    {
        $this->runInJob(function (Connection $q): void {
            $q->beginTransaction();
            $this->app['queue']->connection(self::QUEUE2)->push(new BasicJob);
        }, self::Q2);

        // The end of the job's unit counted the leaked root; the host then rolls
        // it back. The job's own row is completed.
        $this->rollBackQ2();

        return [0, 1, 1, 0];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function artisanInJobTransaction(): array
    {
        $this->runInJob(function (Connection $q): void {
            $q->transaction(function (): void {
                $this->app['queue']->connection(self::QUEUE2)->push(new BasicJob);
                Artisan::call('orrery-test:noop');
            });
        }, self::Q2);

        // The job's own row is completed (it is not in jobs any more); the push is mirrored at the commit.
        return [1, 2, 0, 0];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function firstUseInCommand(): array
    {
        $watched = null;

        $this->runInJob(function (Connection $q) use (&$watched): void {
            $watched = $this->app->make(WatchRegistry::class)->instances();
            $q->transaction(static function (): void {
                Artisan::call('orrery-test:push', ['connection' => self::QUEUE2]);
            });
        }, self::Q2);

        self::assertSame(1, $watched, 'Only Q was watched when the job began: the command is the first queue use of Q2.');
        self::assertSame(2, $this->app->make(WatchRegistry::class)->instances(), 'The command watched Q2.');

        return [1, 2, 0, 0];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function commandsInsideAWalk(): array
    {
        $q = $this->q();
        $q->beginTransaction();
        $this->push(new BasicJob);
        $root = $this->record();
        $q->beginTransaction();
        $this->record()->addCallback(static function (): void {
            Artisan::call('orrery-test:noop');
        });
        $q->commit();
        self::afterCommitBeforeGate($root, static function (): void {
            Bus::dispatchSync(new DeferralSyncJob);
        });
        $q->commit();

        self::assertSame(1, DeferralSyncJob::$runs, 'The sync job ran inside the walk.');
        DeferralSyncJob::$runs = 0;

        return [1, 1, 0, 0];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function manualCommitInsideAClosure(): array
    {
        $q = $this->q();
        $q->beginTransaction();
        self::applicationError(fn () => $q->transaction(function () use ($q): void {
            $this->push(new BasicJob);
            $q->commit();

            throw new RuntimeException('closure failed after its commit');
        }), 'closure failed after its commit');

        if ($q->transactionLevel() > 0) {
            $q->rollBack(0);
        }

        return [0, 0, 0, 1];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function failedRollback(): array
    {
        $q = $this->q();
        $q->beginTransaction();
        $this->push(new BasicJob);
        $q->getPdo()->rollBack();

        try {
            $q->rollBack();
        } catch (Throwable) {
            // The rollback failed: there is no active transaction.
        }

        return [0, 0, 0, 1];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function closureThrowsAfterPush(): array
    {
        $q = $this->q();
        self::applicationError(fn () => $q->transaction(function (): void {
            $this->push(new BasicJob);

            throw new RuntimeException('closure failed');
        }), 'closure failed');

        return [0, 0, 0, 1];
    }

    /**
     * Pushes a DeferralHookJob that runs $scenario with the connection
     * $connection, and runs it through the real worker: JobProcessing and
     * JobAttempted begin and end its unit.
     *
     * @param  Closure(Connection): void  $scenario
     */
    private function runInJob(Closure $scenario, string $connection): void
    {
        $name = 'scenario-'.count(DeferralHookJob::$scenarios);
        DeferralHookJob::$scenarios[$name] = function () use ($scenario, $connection): void {
            /** @var Connection $db */
            $db = $this->app['db']->connection($connection);
            $scenario($db);
        };

        $this->push(new DeferralHookJob($name));
        $this->workHooks();
    }

    /**
     * The jobs rows of Q and, when it has its own file, of Q2.
     */
    private function jobsRows(): int
    {
        $rows = count($this->qJobs());

        if ($this->file2 !== null) {
            $rows += $this->app['db']->connection(self::Q2)->table('jobs')->count();
        }

        return $rows;
    }

    private function rollBackQ2(): void
    {
        $q2 = $this->app['db']->connection(self::Q2);

        if ($q2->transactionLevel() > 0) {
            $q2->rollBack(0);
        }
    }

    /**
     * Runs $act with the connection once, at the first jobs insert of Q.
     *
     * @param  Closure(Connection): void  $act
     */
    private function onFirstJobsInsert(Closure $act): void
    {
        $done = false;

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$done, $act): void {
            if (! $done && $event->connectionName === self::Q && self::isJobsInsert($event->sql)) {
                $done = true;
                $act($event->connection);
            }
        });
    }

    /**
     * The job ids of the mirror rows, in the order the deferral ran them.
     *
     * @return list<int>
     */
    private function mirroredJobIds(): array
    {
        return $this->mirror()->table('orrery_jobs')->orderBy('id')->pluck('job_id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function assertCounts(int $unverified, int $rollbackUnverified): void
    {
        self::assertSame(
            ['unverified' => $unverified, 'rollback_unverified' => $rollbackUnverified],
            ['unverified' => self::unverified(), 'rollback_unverified' => self::rollbackUnverified()],
        );
    }

    /**
     * @return array{beforeStartingTransaction: int, beforeExecuting: int}
     */
    private static function callbackCounts(Connection $connection): array
    {
        return Closure::bind(static fn (): array => [
            'beforeStartingTransaction' => count($connection->beforeStartingTransaction),
            'beforeExecuting' => count($connection->beforeExecutingCallbacks),
        ], null, Connection::class)();
    }
}

/**
 * A test module before ObservationModule: its CommandStarting listener runs
 * before the package's, and throws while armed.
 */
final class ThrowingCommandStartModule implements Module
{
    public const MESSAGE = 'command starting listener failed';

    public static bool $armed = false;

    public function register(Application $app): void
    {
    }

    public function boot(Application $app): void
    {
        $app['events']->listen(CommandStarting::class, static function (): void {
            if (self::$armed) {
                throw new RuntimeException(self::MESSAGE);
            }
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public static function resetForTesting(): void
    {
        self::$armed = false;
    }
}

/**
 * A job that runs a scenario of DeferralHookTest by its name.
 */
final class DeferralHookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var array<string, Closure(): void> */
    public static array $scenarios = [];

    public function __construct(public string $scenario)
    {
    }

    public function handle(): void
    {
        (self::$scenarios[$this->scenario])();
    }
}

/**
 * A ShouldQueue job that dispatchSync() runs: its JobProcessing and
 * JobAttempted begin and end a unit inside the walk.
 */
final class DeferralSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static int $runs = 0;

    public function handle(): void
    {
        self::$runs++;
    }
}

/**
 * A transactions manager whose getPendingTransactions() throws while armed.
 */
final class ThrowingPendingManager extends DatabaseTransactionsManager
{
    public bool $armed = false;

    public int $calls = 0;

    public function getPendingTransactions()
    {
        if ($this->armed) {
            $this->calls++;

            throw new RuntimeException('The pending transactions cannot be read.');
        }

        return parent::getPendingTransactions();
    }
}
