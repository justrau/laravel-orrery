<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Justrau\Orrery\Http\Controllers\HealthController;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Storage\Clock;

/**
 * `awaiting_resolution` of /api/health: the operations that
 * wait for an operator, with the target_changed ones checked again at each
 * read. Kind: new behaviour.
 */
final class AwaitingHealthTest extends RetryProtocolTestCase
{
    /**
     * Rejects: a fingerprint check whose error nulls the whole field (unknown for every operation): an operation
     * whose failed-record connection is no longer configured is listed as target_changed, and one whose targets hold
     * again is not.
     */
    public function test_awaiting_lists_an_operation_whose_connection_was_removed(): void
    {
        $gone = $this->changed(['record_connection' => 'testbench_removed', 'record_table' => 'failed_jobs', 'record_fingerprint' => str_repeat('1', 64)]);
        $holds = $this->changed([]);

        $field = $this->awaiting();

        self::assertNotNull($field, 'The field is read.');
        self::assertSame(1, $field['count']);
        self::assertFalse($field['at_least']);
        self::assertSame([$gone], array_column($field['operations'], 'id'));
        self::assertSame(RetryHandler::TARGET_CHANGED, $field['operations'][0]['reason']);
        self::assertNotContains($holds, array_column($field['operations'], 'id'));
    }

    /**
     * Rejects: a count of the target_changed operations that reads as exact past its scan cap.
     */
    public function test_awaiting_count_says_at_least_past_its_scan_cap(): void
    {
        $rows = [];

        foreach (range(1, HealthController::TARGET_SCAN_LIMIT + 1) as $i) {
            $rows[] = $this->ledgerRow(Operations::newId(), ['queue_fingerprint' => str_repeat('0', 64)]);
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            $this->mirror()->table(Operations::TABLE)->insert($chunk);
        }

        $field = $this->awaiting();
        self::assertNotNull($field);
        self::assertSame([HealthController::TARGET_SCAN_LIMIT, true], [$field['count'], $field['at_least']]);
        self::assertCount(HealthController::AWAITING_LIMIT, $field['operations']);

        $this->mirror()->table(Operations::TABLE)->limit(491)->delete();
        $field = $this->awaiting();
        self::assertNotNull($field);
        self::assertSame([10, false], [$field['count'], $field['at_least']]);
    }

    /**
     * @return array{count: int, at_least: bool, operations: list<array<string, mixed>>}|null
     */
    private function awaiting(): ?array
    {
        Orrery::auth(static fn (): bool => true);

        return $this->getJson('/orrery/api/health')->assertOk()->json('awaiting_resolution');
    }

    /**
     * A claimed retry with target_changed; $overrides replace its columns (by default both targets hold).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function changed(array $overrides): string
    {
        $id = Operations::newId();
        $this->mirror()->table(Operations::TABLE)->insert($this->ledgerRow($id, $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ledgerRow(string $id, array $overrides): array
    {
        $time = Clock::toDecimal(Clock::now());

        return array_replace([
            'id' => $id, 'action' => Operations::RETRY, 'state' => Operations::CLAIMED, 'mode' => Operations::NONE,
            'job_uuid' => '0c5b0c5b-0c5b-4c5b-8c5b-'.substr($id, 0, 12), 'occurrence_id' => 1, 'queue_connection' => 'database', 'queue' => 'default',
            'queue_fingerprint' => $this->app->make(Publication::class)->queueFingerprint('database'), 'deadline' => Clock::toDecimal(1000.0),
            'next_check_at' => Clock::toDecimal(1000.0), 'checks' => 0, 'error_code' => RetryHandler::TARGET_CHANGED, 'source' => 'cli',
            'created_at' => $time, 'updated_at' => $time,
        ], $overrides);
    }
}
