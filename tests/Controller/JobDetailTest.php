<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\History;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageStatements;
use RuntimeException;

/**
 * GET /api/jobs/{uuid}, its history and a history entry, on
 * mirror rows written directly: the cycles, the retries, the history pages,
 * the bounded large columns, the response budget, the operation, the
 * actions and the batch.
 */
final class JobDetailTest extends ControllerTest
{
    private const UUID = '0e5f7a5c-1b2d-4c3e-8f9a-000000000001';

    private const OTHER = '0e5f7a5c-1b2d-4c3e-8f9a-000000000002';

    private const T = 1_790_000_000.0;

    /** @var list<string> */
    private array $statements = [];

    /**
     * Rejects: a case-sensitive read of the uuid, a 404 without the error
     * code, and a non-uuid path served by the detail (it is the catch-all's
     * body).
     */
    public function test_the_uuid_route(): void
    {
        $this->job();

        $this->detail(strtoupper(self::UUID))->assertOk()->assertJsonPath('id', self::UUID);
        $this->read('/orrery/api/jobs/'.self::OTHER)->assertNotFound()->assertExactJson(['error' => 'not_found', 'message' => 'Not found.']);
        $this->read('/orrery/api/jobs/'.self::OTHER.'/history')->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->read('/orrery/api/jobs/not-a-uuid')->assertNotFound()->assertExactJson(['error' => 'not_found']);
    }

    /**
     * The legacy detail (/api/legacy/jobs/{id}) is gone;
     * the new path answers the new shape without
     * the Horizon fields.
     *
     * Rejects: a legacy detail route left in place, and a detail that still
     * carries the Horizon shapes (`status_label`, `retried_by`,
     * `completed_at`).
     */
    public function test_the_legacy_detail_is_gone(): void
    {
        $this->job();

        $this->read('/orrery/api/legacy/jobs/'.self::UUID)->assertNotFound()->assertExactJson(['error' => 'not_found']);

        $detail = $this->detail()->assertOk()->json();
        self::assertArrayHasKey('cycles', $detail);

        foreach (['status_label', 'retried_by', 'completed_at'] as $field) {
            self::assertArrayNotHasKey($field, $detail);
        }
    }

    /**
     * Rejects: an older cycle read from the row (its frozen effective values
     * and limits), a current cycle that reads the worker evidence of another
     * attempt, a merged row that is not last, and a cycle message that is
     * not the redacted first line.
     */
    public function test_the_cycles_come_from_the_row_and_the_frozen_summaries(): void
    {
        $this->job([
            'status' => 'failed',
            'cycle' => 3,
            'cycle_source' => 'dashboard',
            'cycle_started_at' => Clock::toDecimal(self::T + 300),
            // Laravel's check before attempt 5 failed it: the attempt is more than the tries value.
            'attempts' => 5,
            'releases' => 1,
            'max_tries' => null,
            'timeout' => null,
            'retry_until' => null,
            'worker_tries' => 4,
            'worker_timeout' => 30,
            'worker_evidence_attempt' => 5,
            'finished_at' => Clock::toDecimal(self::T + 400),
            'failed_at' => Clock::toDecimal(self::T + 400),
            'exception_class' => 'Illuminate\\Queue\\MaxAttemptsExceededException',
            'exception_message' => "Login password=canary-cycle-current\nsecond line",
            'retried_count' => 2,
        ]);
        $this->summary(2, ['outcome' => 'failed', 'started_source' => 'cli', 'started_at' => Clock::toDecimal(self::T + 200), 'finished_at' => Clock::toDecimal(self::T + 250),
            'attempts' => 7, 'max_tries' => 7, 'effective_tries' => 7, 'effective_tries_source' => 'payload', 'effective_timeout' => 22, 'effective_timeout_source' => 'worker',
            'exception_class' => 'RuntimeException', 'exception_message' => 'Bearer canary-cycle-frozen']);
        $this->summary(1, ['outcome' => 'failed', 'started_source' => 'push', 'finished_at' => Clock::toDecimal(self::T + 100), 'attempts' => 1]);

        $cycles = $this->detail()->assertOk()->json('cycles');

        self::assertSame([3, 2, 1], array_column($cycles, 'cycle'));
        self::assertSame([true, false, false], array_column($cycles, 'current'));
        self::assertSame(['tries' => 4, 'tries_source' => 'worker', 'timeout' => 30, 'timeout_source' => 'worker'], $cycles[0]['effective']);
        self::assertSame('attempt_limit', $cycles[0]['enforced_rule']['kind']);
        self::assertSame(['kind' => 'attempt_limit', 'facts' => ['tries' => 4, 'tries_source' => 'worker', 'max_exceptions' => null, 'attempt' => 5]], $cycles[0]['terminal']);
        self::assertSame('Login password=[redacted]', $cycles[0]['exception_message']);
        self::assertSame(1, $cycles[0]['releases']);

        self::assertSame(['tries' => 7, 'tries_source' => 'payload', 'timeout' => 22, 'timeout_source' => 'worker'], $cycles[1]['effective'], 'the frozen values, not the row');
        self::assertSame(7, $cycles[1]['limits']['max_tries']);
        self::assertSame('cli', $cycles[1]['started_source']);
        self::assertSame('other', $cycles[1]['terminal']['kind']);
        self::assertSame(7, $cycles[1]['terminal']['facts']['attempt']);
        self::assertSame('Bearer [redacted]', $cycles[1]['exception_message']);

        // The worker evidence of an older attempt is never the current cycle's.
        $this->mirror()->table(JobStore::TABLE)->where('uuid', self::UUID)->update(['worker_evidence_attempt' => 1]);
        $current = $this->detail()->json('cycles.0');
        self::assertSame(['tries' => null, 'tries_source' => 'unknown', 'timeout' => null, 'timeout_source' => 'unknown'], $current['effective']);
        self::assertSame('unknown', $current['enforced_rule']['kind']);
    }

    /**
     * A job past the summary cap: 50 individual summaries and the merged row
     * last, with one `earlier` retry entry and its count.
     *
     * Rejects: a merged row read as a cycle with current values, a missing
     * `earlier` entry, retries counted twice, and an unrecorded count that
     * ignores the folded retries.
     */
    public function test_a_job_past_the_summary_cap(): void
    {
        $summaries = $this->app->make(CycleSummaries::class);
        $mirror = $this->mirror();

        for ($cycle = 1; $cycle <= 53; $cycle++) {
            $summaries->freeze($mirror, (object) [
                'uuid' => self::UUID, 'cycle' => $cycle, 'status' => 'failed', 'attempts' => 1, 'cycle_source' => $cycle === 1 ? 'push' : 'dashboard',
                'cycle_started_at' => self::T + $cycle, 'finished_at' => self::T + $cycle + 0.5, 'exception_class' => 'RuntimeException', 'exception_message' => "failure {$cycle}",
            ], self::T + $cycle);
        }

        $this->job(['cycle' => 54, 'cycle_source' => 'dashboard', 'retried_count' => 55, 'cycle_started_at' => Clock::toDecimal(self::T + 54)]);

        $response = $this->detail()->assertOk();
        $cycles = $response->json('cycles');
        self::assertCount(52, $cycles, 'the current cycle, 50 individual summaries and the merged row');
        self::assertSame(54, $cycles[0]['cycle']);
        self::assertSame(53, $cycles[1]['cycle']);
        self::assertSame(4, $cycles[50]['cycle']);
        self::assertSame(['cycle' => 3, 'merged' => true, 'first_cycle' => 1, 'merged_cycles' => 3, 'merged_retries' => 2], $cycles[51]);

        $retries = $response->json('retries');
        self::assertSame(51, $retries['retries_retained'], 'cycles 4 to 54');
        self::assertSame(2, $retries['retries_folded']);
        self::assertSame(2, $retries['retries_unrecorded'], '55 - 51 - 2');
        self::assertSame(['kind' => 'retry', 'cycle' => 54, 'started_at' => self::T + 54, 'source' => 'dashboard', 'outcome' => 'in_progress'], $retries['entries'][0]);
        self::assertSame(['kind' => 'retry', 'cycle' => 53, 'started_at' => self::T + 53, 'source' => 'dashboard', 'outcome' => 'failed'], $retries['entries'][1]);
        self::assertSame(['kind' => 'earlier', 'first_cycle' => 1, 'last_cycle' => 3, 'count' => 2], $retries['entries'][51]);
        self::assertCount(52, $retries['entries']);
    }

    /**
     * Rejects: an invented entry for an unrecorded retry, a cycle without a
     * source shown as a retry, and a lost inferred flag.
     */
    public function test_unrecorded_and_inferred_retries(): void
    {
        $this->job(['cycle' => 4, 'cycle_source' => 'unknown', 'retried_count' => 3, 'retried_count_inferred' => true, 'status' => 'completed', 'finished_at' => Clock::toDecimal(self::T + 10)]);
        $this->summary(3, ['outcome' => 'failed', 'started_source' => null]);

        $retries = $this->detail()->assertOk()->json('retries');

        self::assertSame([['kind' => 'retry', 'cycle' => 4, 'started_at' => null, 'source' => 'unknown', 'outcome' => 'completed']], $retries['entries']);
        self::assertSame(1, $retries['retries_retained']);
        self::assertSame(0, $retries['retries_folded']);
        self::assertSame(2, $retries['retries_unrecorded']);
        self::assertTrue($retries['retried_count_inferred']);
    }

    /**
     * History pruned under a frozen summary: the cycle keeps its summary,
     * the history says it was truncated.
     *
     * Rejects: a cycle that disappears with its history rows, and a lost
     * truncation note.
     */
    public function test_history_pruned_under_a_frozen_summary(): void
    {
        $this->job(['cycle' => 2, 'history_truncated' => true, 'history_discarded' => 17]);
        $this->summary(1, ['outcome' => 'failed', 'history_truncated' => true, 'attempts' => 3, 'exceptions' => 3]);
        $this->history(2, 1, 'exception');

        $response = $this->detail()->assertOk();
        self::assertSame(3, $response->json('cycles.1.exceptions'));
        self::assertTrue($response->json('history.history_truncated'));
        self::assertSame(17, $response->json('history.history_discarded'));
        self::assertSame([], $this->read($this->path('/history?cycle=1'))->assertOk()->json('entries'));
    }

    /**
     * Rejects: offset paging (a page repeated when an entry arrives between
     * two pages, skipped when one leaves), a page longer than
     * 50, an order other than (cycle desc, id desc), a cycle filter that is
     * ignored, a cursor of one cycle used on another, and an entry with its
     * full exception or an unredacted message.
     */
    public function test_history_pages_by_cursor(): void
    {
        $this->job(['cycle' => 3]);
        $expected = [];

        foreach ([1 => 40, 2 => 40, 3 => 41] as $cycle => $count) {
            for ($i = 0; $i < $count; $i++) {
                $expected[] = [$cycle, $this->history($cycle, $i + 1, 'exception', ['exception_message' => "Bearer canary-history-{$cycle}-{$i}\nline 2", 'exception' => 'trace', 'exception_bytes' => 5])];
            }
        }

        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $first = $this->detail()->assertOk()->json('history');
        self::assertCount(50, $first['entries']);

        // A traversal with an arrival between pages 1 and 2: an offset would repeat the last entry of page 1.
        [$seen, $pages] = $this->historyTraversal(function (): void {
            $this->history(3, 99, 'exception');
        });
        self::assertSame(3, $pages);
        self::assertSame($expected, $seen, 'an arrival between pages repeats nothing');
        self::assertSame(array_column($first['entries'], 'id'), array_map(static fn (array $pair): int => $pair[1], array_slice($seen, 0, 50)));

        // A traversal with a departure between pages 1 and 2 (the history bound pruned an entry of page 1): an offset would skip the first entry of page 2.
        $expected = array_map(static fn (object $row): array => [(int) $row->cycle, (int) $row->id], $this->mirror()->table(History::TABLE)
            ->where('job_uuid', self::UUID)->orderByDesc('cycle')->orderByDesc('id')->get(['cycle', 'id'])->all());
        [$seen, $pages] = $this->historyTraversal(function (array $seen): void {
            $this->mirror()->table(History::TABLE)->where('id', $seen[10][1])->delete();
        });
        self::assertSame(3, $pages);
        self::assertSame($expected, $seen, 'a departure between pages skips nothing');

        self::assertSame('Bearer [redacted]', $first['entries'][0]['exception_message']);
        self::assertTrue($first['entries'][0]['has_exception']);

        $cycle2 = $this->read($this->path('/history?cycle=2'))->assertOk();
        self::assertSame([2], array_values(array_unique(array_column($cycle2->json('entries'), 'cycle'))));
        self::assertCount(40, $cycle2->json('entries'));
        self::assertNull($cycle2->json('next_cursor'));

        $this->read($this->path('/history?cycle=3&cursor='.$first['next_cursor']))->assertStatus(422)->assertJsonPath('error', 'cursor_mismatch');
        $this->read($this->path('/history?cycle=0'))->assertStatus(422)->assertJsonPath('field', 'cycle');
        $this->read($this->path('/history?cycle[]=1'))->assertStatus(422)->assertJsonPath('field', 'cycle');
        $this->read($this->path('/history?cursor=abc'))->assertStatus(422)->assertJsonPath('field', 'cursor');
    }

    /**
     * Rejects: a cycle, or a cursor's cycle, above the 32-bit `integer` of Postgres: it reached the
     * column and answered 503 with a host report there. Both answer 422; the largest 32-bit value is accepted.
     * Still passes: a bound that is lower than the column's but still above every real cycle.
     */
    public function test_a_cycle_beyond_the_integer_column_is_refused(): void
    {
        $this->job(['cycle' => 2]);

        $this->read($this->path('/history?cycle=2147483648'))->assertStatus(422)->assertJsonPath('field', 'cycle');
        $this->read($this->path('/history?cycle=2147483647'))->assertOk();

        $hash = hash('sha256', 'history|'.self::UUID.'|');

        foreach (['2147483648.000000' => 422, '2147483647.000000' => 200] as $order => $status) {
            $cursor = \Justrau\Orrery\Http\Lists\Cursor::at($order, 1, \Justrau\Orrery\Http\Lists\Cursor::AFTER, $hash)->encode();
            $this->read($this->path('/history?cursor='.$cursor))->assertStatus($status);
        }
    }

    /**
     * Rejects: an entry of another job, an entry without its redacted full
     * exception and context, and an oversized exception loaded or cut.
     */
    public function test_a_history_entry(): void
    {
        $this->job();
        $this->job(['uuid' => self::OTHER]);
        $text = "RuntimeException: password=canary-entry\n#0 Bearer canary-entry-trace";
        $context = (string) json_encode(['api_key' => 'canary-entry-context']);
        $id = $this->history(1, 1, 'exception', ['exception' => $text, 'exception_bytes' => strlen($text), 'context' => $context, 'context_bytes' => strlen($context)]);
        $large = str_repeat('x', JobProjection::EXCEPTION_BYTES + 1);
        $big = $this->history(1, 2, 'exception', ['exception' => $large, 'exception_bytes' => strlen($large)]);

        $entry = $this->read($this->path('/history/'.$id))->assertOk();
        self::assertStringNotContainsString('canary', (string) $entry->getContent());
        self::assertSame("RuntimeException: password=[redacted]\n#0 Bearer [redacted]", $entry->json('exception'));
        self::assertSame(['api_key' => '[redacted]'], $entry->json('context'));
        self::assertSame($id, $entry->json('id'));

        $this->recordStatements();
        self::assertSame(['omitted' => 'too_large', 'bytes' => strlen($large)], $this->read($this->path('/history/'.$big))->assertOk()->json('exception'));
        self::assertStringContainsString('case when exception_bytes <= 65536 then exception end', strtolower(implode("\n", $this->statements)));

        $this->read('/orrery/api/jobs/'.self::OTHER.'/history/'.$id)->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->read($this->path('/history/999999'))->assertNotFound();
    }

    /**
     * Rejects: a detail that loads an oversized payload, exception or context
     * (the SQL log shows the CASE of each), or cuts it to a prefix.
     */
    public function test_oversized_columns_are_omitted_whole(): void
    {
        $payload = (string) json_encode(['uuid' => self::UUID, 'displayName' => 'Big', 'data' => ['command' => 'N;'], 'pad' => str_repeat('p', JobProjection::PAYLOAD_BYTES)]);
        $exception = str_repeat('e', JobProjection::EXCEPTION_BYTES + 5);
        $context = (string) json_encode(['pad' => str_repeat('c', JobProjection::CONTEXT_BYTES)]);
        $this->job(['payload' => $payload, 'payload_bytes' => strlen($payload), 'exception' => $exception, 'exception_bytes' => strlen($exception), 'context' => $context, 'context_bytes' => strlen($context)]);
        $this->recordStatements();

        $response = $this->detail()->assertOk();

        self::assertSame(['omitted' => 'too_large', 'bytes' => strlen($payload)], $response->json('payload'));
        self::assertSame(['omitted' => 'too_large', 'bytes' => strlen($payload)], $response->json('command'));
        self::assertSame(['omitted' => 'too_large', 'bytes' => strlen($exception)], $response->json('exception'));
        self::assertSame(['omitted' => 'too_large', 'bytes' => strlen($context)], $response->json('context'));
        $sql = strtolower(implode("\n", $this->statements));

        foreach (['payload' => 1048576, 'exception' => 65536, 'context' => 262144] as $column => $limit) {
            self::assertStringContainsString("case when {$column}_bytes <= {$limit} then {$column} end", $sql);
        }

        self::assertLessThan(10_000, strlen((string) $response->getContent()));
    }

    /**
     * 51 cycles of 60 KB messages stay within the 2 MB budget: each cycle
     * message is the first line, at most 500 characters.
     *
     * Rejects: a cycle message sent whole, and a response over the budget.
     */
    public function test_the_response_budget_with_many_long_cycles(): void
    {
        $long = str_repeat('m', 60_000);
        $this->job(['cycle' => 52, 'exception_message' => $long, 'status' => 'failed', 'finished_at' => Clock::toDecimal(self::T), 'failed_at' => Clock::toDecimal(self::T)]);

        for ($cycle = 2; $cycle <= 51; $cycle++) {
            $this->summary($cycle, ['outcome' => 'failed', 'exception_message' => $long, 'started_source' => 'dashboard']);
        }

        $this->summary(1, ['merged' => true, 'first_cycle' => 1, 'merged_cycles' => 1, 'merged_retries' => 0]);

        $response = $this->detail()->assertOk();
        self::assertLessThan(JobProjection::RESPONSE_BYTES, strlen((string) $response->getContent()));
        self::assertCount(52, $response->json('cycles'));
        self::assertSame(500, mb_strlen($response->json('cycles.0.exception_message')));
        self::assertSame(500, mb_strlen($response->json('cycles.50.exception_message')));
        self::assertNotContains('earlier', array_column($response->json('retries.entries'), 'kind'), 'a merged row without a retry gives no earlier entry');
    }

    /**
     * Rejects: a ledger read that selects payload, saved_record or error, a
     * held row offered Retry or Forget (also a failed row that a forget
     * claim holds), Resolve without an awaiting operation, and a gone ledger
     * row shown as "not held".
     */
    public function test_the_operation_and_the_actions(): void
    {
        $operation = str_repeat('a', 32);
        $this->job(['status' => 'retrying', 'failed_at' => Clock::toDecimal(self::T), 'finished_at' => Clock::toDecimal(self::T), 'operation_id' => $operation]);
        $this->mirror()->table(Operations::TABLE)->insert([
            'id' => $operation, 'action' => 'retry', 'state' => 'claimed', 'mode' => 'in_tx', 'job_uuid' => self::UUID, 'occurrence_id' => 1,
            'queue_connection' => 'database', 'queue' => 'default', 'payload' => '{"secret":"canary-ledger-payload"}', 'error' => 'canary-ledger-error',
            'queue_fingerprint' => str_repeat('f', 64), 'deadline' => Clock::toDecimal(self::T + 60), 'next_check_at' => Clock::toDecimal(self::T + 5),
            'awaiting_since' => Clock::toDecimal(self::T), 'awaiting_reason' => 'sqlite_host_file', 'actor' => 'ops@example.com', 'source' => 'dashboard',
            'error_code' => 'publish_failed', 'created_at' => Clock::toDecimal(self::T), 'updated_at' => Clock::toDecimal(self::T + 1),
        ]);
        $this->recordStatements();

        $response = $this->detail()->assertOk();
        self::assertStringNotContainsString('canary-ledger', (string) $response->getContent());
        self::assertSame([
            'id' => $operation, 'action' => 'retry', 'state' => 'claimed', 'mode' => 'in_tx', 'source' => 'dashboard', 'actor' => 'ops@example.com',
            'error_code' => 'publish_failed', 'awaiting_reason' => 'sqlite_host_file', 'bulk_ref' => null,
            'created_at' => self::T, 'updated_at' => self::T + 1, 'next_check_at' => self::T + 5,
        ], $response->json('operation'));
        self::assertSame(['retry' => false, 'forget' => false, 'resolve' => true, 'read_only_reason' => null], $response->json('actions'));

        foreach ($this->statements as $sql) {
            if (str_contains($sql, Operations::TABLE)) {
                self::assertDoesNotMatchRegularExpression('/"payload"|`payload`|saved_record|"error"|`error`|\*/', $sql, $sql);
            }
        }

        // A forget claim holds a row that stays failed: still no Retry or Forget.
        $this->mirror()->table(JobStore::TABLE)->where('uuid', self::UUID)->update(['status' => 'failed', 'provider_id' => 7]);
        self::assertSame(['retry' => false, 'forget' => false, 'resolve' => true, 'read_only_reason' => null], $this->detail()->json('actions'));

        $this->mirror()->table(Operations::TABLE)->delete();
        $gone = $this->detail()->assertOk()->json('operation');
        self::assertSame($operation, $gone['id']);
        self::assertNull($gone['action']);
        self::assertNull($gone['state']);
        self::assertSame(['retry' => false, 'forget' => false, 'resolve' => false, 'read_only_reason' => null], $this->detail()->json('actions'));
    }

    /**
     * Rejects: Retry offered for a failure whose record Orrery cannot prove
     * (a null provider_id under the SQL failed-record adapter), and refused
     * for a provable one.
     */
    public function test_the_actions_of_a_failed_row(): void
    {
        $this->job(['status' => 'failed', 'failed_at' => Clock::toDecimal(self::T), 'finished_at' => Clock::toDecimal(self::T), 'provider_id' => null]);
        self::assertSame(['retry' => false, 'forget' => false, 'resolve' => false, 'read_only_reason' => 'occurrence_unprovable'], $this->detail()->assertOk()->json('actions'));

        $this->mirror()->table(JobStore::TABLE)->where('uuid', self::UUID)->update(['provider_id' => 7]);
        self::assertSame(['retry' => true, 'forget' => true, 'resolve' => false, 'read_only_reason' => null], $this->detail()->json('actions'));

        $this->app['config']->set('queue.failed.driver', 'file');
        $this->app->forgetInstance('queue.failer');
        self::assertSame('provider_read_only', $this->detail()->json('actions.read_only_reason'));
        self::assertFalse($this->detail()->json('actions.retry'));
    }

    /**
     * Rejects: Retry and Forget offered while the failed-record adapter
     * cannot be decided (the bound failer throws): the server would refuse
     * them, so the detail does not offer them.
     */
    public function test_an_undecidable_failer_offers_no_retry(): void
    {
        $this->job(['status' => 'failed', 'failed_at' => Clock::toDecimal(self::T), 'finished_at' => Clock::toDecimal(self::T), 'provider_id' => 7]);
        $this->app->bind('queue.failer', static fn () => throw new RuntimeException('The failer cannot be built.'));

        self::assertSame(['retry' => false, 'forget' => false, 'resolve' => false, 'read_only_reason' => null], $this->detail()->assertOk()->json('actions'));
    }

    /**
     * A section whose table cannot be read is unknown: null, never empty.
     *
     * Rejects: a detail that answers 503 for a missing part table, and tags,
     * cycles, retries or history shown as [] (no tags, no retries) when they
     * could not be read.
     */
    #[FreshSchema]
    public function test_a_section_that_cannot_be_read_is_null(): void
    {
        $this->job(['status' => 'failed', 'failed_at' => Clock::toDecimal(self::T), 'finished_at' => Clock::toDecimal(self::T), 'cycle' => 2, 'cycle_source' => 'dashboard']);

        foreach (['orrery_job_tags', CycleSummaries::TABLE, History::TABLE] as $table) {
            $this->mirrorSchema()->drop($table);
        }

        $response = $this->detail()->assertOk();
        self::assertSame(self::UUID, $response->json('id'));
        self::assertNull($response->json('tags'));
        self::assertNull($response->json('cycles'));
        self::assertNull($response->json('retries'));
        self::assertNull($response->json('history'));
        self::assertArrayHasKey('tags', $response->json());
        self::assertArrayHasKey('history', $response->json());
    }

    /**
     * The 2 MB budget through the detail: a payload just under 1 MB, whose
     * command holds 16 strings of 61,000 bytes, is projected twice (the
     * envelope and the command), and with the exception and the context the
     * response passes the budget: the largest field, the payload, is
     * replaced.
     *
     * Rejects: a detail that does not fit its response (a body over 2 MB), or
     * that drops another field than the largest.
     */
    public function test_the_detail_fits_the_response_budget(): void
    {
        $properties = [];

        for ($i = 0; $i < 16; $i++) {
            $properties[] = 's:4:"p'.sprintf('%03d', $i).'";s:61000:"'.str_repeat(chr(97 + $i % 26), 61_000).'";';
        }

        $command = 'O:13:"App\\Jobs\\Sync":16:{'.implode('', $properties).'}';
        $payload = (string) json_encode(['uuid' => self::UUID, 'displayName' => 'App\\Jobs\\Sync', 'data' => ['commandName' => 'App\\Jobs\\Sync', 'command' => $command]]);
        self::assertLessThanOrEqual(JobProjection::PAYLOAD_BYTES, strlen($payload));
        $exception = str_repeat('e', 60_000);
        // Four strings of 60,000 bytes: a string over 64 KB would be omitted on its own.
        $context = (string) json_encode(['a' => str_repeat('c', 60_000), 'b' => str_repeat('d', 60_000), 'c' => str_repeat('e', 60_000), 'd' => str_repeat('f', 60_000)]);
        $this->job(['payload' => $payload, 'payload_bytes' => strlen($payload), 'exception' => $exception, 'exception_bytes' => strlen($exception), 'context' => $context, 'context_bytes' => strlen($context)]);

        $response = $this->detail()->assertOk();

        self::assertLessThanOrEqual(JobProjection::RESPONSE_BYTES, strlen((string) $response->getContent()));
        self::assertSame('response_budget', $response->json('payload.omitted'));
        self::assertSame(16, count($response->json('command.properties')), 'the command stays');
        self::assertSame($exception, $response->json('exception'));
        self::assertSame(str_repeat('c', 60_000), $response->json('context.a'), 'the context stays');
    }

    /**
     * Rejects: a running row without its worker and lease, and tags read as
     * empty.
     */
    public function test_a_running_row_has_its_worker_tags_and_lease(): void
    {
        $now = Clock::now();
        $this->job(['status' => 'reserved', 'reserved_at' => Clock::toDecimal($now - 10), 'reserved_by' => 'host:1:2', 'attempts' => 1]);
        $this->mirror()->table('orrery_job_tags')->insert([['tag' => 'b', 'job_uuid' => self::UUID, 'pushed_at' => Clock::toDecimal(self::T)], ['tag' => 'a', 'job_uuid' => self::UUID, 'pushed_at' => Clock::toDecimal(self::T)]]);

        $response = $this->detail()->assertOk();
        self::assertSame(['a', 'b'], $response->json('tags'));
        self::assertEqualsWithDelta($now - 10 + 90, $response->json('lease_expires_at'), 0.01);
        self::assertSame('live', $response->json('worker.liveness'));
        self::assertSame('host:1:2', $response->json('worker.id'));
        self::assertArrayHasKey('signals', $response->json());
        self::assertSame('unknown', $response->json('cycles.0.enforced_rule.kind'), 'no tries value and no deadline');
        self::assertSame('in_progress', $response->json('cycles.0.outcome'));
        self::assertNull($response->json('cycles.0.terminal'));
    }

    /**
     * Rejects: a batch name sent unredacted or unbounded, and a batch table
     * read error that fails the detail.
     */
    public function test_the_batch_name_goes_through_the_pattern_rules(): void
    {
        $this->job(['batch_id' => 'batch-1']);
        $this->app['db']->connection()->table('job_batches')->insert([
            'id' => 'batch-1', 'name' => 'Import Bearer canary-batch-name', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0,
            'failed_job_ids' => '[]', 'options' => serialize([]), 'created_at' => (int) self::T, 'cancelled_at' => null, 'finished_at' => null,
        ]);

        self::assertSame(['id' => 'batch-1', 'name' => 'Import Bearer [redacted]'], $this->detail()->assertOk()->json('batch'));

        // A name that redaction makes longer than 255 characters (254 stored, 263 redacted) is redacted whole, then cut.
        $this->app['db']->connection()->table('job_batches')->where('id', 'batch-1')->update(['name' => str_repeat('é', 245).' Bearer x']);
        self::assertSame(str_repeat('é', 245).' Bearer [r', $this->detail()->json('batch.name'));

        $this->app['config']->set('queue.batching.table', 'orrery_d8_6_missing_batches');
        self::assertSame(['id' => 'batch-1', 'name' => null], $this->detail()->assertOk()->json('batch'));
    }

    /**
     * Rejects: job data sent while the redaction configuration is invalid.
     */
    public function test_an_invalid_redaction_pattern_refuses_every_endpoint(): void
    {
        $this->job();
        $id = $this->history(1, 1, 'exception');
        $this->app['config']->set('orrery.redact.patterns', ['~valid~', 'not a pattern']);

        // A job without history rows projects nothing on its history page: the refusal still comes first.
        $this->job(['uuid' => self::OTHER]);
        $this->read('/orrery/api/jobs/'.self::OTHER.'/history')->assertStatus(500)->assertJsonPath('error', 'redaction_config_invalid');

        foreach (['', '/history', '/history/'.$id] as $suffix) {
            $this->read($this->path($suffix))->assertStatus(500)->assertExactJson([
                'error' => 'redaction_config_invalid',
                'message' => 'The redaction configuration is invalid, so job data is not shown.',
                'field' => 'orrery.redact.patterns.1',
            ]);
        }
    }

    /**
     * Pages through the job's history; $between runs once, after page 1,
     * with the entries seen so far.
     *
     * @param  \Closure(list<array{0: int, 1: int}>): void  $between
     * @return array{0: list<array{0: int, 1: int}>, 1: int}
     */
    private function historyTraversal(\Closure $between): array
    {
        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $this->read($this->path('/history'.($cursor === null ? '' : '?cursor='.$cursor)))->assertOk();
            $raw = (string) $page->getContent();
            self::assertStringNotContainsString('canary-history', $raw);
            self::assertStringNotContainsString('trace', $raw);
            $seen = [...$seen, ...array_map(static fn (array $entry): array => [$entry['cycle'], $entry['id']], $page->json('entries'))];
            $cursor = $page->json('next_cursor');
            $pages++;

            if ($pages === 1) {
                $between($seen);
            }
        } while ($cursor !== null && $pages < 10);

        return [$seen, $pages];
    }

    private function detail(string $uuid = self::UUID): TestResponse
    {
        return $this->read('/orrery/api/jobs/'.$uuid);
    }

    private function path(string $suffix): string
    {
        return '/orrery/api/jobs/'.self::UUID.$suffix;
    }

    private function read(string $uri): TestResponse
    {
        return $this->actingAs(new User)->getJson($uri);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function job(array $values = []): void
    {
        $uuid = $values['uuid'] ?? self::UUID;
        $payload = (string) json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\Sync', 'data' => ['commandName' => 'App\\Jobs\\Sync', 'command' => 'O:13:"App\\Jobs\\Sync":0:{}']]);

        $this->mirror()->table(JobStore::TABLE)->insert(array_merge([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'name' => 'App\\Jobs\\Sync',
            'status' => 'pending',
            'payload' => $payload,
            'payload_bytes' => strlen($payload),
            'attempts' => 0,
            'occurrence_id' => 1,
            'pushed_at' => Clock::toDecimal(self::T),
            'updated_at' => Clock::toDecimal(self::T),
        ], $values));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function summary(int $cycle, array $values = []): void
    {
        $this->mirror()->table(CycleSummaries::TABLE)->insert(array_merge(['job_uuid' => self::UUID, 'cycle' => $cycle], $values));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function history(int $cycle, int $attempt, string $event, array $values = []): int
    {
        return (int) $this->mirror()->table(History::TABLE)->insertGetId(array_merge([
            'job_uuid' => self::UUID,
            'cycle' => $cycle,
            'attempt' => $attempt,
            'event' => $event,
            'occurred_at' => Clock::toDecimal(self::T + $cycle * 100 + $attempt),
        ], $values));
    }

    private function recordStatements(): void
    {
        $this->statements = [];
        PackageStatements::listen($this->app, function (string $kind, string $sql): void {
            if ($kind === PackageSession::STATEMENT) {
                $this->statements[] = $sql;
            }
        });
    }
}
