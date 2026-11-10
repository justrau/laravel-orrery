<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobQueued;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Deferred events end to end.
 * Every case skips on a Laravel release without
 * Event::defer(). The swap-off cases are removed
 * with the swap-off observation path. The commit deferral
 * reads no event, so a deferred event changes nothing.
 */
#[FreshSchema]
final class DeferredOwnershipTest extends EndToEndTestCase
{
    /**
     * The transaction T is begun by the test, or by an application
     * beforeExecuting callback just before the insert. In the second case
     * the level rose during the call, so the item is ambiguous: it runs at
     * T's commit (true either way), and T's
     * rollback drops it and counts it as unverified. A push inside T that
     * the test began is decided by T: mirrored at its commit, counted as a
     * rollback at its rollback.
     *
     * Rejects: an observation decided from a deferred event, an ambiguous
     * item dropped at a commit, and a rollback that counts nothing.
     */
    #[DataProvider('deferredPushes')]
    public function test_deferred_events_around_a_push_and_a_rollback(string $deferral, bool $commit, bool $beganByCallback = false): void
    {
        $events = $deferral === 'all events' ? null : [QueryExecuted::class, JobQueued::class];
        $q = $this->q();
        $began = false;

        if ($beganByCallback) {
            $q->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use (&$began): void {
                if (! $began && self::isJobsInsert($query)) {
                    $began = true;
                    $connection->beginTransaction();
                }
            });
        }

        $id = $this->deferEvents(function () use ($q, $commit, $beganByCallback): int {
            if (! $beganByCallback) {
                $q->beginTransaction();
            }

            $id = $this->push(new BasicJob);
            self::assertSame(1, $q->transactionLevel(), 'The insert ran inside T.');

            if ($commit) {
                $q->commit();
            } else {
                $q->rollBack();
            }

            return $id;
        }, $events);

        $this->endUnit();

        if (! $commit) {
            self::assertSame([], $this->qJobs());
        }

        if ($beganByCallback) {
            self::assertTrue($began);
        }

        if ($commit) {
            $uuid = $this->uuidOfJob($id);
            self::assertSame('pending', $this->rowOrFail($uuid)->status, 'The committed push is mirrored at the commit.');
            self::assertSame($id, (int) $this->rowOrFail($uuid)->job_id);
            self::assertSame(0, self::unverified());
            self::assertSame(0, self::rollbackUnverified());
        } elseif ($beganByCallback) {
            self::assertSame(0, $this->mirrorCount(), 'The ambiguous push of the rolled-back T is dropped.');
            self::assertSame(1, self::unverified(), 'The ambiguous item is counted as unverified.');
            self::assertSame(0, self::rollbackUnverified());
        } else {
            self::assertSame(0, $this->mirrorCount(), 'No pending row exists for a rolled-back insert.');
            self::assertSame(0, self::unverified());
            self::assertSame(1, self::rollbackUnverified(), 'The rolled-back push is counted as a rollback.');
        }

        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2?: bool}>
     */
    public static function deferredPushes(): array
    {
        $cases = [];

        foreach (['all events', 'queue and query events'] as $deferral) {
            $cases["swap on, {$deferral}, commit"] = [$deferral, true];
            $cases["swap on, {$deferral}, rollback"] = [$deferral, false];
        }

        $cases['swap on, queue and query events, T begun before the insert, commit'] = ['queue and query events', true, true];
        $cases['swap on, queue and query events, T begun before the insert, rollback'] = ['queue and query events', false, true];

        return $cases;
    }

    /**
     * Rejects: a capture matched by level, and a decision read from a
     * deferred transaction event.
     */
    #[DataProvider('replacementRoots')]
    public function test_deferred_replacement_root_with_a_real_push(string $case): void
    {
        $q = $this->q();

        if (str_contains($case, 'root')) {
            $q->beginTransaction();
            $a = $this->push(new BasicJob);

            $b = $this->deferTransactionEvents(function () use ($q): int {
                $q->commit();
                $q->beginTransaction();
                $b = $this->push(new BasicJob);
                $q->rollBack();

                return $b;
            });
        } else {
            $q->beginTransaction();
            $a = $this->push(new BasicJob);
            $q->beginTransaction();
            $s1 = $this->push(new BasicJob);

            $b = $this->deferTransactionEvents(function () use ($q): int {
                $q->commit();
                $q->beginTransaction();
                $b = $this->push(new BasicJob);
                $q->rollBack();

                return $b;
            });

            self::assertSame(1, $q->transactionLevel());
            $q->commit();
            self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($s1))->status, 'The committed sibling S1 runs once.');
        }

        $this->endUnit();

        self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($a))->status, "A's own observation runs.");
        self::assertNull($this->qJob($b), "B's insert rolled back.");
        self::assertSame(str_contains($case, 'root') ? 1 : 2, $this->mirrorCount(), "No pending row exists for B's insert.");
        self::assertSame(0, self::unverified());
        // A rolled-back root counts its items; a rolled-back savepoint of a
        // committed root is decided by its rollback mark and counts nothing.
        self::assertSame(str_contains($case, 'root') ? 1 : 0, self::rollbackUnverified(), "B's rolled-back push is counted only as a rolled-back root.");
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function replacementRoots(): array
    {
        return [
            'swap on, replacement root' => ['root'],
            'swap on, nested sibling replaced at the same level' => ['sibling'],
        ];
    }

    /**
     * The level rose during the call, so the item is
     * ambiguous: T's commit runs it (formerly
     * it was dropped and counted), and T's rollback drops it and counts it
     * as unverified.
     *
     * Rejects: autocommit inferred from a later level, and an ambiguous
     * item dropped at a commit.
     */
    #[DataProvider('swapOnSequences')]
    public function test_swap_on_rollback_sequence_is_dropped_and_counted(string $outcome): void
    {
        $id = $this->beginTransactionSequence($outcome, false);
        $this->assertNothingDeferred();
        self::assertSame(0, self::rollbackUnverified());

        if ($outcome === 'rollback') {
            self::assertSame(1, self::unverified(), 'The ambiguous item of the rolled-back T is dropped and counted.');
            self::assertSame(0, $this->mirrorCount(), 'The push is not mirrored.');
            self::assertNull($this->qJob($id));

            return;
        }

        self::assertSame(0, self::unverified(), 'The ambiguous item runs at the commit.');
        self::assertNotNull($this->qJob($id));
        $uuid = $this->uuidOfJob($id);
        self::assertSame('pending', $this->rowOrFail($uuid)->status, "The push is mirrored at T's commit.");

        $this->workHooks();

        $row = $this->rowOrFail($uuid);
        self::assertSame('completed', $row->status, 'The job is reserved, then completed.');
        self::assertSame(1, (int) $row->attempts);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function swapOnSequences(): array
    {
        return [
            'T rolls back' => ['rollback'],
            'T commits' => ['commit'],
        ];
    }

    /**
     * An application beforeExecuting callback begins
     * a Q transaction T for the job insert. QueryExecuted and JobQueued are
     * deferred, while JobQueueing (and, unless asked, the transaction
     * events) stay synchronous. The push returns, the application ends T,
     * and the deferral ends. Returns the jobs.id of the push.
     */
    private function beginTransactionSequence(string $outcome, bool $deferTransactionEvents): int
    {
        $q = $this->q();
        $began = false;

        if ($outcome !== 'no transaction') {
            $q->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use (&$began): void {
                if (! $began && self::isJobsInsert($query)) {
                    $began = true;
                    $connection->beginTransaction();
                }
            });
        }

        $events = [QueryExecuted::class, JobQueued::class];

        if ($deferTransactionEvents) {
            $events = [...$events, ...self::TRANSACTION_EVENTS];
        }

        return $this->deferEvents(function () use ($q, $outcome, &$began): int {
            $id = $this->push(new BasicJob);

            if ($outcome === 'no transaction') {
                self::assertSame(0, $q->transactionLevel());

                return $id;
            }

            self::assertTrue($began);
            self::assertSame(1, $q->transactionLevel(), 'The callback began T for the insert.');

            if ($outcome === 'commit') {
                $q->commit();
            } else {
                $q->rollBack();
            }

            return $id;
        }, $events);
    }
}
