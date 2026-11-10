<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Retry\RetryResult;

/**
 * Package and native retries of one job, and their generations (lifetime retry count, R5 and R7).
 */
final class RetryCycleTest extends RetryTestCase
{
    /**
     * Rejects: a stale `orreryRetry` that blocks R5.
     */
    public function test_package_retry_failure_then_native_retry_with_a_stale_operation_starts_a_cycle(): void
    {
        $this->queue();
        [$uuid, $occurrence] = $this->failedJob($this->job(failRuns: 2));

        // Cycle 2: the package retry, which fails again.
        $retry = $this->service()->retry($uuid, $occurrence, 'cli');
        self::assertResult(RetryResult::ACCEPTED, null, $retry);
        $operation = (string) $retry->operationId;
        $this->workAndLogFailures();
        $failed = $this->rowOrFail($uuid);
        self::assertSame(['failed', 2, $operation], [$failed->status, (int) $failed->cycle, $failed->cycle_gen]);
        $record = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($record);
        self::assertSame($operation, json_decode((string) $record->payload, true)['orreryRetry']['operation'], 'the failed payload carries the old operation');

        // Cycle 3: a native retry of that payload.
        self::assertSame(0, Artisan::call('queue:retry', ['id' => ['all']]));
        $republished = $this->onlyJobsRow();

        self::assertArrayNotHasKey('orreryRetry', $republished['data'], 'the swapped queue removes the stale orreryRetry');
        self::assertNotSame($operation, $republished['data']['orreryGen'], 'and stamps a new generation');

        $this->work();

        $row = $this->rowOrFail($uuid);
        self::assertSame(['completed', 3, 'native', 2], [$row->status, (int) $row->cycle, $row->cycle_source, (int) $row->retried_count], 'R5 started cycle 3');
        self::assertSame([1, 2], array_map(static fn (array $summary): int => (int) $summary['cycle'], $this->summaries($uuid)));
        self::assertSame(['push', 'cli'], array_map(static fn (array $summary): string => (string) $summary['started_source'], $this->summaries($uuid)));
    }

    /**
     * Rejects: `retried_count` without the native retries.
     */
    public function test_mixed_native_and_package_retries_count_retained_and_folded(): void
    {
        [$uuid] = $this->failedJob($this->job(failRuns: 3));
        // 49 retained summaries of earlier cycles: the row is in cycle 50 after 49 retries.
        for ($cycle = 1; $cycle <= 49; $cycle++) {
            $this->mirror()->table(CycleSummaries::TABLE)->insert([
                'job_uuid' => $uuid, 'cycle' => $cycle, 'cycle_gen' => str_pad(dechex($cycle), 32, 'e', STR_PAD_LEFT), 'outcome' => 'failed', 'merged' => false, 'attempts' => 1, 'observed_attempts' => 1, 'last_outcome_attempt' => 1,
            ]);
        }
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['cycle' => 50, 'retried_count' => 49]);

        // A native retry (cycle 51), a package retry (cycle 52, the 51st summary compacts cycle 1),
        // and a native retry again (cycle 53, which compacts cycle 2).
        self::assertSame(0, Artisan::call('queue:retry', ['id' => ['all']]));
        $this->workAndLogFailures();
        $failed = $this->rowOrFail($uuid);
        self::assertSame(['failed', 51, 'native'], [$failed->status, (int) $failed->cycle, $failed->cycle_source]);

        self::assertResult(RetryResult::ACCEPTED, null, $this->service()->retry($uuid, (int) $failed->occurrence_id, 'batch'));
        $this->workAndLogFailures();
        self::assertSame(['failed', 52, 'batch'], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->cycle, $this->rowOrFail($uuid)->cycle_source]);

        self::assertSame(0, Artisan::call('queue:retry', ['id' => ['all']]));
        $this->work();

        $row = $this->rowOrFail($uuid);
        self::assertSame(['completed', 53, 52], [$row->status, (int) $row->cycle, (int) $row->retried_count]);

        $merged = $this->mirror()->table(CycleSummaries::TABLE)->where('job_uuid', $uuid)->where('merged', true)->first();
        self::assertNotNull($merged);
        self::assertSame([2, 1], [(int) $merged->merged_cycles, (int) $merged->merged_retries], 'cycles 1 and 2 are folded; cycle 2 was a retry');
        $retained = $this->mirror()->table(CycleSummaries::TABLE)->where('job_uuid', $uuid)->where('merged', false)->where('cycle', '>', 1)->count();
        self::assertSame(50, $retained);
        self::assertSame((int) $merged->merged_retries + $retained + 1, (int) $row->retried_count, 'retried_count = the folded retries + the retained retries + the current cycle');

        // R7 with a later cycle: a row created by the reservation of a package retry payload infers its count.
        $orphan = strtolower((string) Str::uuid());
        $payload = (string) json_encode(['uuid' => $orphan, 'displayName' => 'Orphan', 'job' => 'Orphan@handle', 'orreryGen' => str_repeat('f', 32), 'orreryRetry' => ['cycle' => 4, 'operation' => str_repeat('f', 32)]]);
        $reserve = Observation::fromPayload(Observation::RESERVE, $payload, 'database', 'default', ['jobId' => 999_999, 'attempt' => 1]);
        self::assertNotNull($reserve);
        self::assertSame('reserveWithoutRow', $this->app->make(JobStore::class)->observe($reserve)->rule);
        $created = $this->rowOrFail($orphan);
        self::assertSame([4, 3, true], [(int) $created->cycle, (int) $created->retried_count, (bool) $created->retried_count_inferred]);
    }
}
