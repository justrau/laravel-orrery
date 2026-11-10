<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Adoption of a package retry by its own worker before the publisher
 * finalises (rules R4, C2 and F1).
 */
final class AdoptionTest extends RetryTestCase
{
    /**
     * Rejects: an adoption that finalises twice.
     */
    #[DataProvider('workers')]
    public function test_worker_reserves_completes_and_fails_before_finalisation(string $worker, string $status, string $rule): void
    {
        [$uuid, $occurrence] = $this->failedJob($this->job(failRuns: $status === 'failed' ? 2 : 1));
        $seen = null;

        Hooks::$callback = function (string $point) use ($worker, $uuid, &$seen): void {
            if ($point !== RetryService::AFTER_COMMIT || $seen !== null) {
                return;
            }

            $published = $this->onlyJobsRow();
            self::assertSame('retrying', $this->rowOrFail($uuid)->status, 'the publisher has not finalised yet');

            switch ($worker) {
                case 'reserve and complete':
                    $this->work();
                    break;
                case 'reserve and fail':
                    $this->workAndLogFailures();
                    break;
                default:
                    // The outcome arrives without its reservation (a dropped reserve observation).
                    $kind = $worker === 'completion only' ? Observation::COMPLETE : Observation::FAIL;
                    $observation = Observation::fromPayload($kind, $published['payload'], 'database', 'default', [
                        'jobId' => $published['id'],
                        'attempt' => 1,
                        'exception' => new RuntimeException('the adopted attempt failed'),
                    ]);
                    self::assertNotNull($observation);
                    $seen = $this->app->make(JobStore::class)->observe($observation)->rule;
            }

            $seen ??= 'worker';
        };

        $result = $this->service()->retry($uuid, $occurrence, 'dashboard');
        Hooks::$callback = null;

        if ($worker === 'completion only' || $worker === 'failure only') {
            self::assertSame($rule, $seen, 'the outcome adopted the claim');
        }

        self::assertResult(RetryResult::ACCEPTED, null, $result, 'the adopted publication is accepted');
        $operationId = (string) $result->operationId;
        self::assertSame(2, $result->cycle);

        $row = $this->rowOrFail($uuid);
        self::assertSame([$status, 2, $operationId, 1, 'dashboard'], [$row->status, (int) $row->cycle, $row->cycle_gen, (int) $row->retried_count, $row->cycle_source]);
        self::assertNull($row->operation_id, 'the adoption released the row');
        self::assertSame(Operations::DONE, $this->operation($operationId)->state, 'the adoption ended the operation, once');
        self::assertSame(1, count(array_filter($this->historyEvents($uuid), static fn (string $event): bool => $event === 'retried')), 'one retried row');
        self::assertCount(1, $this->summaries($uuid), 'one frozen summary');
        self::assertSame(str_starts_with($worker, 'reserve') ? 1 : 0, (int) $row->attempts, 'a missed reservation leaves the attempts of the new cycle at 0');
        self::assertSame(1, (int) $row->observed_attempts, 'the outcome of attempt 1 is counted');

        if ($status === 'failed') {
            self::assertNotSame($occurrence, (int) $row->occurrence_id, 'the adopted attempt failed: a new occurrence');
            self::assertSame(['failed', 'retried', 'failed'], $this->historyEvents($uuid));
        } else {
            self::assertSame(['failed', 'retried', 'completed'], $this->historyEvents($uuid));
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function workers(): array
    {
        return [
            'reserve and complete' => ['reserve and complete', 'completed', 'adoptRetryReservation'],
            'reserve and fail' => ['reserve and fail', 'failed', 'adoptRetryReservation'],
            'completion only' => ['completion only', 'completed', 'adoptRetryCompletion'],
            'failure only' => ['failure only', 'failed', 'adoptRetryFailure'],
        ];
    }
}
