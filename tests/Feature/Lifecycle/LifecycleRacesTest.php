<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\History;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\LifecycleCommandsProvider;
use Justrau\Orrery\Tests\Support\WaitTimeout;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The lifecycle races: concurrent history writes of
 * one job, U1 after a reclaim, a compaction between the O lookup and its
 * locked read, and the provider identity of a new occurrence.
 */
final class LifecycleRacesTest extends CycleTestCase
{
    /** The current cycle of the history bound case. */
    private const CURRENT = 5;

    /** How long the second process may run while the first one holds its transaction open. */
    private const BLOCKED_SECONDS = 3.0;

    /**
     * Rejects: a history insert and its count that do not share the job row lock, so two transactions of different cycles both count 200 and commit 201 rows.
     */
    #[Group('process')]
    #[Group('shared-database')]
    #[DataProvider('writerOrders')]
    public function test_concurrent_history_writes_of_two_cycles_keep_the_job_bound(string $first): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('Two processes need a database that they share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $uuid = $this->jobWithOneHundredNinetyNineHistoryRows();
        self::assertSame(199, $this->historyRows($uuid));

        // The current-cycle exception (E1) and a delayed exception of cycle 4 (O).
        $current = ['test:observe', 'exception', $uuid, '50', '1', self::gen(self::CURRENT)];
        $old = ['test:observe', 'exception', $uuid, '40', '2', self::gen(4)];
        [$a, $b] = $first === 'current' ? [$current, $old] : [$old, $current];

        $providers = ['ORRERY_TEST_PROVIDERS' => LifecycleCommandsProvider::class];
        $barrier = Barrier::forTest();

        // A adds its row, counts 200 and holds before its commit.
        $holder = WorkerProcess::start($a, $this->testEnvironment(), $providers + $barrier->holdEnvironment(History::AFTER_BOUNDS));
        $holder->waitUntil(static fn (): bool => ! $holder->isRunning() || $barrier->arrived(History::AFTER_BOUNDS.'.reached'), 60, 'it counted the rows of the job');
        self::assertTrue($barrier->arrived(History::AFTER_BOUNDS.'.reached'), $holder->describe());

        $second = WorkerProcess::start($b, $this->testEnvironment(), $providers);

        try {
            $second->waitForExit(self::BLOCKED_SECONDS);
            $finishedWhileHeld = true;
        } catch (WaitTimeout) {
            $finishedWhileHeld = false;
        }

        $barrier->arrive(History::AFTER_BOUNDS.'.release');
        self::assertSame(0, $holder->waitForExit(60), $holder->describe());

        if (! $finishedWhileHeld) {
            self::assertSame(0, $second->waitForExit(60), $second->describe());
        } else {
            self::assertSame(0, $second->exitCode(), $second->describe());
        }

        self::assertSame(History::PER_JOB, $this->historyRows($uuid), 'the job keeps at most 200 history rows');
        $row = $this->rowOrFail($uuid);
        self::assertTrue((bool) $row->history_truncated);
        self::assertSame(1, (int) $row->history_discarded, 'the oldest row was dropped and recorded');
        self::assertSame(49, $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('cycle', 1)->count(), 'the oldest cycle lost the row');
        self::assertSame(1, (int) $row->exceptions, 'the current-cycle exception is counted');
        self::assertSame(1, (int) $this->mirror()->table(CycleSummaries::TABLE)->where('job_uuid', $uuid)->where('cycle', 4)->value('exceptions'), 'the delayed exception updates the summary of cycle 4');
        self::assertFalse($finishedWhileHeld, 'the second writer waited for the job row lock of the first one');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function writerOrders(): array
    {
        return ['the current cycle holds' => ['current'], 'the old cycle holds' => ['old']];
    }

    /**
     * Rejects: a U1 whose guard omits attempts, so a reclaim after its read leaves a gap computed from the old attempt count.
     */
    public function test_mark_unknown_after_a_concurrent_reclaim_uses_the_new_attempt_count(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);

        $reclaimed = false;
        Hooks::$callback = function (string $point) use ($uuid, &$reclaimed): void {
            if ($point === JobStore::AFTER_READ && ! $reclaimed) {
                $reclaimed = true;
                self::assertSame('reclaim', $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 2])->rule, 'the reclaim between the U1 read and its write');
            }
        };

        self::assertTrue($this->store()->markUnknown($uuid, 10, 1, 'absent', self::T + 10));
        self::assertTrue($reclaimed);

        $row = $this->rowOrFail($uuid);
        self::assertSame('unknown', $row->status);
        self::assertSame(2, (int) $row->attempts);
        self::assertSame(1, (int) $row->reclaims);
        self::assertSame(2, (int) $row->observed_gap, 'both attempts ended without an observed outcome');
    }

    /**
     * Rejects: an O observation whose summary a compaction merged after the lookup, dropped without an unmatched count.
     */
    public function test_compaction_between_the_lookup_and_the_locked_read_counts_unmatched(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 100]);
        $this->observe('reserve', $uuid, ['jobId' => 100, 'attempt' => 1]);
        $this->observe('fail', $uuid, ['jobId' => 100, 'attempt' => 1, 'exception' => new \RuntimeException('cycle 51')]);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['cycle' => 51, 'retried_count' => 50]);

        for ($cycle = 1; $cycle <= 50; $cycle++) {
            $this->mirror()->table(CycleSummaries::TABLE)->insert([
                'job_uuid' => $uuid, 'cycle' => $cycle, 'cycle_gen' => self::gen($cycle), 'outcome' => 'failed', 'merged' => false, 'attempts' => 2,
            ]);
        }

        $compacted = false;
        Hooks::$callback = function (string $point) use ($uuid, &$compacted): void {
            if ($point === JobStore::BEFORE_OLDER_EVIDENCE && ! $compacted) {
                $compacted = true;
                // R5 freezes cycle 51, which merges cycle 1.
                self::assertSame('nativeRetry', $this->observe('reserve', $uuid, ['jobId' => 200, 'attempt' => 1], ['orreryGen' => self::G2])->rule);
            }
        };

        $unmatched = self::unmatched();
        $outcome = $this->observe('exception', $uuid, ['jobId' => 5, 'attempt' => 2], ['orreryGen' => self::gen(1)]);

        self::assertTrue($compacted, 'the lookup found the summary of cycle 1 before the compaction');
        self::assertFalse($outcome->applied);
        self::assertSame($unmatched + 1, self::unmatched(), 'the observation of the merged generation counts as unmatched');
        self::assertSame(0, $this->mirror()->table(CycleSummaries::TABLE)->where('job_uuid', $uuid)->where('cycle_gen', self::gen(1))->count());
        self::assertSame(0, (int) $this->mirror()->table(CycleSummaries::TABLE)->where('job_uuid', $uuid)->where('merged', true)->value('exceptions'), 'the merged row takes no evidence');
        self::assertSame(0, $this->historyRows($uuid, 'exception'));
    }

    /**
     * Rejects: a new occurrence that keeps the bound record of the previous one (never filled from
     * an older failure), or a rejected, accounted or O failure that clears it.
     */
    public function test_new_occurrence_clears_the_provider_identity(): void
    {
        $this->app['config']->set('queue.failed.driver', 'database-uuids');
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 2]);
        self::assertSame('fail', $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 2, 'exception' => new \RuntimeException('occurrence 1')])->rule);
        $first = (int) $this->rowOrFail($uuid)->occurrence_id;

        $identity = ['provider_id' => 77, 'provider_target' => str_repeat('a', 64)];
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update($identity);

        // A failure of another generation (unmatched) and a late failure of attempt 1 (accounted).
        $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 2, 'exception' => new \RuntimeException('other')], ['orreryGen' => self::G2]);
        self::assertSame('accounted', $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => new \RuntimeException('late')])->rule);
        $this->assertIdentity($uuid, $identity, 'a rejected or accounted failure keeps the identity');

        self::assertSame('nativeRetry', $this->observe('reserve', $uuid, ['jobId' => 20, 'attempt' => 1], ['orreryGen' => self::G2])->rule);
        self::assertSame('olderCycle', $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 3, 'exception' => new \RuntimeException('old cycle')])->rule);
        $this->assertIdentity($uuid, $identity, 'R5 and an O failure keep the identity');

        self::assertSame('fail', $this->observe('fail', $uuid, ['jobId' => 20, 'attempt' => 1, 'exception' => new \RuntimeException('occurrence 2')], ['orreryGen' => self::G2])->rule);

        $row = $this->rowOrFail($uuid);
        self::assertNotSame($first, (int) $row->occurrence_id, 'a new occurrence');
        self::assertSame([null, null], [$row->provider_id, $row->provider_target]);

        // F1 without a row creates the occurrence with the same null identity.
        $fresh = $this->uuid();
        self::assertSame('failWithoutRow', $this->observe('fail', $fresh, ['jobId' => 30, 'attempt' => 1, 'exception' => new \RuntimeException('no row')])->rule);
        $row = $this->rowOrFail($fresh);
        self::assertSame([null, null], [$row->provider_id, $row->provider_target]);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function assertIdentity(string $uuid, array $identity, string $message): void
    {
        $row = $this->rowOrFail($uuid);
        self::assertSame($identity['provider_id'], (int) $row->provider_id, $message);
        self::assertSame($identity['provider_target'], $row->provider_target, $message);
    }

    /**
     * A reserved row in cycle 5 (job 50, attempt 1) with the retained
     * summaries of cycles 1 to 4 and 199 history rows: 50 in each of the
     * cycles 1 to 3 and 49 in cycle 4.
     */
    private function jobWithOneHundredNinetyNineHistoryRows(): string
    {
        $uuid = $this->uuid();
        $gen = ['orreryGen' => self::gen(self::CURRENT)];
        $this->observe('push', $uuid, ['jobId' => 50], $gen);
        $this->observe('reserve', $uuid, ['jobId' => 50, 'attempt' => 1], $gen);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['cycle' => self::CURRENT, 'retried_count' => self::CURRENT - 1]);

        for ($cycle = 1; $cycle < self::CURRENT; $cycle++) {
            $this->mirror()->table(CycleSummaries::TABLE)->insert([
                'job_uuid' => $uuid, 'cycle' => $cycle, 'cycle_gen' => self::gen($cycle), 'outcome' => 'failed', 'merged' => false, 'attempts' => 2,
            ]);

            $rows = [];

            for ($i = 0; $i < ($cycle === 4 ? 49 : 50); $i++) {
                $rows[] = [
                    'job_uuid' => $uuid, 'cycle' => $cycle, 'attempt' => 1, 'event' => 'completed', 'dedupe_key' => null,
                    'occurred_at' => Clock::toDecimal(self::T - 1000 + $cycle),
                ];
            }

            $this->mirror()->table(History::TABLE)->insert($rows);
        }

        return $uuid;
    }

    private static function gen(int $cycle): string
    {
        return str_pad(dechex($cycle), 32, '0', STR_PAD_LEFT);
    }
}
