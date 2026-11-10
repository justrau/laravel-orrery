<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Observation;

use Closure;
use Illuminate\Database\Connection;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\DegradationDropCounter;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Tests\Support\FailingCommitPdo;
use PDOException;
use Throwable;

/**
 * The byte budget of the commit deferral: each held item carries the byte
 * estimate of its hook, the held bytes follow every move of the held items
 * (hold, untrack, the walk transfer), and an item over the budget is
 * dropped with the reason `byte_budget`, apart from the count cap's
 * `count_cap`.
 */
final class DeferralByteBudgetTest extends DeferralTestCase
{
    /**
     * Rejects: held bytes that only grow (no subtraction at untrack), or a
     * subtraction on only some of the ways a root leaves the store.
     */
    public function test_held_bytes_return_to_zero_after_commit_rollback_stale_rule_and_end_unit(): void
    {
        $q = $this->q();

        // Commit: the root's item and a committed savepoint's item.
        $q->beginTransaction();
        $this->raiseBytes('root', 100);
        $q->beginTransaction();
        $this->raiseBytes('savepoint', 50);
        $q->commit();
        self::assertSame(150, $this->deferral()->heldBytes(), 'Both held items count their bytes.');
        $q->commit();
        self::assertSame(['root', 'savepoint'], $this->ran());
        self::assertSame(0, $this->deferral()->heldBytes(), 'The commit gives the bytes back.');

        // Rollback.
        $q->beginTransaction();
        $this->raiseBytes('rolled back', 70);
        self::assertSame(70, $this->deferral()->heldBytes());
        $q->rollBack();
        self::assertSame(0, $this->deferral()->heldBytes(), 'The rollback gives the bytes back.');

        // The end of a unit.
        $token = $this->deferral()->beginUnit();
        $q->beginTransaction();
        $this->raiseBytes('leaked', 30);
        self::assertSame(30, $this->deferral()->heldBytes());
        $this->deferral()->endUnit($token);
        self::assertSame(0, $this->deferral()->heldBytes(), 'The end of the unit gives the bytes back.');
        $q->rollBack();

        // The stale rule: a manual commit() whose COMMIT fails leaves its root pending.
        $failing = FailingCommitPdo::forConfig($q->getConfig(), 1);
        $q->setPdo($failing);
        $q->beginTransaction();
        $this->raiseBytes('stale', 40);

        try {
            $q->commit();
            self::fail('The COMMIT fails.');
        } catch (PDOException) {
        }

        self::assertSame(40, $this->deferral()->heldBytes());
        $q->setPdo($failing);
        $q->transaction(static function (): void {});
        self::assertSame(0, $this->deferral()->heldBytes(), 'The stale rule gives the bytes back.');

        self::assertSame(['root', 'savepoint'], $this->ran());
        self::assertSame(0, $this->deferral()->open());
        self::assertSame(0, $this->deferral()->held());
    }

    /**
     * Root B commits inside the commit walk of root A: its item moves to A.
     *
     * Rejects: a walk transfer that moves the item but not its bytes (the
     * held bytes then miss the moved item until A's commit callback runs).
     */
    public function test_held_bytes_follow_the_walk_transfer(): void
    {
        $q = $this->q();
        $inside = null;

        $q->beginTransaction();
        $this->raiseBytes('a', 10);
        $q->beginTransaction();
        $this->record()->addCallback(function () use ($q, &$inside): void {
            $q->beginTransaction();
            $this->raiseBytes('b', 20);
            $q->commit();
            $inside = $this->deferral()->heldBytes();
        });
        $q->commit();
        $q->commit();

        self::assertSame(30, $inside, "B's item and its bytes moved to A's walk.");
        self::assertSame(['a', 'b'], $this->ran());
        self::assertSame(0, $this->deferral()->heldBytes());
        $this->assertCounts(0, 0);
    }

    /**
     * Rejects: a byte budget that is not checked (only the count cap), a
     * budget checked against the new item alone (not the held bytes plus
     * it), a byte drop counted under the count cap's reason, or a check
     * after the root is tracked (the dropped item would leave callbacks).
     */
    public function test_a_large_payload_reaches_the_byte_budget_before_the_count_cap(): void
    {
        $q = $this->q();
        $budget = $this->deferral()->byteBudget();
        $third = intdiv($budget, 3);

        $q->beginTransaction();
        $this->raiseBytes('first', $third);
        $this->raiseBytes('second', $third);
        $this->raiseBytes('third', $budget - 2 * $third);
        self::assertSame($budget, $this->deferral()->heldBytes(), 'An item that fills the budget exactly is held.');

        $q->beginTransaction();
        $savepoint = $this->record();
        $this->raiseBytes('over the budget', 1);

        self::assertSame(3, $this->deferral()->held(), 'Far below the count cap.');
        self::assertLessThan(CommitDeferral::MAX_HELD, $this->deferral()->held());
        self::assertSame(1, $this->drops->overBudgetCount(CommitDeferral::BYTE_BUDGET), 'The drop has its own reason.');
        self::assertSame(0, $this->drops->overBudgetCount(CommitDeferral::COUNT_CAP));
        $this->assertCounts(1, 0, 'The dropped item is counted.');
        self::assertSame([], $savepoint->getCallbacks(), 'The dropped item marks no savepoint.');
        self::assertSame([], $savepoint->getCallbacksForRollback());

        $q->commit();
        $q->commit();

        self::assertSame(['first', 'second', 'third'], $this->ran());
        self::assertSame(0, $this->deferral()->heldBytes());

        // The commit gave the budget back.
        $q->beginTransaction();
        $this->raiseBytes('after the commit', $third);
        $q->commit();
        self::assertSame(['first', 'second', 'third', 'after the commit'], $this->ran());
        $this->assertCounts(1, 0);
    }

    /**
     * Rejects: letting through an item that is larger than the whole
     * budget because the store holds nothing, or tracking its root.
     */
    public function test_a_single_item_larger_than_the_budget_is_dropped(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $root = $this->record();
        $this->raiseBytes('too large', $this->deferral()->byteBudget() + 1);

        self::assertSame(0, $this->deferral()->held());
        self::assertSame(0, $this->deferral()->open(), 'No root is tracked for the dropped item.');
        self::assertSame([], $root->getCallbacks());
        self::assertSame(1, $this->drops->overBudgetCount(CommitDeferral::BYTE_BUDGET));
        $q->commit();

        self::assertSame([], $this->ran());
        $this->assertCounts(1, 0);
    }

    /**
     * An item at level 0 while a root walks joins the walking root.
     *
     * Rejects: a byte budget checked only on the way to a pending root.
     */
    public function test_an_item_that_joins_a_walk_is_held_within_the_budget(): void
    {
        $q = $this->q();
        $budget = $this->deferral()->byteBudget();

        $q->beginTransaction();
        $this->raiseBytes('a', $budget);
        $q->beginTransaction();
        $this->record()->addCallback(function (): void {
            $this->raiseBytes('autocommit over the budget', 1);
        });
        $q->commit();
        $q->commit();

        self::assertSame(['a'], $this->ran());
        self::assertSame(1, $this->drops->overBudgetCount(CommitDeferral::BYTE_BUDGET));
        $this->assertCounts(1, 0);
        self::assertSame(0, $this->deferral()->heldBytes());
    }

    /**
     * Rejects: a budget without the ceiling, without the share of the
     * memory limit, or one that reads an unlimited or unreadable limit
     * as zero.
     */
    public function test_the_budget_is_the_smaller_of_the_ceiling_and_an_eighth_of_the_memory_limit(): void
    {
        $ceiling = 32 * 1024 * 1024;

        self::assertSame($ceiling, CommitDeferral::MAX_HELD_BYTES);
        self::assertSame($ceiling, CommitDeferral::byteBudgetFor('-1'), 'No memory limit: the ceiling.');
        self::assertSame($ceiling, CommitDeferral::byteBudgetFor('1G'));
        self::assertSame($ceiling, CommitDeferral::byteBudgetFor('256M'));
        self::assertSame(16 * 1024 * 1024, CommitDeferral::byteBudgetFor('128M'));
        self::assertSame(12_500_000, CommitDeferral::byteBudgetFor('100000000'));
        self::assertSame($ceiling, CommitDeferral::byteBudgetFor(''), 'An unreadable limit: the ceiling.');
        self::assertSame($ceiling, CommitDeferral::byteBudgetFor('many'));
        self::assertSame(CommitDeferral::byteBudgetFor((string) ini_get('memory_limit')), $this->deferral()->byteBudget());
    }

    /**
     * Rejects: an over-budget drop that the health window does not count
     * in `unverified`, one whose reason it does not keep apart from the
     * other budget's, or a reason that never shows as the last error of a
     * window without one.
     */
    public function test_the_health_window_counts_the_drop_and_names_its_reason(): void
    {
        $drops = new DegradationDropCounter;

        $drops->overBudget(CommitDeferral::COUNT_CAP);
        self::assertSame('deferral.count_cap', Degradation::record()['last_error_operation'] ?? null, 'A window without an error names the reason.');

        $drops->overBudget(CommitDeferral::BYTE_BUDGET, 2);
        $record = Degradation::record();
        self::assertSame(3, $record['unverified'] ?? null);
        self::assertSame('deferral.count_cap', $record['last_error_operation'] ?? null, 'A later reason does not replace the first.');
        self::assertArrayHasKey('deferral.count_cap', $record['errors'] ?? []);
        self::assertArrayHasKey('deferral.byte_budget', $record['errors'] ?? []);
    }

    /**
     * Rejects: a budget drop that replaces the real error of the window
     * (here a lock timeout of a mirror write) as its last error.
     */
    public function test_a_budget_drop_never_replaces_an_earlier_error(): void
    {
        Degradation::error('hook:push', 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
        $at = Degradation::record()['last_error_at'] ?? null;

        (new DegradationDropCounter)->overBudget(CommitDeferral::BYTE_BUDGET);

        $record = Degradation::record();
        self::assertSame('hook:push', $record['last_error_operation'] ?? null, 'The lock timeout stays the last error.');
        self::assertSame($at, $record['last_error_at'] ?? null);
        self::assertArrayHasKey('deferral.byte_budget', $record['errors'] ?? []);
        self::assertSame(1, $record['unverified'] ?? null);
    }

    /**
     * Rejects: an estimate that still counts a decoded copy (a factor above
     * 1), one without the fixed size per held item or per bulk member (a
     * bulk of small payloads would look free), one size for both, or one
     * that a negative size makes negative.
     */
    public function test_the_estimate_is_the_payload_strings_and_a_size_per_job(): void
    {
        self::assertSame(1792, CommitDeferral::OBSERVATION_BYTES);
        self::assertSame(640, CommitDeferral::BULK_MEMBER_BYTES);
        self::assertSame(1000 + 1792, CommitDeferral::estimate(1000));
        self::assertSame(1792, CommitDeferral::estimate(-1));
        self::assertSame(3000 + 3 * 640, CommitDeferral::bulkEstimate(3000, 3));
        self::assertSame(0, CommitDeferral::bulkEstimate(-1, -1));
    }

    /**
     * Rejects: a budget drop that only the health window shows (whose
     * errors map is not stored), a report per drop instead of the first of
     * each reason, or one report for both reasons.
     */
    public function test_the_first_drop_of_each_reason_is_reported(): void
    {
        $reports = $this->recordReports();
        $q = $this->q();
        $budget = $this->deferral()->byteBudget();

        $q->beginTransaction();
        $this->raiseBytes('fills the budget', $budget);
        $this->raiseBytes('first over the budget', 1);
        $this->raiseBytes('second over the budget', 1);
        $q->rollBack();

        $q->beginTransaction();

        for ($index = 0; $index < CommitDeferral::MAX_HELD; $index++) {
            $this->raiseWithoutStatement('item');
        }

        $this->raise('first over the cap');
        $this->raise('second over the cap');
        $q->rollBack();

        $messages = array_map(static fn (Throwable $error): string => $error->getMessage(), $reports->getArrayCopy());
        $bytes = array_values(array_filter($messages, static fn (string $message): bool => str_contains($message, 'deferral.byte_budget')));
        $cap = array_values(array_filter($messages, static fn (string $message): bool => str_contains($message, 'deferral.count_cap')));

        self::assertCount(1, $bytes, 'One report of the byte budget.');
        self::assertCount(1, $cap, 'One report of the count cap.');
        self::assertStringContainsString('the byte budget of '.$budget.' bytes', $bytes[0]);
        self::assertStringContainsString('1 job(s)', $bytes[0]);
        self::assertSame(2, $this->drops->overBudgetCount(CommitDeferral::BYTE_BUDGET));
        self::assertSame(2, $this->drops->overBudgetCount(CommitDeferral::COUNT_CAP));
    }

    /**
     * An insert hook on $q whose item carries $bytes.
     *
     * @param  (Closure(): mixed)|null  $parent
     */
    private function raiseBytes(string $label, int $bytes, ?Connection $q = null, ?Closure $parent = null): mixed
    {
        $q ??= $this->q();

        return $this->deferral()->around($q, 0, $parent ?? static fn () => $q->select(self::INSERT), $this->observationFor($label), CommitDeferral::OPERATION, $bytes);
    }
}
