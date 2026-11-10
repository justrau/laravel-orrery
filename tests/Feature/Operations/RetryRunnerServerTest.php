<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Maintenance\Phases\RetentionPhase;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\OperationContext;
use Justrau\Orrery\Retry\OperationHandler;
use Justrau\Orrery\Retry\OperationRunner;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\OperationStep;
use Justrau\Orrery\Retry\Phases\OperationsPhase;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use stdClass;

/**
 * The runner of retry operations against connections that block (the stop rule,
 * the progress
 * bound and the phase order), on MySQL and Postgres only: a SQLite file has
 * no lock-held or black-holed connection. Kind: new
 * behaviour.
 *
 * The stop rule and the progress bound run on the test bounds of the
 * package sessions (1 s lock wait, 2 s connect timeout): a blocking failure
 * is decided by its error, not by its duration. The phase-order test keeps
 * the production connect timeout and asserts it.
 */
final class RetryRunnerServerTest extends RetryProtocolTestCase
{
    /** A connection whose fence key another session holds in an open transaction. */
    private const LOCKED = 'testbench_locked';

    /** A connection to an address that answers nothing. */
    private const BLACK_HOLE = 'testbench_black_hole';

    private ?Connection $holder = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->testEnvironment()->isServer()) {
            self::markTestSkipped('A lock-held or black-holed connection needs MySQL or Postgres.');
        }

        $config = $this->app['config'];
        $primary = (array) $config->get('database.connections.'.TestEnvironment::PRIMARY);

        foreach ([self::LOCKED, 'testbench_holder'] as $name) {
            $config->set("database.connections.{$name}", $primary);
        }

        $config->set('database.connections.'.self::BLACK_HOLE, array_replace($primary, ['host' => '10.255.255.1']));

        foreach ([self::LOCKED, self::BLACK_HOLE] as $name) {
            $config->set("queue.connections.{$name}", ['driver' => 'database', 'connection' => $name, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        }

        $this->beforeApplicationDestroyed(function (): void {
            if ($this->holder !== null && $this->holder->transactionLevel() > 0) {
                $this->holder->rollBack();
            }

            // The rollback of the old fence migration visits every database queue connection: not these.
            $config = $this->app['config'];
            $config->set('queue.connections', array_diff_key((array) $config->get('queue.connections'), [self::LOCKED => true, self::BLACK_HOLE => true]));
        });
    }

    /**
     * Rejects: a run that goes on blocking after a lock timeout: one operation on a lock-held connection, then
     * healthy ones; the run ends right after the lock timeout and the deferral, the healthy ones untouched; the
     * next run skips the deferred connection and decides them.
     */
    public function test_runner_stops_at_the_first_blocking_failure(): void
    {
        $this->useShortBounds();
        $now = $this->mirrorNow();
        $locked = $this->claimed(self::LOCKED, $now - 100);
        $this->holdFence($locked);
        $healthy = [];

        foreach (range(1, 3) as $i) {
            $healthy[] = $this->claimed('database', $now - 50 + $i);
        }

        $report = $this->runOnce();

        self::assertSame([OperationRunner::STOP_BLOCKED, self::LOCKED, 1], [$report['stopped'], $report['deferred'], $report['inspected']]);
        self::assertSame(1, (int) $this->operation($locked)->checks);

        foreach ($healthy as $id) {
            self::assertSame([Operations::CLAIMED, 0], [$this->operation($id)->state, (int) $this->operation($id)->checks]);
        }

        $this->runOnce();

        foreach ($healthy as $id) {
            self::assertSame(Operations::ABORTED, $this->operation($id)->state);
        }

        self::assertSame(Operations::CLAIMED, $this->operation($locked)->state, 'The deferred connection was not retried in the same window.');
    }

    /**
     * Rejects: the old "F + ceil(N / 100) + 1" claim, no cursor, and retrying a deferred connection: (a) 150 due
     * operations on a lock-held connection and 120 on a black-holed one, then one healthy operation: it is decided
     * within F + D + 1 runs, and each run blocks at most once; (b) 300 healthy operations of 200 ms each ahead of the
     * target: about 25 decisions per run, the target decided within 13 runs.
     */
    public function test_runner_progress_bound(): void
    {
        $this->useShortBounds();
        $now = $this->mirrorNow();
        $rows = [];

        foreach (range(1, 150) as $i) {
            $rows[] = $this->claimedRow(self::LOCKED, $now - 1000 + $i / 100);
        }

        foreach (range(1, 120) as $i) {
            $rows[] = $this->claimedRow(self::BLACK_HOLE, $now - 900 + $i / 100);
        }

        $this->insertRows($rows);
        $this->holdFence((string) $rows[0]['id']);
        $target = $this->claimed('database', $now - 800);
        $runs = 0;
        $blocks = 0;

        while ($this->operation($target)->state === Operations::CLAIMED && $runs < 10) {
            $report = $this->runOnce();
            $runs++;
            $blocks += $report['stopped'] === OperationRunner::STOP_BLOCKED ? 1 : 0;
            self::assertLessThanOrEqual(1, $report['stopped'] === OperationRunner::STOP_BLOCKED ? 1 : 0);
        }

        self::assertSame(Operations::ABORTED, $this->operation($target)->state);
        self::assertLessThanOrEqual(2 + 0 + 1, $runs, 'F + D + 1 with F = 2 and no healthy operation ahead.');
        self::assertSame(2, $blocks, 'Each failing connection blocks one run.');

        // (b) 300 healthy operations of 200 ms each ahead of the target.
        $this->rollBackHolder();
        $this->mirror()->table(Operations::TABLE)->delete();
        $this->mirror()->table('orrery_settings')->where('key', 'like', 'operations_%')->delete();
        $now = $this->mirrorNow();
        $rows = [];

        foreach (range(1, 300) as $i) {
            $rows[] = $this->claimedRow('database', $now - 1000 + $i / 100);
        }

        $this->insertRows($rows);
        $target = $this->claimed('database', $now - 500);
        $real = $this->app->make(RetryHandler::class);
        $this->runner()->handle(new class($real) implements OperationHandler
        {
            public function __construct(private readonly RetryHandler $real)
            {
            }

            public function action(): string
            {
                return Operations::RETRY;
            }

            public function step(stdClass $operation, OperationContext $context): OperationStep
            {
                Clock::advanceMonotonicForTesting(0.2);

                return $this->real->step($operation, $context);
            }
        });

        $runs = 0;
        $fewest = PHP_INT_MAX;

        while ($this->operation($target)->state === Operations::CLAIMED && $runs < 301) {
            $report = $this->runOnce();
            $runs++;
            self::assertGreaterThanOrEqual(1, $report['decided'], 'Every run decides at least its first operation.');
            self::assertLessThanOrEqual(26, $report['decided'], 'A run decides at most 5 s / 200 ms operations.');
            $fewest = min($fewest, $report['decided']);
        }

        self::assertSame(Operations::ABORTED, $this->operation($target)->state);
        // ceil(D / min(100, floor(5 s / c))) + 1 with the decisions per run that this machine measured (about 25).
        self::assertLessThanOrEqual((int) ceil(300 / $fewest) + 1, $runs);
        self::assertLessThanOrEqual(301, $runs, 'Never later than D + 1.');
    }

    /**
     * Rejects: `operations` before the maintenance phases: with a due operation on a black-holed connection, one
     * tick runs `retention` before `operations`, and both run. The one test per engine that waits for the
     * production connect timeout: the tick takes at least PackageSession::CONNECT_TIMEOUT_SECONDS, and at most
     * that plus 3 s, so one connect attempt (the package sessions never connect again after a failed connect,
     * also where the Linux text of the timeout makes Laravel try again; ConnectAttemptsTest). Where the
     * black-holed address is rejected at once, no connect wait can be measured: the test skips and says so.
     */
    public function test_unreachable_queue_connection_does_not_starve_the_tick(): void
    {
        $this->claimed(self::BLACK_HOLE, $this->mirrorNow() - 100);
        $phases = [$this->app->make(OperationsPhase::class), $this->app->make(RetentionPhase::class)];

        $started = microtime(true);
        $report = $this->app->make(TickRunner::class)->run($phases);
        $elapsed = microtime(true) - $started;

        $ran = $report->ran();
        self::assertContains('retention', $ran);
        self::assertContains('operations', $ran);
        self::assertLessThan(array_search('operations', $ran, true), array_search('retention', $ran, true), 'Retention runs first.');

        self::assertSame(5, PackageSession::CONNECT_TIMEOUT_SECONDS);

        if ($elapsed <= PackageSession::CONNECT_TIMEOUT_SECONDS - 1) {
            $this->skipWhenTheBlackHoleIsRejectedAtOnce();
        }

        self::assertGreaterThan(PackageSession::CONNECT_TIMEOUT_SECONDS - 1, $elapsed, 'The connect waited for the production timeout, not a test bound.');
        self::assertLessThan((float) $this->app['config']->get('orrery.tick.deadline_seconds'), $elapsed, 'The blocked connect ended within the tick deadline.');
        self::assertLessThan(PackageSession::CONNECT_TIMEOUT_SECONDS + 3, $elapsed, 'One connect attempt, not one per retry.');
    }

    /**
     * A short tick is a failure unless this network rejects the black-holed
     * address at once (a refused or unreachable route, not a timeout): then
     * the test cannot measure a connect wait, and it skips with that reason.
     */
    private function skipWhenTheBlackHoleIsRejectedAtOnce(): void
    {
        $config = (array) $this->app['config']->get('database.connections.'.self::BLACK_HOLE);
        $started = microtime(true);

        try {
            new \PDO(sprintf('%s:host=%s;port=%d;dbname=orrery_probe', $config['driver'], $config['host'], $config['port']), 'probe', 'probe', [\PDO::ATTR_TIMEOUT => 2]);
        } catch (\PDOException $error) {
            $message = $error->getMessage();

            if (microtime(true) - $started < 1.0 && ! preg_match('/time(d)? ?out|timeout expired/i', $message)) {
                self::markTestSkipped('This network rejects '.$config['host'].' at once, so no connect wait can be measured: '.$message);
            }
        }
    }

    /**
     * A claimed retry of mode none on a queue connection, past its deadline in that database's clock.
     */
    private function claimed(string $queueConnection, float $nextCheckAt): string
    {
        $row = $this->claimedRow($queueConnection, $nextCheckAt);
        $this->insertRows([$row]);

        return (string) $row['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function claimedRow(string $queueConnection, float $nextCheckAt): array
    {
        $id = Operations::newId();
        $time = Clock::toDecimal(Clock::now());

        return [
            'id' => $id, 'action' => Operations::RETRY, 'state' => Operations::CLAIMED, 'mode' => Operations::NONE,
            'job_uuid' => '0f5a3c1e-6b1d-4e57-9a3e-'.substr($id, 0, 12), 'occurrence_id' => 1, 'queue_connection' => $queueConnection, 'queue' => 'default',
            'queue_fingerprint' => $this->app->make(Publication::class)->queueFingerprint($queueConnection),
            'deadline' => Clock::toDecimal($nextCheckAt - 60), 'next_check_at' => Clock::toDecimal($nextCheckAt), 'checks' => 0, 'source' => 'cli',
            'created_at' => $time, 'updated_at' => $time,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertRows(array $rows): void
    {
        foreach (array_chunk($rows, 50) as $chunk) {
            $this->mirror()->table(Operations::TABLE)->insert($chunk);
        }
    }

    /**
     * Another session holds the publish fence key of an operation in an open transaction.
     */
    private function holdFence(string $operationId): void
    {
        $this->holder = DB::connection('testbench_holder');
        $this->holder->beginTransaction();
        $this->holder->table(Fence::TABLE)->insert([
            'operation_id' => $operationId, 'slot' => Fence::PUBLISH, 'state' => Fence::PUBLISHED, 'job_id' => null,
            'deadline' => Clock::toDecimal(DatabaseClock::now($this->holder)), 'created_at' => Clock::toDecimal(DatabaseClock::now($this->holder)),
        ]);
    }

    /**
     * The test bounds on every new package session: a lock wait of 1 s, and a connect timeout of 2 s (a 1 s
     * connect can fail a healthy connection on a loaded machine).
     */
    private function useShortBounds(): void
    {
        PackageSession::useLockWaitSecondsForTesting(1);
        PackageSession::useConnectTimeoutSecondsForTesting(2);
    }

    private function rollBackHolder(): void
    {
        if ($this->holder !== null && $this->holder->transactionLevel() > 0) {
            $this->holder->rollBack();
        }

        PackageSession::releaseAll();
    }
}
