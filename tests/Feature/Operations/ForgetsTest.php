<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider as LaravelUuidProvider;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\TestClock;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The single forget of the new protocol,
 * in one process on every engine: refusals, eligibility, the
 * claim, the fenced exact delete, the mirror delete and the caller's abort.
 * Kind: new behaviour (Forgets is new; the RED of each guard is its mutation
 * check).
 */
#[FreshSchema]
final class ForgetsTest extends TestCase
{
    use ForgetFixtures;
    use PooledSchemaDrift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpForgets();
    }

    /**
     * Rejects: a forget whose record delete does not name exactly id and uuid (the recorder), that leaves the
     * mirror row or its dependents, or that leaves the operation open.
     */
    public function test_forget_deletes_the_exact_record_and_the_mirror_row(): void
    {
        [$other] = $this->failedOccurrence();
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $this->mirror()->table('orrery_job_tags')->insert(['tag' => 'forget-me', 'job_uuid' => $uuid, 'pushed_at' => '1.000000']);

        $result = $this->forgets()->forget($uuid, $occurrence, 'ops@example.test');

        self::assertSame(ForgetResult::DELETED, $result->result, (string) $result->code);
        self::assertSame($occurrence, $result->occurrenceId);
        self::assertNull($this->record($recordId, $uuid));
        self::assertNull($this->row($uuid));
        self::assertSame(0, $this->mirror()->table('orrery_job_tags')->where('job_uuid', $uuid)->count(), 'The dependents go with the row.');
        self::assertNotNull($this->row($other), 'Another failure stays.');
        self::assertSame(1, DB::table('failed_jobs')->count());

        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::FORGET, $operation->action);
        self::assertSame(Operations::DONE, $operation->state);
        self::assertSame(Operations::BEFORE, $operation->mode);
        self::assertSame($recordId, (int) $operation->record_id);
        self::assertSame('ops@example.test', $operation->actor);
        self::assertSame(Fence::DELETED, $this->fenceState((string) $operation->id));
        self::assertSame(1, $this->assertFailedDeletesNameIdAndUuid(), 'One exact delete.');
        self::assertTrue($this->forgets()->forgotten($uuid, $occurrence), 'The ledger answers already_done.');
        self::assertFalse($this->forgets()->forgotten($uuid, $occurrence + 1));
    }

    /**
     * Rejects: a forget of a record that is gone that deletes anything else, or that fails instead of removing
     * the mirror row ("already gone").
     */
    public function test_forget_of_a_gone_record_removes_only_the_mirror_row(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        // A native forget removed R1; another record of the same uuid (a later re-failure, R2) exists.
        DB::table('failed_jobs')->where('id', $recordId)->delete();
        $r2 = (int) DB::table('failed_jobs')->insertGetId(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'R2', 'failed_at' => now()]);
        $this->failedDeletes = [];

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertSame(ForgetResult::DELETED, $result->result, (string) $result->code);
        self::assertNull($this->row($uuid));
        self::assertNotNull($this->record($r2, $uuid), 'R2 is intact.');
        self::assertSame(Operations::NONE, $this->operationOf($uuid)->mode);
        self::assertNull($this->operationOf($uuid)->record_id);
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid(), 'No delete on the failed-job table.');
    }

    /**
     * Rejects: a null failer that is read-only, and one whose forget touches a failed-job table.
     */
    public function test_null_failer_forget_is_mirror_only(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $this->useNullFailerFor($uuid);

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertSame(ForgetResult::DELETED, $result->result, (string) $result->code);
        self::assertNull($this->row($uuid));
        self::assertNotNull($this->record($recordId, $uuid), 'The null failer has no records: the table is untouched.');
        self::assertSame(Operations::NONE, $this->operationOf($uuid)->mode);
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid());
    }

    /**
     * Rejects: a forget without the eligibility checks before its claim: another occurrence, a missing row, a row
     * that an operation holds (the forget half of test_retry_and_forget_of_one_occurrence_serialise), a row that is
     * not failed.
     */
    public function test_forget_needs_a_free_failed_occurrence(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();

        self::assertResult(ForgetResult::NOT_ELIGIBLE, 'occurrence_mismatch', $this->forgets()->forget($uuid, $occurrence + 1, null));
        self::assertResult(ForgetResult::NOT_ELIGIBLE, 'occurrence_mismatch', $this->forgets()->forget('0f5a3c1e-6b1d-4e57-9a3e-000000000000', 1, null));

        // A row that an operation holds (here a claimed retry).
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['operation_id' => str_repeat('a', 32)]);
        self::assertResult(ForgetResult::NOT_ELIGIBLE, 'operation_in_progress', $this->forgets()->forget($uuid, $occurrence, null));
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['operation_id' => null]);

        // A row that is not failed.
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'completed']);
        self::assertResult(ForgetResult::NOT_ELIGIBLE, 'not_failed', $this->forgets()->forget($uuid, $occurrence, null));

        self::assertNotNull($this->record($recordId, $uuid));
        self::assertSame(0, $this->mirror()->table(Operations::TABLE)->count(), 'No operation was written.');
    }

    /**
     * Rejects: a claim that reads and then writes without its guarded update (without `operation_id IS NULL`, or
     * without the status guard): a row that changes between the read and the claim (another operation holds it,
     * or it completes) is never claimed.
     */
    #[DataProvider('concurrentChanges')]
    public function test_claim_loses_to_a_concurrent_change(string $change, string $code): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $winner = str_repeat('b', 32);
        Hooks::$callback = function (string $point) use ($uuid, $winner, $change): void {
            if ($point === ForgetService::BEFORE_CLAIM) {
                Hooks::$callback = null;
                $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(match ($change) {
                    'held' => ['operation_id' => $winner],
                    'completed' => ['status' => 'completed'],
                    default => ['occurrence_id' => (int) $this->row($uuid)?->occurrence_id + 1],
                });
            }
        };

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertResult(ForgetResult::NOT_ELIGIBLE, $code, $result);
        self::assertSame($change === 'held' ? $winner : null, $this->row($uuid)?->operation_id);
        self::assertSame(0, $this->mirror()->table(Operations::TABLE)->count());
        self::assertNotNull($this->record($recordId, $uuid));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function concurrentChanges(): array
    {
        return [
            'another operation claims it' => ['held', 'operation_in_progress'],
            'the row completes' => ['completed', 'not_failed'],
            'a new occurrence, still failed' => ['occurrence', 'occurrence_mismatch'],
        ];
    }

    /**
     * Rejects: a claim that does not guard on the provider_id it read: a record restored meanwhile (a retry's
     * abort binds the restored record's new id) is never claimed against the old one.
     */
    public function test_claim_loses_to_a_restored_record(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        Hooks::$callback = function (string $point) use ($uuid, $recordId): void {
            if ($point === ForgetService::BEFORE_CLAIM) {
                Hooks::$callback = null;
                $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['provider_id' => $recordId + 1000]);
            }
        };

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertResult(ForgetResult::NOT_ELIGIBLE, 'occurrence_mismatch', $result);
        self::assertNull($this->row($uuid)?->operation_id);
        self::assertSame(0, $this->mirror()->table(Operations::TABLE)->count());
        self::assertNotNull($this->record($recordId, $uuid));
    }

    /**
     * Rejects: an operation stored with a source outside the ledger's list, and a forget that throws when the
     * queue target cannot be computed: both are refused before any write.
     */
    public function test_unknown_source_and_unreachable_queue_target_are_refused(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();

        self::assertResult(ForgetResult::REFUSED, 'unknown_source', $this->forgets()->forget($uuid, $occurrence, null, 'browser'));

        $config = $this->app['config'];
        $queues = (array) $config->get('queue.connections');
        // The teardown migration reads every queue connection: the ghost goes before it.
        $this->beforeApplicationDestroyed(static fn () => $config->set('queue.connections', $queues));
        $config->set('queue.connections.ghost', ['driver' => 'database', 'connection' => 'not_configured', 'table' => 'jobs', 'queue' => 'default']);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['connection' => 'ghost']);
        self::assertResult(ForgetResult::REFUSED, 'queue_unreachable', $this->forgets()->forget($uuid, $occurrence, null));

        self::assertSame(0, $this->mirror()->table(Operations::TABLE)->count());
        self::assertNotNull($this->record($recordId, $uuid));
    }

    /**
     * Rejects: a forget that throws when the mirror session cannot be built: it answered HTTP 500
     * with the raw exception, where a retry answers `refused / mirror_unreachable`. It is refused, and the
     * failed record stays.
     * Still passes: a forget that refuses with another code of the same result.
     */
    public function test_a_mirror_that_cannot_be_built_is_refused(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();

        $config = $this->app['config'];
        $database = $config->get('orrery.database');
        // The teardown migration reads the mirror connection: the setting comes back before it.
        $this->beforeApplicationDestroyed(static fn () => $config->set('orrery.database', $database));
        $config->set('orrery.database', 'not_configured');

        self::assertResult(ForgetResult::REFUSED, 'mirror_unreachable', $this->forgets()->forget($uuid, $occurrence, null));

        $config->set('orrery.database', $database);
        self::assertNotNull($this->record($recordId, $uuid));
        self::assertNotNull($this->row($uuid));
    }

    /**
     * Rejects: filling a missing id later (a lookup by uuid), and a forget that deletes an unbound occurrence's
     * record (test_failed_binding_is_refused, the forget half).
     */
    public function test_unbound_occurrence_is_refused(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['provider_id' => null]);

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertResult(ForgetResult::REFUSED, 'occurrence_unprovable', $result);
        self::assertSame("Orrery cannot prove which failed record belongs to this failure; use Laravel's queue:retry or queue:forget.", $result->message);
        self::assertNotNull($this->record($recordId, $uuid));
        self::assertNotNull($this->row($uuid));
        self::assertSame(0, $this->mirror()->table(Operations::TABLE)->count());
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid());
    }

    /**
     * Rejects: a target checked only after the claim, or never: the failed-job storage changes
     * after the failure (the table, the prefix, the database, the effective schema), with a record of the same
     * id and uuid planted in the new target; a variant keeps the host's cached connection and changes only the
     * configuration.
     *
     * @param  'table'|'prefix'|'cached'|'database'|'schema'  $change
     */
    #[DataProvider('targetChanges')]
    public function test_target_drift_before_the_first_action_is_refused(string $change): void
    {
        $this->plantDriftedTarget($change, ...$this->failedOccurrenceFor($change));
        [$uuid, $occurrence] = $this->drifted;
        $pooled = $this->pooledSchemaDrift($change);

        if ($pooled) {
            $this->expectPooledRefusal();
        }

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        if ($pooled) {
            // Pooled mode refuses the drifted search_path itself (PooledSchemaDrift).
            self::assertResult(ForgetResult::REFUSED, 'record_unreachable', $result);
            $this->assertPooledRefusalReported('forget:records');
        } else {
            self::assertResult(ForgetResult::REFUSED, 'target_changed', $result);
        }

        self::assertSame(0, $this->mirror()->table(Operations::TABLE)->count(), 'Refused before the claim.');
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid());
        $this->assertPlantedRecordStays();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targetChanges(): array
    {
        return ['another table' => ['table'], 'another prefix' => ['prefix'], 'a cached host connection' => ['cached'], 'another database' => ['database'], 'another schema' => ['schema'], 'connect_via_database' => ['connect_via']];
    }

    /**
     * Rejects: trusting a binding other than the verified one, and any statement on the failed-job
     * table for a read-only failer (test_application_failer_binding_is_read_only, the forget half).
     */
    public function test_application_failer_binding_is_read_only(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $subclass = new class($this->app['db'], TestEnvironment::PRIMARY, 'failed_jobs') extends LaravelUuidProvider {};
        $this->app->instance('queue.failer', $subclass);
        $statements = 0;
        DB::listen(static function (QueryExecuted $event) use (&$statements): void {
            if (str_contains($event->sql, 'failed_jobs')) {
                $statements++;
            }
        });

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertResult(ForgetResult::REFUSED, FailedRecords::PROVIDER_READ_ONLY, $result);
        self::assertStringContainsString('Retry and forget need the database-uuids or null failed-job driver', (string) $result->message);
        self::assertSame(0, $statements, 'Zero statements on the failed-job table.');
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid());
        self::assertNotNull($this->record($recordId, $uuid));
        self::assertSame(0, $this->mirror()->table(Operations::TABLE)->count());
    }

    /**
     * Rejects: a forget that claims or deletes without the fence table in the failed-record database
     * (test_missing_fence_table_refuses_before_a_claim, the forget half).
     */
    public function test_missing_fence_table_refuses_before_a_claim(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $this->primary()->getSchemaBuilder()->dropIfExists(Fence::TABLE);
        $this->createdRecordFence = false;

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertResult(ForgetResult::REFUSED, 'fence_missing', $result);
        self::assertNotNull($this->record($recordId, $uuid));
        self::assertNotNull($this->row($uuid));
    }

    /**
     * Rejects: a delete without its fence row inside its own transaction: an error before the COMMIT of the
     * delete leaves nothing deleted, and the caller's own abort releases the row (failed / provider_error).
     */
    public function test_delete_error_before_commit_aborts_the_forget(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $fail = true;
        PackageStatements::beforeEach($this->app, TestEnvironment::PRIMARY, static function (string $sql) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($sql), 'delete') && str_contains($sql, 'failed_jobs')) {
                throw new RuntimeException('the delete fails');
            }
        });

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertResult(ForgetResult::FAILED, 'provider_error', $result);
        $fail = false;
        self::assertNotNull($this->record($recordId, $uuid), 'Nothing was deleted.');
        $row = $this->row($uuid);
        self::assertNotNull($row);
        self::assertSame('failed', $row->status);
        self::assertNull($row->operation_id, 'The abort released the row.');
        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::ABORTED, $operation->state);
        self::assertSame('provider_error', $operation->error_code);
        self::assertSame(Fence::DELETE_CANCELLED, $this->fenceState((string) $operation->id), 'The delete is decided cancelled.');

        // The occurrence can be forgotten again.
        self::assertSame(ForgetResult::DELETED, $this->forgets()->forget($uuid, $occurrence, null)->result);
        self::assertNull($this->record($recordId, $uuid));
    }

    /**
     * Rejects: a deadline checked outside the fence insert: a delete whose failed-record clock passed the
     * record deadline deletes nothing and aborts (deadline_passed).
     */
    public function test_delete_after_the_record_deadline_deletes_nothing(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        Hooks::$callback = function (string $point): void {
            if ($point === ForgetService::BEFORE_RECORD_DELETE) {
                // Only the failed-record database's clock moves past the deadline.
                TestClock::shift(TestEnvironment::PRIMARY, ForgetService::WINDOW_SECONDS + 5);
            }
        };

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertResult(ForgetResult::FAILED, 'deadline_passed', $result);
        self::assertNotNull($this->record($recordId, $uuid));
        self::assertNull($this->row($uuid)?->operation_id);
        self::assertSame(Operations::ABORTED, $this->operationOf($uuid)->state);
    }

    /**
     * Rejects: a forget inside an open mirror transaction (its record delete commits at once, so a rollback of
     * the caller would leave the row without its record).
     */
    public function test_forget_refuses_an_open_mirror_transaction(): void
    {
        [$uuid, $occurrence] = $this->failedOccurrence();
        $mirror = $this->app->make(PackageSession::class)->mirror();
        $this->expectException(LogicException::class);

        try {
            $mirror->beginTransaction();
            $this->forgets()->forget($uuid, $occurrence, null);
        } finally {
            $mirror->rollBack();
        }
    }

    private function fenceState(string $operationId): ?string
    {
        $state = $this->primary()->table(Fence::TABLE)->where('operation_id', $operationId)->where('slot', Fence::DELETE)->value('state');

        return is_string($state) ? $state : null;
    }

    private static function assertResult(string $result, ?string $code, ForgetResult $actual): void
    {
        self::assertSame([$result, $code], [$actual->result, $actual->code], (string) $actual->message);
    }
}
