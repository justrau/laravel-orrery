<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\AutocommitBounds;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use LogicException;

/**
 * The retry protocol in one process: the
 * refusals, the claim, the publication and the finalisation, on every
 * engine. Kind: new behaviour.
 */
final class RetryProtocolTest extends RetryProtocolTestCase
{
    /**
     * Rejects: a retry that publishes without the fence row, keeps the failed record, or leaves the row and the
     * ledger unfinished; a payload without orreryGen = the operation id.
     */
    public function test_retry_publishes_once_and_finalises(): void
    {
        [$uuid, $occurrence] = $this->failedJob($this->job(['alpha']));
        self::assertSame(['alpha'], $this->failedTags($uuid));

        $outcome = $this->retry($uuid, $occurrence);

        self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
        $id = (string) $outcome->operationId;
        self::assertSame(1, $this->jobsOf($id), 'One jobs row carries the operation id as its generation.');
        self::assertSame(0, $this->failedRecords($uuid), 'Mode in_tx: the record went with the publication.');

        $fence = $this->fenceRow($id, Fence::PUBLISH);
        self::assertNotNull($fence);
        self::assertSame([Fence::PUBLISHED, $outcome->jobId], [$fence->state, (int) $fence->job_id]);

        $row = $this->rowOrFail($uuid);
        self::assertSame(['pending', 2, $outcome->jobId, $id, null], [$row->status, (int) $row->cycle, (int) $row->job_id, $row->cycle_gen, $row->operation_id]);
        self::assertSame(['failed', 'retried'], $this->historyEvents($uuid));
        self::assertSame([], $this->failedTags($uuid), 'The claim removed the failed tags.');

        $operation = $this->operation($id);
        self::assertSame([Operations::DONE, Operations::IN_TX, $outcome->jobId, 'cli', 'tester'], [$operation->state, $operation->mode, (int) $operation->result_job_id, $operation->source, $operation->actor]);
        self::assertSame(2, $outcome->cycle);

        $payload = json_decode((string) $row->payload, true);
        self::assertIsArray($payload);
        self::assertSame(['cycle' => 2, 'operation' => $id], $payload['orreryRetry']);
        self::assertSame($id, $payload['orreryGen']);

        // The worker that runs the retried job completes it in cycle 2.
        $this->work();
        $row = $this->rowOrFail($uuid);
        self::assertSame(['completed', 2], [$row->status, (int) $row->cycle]);
    }

    /**
     * Rejects: a claim without the status guard: a completed row is refused, and a row that completes between the
     * preparation and the claim is not claimed.
     */
    /**
     * Rejects: a publication queue without the connection name, which skips a queue forward scoped to that
     * connection (Laravel's queue:retry pushes through the named connection, so the forward applies there).
     */
    public function test_publication_applies_a_forward_scoped_to_the_connection(): void
    {
        if (! method_exists(\Illuminate\Queue\QueueManager::class, 'forward')) {
            self::markTestSkipped('This Laravel release has no queue forwards.');
        }

        [$uuid, $occurrence] = $this->failedJob($this->job());
        $this->app['queue']->forward('default', 'forwarded', 'database');

        $outcome = $this->retry($uuid, $occurrence);

        self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
        self::assertSame('forwarded', DB::table('jobs')->where('id', $outcome->jobId)->value('queue'));
    }

    public function test_claim_requires_status_failed(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'completed']);
        self::assertOutcome(RetryResult::NOT_ELIGIBLE, 'not_failed', $this->retry($uuid, $occurrence));

        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'failed']);
        $mirror = $this->mirror();
        Hooks::$callback = static function (string $point) use ($mirror, $uuid): void {
            if ($point === RetryService::BEFORE_CLAIM) {
                $mirror->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'completed']);
            }
        };

        self::assertOutcome(RetryResult::NOT_ELIGIBLE, 'not_failed', $this->retry($uuid, $occurrence));
        self::assertSame([], $this->operations(), 'No operation was claimed.');
        self::assertSame('completed', $this->rowOrFail($uuid)->status);
        self::assertSame(1, $this->failedRecords($uuid));
    }

    /**
     * Rejects: a retry claim without `operation_id IS NULL`: a forget that claims the occurrence between the
     * retry's preparation and its claim keeps it, and the retry gets operation_in_progress.
     */
    public function test_retry_and_forget_of_one_occurrence_serialise(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $forget = Operations::newId();
        $mirror = $this->mirror();
        $operations = $this->app->make(Operations::class);
        Hooks::$callback = static function (string $point) use ($mirror, $operations, $uuid, $occurrence, $forget): void {
            if ($point !== RetryService::BEFORE_CLAIM) {
                return;
            }

            // The forget's claim: the row's hold and its ledger row.
            $mirror->table('orrery_jobs')->where('uuid', $uuid)->update(['operation_id' => $forget]);
            $operations->insertClaimed($mirror, [
                'id' => $forget, 'action' => Operations::FORGET, 'mode' => Operations::IN_TX, 'job_uuid' => $uuid, 'occurrence_id' => $occurrence,
                'queue_connection' => 'database', 'queue' => 'default', 'queue_fingerprint' => str_repeat('0', 64),
                'deadline' => 9.0e9, 'next_check_at' => 9.0e9, 'source' => 'cli',
            ]);
        };

        self::assertOutcome(RetryResult::NOT_ELIGIBLE, 'operation_in_progress', $this->retry($uuid, $occurrence));
        Hooks::$callback = null;

        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', $forget], [$row->status, $row->operation_id]);
        self::assertCount(1, $this->operations(), 'Only the forget operation exists.');
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame(0, (int) DB::table('jobs')->count());

        // A later retry of the held occurrence is refused the same way.
        self::assertOutcome(RetryResult::NOT_ELIGIBLE, 'operation_in_progress', $this->retry($uuid, $occurrence));
    }

    /**
     * Rejects: a retry of another occurrence than the row's, and a retry of a row without a mirror row.
     */
    public function test_occurrence_must_match(): void
    {
        [$uuid, $occurrence] = $this->failedJob();

        self::assertOutcome(RetryResult::NOT_ELIGIBLE, 'occurrence_mismatch', $this->retry($uuid, $occurrence + 1));
        self::assertOutcome(RetryResult::NOT_ELIGIBLE, 'occurrence_mismatch', $this->retry('7a1c9d2e-3b4f-4a5c-8d6e-0f1a2b3c4d5e', 1));
        self::assertSame([], $this->operations());
    }

    /**
     * Rejects: a retry that falls back to a uuid lookup or to the mirror payload when the bound record is gone.
     */
    public function test_missing_record_is_not_eligible(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        self::assertOutcome(RetryResult::NOT_ELIGIBLE, 'record_missing', $this->retry($uuid, $occurrence));
        self::assertSame([], $this->operations());
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a retry without the fence table on the queue's database: it is refused before any claim.
     */
    public function test_missing_fence_table_refuses_before_a_claim(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $schema = $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder();
        $schema->rename(Fence::TABLE, 'orrery_fences_away');

        try {
            $this->app->make(Fence::class)->forgetAvailability();
            $outcome = $this->retry($uuid, $occurrence);
        } finally {
            $schema->rename('orrery_fences_away', Fence::TABLE);
            $this->app->make(Fence::class)->forgetAvailability();
        }

        self::assertOutcome(RetryResult::REFUSED, 'fence_missing', $outcome);
        self::assertStringContainsString('orrery:install --fence', (string) $outcome->message);
        self::assertSame([], $this->operations());
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
        self::assertSame(1, $this->failedRecords($uuid));
    }

    /**
     * Rejects: a retry of the null failer that reads a failed record (there is none) or refuses: mode none
     * publishes the mirror row's payload.
     */
    public function test_null_failer_retry_uses_the_mirror_payload(): void
    {
        $this->useNullFailer();
        [$uuid, $occurrence] = $this->failedJob(bound: false);
        self::assertSame(0, $this->failedRecords());

        $outcome = $this->retry($uuid, $occurrence);

        self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
        $operation = $this->operation((string) $outcome->operationId);
        self::assertSame([Operations::NONE, Operations::DONE, null, null], [$operation->mode, $operation->state, $operation->record_id, $operation->record_connection]);
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        self::assertIsArray($payload);
        self::assertSame($uuid, $payload['uuid']);
        self::assertSame($outcome->operationId, $payload['orreryGen']);
    }

    /**
     * Rejects: delete-then-publish in two transactions, a clock read or another package statement inside the
     * publication, and a publication that is not one transaction (test_in_tx_statement_order and
     * test_publication_transaction_is_short).
     */
    public function test_in_tx_statement_order(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $recorded = [];
        $recording = false;
        Hooks::$callback = static function (string $point) use (&$recording): void {
            if ($point === Publication::BEFORE_FENCE_INSERT) {
                $recording = true;
            }
        };
        $this->recordQueueSession(static function (string $kind, string $sql) use (&$recording, &$recorded): void {
            if ($recording) {
                $recorded[] = [$kind, $sql];
            }
        });

        self::assertOutcome(RetryResult::ACCEPTED, null, $this->retry($uuid, $occurrence));

        $commit = array_search('commit', array_column($recorded, 0), true);
        self::assertIsInt($commit, 'The publication commits.');
        $transaction = array_slice($recorded, 0, $commit + 1);
        $shapes = array_map(static fn (array $entry): string => $entry[0] === 'statement' ? self::shape($entry[1]) : $entry[0], $transaction);

        $p = $this->testEnvironment()->primaryPrefix();
        self::assertSame(['begin', "insert {$p}orrery_fences", "delete {$p}failed_jobs", "insert {$p}jobs", "update {$p}orrery_fences", 'commit'], $shapes);
        self::assertStringContainsString('where', strtolower($transaction[1][1]), 'The fence insert is conditional on the clock.');
        self::assertMatchesRegularExpression('/"id" = \?|`id` = \?/', $transaction[2][1]);
        self::assertMatchesRegularExpression('/uuid/', $transaction[2][1]);

        foreach ([3, 4] as $index) {
            self::assertStringNotContainsStringIgnoringCase('julianday', $transaction[$index][1]);
            self::assertStringNotContainsStringIgnoringCase('unix_timestamp', $transaction[$index][1]);
            self::assertStringNotContainsStringIgnoringCase('clock_timestamp', $transaction[$index][1]);
        }
    }

    /**
     * Rejects: a clock read, PHP work or another package statement inside the publication:
     * between BEGIN and COMMIT on Q's session exactly the conditional fence insert, the exact delete (mode
     * in_tx only), the jobs insert and the fence update.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_publication_transaction_is_short(string $mode): void
    {
        if ($mode === Operations::BEFORE) {
            $this->useSplitFailedConnection();
        } elseif ($mode === Operations::NONE) {
            $this->useNullFailer();
        }

        [$uuid, $occurrence] = $this->failedJob(bound: $mode !== Operations::NONE);
        $recorded = [];
        $recording = false;
        Hooks::$callback = static function (string $point) use (&$recording): void {
            if ($point === Publication::BEFORE_FENCE_INSERT) {
                $recording = true;
            }
        };
        $this->recordQueueSession(static function (string $kind, string $sql) use (&$recording, &$recorded): void {
            if ($recording) {
                $recorded[] = [$kind, $sql];
            }
        });

        $outcome = $this->retry($uuid, $occurrence);
        self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
        self::assertSame($mode, $this->operation((string) $outcome->operationId)->mode);

        $commit = array_search('commit', array_column($recorded, 0), true);
        self::assertIsInt($commit);
        $shapes = array_map(static fn (array $entry): string => $entry[0] === 'statement' ? self::shape($entry[1]) : $entry[0], array_slice($recorded, 0, $commit + 1));
        // The tables of Q's session carry its table prefix (the run's prefix in same mode, none in separate mode).
        $p = $this->testEnvironment()->primaryPrefix();
        $expected = $mode === Operations::IN_TX
            ? ['begin', "insert {$p}orrery_fences", "delete {$p}failed_jobs", "insert {$p}jobs", "update {$p}orrery_fences", 'commit']
            : ['begin', "insert {$p}orrery_fences", "insert {$p}jobs", "update {$p}orrery_fences", 'commit'];

        self::assertSame($expected, $shapes);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function modes(): array
    {
        return ['in_tx' => [Operations::IN_TX], 'before' => [Operations::BEFORE], 'none' => [Operations::NONE]];
    }

    /**
     * Rejects: a retry inside an open transaction of the mirror session.
     */
    public function test_refuses_inside_an_open_mirror_transaction(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $mirror = $this->app->make(PackageSession::class)->mirror();
        $mirror->beginTransaction();

        try {
            $this->retry($uuid, $occurrence);
            self::fail('The retry refuses inside an open mirror transaction.');
        } catch (LogicException) {
        } finally {
            $mirror->rollBack();
        }

        self::assertSame([], $this->operations());
    }

    /**
     * A recorder of the statements on the queue database's session: the
     * session recorder, and on in-memory SQLite (where the session is the
     * application connection) that connection's events.
     *
     * @param  \Closure(string, string): void  $listener
     */
    private function recordQueueSession(\Closure $listener): void
    {
        $name = $this->app->make(PackageSession::class)->forQueue('database')->getName();

        PackageSession::recordForTesting(static function (string $kind, string $sql, ?string $session) use ($listener, $name): void {
            if ($kind !== PackageSession::EXECUTED && ($session === $name || $session === null)) {
                $listener($kind, $sql);
            }
        });

        if (! PackageSession::isPackageSession($name)) {
            $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($listener, $name): void {
                if ($event->connectionName === $name) {
                    $listener(PackageSession::STATEMENT, $event->sql);
                }
            });
        }
    }

    private static function shape(string $sql): string
    {
        // A pooled session names each table with its schema (`public.jobs`): the shape keeps the table.
        $sql = strtolower(str_replace(['"', '`'], '', AutocommitBounds::strip($sql)));

        if (preg_match('/^(insert)(?: or ignore| ignore)? into (?:\w+\.)?(\w+)/', $sql, $match) === 1
            || preg_match('/^(update) (?:\w+\.)?(\w+)/', $sql, $match) === 1
            || preg_match('/^(delete) from (?:\w+\.)?(\w+)/', $sql, $match) === 1
            || preg_match('/^(select)\b.*? from (?:\w+\.)?(\w+)/', $sql, $match) === 1) {
            return $match[1].' '.$match[2];
        }

        return $sql;
    }
}
