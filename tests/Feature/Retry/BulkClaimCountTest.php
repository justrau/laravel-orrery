<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Storage\Clock;

/**
 * The claim count of a bulk run inside the services' claim transactions:
 * a service
 * that receives a bulk_ref stores it on the operation and adds one claim to
 * the run's audit row in the same transaction as its claim. A forget takes a
 * slot only while `claimed < confirmed_total`; without a slot it claims
 * nothing (`not_confirmed`). A claim that the same transaction finds not
 * eligible rolls the slot back with it.
 */
final class BulkClaimCountTest extends RetryTestCase
{
    private const REF = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    /**
     * Rejects: a retry that drops the bulk_ref, counts no claim, or counts a
     * claim outside its claim transaction for a call without a run.
     * Still passes: a count written after the claim's COMMIT in a second
     * statement (the forget slot test below rejects that for a forget).
     */
    public function test_a_retry_with_a_run_stores_its_ref_and_counts_its_claim(): void
    {
        $this->insertRun('retry', null);
        [$uuid, $occurrence] = $this->failedJob();
        [$other, $otherOccurrence] = $this->failedJob();

        $result = $this->app->make(RetryService::class)->retry($uuid, $occurrence, 'bulk', 'operator', self::REF);
        self::assertSame(RetryResult::ACCEPTED, $result->result);
        self::assertSame(self::REF, $this->operation((string) $result->operationId)->bulk_ref);
        self::assertSame(1, $this->claimed());

        $alone = $this->app->make(RetryService::class)->retry($other, $otherOccurrence, 'dashboard', 'operator');
        self::assertSame(RetryResult::ACCEPTED, $alone->result);
        self::assertNull($this->operation((string) $alone->operationId)->bulk_ref, 'an operation of no run has no bulk_ref');
        self::assertSame(1, $this->claimed(), 'a retry without a run counts nothing');
    }

    /**
     * Rejects: a forget that ignores the confirmed total (deletes a second
     * record), or that takes its slot in a statement of its own after the
     * claim (the second forget would then hold the row or write an
     * operation before its slot is refused).
     */
    public function test_a_forget_without_a_free_slot_claims_nothing(): void
    {
        $this->insertRun('forget', 1);
        [$first, $firstOccurrence] = $this->failedJob();
        [$second, $secondOccurrence] = $this->failedJob();
        $records = $this->providerRecords();

        $done = $this->forgets()->forget($first, $firstOccurrence, 'operator', 'bulk', self::REF);
        self::assertSame(ForgetResult::DELETED, $done->result);
        self::assertSame(self::REF, $this->operation((string) $done->operationId)->bulk_ref);
        self::assertSame(1, $this->claimed());

        $operations = $this->mirror()->table('orrery_operations')->count();
        $refused = $this->forgets()->forget($second, $secondOccurrence, 'operator', 'bulk', self::REF);

        self::assertSame([ForgetResult::NOT_CONFIRMED, null, null], [$refused->result, $refused->code, $refused->operationId]);
        self::assertSame(1, $this->claimed(), 'no slot was taken');
        self::assertSame($operations, $this->mirror()->table('orrery_operations')->count(), 'no operation was claimed');
        $row = $this->rowOrFail($second);
        self::assertSame(['failed', null], [$row->status, $row->operation_id], 'the row is not held');
        self::assertSame($records - 1, $this->providerRecords(), 'only the confirmed record is deleted');
    }

    /**
     * Rejects: a slot taken before the guarded hold and kept when the hold
     * loses (the run would then reach its confirmed total with fewer
     * deletions, and later members would be refused as not_confirmed).
     */
    public function test_a_claim_that_loses_its_hold_leaves_the_slot_free(): void
    {
        $this->insertRun('forget', 1);
        [$lost, $lostOccurrence] = $this->failedJob();
        [$next, $nextOccurrence] = $this->failedJob();

        // Between the read and the claim another provider record is bound: the guarded hold loses.
        $this->onHook(function (string $point) use ($lost): void {
            if ($point === ForgetService::BEFORE_CLAIM) {
                $this->mirror()->table('orrery_jobs')->where('uuid', $lost)->where('status', 'failed')->update(['provider_id' => 987654]);
            }
        });

        $result = $this->forgets()->forget($lost, $lostOccurrence, 'operator', 'bulk', self::REF);
        self::assertSame(ForgetResult::NOT_ELIGIBLE, $result->result);
        self::assertSame(0, $this->claimed(), 'the lost hold rolled its slot back');

        $this->mirror()->table('orrery_jobs')->where('uuid', $lost)->update(['provider_id' => $this->providerId($lost)]);
        $later = $this->forgets()->forget($next, $nextOccurrence, 'operator', 'bulk', self::REF);
        self::assertSame(ForgetResult::DELETED, $later->result, 'the later member still gets the slot');
        self::assertSame(1, $this->claimed());
    }

    /**
     * Rejects: a retry claim that rolls back without its count (or keeps
     * the count of a claim that lost): the run's claimed count would differ
     * from its operations in the ledger.
     */
    public function test_a_retry_that_loses_its_claim_counts_nothing(): void
    {
        $this->insertRun('retry', null);
        [$uuid, $occurrence] = $this->failedJob();

        $this->onHook(function (string $point) use ($uuid): void {
            if ($point === RetryService::BEFORE_CLAIM) {
                $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'completed']);
            }
        });

        $result = $this->app->make(RetryService::class)->retry($uuid, $occurrence, 'bulk', 'operator', self::REF);
        self::assertSame(RetryResult::NOT_ELIGIBLE, $result->result);
        self::assertSame(0, $this->claimed());
        self::assertSame(0, $this->mirror()->table('orrery_operations')->where('bulk_ref', self::REF)->count());
    }

    private function forgets(): ForgetService
    {
        return $this->app->make(ForgetService::class);
    }

    private function insertRun(string $action, ?int $confirmed): void
    {
        $now = Clock::toDecimal(Clock::now());
        $this->mirror()->table('orrery_bulk_runs')->insert([
            'ref' => self::REF, 'action' => $action, 'source' => '{"kind":"batch","batch_id":"b"}', 'source_hash' => str_repeat('0', 64),
            'max_occurrence' => 1_000_000, 'total' => $confirmed ?? 2, 'total_at_least' => false, 'confirmed_total' => $confirmed,
            'actor' => 'operator', 'created_at' => $now, 'last_run_at' => $now,
        ]);
    }

    private function claimed(): int
    {
        return (int) $this->mirror()->table('orrery_bulk_runs')->where('ref', self::REF)->value('claimed');
    }
}
