<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Illuminate\Support\Str;
use Justrau\Orrery\Database\Models\Job;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Lifecycle\RuntimeClock;
use Justrau\Orrery\Lifecycle\StoreOutcome;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\WorkerEvidence;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\LostAcknowledgementStatement;
use Justrau\Orrery\Tests\Support\PackageSessionHelpers;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use RuntimeException;

/**
 * The lifecycle store, on every engine.
 */
final class JobStoreTest extends TestCase
{
    use BuildsObservations;
    use PackageSessionHelpers;

    private const W1 = 'host-a:100:1789990000';

    private const W2 = 'host-b:200:1789990001';

    /** @var list<array{sql: string, in_transaction: bool}> */
    private array $statements = [];

    private int $begins = 0;

    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();

        PackageStatements::listen($this->app, function (string $kind, string $sql): void {
            if (! $this->recording) {
                return;
            }

            if ($kind === PackageSession::STATEMENT && str_contains($sql, 'orrery_')) {
                $this->statements[] = ['sql' => $sql, 'in_transaction' => PackageTransaction::isOpen($this->app->make(PackageSession::class)->mirror())];
            } elseif ($kind === PackageSession::BEGIN && ! str_starts_with($sql, 'SAVEPOINT') && ! str_starts_with($sql, 'BEGIN IMMEDIATE')) {
                // A server begin; PackageTransaction counts its SQLite BEGIN IMMEDIATE itself.
                $this->begins++;
            }
        });

        // A warm mirror session: the sibling SQLite mirror file reads its
        // schema marker when the process first opens it.
        $this->app->make(PackageSession::class)->mirror();
    }

    /**
     * Rejects: runtime_ms only for the `complete` rule: a completion without a row (completeWithoutRow) that the runtime clock timed stores no runtime.
     */
    public function test_completion_without_a_row_stores_the_clocked_runtime(): void
    {
        $uuid = $this->uuid();
        RuntimeClock::start($uuid);
        Clock::advanceMonotonicForTesting(0.75);

        $outcome = $this->observe('complete', $uuid, ['jobId' => 10, 'attempt' => 1]);

        self::assertSame('completeWithoutRow', $outcome->rule);
        $runtime = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('runtime_ms');
        self::assertNotNull($runtime, 'the runtime clock timed the completion');
        self::assertGreaterThanOrEqual(750, (int) $runtime);
    }

    /**
     * Rejects: a runtime fallback from the reserved_at of an earlier
     * attempt (a released row, or a completion after a reclaim that was
     * not observed), which inflates the runtime and the wait estimate; and
     * a completion of a higher attempt of the same job id that the reserved
     * row refuses.
     */
    public function test_the_runtime_fallback_uses_only_the_reservation_of_the_completed_attempt(): void
    {
        $same = $this->uuid();
        $this->observe('push', $same, ['jobId' => 10]);
        $this->observe('reserve', $same, ['jobId' => 10, 'attempt' => 1, 'at' => self::T]);
        self::assertSame('complete', $this->observe('complete', $same, ['jobId' => 10, 'attempt' => 1, 'at' => self::T + 2])->rule);
        self::assertSame(2000, (int) $this->row($same)->runtime_ms, 'the reservation of the same attempt gives the runtime');

        $reclaimed = $this->uuid();
        $this->observe('push', $reclaimed, ['jobId' => 20]);
        $this->observe('reserve', $reclaimed, ['jobId' => 20, 'attempt' => 1, 'at' => self::T]);
        $outcome = $this->observe('fail', $reclaimed, ['jobId' => 20, 'attempt' => 2, 'at' => self::T + 600]);
        self::assertSame('fail', $outcome->rule, 'the failure of attempt 2 after a reclaim that was not observed');
        self::assertSame(2, (int) $this->row($reclaimed)->attempts);
        self::assertNotNull($this->row($reclaimed)->occurrence_id, 'the failure got no occurrence');

        $completed = $this->uuid();
        $this->observe('push', $completed, ['jobId' => 30]);
        $this->observe('reserve', $completed, ['jobId' => 30, 'attempt' => 1, 'at' => self::T]);
        self::assertSame('complete', $this->observe('complete', $completed, ['jobId' => 30, 'attempt' => 2, 'at' => self::T + 600])->rule);
        self::assertNull($this->row($completed)->runtime_ms, 'the reservation of attempt 1 timed attempt 2');

        $released = $this->uuid();
        $this->observe('push', $released, ['jobId' => 40]);
        $this->observe('reserve', $released, ['jobId' => 40, 'attempt' => 1, 'at' => self::T]);
        $this->observe('release', $released, ['jobId' => 40, 'attempt' => 1, 'newJobId' => 41, 'at' => self::T + 1]);
        self::assertSame('complete', $this->observe('complete', $released, ['jobId' => 41, 'attempt' => 2, 'at' => self::T + 600])->rule);
        self::assertNull($this->row($released)->runtime_ms, 'the reservation of the released attempt timed the next one');
    }

    /**
     * Rejects: a transaction around an untagged insert.
     */
    public function test_untagged_push_is_one_autocommit_statement(): void
    {
        $uuid = $this->uuid();

        $outcome = $this->record(fn () => $this->observe('push', $uuid, ['jobId' => 10]));

        self::assertSame('push', $outcome->rule);
        self::assertTrue($outcome->applied);
        self::assertCount(1, $this->statements, 'one statement: '.json_encode($this->statements));
        self::assertStringStartsWith('insert', strtolower($this->statements[0]['sql']));
        self::assertFalse($this->statements[0]['in_transaction']);
        self::assertSame(0, $this->transactionsBegun());

        $row = $this->row($uuid);
        self::assertSame('pending', $row->status);
        self::assertFalse($row->monitored);
        self::assertNull($row->monitor_version);
    }

    /**
     * Rejects: one insert statement per tag.
     */
    public function test_tagged_push_writes_the_job_and_one_multi_row_tag_insert_in_one_transaction(): void
    {
        $uuid = $this->uuid();

        $this->record(fn () => $this->observe('push', $uuid, ['jobId' => 10], ['tags' => ['a', 'b', 'c']]));

        $inserts = array_values(array_filter($this->statements, static fn (array $s): bool => str_starts_with(strtolower($s['sql']), 'insert')));
        $tagInserts = array_values(array_filter($inserts, static fn (array $s): bool => str_contains($s['sql'], 'orrery_job_tags')));

        self::assertCount(2, $inserts, 'the job insert and one tag insert: '.json_encode($inserts));
        self::assertCount(1, $tagInserts);
        self::assertTrue($inserts[0]['in_transaction'] && $inserts[1]['in_transaction'], 'both inserts run inside the package transaction');
        self::assertSame(1, $this->transactionsBegun());

        $row = $this->row($uuid);
        $tags = $this->mirror()->table('orrery_job_tags')->where('job_uuid', $uuid)->orderBy('tag')->get();
        self::assertSame(['a', 'b', 'c'], $tags->pluck('tag')->all());

        foreach ($tags as $tag) {
            self::assertSame(Clock::toDecimal((float) $row->pushed_at), Clock::toDecimal((float) $tag->pushed_at));
        }
    }

    /**
     * Rejects: a live read of the monitored tags per push.
     */
    public function test_monitored_flag_and_version_come_from_the_cache(): void
    {
        $this->monitor(['t1'], 4);

        $first = $this->uuid();
        $this->observe('push', $first, ['jobId' => 1], ['tags' => ['t1']]);
        self::assertTrue($this->row($first)->monitored);
        self::assertSame(4, $this->row($first)->monitor_version);

        $this->monitor(['t1', 't2'], 5);

        $second = $this->uuid();
        $this->record(fn () => $this->observe('push', $second, ['jobId' => 2], ['tags' => ['t2']]));
        self::assertSame([], array_filter($this->statements, static fn (array $s): bool => str_contains($s['sql'], 'orrery_monitored_tags') || str_contains($s['sql'], 'orrery_settings')), 'the push reads no monitoring state inside the cache window');
        self::assertFalse($this->row($second)->monitored, 'the cached set does not know t2 yet');
        self::assertSame(4, $this->row($second)->monitor_version, 'the row keeps the version that its snapshot read');

        MonitoredTagCache::flush();

        $third = $this->uuid();
        $this->observe('push', $third, ['jobId' => 3], ['tags' => ['t2']]);
        self::assertTrue($this->row($third)->monitored);
        self::assertSame(5, $this->row($third)->monitor_version);
    }

    /**
     * Rejects: an upsert for P1.
     */
    public function test_late_initial_push_after_a_failure_changes_nothing(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);
        $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => new RuntimeException('boom')]);

        $before = (array) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();

        $outcome = $this->observe('push', $uuid, ['jobId' => 30, 'at' => self::T + 60], ['orreryGen' => self::G2]);

        self::assertSame('rejected', $outcome->rule);
        self::assertFalse($outcome->applied);
        self::assertSame($before, (array) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first());
        self::assertSame('failed', $before['status']);
    }

    /**
     * Rejects: mb_strlen() in place of strlen().
     */
    public function test_byte_lengths_match_the_stored_values(): void
    {
        $uuid = $this->uuid();
        $exception = new ContextException('Žinutė nepavyko ✓ – ąčęėįšųūž');

        $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => $exception], ['displayName' => 'Siųsti laišką ✓']);

        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();

        self::assertNotSame(strlen($row->payload), mb_strlen($row->payload), 'the payload is multibyte');
        self::assertSame(strlen($row->payload), (int) $row->payload_bytes);
        self::assertNotSame(strlen($row->exception), mb_strlen($row->exception));
        self::assertSame(strlen($row->exception), (int) $row->exception_bytes);
        self::assertNotSame(strlen($row->context), mb_strlen($row->context));
        self::assertSame(strlen($row->context), (int) $row->context_bytes);
        self::assertSame('Siųsti laišką ✓', $row->name);

        $history = $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('event', 'failed')->first();
        self::assertSame(strlen($history->exception), (int) $history->exception_bytes);
        self::assertSame(strlen($history->context), (int) $history->context_bytes);
    }

    /**
     * Rejects: defaults in place of the payload values.
     */
    public function test_limit_columns_follow_the_payload(): void
    {
        $limited = $this->uuid();
        $this->observe('push', $limited, ['jobId' => 1], [
            'maxTries' => 5, 'retryUntil' => 1790003600, 'maxExceptions' => 2, 'timeout' => 90, 'failOnTimeout' => true,
            'type' => 'mail', 'silenced' => true,
        ]);

        $row = $this->row($limited);
        self::assertSame(5, $row->max_tries);
        self::assertSame(1790003600, $row->retry_until);
        self::assertSame(2, $row->max_exceptions);
        self::assertSame(90, $row->timeout);
        self::assertTrue($row->fail_on_timeout);
        self::assertSame('mail', $row->type);
        self::assertTrue($row->silenced);

        $plain = $this->uuid();
        $this->observe('push', $plain, ['jobId' => 2], [
            'maxTries' => null, 'retryUntil' => null, 'maxExceptions' => null, 'timeout' => null, 'failOnTimeout' => null, 'type' => null, 'displayName' => null,
        ]);

        $row = $this->row($plain);
        self::assertNull($row->max_tries);
        self::assertNull($row->retry_until);
        self::assertNull($row->max_exceptions);
        self::assertNull($row->timeout);
        self::assertFalse($row->fail_on_timeout);
        self::assertSame('job', $row->type);
        self::assertSame('Illuminate\\Queue\\CallQueuedHandler', $row->name, 'the name falls back to the job key before @');
    }

    /**
     * Rejects: a batch id of any form inlined into the fast statement, so a
     * job value reaches the SQL text of a watcher report (only a plain id of
     * letters, digits and dashes is inlined; any other is filled through a
     * binding by a later read-decide-write).
     */
    public function test_the_fast_statements_inline_only_a_plain_batch_id(): void
    {
        $odd = $this->uuid();
        $this->observe('push', $odd, ['jobId' => 2]);
        $this->record(fn () => $this->observe('reserve', $odd, ['jobId' => 2, 'attempt' => 1], ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => "batch' or leak"]]));

        self::assertNotSame([], $this->statements);

        foreach ($this->statements as $statement) {
            self::assertStringNotContainsString('leak', $statement['sql'], 'the batch id is in the SQL text');
        }

        self::assertSame('reserved', $this->row($odd)->status);
        $this->observe('complete', $odd, ['jobId' => 2, 'attempt' => 1], ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => "batch' or leak"]]);
        self::assertSame("batch' or leak", $this->row($odd)->batch_id, 'a later read-decide-write fills it through a binding');
    }

    /**
     * Rejects: a batch id written only by P1.
     */
    public function test_batch_id_is_written_and_a_null_is_filled_later(): void
    {
        $batched = $this->uuid();
        $this->observe('push', $batched, ['jobId' => 1], ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => 'batch-1']]);
        self::assertSame('batch-1', $this->row($batched)->batch_id);

        $late = $this->uuid();
        $this->observe('push', $late, ['jobId' => 2]);
        self::assertNull($this->row($late)->batch_id);

        $this->observe('reserve', $late, ['jobId' => 2, 'attempt' => 1], ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => 'batch-2']]);
        self::assertSame('batch-2', $this->row($late)->batch_id, 'a later observation fills a null batch id');

        $this->observe('release', $late, ['jobId' => 2, 'attempt' => 1, 'newJobId' => 3], ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => 'batch-3']]);
        self::assertSame('batch-2', $this->row($late)->batch_id, 'a stored batch id is never replaced');

        // The read-decide-write path fills it too (a completion always reads the row).
        $slow = $this->uuid();
        $this->observe('push', $slow, ['jobId' => 4]);
        $this->observe('reserve', $slow, ['jobId' => 4, 'attempt' => 1]);
        self::assertNull($this->row($slow)->batch_id);
        $this->observe('complete', $slow, ['jobId' => 4, 'attempt' => 1], ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => 'batch-4']]);
        self::assertSame('completed', $this->row($slow)->status);
        self::assertSame('batch-4', $this->row($slow)->batch_id, 'a completion fills a null batch id');

        $fallback = $this->uuid();
        $this->observe('complete', $fallback, ['jobId' => 9, 'attempt' => 1], ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => 'batch-9']]);
        self::assertSame('batch-9', $this->row($fallback)->batch_id, 'a fallback-created row writes the batch id');
    }

    /**
     * Rejects: an occurrence from a separate counter, or a reused id.
     */
    public function test_failure_assigns_the_history_row_id_as_occurrence_and_writes_failed_tags(): void
    {
        $uuid = $this->uuid();
        $tags = ['tags' => ['x', 'y']];
        $this->observe('push', $uuid, ['jobId' => 10], $tags);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1], $tags);

        $outcome = $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => new RuntimeException('first')], $tags);

        $history = $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('event', 'failed')->get();
        self::assertCount(1, $history);
        $first = (int) $history[0]->id;
        self::assertSame('fail', $outcome->rule);
        self::assertSame($first, $outcome->occurrenceId);
        self::assertSame($first, $this->row($uuid)->occurrence_id);
        self::assertSame(1, (int) $history[0]->attempt);
        self::assertSame(RuntimeException::class, $history[0]->exception_class);

        $failedTags = $this->mirror()->table('orrery_failed_tags')->where('job_uuid', $uuid)->orderBy('tag')->get();
        self::assertSame(['x', 'y'], $failedTags->pluck('tag')->all());
        self::assertSame(Clock::toDecimal(self::T), Clock::toDecimal((float) $failedTags[0]->finished_at));

        // A later cycle of the same job (a native retry starts cycles; this test puts the row back in reserved).
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'reserved', 'job_id' => 20, 'attempts' => 1, 'cycle' => 2]);

        $second = $this->observe('fail', $uuid, ['jobId' => 20, 'attempt' => 1, 'at' => self::T + 100, 'exception' => new RuntimeException('second')], $tags);

        self::assertGreaterThan($first, $second->occurrenceId);
        self::assertSame($second->occurrenceId, $this->row($uuid)->occurrence_id);
        self::assertSame(2, $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('event', 'failed')->count());

        $refreshed = $this->mirror()->table('orrery_failed_tags')->where('job_uuid', $uuid)->pluck('finished_at')->all();
        self::assertCount(2, $refreshed);
        foreach ($refreshed as $finishedAt) {
            self::assertSame(Clock::toDecimal(self::T + 100), Clock::toDecimal((float) $finishedAt), 'a new failure refreshes finished_at');
        }
    }

    /**
     * Rejects: a history row inserted before the guarded update.
     */
    public function test_repeated_exception_changes_no_counter_even_after_its_history_row_was_pruned(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);
        $this->observe('exception', $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => new RuntimeException('once')]);

        self::assertSame(1, $this->row($uuid)->exceptions);
        self::assertSame(1, $this->history($uuid, 'exception'));

        $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->delete();

        $outcome = $this->observe('exception', $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => new RuntimeException('once')]);

        self::assertFalse($outcome->applied);
        self::assertSame(1, $this->row($uuid)->exceptions);
        self::assertSame(1, $this->row($uuid)->last_exception_attempt);
        self::assertSame(0, $this->history($uuid, 'exception'), 'no history row comes back');

        $this->observe('timeout', $uuid, ['jobId' => 10, 'attempt' => 1]);
        $this->observe('timeout', $uuid, ['jobId' => 10, 'attempt' => 1]);
        self::assertSame(1, $this->row($uuid)->timeouts);
        self::assertSame(1, $this->history($uuid, 'timeout'));
    }

    /**
     * Rejects: a dedupe key without the job uuid.
     */
    public function test_two_jobs_with_the_same_cycle_and_attempt_both_get_history(): void
    {
        $uuids = [$this->uuid(), $this->uuid()];

        foreach ($uuids as $index => $uuid) {
            $this->observe('push', $uuid, ['jobId' => 10 + $index]);
            $this->observe('reserve', $uuid, ['jobId' => 10 + $index, 'attempt' => 1]);
            $this->observe('exception', $uuid, ['jobId' => 10 + $index, 'attempt' => 1, 'exception' => new RuntimeException('same')]);
        }

        foreach ($uuids as $uuid) {
            self::assertSame(1, $this->row($uuid)->exceptions);
            self::assertSame(1, $this->history($uuid, 'exception'));
            self::assertSame('exception:1:1', $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->value('dedupe_key'));
        }
    }

    /**
     * Rejects: a history row for every completion.
     */
    public function test_completed_history_row_only_after_the_first_cycle(): void
    {
        $first = $this->uuid();
        $this->observe('push', $first, ['jobId' => 10]);
        $this->observe('reserve', $first, ['jobId' => 10, 'attempt' => 1]);
        $this->observe('complete', $first, ['jobId' => 10, 'attempt' => 1]);
        self::assertSame('completed', $this->row($first)->status);
        self::assertSame(0, $this->history($first, 'completed'));

        $retried = $this->uuid();
        // A package retry's payload: orreryRetry.operation is the observation's own generation.
        $retry = ['orreryRetry' => ['cycle' => 2, 'operation' => self::G1]];
        $this->observe('reserve', $retried, ['jobId' => 20, 'attempt' => 1], $retry);
        $this->observe('complete', $retried, ['jobId' => 20, 'attempt' => 1], $retry);
        self::assertSame('completed', $this->row($retried)->status);
        self::assertSame(1, $this->history($retried, 'completed'));
        self::assertSame(2, (int) $this->mirror()->table('orrery_job_history')->where('job_uuid', $retried)->value('cycle'));
    }

    /**
     * Rejects: retried_count 0 for a row first seen in cycle 3, and a cycle
     * from an orreryRetry whose operation is not the observation's own
     * generation (a stale copy after a republish).
     */
    public function test_r7_of_a_package_retry_infers_the_retry_count(): void
    {
        $uuid = $this->uuid();

        $outcome = $this->observe('reserve', $uuid, ['jobId' => 5, 'attempt' => 1], ['orreryRetry' => ['cycle' => 3, 'operation' => self::G1]]);

        self::assertSame('reserveWithoutRow', $outcome->rule);
        $row = $this->row($uuid);
        self::assertSame('reserved', $row->status);
        self::assertSame(3, $row->cycle);
        self::assertSame(2, $row->retried_count);
        self::assertTrue($row->retried_count_inferred);
        self::assertSame(self::G1, $row->cycle_gen);
        self::assertSame(5, $row->cycle_first_job_id);

        $stale = $this->uuid();
        $this->observe('reserve', $stale, ['jobId' => 6, 'attempt' => 1], ['orreryRetry' => ['cycle' => 3, 'operation' => self::G2]]);
        self::assertSame([1, 0], [$this->row($stale)->cycle, $this->row($stale)->retried_count], 'a stale orreryRetry gives no cycle');
    }

    /**
     * Rejects: tag storage and monitoring only for P1 and P2.
     */
    public function test_fallback_created_rows_store_tags_and_monitoring(): void
    {
        $this->monitor(['t1'], 7);
        $tags = ['tags' => ['t1', 't2'], 'tagsTruncated' => true];

        $cases = [
            'reserve' => ['jobId' => 11, 'attempt' => 1],
            'complete' => ['jobId' => 12, 'attempt' => 1],
            'fail' => ['jobId' => 13, 'attempt' => 1, 'exception' => new RuntimeException('first seen at failure')],
        ];

        foreach ($cases as $kind => $fields) {
            $uuid = $this->uuid();
            $this->statements = [];

            $this->record(fn () => $this->observe($kind, $uuid, $fields, $tags));

            $row = $this->row($uuid);
            self::assertTrue($row->tags_truncated, "{$kind}: tags_truncated");
            self::assertTrue($row->monitored, "{$kind}: monitored");
            self::assertSame(7, $row->monitor_version, "{$kind}: monitor_version");

            $stored = $this->mirror()->table('orrery_job_tags')->where('job_uuid', $uuid)->orderBy('tag')->get();
            self::assertSame(['t1', 't2'], $stored->pluck('tag')->all(), "{$kind}: tag rows");
            foreach ($stored as $tag) {
                self::assertSame(Clock::toDecimal((float) $row->pushed_at), Clock::toDecimal((float) $tag->pushed_at), "{$kind}: the tag rows carry the row's pushed_at");
            }

            $inserts = array_values(array_filter($this->statements, static fn (array $s): bool => str_starts_with(strtolower($s['sql']), 'insert') && (str_contains($s['sql'], 'orrery_jobs') || str_contains($s['sql'], 'orrery_job_tags'))));
            self::assertCount(2, $inserts, "{$kind}: the job insert and one tag insert");
            self::assertTrue($inserts[0]['in_transaction'] && $inserts[1]['in_transaction'], "{$kind}: both inserts run in one package transaction");
        }

        self::assertSame(2, $this->mirror()->table('orrery_failed_tags')->count(), 'the failure also writes its failed tags');
    }

    /**
     * Rejects: evidence kept after a failure outside a worker.
     */
    public function test_worker_evidence_is_written_for_its_attempt_and_cleared_outside_a_worker(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1] + $this->verified(self::W1, 3, 60));

        $row = $this->row($uuid);
        self::assertSame(self::W1, $row->reserved_by);
        self::assertSame(3, $row->worker_tries);
        self::assertSame(60, $row->worker_timeout);
        self::assertSame(1, $row->worker_evidence_attempt);

        $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 1, 'inWorker' => true, 'worker' => new WorkerEvidence(5, 30, 'database', 'default', true)]);

        $row = $this->row($uuid);
        self::assertSame(5, $row->worker_tries, 'a failure in a worker writes that worker\'s evidence');
        self::assertSame(30, $row->worker_timeout);
        self::assertSame(1, $row->worker_evidence_attempt);

        $other = $this->uuid();
        $this->observe('push', $other, ['jobId' => 20]);
        $this->observe('reserve', $other, ['jobId' => 20, 'attempt' => 1] + $this->verified(self::W1, 3, 60));
        $this->observe('fail', $other, ['jobId' => 20, 'attempt' => 1, 'inWorker' => false]);

        $row = $this->row($other);
        self::assertSame('failed', $row->status);
        self::assertNull($row->worker_tries);
        self::assertNull($row->worker_timeout);
        self::assertNull($row->worker_evidence_attempt);
    }

    /**
     * Rejects: U1 by uuid only.
     */
    public function test_mark_unknown_needs_the_same_job_id_and_cycle(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $store = $this->store();

        self::assertFalse($store->markUnknown($uuid, 11, 1, 'absent', self::T + 700));
        self::assertFalse($store->markUnknown($uuid, 10, 2, 'absent', self::T + 700));
        self::assertSame('pending', $this->row($uuid)->status);

        self::assertTrue($store->markUnknown($uuid, 10, 1, 'absent', self::T + 700));
        $row = $this->row($uuid);
        self::assertSame('unknown', $row->status);
        self::assertSame('absent', $row->unknown_reason);
        self::assertSame(Clock::toDecimal(self::T + 700), Clock::toDecimal((float) $row->unknown_since));
        self::assertSame(Clock::toDecimal(self::T + 700), Clock::toDecimal((float) $row->finished_at));

        self::assertTrue($store->markUnknown($uuid, 10, 1, 'absent', self::T + 700), 'a repeat of the same mark reads back its own result');
        self::assertFalse($store->markUnknown($uuid, 10, 1, 'absent', self::T + 800), 'another mark of an unknown row changes nothing');

        $bulk = $this->uuid();
        $this->observe('bulk_push', $bulk, ['jobId' => null, 'bulkFloor' => 5]);
        self::assertTrue($this->row($bulk)->job_id_unresolved);
        $this->mirror()->table('orrery_jobs')->where('uuid', $bulk)->update(['job_id' => 12]);
        self::assertFalse($store->markUnknown($bulk, 12, 1, 'absent', self::T + 700), 'an unresolved bulk row is never marked unknown');
    }

    /**
     * Rejects: an unbounded retry loop, or a drop without a count.
     */
    public function test_guard_miss_retries_then_counts_a_drop(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);

        $reads = 0;
        Hooks::$callback = function (string $point) use (&$reads, $uuid): void {
            if ($point === JobStore::AFTER_READ) {
                $reads++;
                $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['reserved_by' => 'changed-'.$reads]);
            }
        };

        $outcome = $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 2]);

        self::assertSame(3, $reads, 'three read-decide-write attempts');
        self::assertFalse($outcome->applied);
        self::assertSame(1, $this->row($uuid)->attempts);
        self::assertSame(1, Degradation::record()['dropped'] ?? null, 'the drop is counted');

        $reads = 0;
        Hooks::$callback = function (string $point) use (&$reads, $uuid): void {
            if ($point === JobStore::AFTER_READ && ++$reads === 1) {
                $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['reserved_by' => 'changed-once']);
            }
        };

        $outcome = $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 2]);

        self::assertSame(2, $reads, 'the second attempt reads the changed row and succeeds');
        self::assertSame('reclaim', $outcome->rule);
        self::assertTrue($outcome->applied);
        self::assertSame(2, $this->row($uuid)->attempts);
        self::assertSame(1, $this->row($uuid)->reclaims);
        self::assertSame(1, Degradation::record()['dropped'] ?? null, 'no second drop');
    }

    /**
     * Rejects: a second write of the same reservation, or a result that needs a read of the row before the fast statement.
     */
    public function test_repeated_autocommit_transition_changes_nothing_twice(): void
    {
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);

        $reads = 0;
        Hooks::$callback = static function (string $point) use (&$reads): void {
            if ($point === JobStore::AFTER_READ) {
                $reads++;
            }
        };

        $first = $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);
        self::assertSame('reserve', $first->rule);
        self::assertTrue($first->applied);
        self::assertSame(0, $reads, 'the fast statement applied R1 without a read');

        // The same statement again, as Laravel repeats it after a lost acknowledgement.
        $second = $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);

        self::assertSame(1, $reads, 'the zero-row count only triggered the re-read');
        self::assertSame('reserve', $second->rule, 'the re-read row already holds this reservation');
        self::assertTrue($second->applied, 'a repeat reports the row state, as a lost acknowledgement does');

        $row = $this->row($uuid);
        self::assertSame('reserved', $row->status);
        self::assertSame(1, $row->attempts);
        self::assertSame(0, $row->reclaims);
    }

    /**
     * Rejects: a release available_at without the delay.
     */
    public function test_release_available_at_is_at_plus_delay(): void
    {
        $plain = $this->uuid();
        $this->observe('push', $plain, ['jobId' => 20]);
        $this->observe('reserve', $plain, ['jobId' => 20, 'attempt' => 1]);
        $this->observe('release', $plain, ['jobId' => 20, 'attempt' => 1, 'newJobId' => 21, 'delay' => 10]);
        self::assertSame(Clock::toDecimal(self::T + 10), Clock::toDecimal((float) $this->row($plain)->available_at), 'a verified release uses at + delay');
    }

    /**
     * Rejects: a worker kept across reservations.
     */
    public function test_worker_enrichment_by_r8(): void
    {
        // A workerless reservation, then the verified observation of the same reservation.
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 10]);
        $reserve = $this->observation('reserve', $uuid, ['jobId' => 10, 'attempt' => 1, 'at' => self::T + 2] + $this->verified(self::W1, 3, 60));
        $this->store()->observe(self::workerless($reserve, 1, self::T + 1));
        $before = $this->counters($uuid);
        self::assertNull($this->row($uuid)->reserved_by);

        $outcome = $this->store()->observe($reserve);

        self::assertSame('fillWorker', $outcome->rule);
        $row = $this->row($uuid);
        self::assertSame(self::W1, $row->reserved_by);
        self::assertSame(3, $row->worker_tries);
        self::assertSame(60, $row->worker_timeout);
        self::assertSame(1, $row->worker_evidence_attempt);
        self::assertSame(Clock::toDecimal(self::T + 1), Clock::toDecimal((float) $row->reserved_at), 'R8 keeps reserved_at');
        self::assertSame($before, $this->counters($uuid), 'R8 changes no counter and no activity_at');

        // A duplicate verified observation changes nothing. The row holds its
        // result, so it reports applied (the result is about the row's state).
        $snapshot = (array) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();
        self::assertTrue($this->store()->observe($reserve)->applied);
        self::assertSame($snapshot, (array) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first());

        // The reverse order: verified first, then the workerless one.
        $reverse = $this->uuid();
        $this->observe('push', $reverse, ['jobId' => 20]);
        $verified = $this->observation('reserve', $reverse, ['jobId' => 20, 'attempt' => 1] + $this->verified(self::W1, 3, 60));
        $this->store()->observe($verified);
        $snapshot = (array) $this->mirror()->table('orrery_jobs')->where('uuid', $reverse)->first();
        self::assertFalse($this->store()->observe(self::workerless($verified, 1, self::T + 9))->applied);
        self::assertSame($snapshot, (array) $this->mirror()->table('orrery_jobs')->where('uuid', $reverse)->first(), 'the workerless duplicate keeps the verified worker');

        // A workerless reservation at a higher attempt removes the old worker.
        $reclaim = $this->observation('reserve', $reverse, ['jobId' => 20, 'attempt' => 2, 'at' => self::T + 30] + $this->verified(self::W2, 7, 90));
        $this->store()->observe(self::workerless($reclaim, 2, self::T + 29));
        $row = $this->row($reverse);
        self::assertSame(2, $row->attempts);
        self::assertSame(1, $row->reclaims);
        self::assertNull($row->reserved_by);
        self::assertNull($row->worker_tries);
        self::assertNull($row->worker_evidence_attempt);

        $counters = $this->counters($reverse);
        self::assertSame('fillWorker', $this->store()->observe($reclaim)->rule, 'the next verified observation fills the worker once');
        self::assertSame(self::W2, $this->row($reverse)->reserved_by);
        self::assertSame(7, $this->row($reverse)->worker_tries);
        self::assertSame(2, $this->row($reverse)->worker_evidence_attempt);
        self::assertSame($counters, $this->counters($reverse));
        $snapshot = (array) $this->mirror()->table('orrery_jobs')->where('uuid', $reverse)->first();
        self::assertTrue($this->store()->observe($reclaim)->applied, 'the duplicate finds its own result');
        self::assertSame($snapshot, (array) $this->mirror()->table('orrery_jobs')->where('uuid', $reverse)->first(), 'and changes nothing');

        // A workerless reservation at a replacement id removes the worker too.
        $replacement = $this->observation('reserve', $reverse, ['jobId' => 21, 'attempt' => 3, 'at' => self::T + 60] + $this->verified(self::W1, 3, 60));
        $this->store()->observe(self::workerless($replacement, 3, self::T + 59));
        $row = $this->row($reverse);
        self::assertSame(21, $row->job_id);
        self::assertSame(20, $row->prev_job_id);
        self::assertSame(1, $row->reclaims, 'R3 is no reclaim');
        self::assertNull($row->reserved_by);
        self::assertNull($row->worker_evidence_attempt);

        self::assertSame('fillWorker', $this->store()->observe($replacement)->rule);
        self::assertSame(self::W1, $this->row($reverse)->reserved_by);
        self::assertSame(3, $this->row($reverse)->worker_evidence_attempt);

        // R8 fills a missing generation and a null batch id, and keeps a set one.
        $bare = $this->uuid();
        $this->observe('push', $bare, ['jobId' => 30], ['orreryGen' => null]);
        $bareReserve = $this->observation('reserve', $bare, ['jobId' => 30, 'attempt' => 1] + $this->verified(self::W1, 3, 60), ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => 'batch-r8']]);
        $this->store()->observe(self::workerless($bareReserve, 1, self::T + 1));
        $this->mirror()->table('orrery_jobs')->where('uuid', $bare)->update(['cycle_gen' => null, 'batch_id' => null]);
        $before = $this->counters($bare);

        self::assertSame('fillWorker', $this->store()->observe($bareReserve)->rule);
        $row = $this->row($bare);
        self::assertSame(self::W1, $row->reserved_by);
        self::assertSame(self::G1, $row->cycle_gen, 'R8 fills a missing generation');
        self::assertSame('batch-r8', $row->batch_id, 'R8 fills a null batch id');
        self::assertSame($before, $this->counters($bare), 'R8 still changes no counter, reserved_at or activity_at');

        $kept = $this->uuid();
        $this->observe('push', $kept, ['jobId' => 40], ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => 'batch-first']]);
        $keptReserve = $this->observation('reserve', $kept, ['jobId' => 40, 'attempt' => 1] + $this->verified(self::W2, 3, 60), ['data' => ['commandName' => self::JOB_CLASS, 'batchId' => 'batch-other']]);
        $this->store()->observe(self::workerless($keptReserve, 1, self::T + 1));
        self::assertSame('fillWorker', $this->store()->observe($keptReserve)->rule);
        self::assertSame('batch-first', $this->row($kept)->batch_id, 'R8 keeps a batch id that is set');
    }

    /**
     * Rejects: a retry request that changes the status or activity_at, as a transition would.
     */
    public function test_retry_requested_sets_only_retry_requested_at(): void
    {
        $uuid = $this->uuid();
        $this->observe('fail', $uuid, ['jobId' => 10, 'attempt' => 1]);
        $before = (array) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();

        $this->store()->retryRequested($uuid, self::T + 50);

        $after = (array) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();
        self::assertSame(Clock::toDecimal(self::T + 50), Clock::toDecimal((float) $after['retry_requested_at']));
        self::assertSame(
            array_diff_key($before, ['retry_requested_at' => 1, 'updated_at' => 1]),
            array_diff_key($after, ['retry_requested_at' => 1, 'updated_at' => 1]),
            'no other column changes',
        );
    }

    /**
     * Rejects: a result taken from the count of an autocommit statement whose acknowledgement was lost and whose repeat changed no row.
     */
    public function test_a_lost_acknowledgement_after_the_write_still_reports_the_applied_transition(): void
    {
        $this->skipOnInMemory();

        PackageSession::usePdoFactoryForTesting(function (string $name, array $config): ?PDO {
            if ($name !== self::mirrorSession()) {
                return null;
            }

            $pdo = $this->rawPdo($config);
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LostAcknowledgementStatement::class, []]);

            return $pdo;
        });
        PackageSession::releaseAll();

        try {
            // P1: an untagged insertOrIgnore.
            $uuid = $this->uuid();
            LostAcknowledgementStatement::arm('/^insert .*orrery_jobs/i');
            $outcome = $this->observe('push', $uuid, ['jobId' => 10]);
            $this->assertLostOnce('push');
            self::assertSame('push', $outcome->rule);
            self::assertTrue($outcome->applied, 'P1: the row holds this push after the repeat');
            self::assertSame('pending', $this->row($uuid)->status);

            // R1 (fast statement).
            LostAcknowledgementStatement::arm('/^update .*orrery_jobs/i');
            $outcome = $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1] + $this->verified(self::W1, 3, 60));
            $this->assertLostOnce('reserve');
            self::assertSame('reserve', $outcome->rule);
            self::assertTrue($outcome->applied, 'R1: the row holds this reservation after the repeat');
            self::assertSame(1, $this->row($uuid)->attempts);
            self::assertSame(0, $this->row($uuid)->reclaims);
            self::assertSame(self::W1, $this->row($uuid)->reserved_by);

            // L1 (fast statement).
            LostAcknowledgementStatement::arm('/^update .*orrery_jobs/i');
            $outcome = $this->observe('release', $uuid, ['jobId' => 10, 'attempt' => 1, 'newJobId' => 11, 'delay' => 5]);
            $this->assertLostOnce('release');
            self::assertSame('release', $outcome->rule);
            self::assertTrue($outcome->applied, 'L1: the row holds this release after the repeat');
            self::assertSame(1, $this->row($uuid)->releases, 'one release, not two');
            self::assertSame(11, $this->row($uuid)->job_id);

            // U1.
            LostAcknowledgementStatement::arm('/^update .*orrery_jobs/i');
            $marked = $this->store()->markUnknown($uuid, 11, 1, 'absent', self::T + 700);
            $this->assertLostOnce('markUnknown');
            self::assertTrue($marked, 'U1: the row is unknown by this mark after the repeat');
            self::assertSame('unknown', $this->row($uuid)->status);

            // The fallback path (swap off, or no generation): an ordinary
            // duplicate reports the same row state as a lost acknowledgement.
            $cases = [
                'swap off' => [['instrumented' => false], []],
                'no generation' => [[], ['orreryGen' => null]],
            ];

            foreach ($cases as $label => [$fields, $payload]) {
                $job = $this->uuid();
                $this->observe('push', $job, ['jobId' => 20] + $fields, $payload);

                LostAcknowledgementStatement::arm('/^update .*orrery_jobs/i');
                $replay = $this->observe('reserve', $job, ['jobId' => 20, 'attempt' => 1] + $fields, $payload);
                $this->assertLostOnce("{$label} reservation");
                $duplicate = $this->observe('reserve', $job, ['jobId' => 20, 'attempt' => 1] + $fields, $payload);

                self::assertTrue($replay->applied, "{$label}: the replayed reservation reports the row state");
                self::assertTrue($duplicate->applied, "{$label}: the duplicate reservation reports the same row state");
                self::assertSame(1, $this->row($job)->attempts);
                self::assertSame(0, $this->row($job)->reclaims, "{$label}: one reservation");

                LostAcknowledgementStatement::arm('/^update .*orrery_jobs/i');
                $replay = $this->observe('release', $job, ['jobId' => 20, 'attempt' => 1, 'newJobId' => 21] + $fields, $payload);
                $this->assertLostOnce("{$label} release");
                $duplicate = $this->observe('release', $job, ['jobId' => 20, 'attempt' => 1, 'newJobId' => 21] + $fields, $payload);

                self::assertTrue($replay->applied, "{$label}: the replayed release reports the row state");
                self::assertTrue($duplicate->applied, "{$label}: the duplicate release reports the same row state");
                self::assertSame(1, $this->row($job)->releases, "{$label}: one release");
                self::assertSame(21, $this->row($job)->job_id);
            }

            self::assertNull(Degradation::record(), 'no observation was dropped');
        } finally {
            LostAcknowledgementStatement::reset();
        }
    }

    /**
     * Rejects: a duplicate on the read-decide-write path that reports rejected while a replay of the same write reports applied.
     */
    public function test_a_duplicate_on_the_fallback_path_reports_the_row_state(): void
    {
        foreach (['swap off' => [['instrumented' => false], []], 'no generation' => [[], ['orreryGen' => null]]] as $label => [$fields, $payload]) {
            $job = $this->uuid();
            $this->observe('push', $job, ['jobId' => 20] + $fields, $payload);
            self::assertTrue($this->observe('reserve', $job, ['jobId' => 20, 'attempt' => 1] + $fields, $payload)->applied);
            $before = (array) $this->mirror()->table('orrery_jobs')->where('uuid', $job)->first();

            self::assertTrue($this->observe('reserve', $job, ['jobId' => 20, 'attempt' => 1] + $fields, $payload)->applied, "{$label}: duplicate reservation");
            self::assertSame($before, (array) $this->mirror()->table('orrery_jobs')->where('uuid', $job)->first(), "{$label}: the duplicate changes nothing");

            self::assertTrue($this->observe('release', $job, ['jobId' => 20, 'attempt' => 1, 'newJobId' => 21] + $fields, $payload)->applied);
            self::assertTrue($this->observe('release', $job, ['jobId' => 20, 'attempt' => 1, 'newJobId' => 21] + $fields, $payload)->applied, "{$label}: duplicate release");
            self::assertSame(1, $this->row($job)->releases, "{$label}: one release");
        }

        self::assertNull(Degradation::record(), 'no observation was dropped');
    }

    private function assertLostOnce(string $rule): void
    {
        self::assertSame(1, LostAcknowledgementStatement::$lost, "{$rule}: the acknowledgement was lost once");
        self::assertSame(2, LostAcknowledgementStatement::$executions, "{$rule}: Laravel ran the statement again after the reconnect");
        LostAcknowledgementStatement::reset();
    }

    private function store(): JobStore
    {
        return $this->app->make(JobStore::class);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $payload
     */
    private function observe(string $kind, string $uuid, array $fields = [], array $payload = []): StoreOutcome
    {
        return $this->store()->observe($this->observation($kind, $uuid, $fields, $payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function verified(string $worker, int $tries, int $timeout): array
    {
        return ['workerId' => $worker, 'inWorker' => true, 'worker' => new WorkerEvidence($tries, $timeout, 'database', 'default', false)];
    }

    private function row(string $uuid): Job
    {
        $row = Job::query()->where('uuid', $uuid)->first();
        self::assertInstanceOf(Job::class, $row, "the mirror row of {$uuid} exists");

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function counters(string $uuid): array
    {
        return (array) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)
            ->select(['attempts', 'releases', 'exceptions', 'timeouts', 'reclaims', 'reserved_at', 'activity_at', 'status', 'job_id'])
            ->first();
    }

    private function history(string $uuid, string $event): int
    {
        return $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('event', $event)->count();
    }

    /**
     * @param  list<string>  $tags
     */
    private function monitor(array $tags, int $version): void
    {
        $this->mirror()->table('orrery_monitored_tags')->delete();
        $this->mirror()->table('orrery_monitored_tags')->insert(array_map(static fn (string $tag): array => ['tag' => $tag, 'created_at' => Clock::toDecimal(self::T)], $tags));
        $this->mirror()->table('orrery_settings')->updateOrInsert(['key' => 'monitoring_version'], ['value' => (string) $version]);
    }

    private function uuid(): string
    {
        return strtolower((string) Str::uuid());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function record(callable $work): mixed
    {
        $this->statements = [];
        $this->begins = 0;
        PackageTransaction::resetCounters();
        $this->recording = true;

        try {
            return $work();
        } finally {
            $this->recording = false;
        }
    }

    private function transactionsBegun(): int
    {
        return PackageTransaction::sqliteCounters()['begin'] + $this->begins;
    }
}

/**
 * An exception with a context() method, as Laravel's reporting reads it.
 */
final class ContextException extends RuntimeException
{
    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['vartotojas' => 'Jonas Žemaitis ✓'];
    }
}
