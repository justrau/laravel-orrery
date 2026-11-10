<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\OperationRunner;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\OperationStep;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\MirrorOutage;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

/**
 * The recovery of a forget by the operation runner (the forget
 * protocol's step 6 and the operation runner), in one process on
 * every engine. A crash is a throw at a hold point (Hooks); the process
 * tests (ForgetsProcessTest) kill a real child there. On a SQLite file the
 * failed-job table is a host file (D17, D18): a step that needs a write
 * there waits for an operator (resolve()); elsewhere the HostFiles seam makes
 * the same case. Kind: new behaviour.
 */
#[FreshSchema]
final class ForgetsRecoveryTest extends TestCase
{
    use ForgetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpForgets();
    }

    /**
     * Rejects: a recovery that only time ends, a runner that deletes the record again or never finishes the
     * mirror delete after the delete committed (in one process; and its mirror-outage variant).
     */
    #[DataProvider('afterDelete')]
    public function test_forget_mirror_failure_is_finished_by_the_runner(string $case): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $outage = null;

        if ($case === 'crash') {
            $this->crashAt(ForgetService::AFTER_RECORD_DELETE);
        } else {
            Hooks::$callback = function (string $point) use (&$outage): void {
                if ($point === ForgetService::AFTER_RECORD_DELETE) {
                    $outage = $this->mirrorOutage();
                }
            };
        }

        $result = $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;

        if ($case === 'outage') {
            self::assertSame([ForgetResult::MIRROR_PENDING, 'mirror_error'], [$result?->result, $result?->code]);
            self::assertInstanceOf(MirrorOutage::class, $outage);
            $outage->end();
        }

        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::CLAIMED, $operation->state);
        self::assertNull($this->record($recordId, $uuid), 'The delete committed.');
        self::assertSame((string) $operation->id, $this->row($uuid)?->operation_id, 'The row is still held.');

        // Before the record deadline the runner waits.
        $this->makeDue((string) $operation->id);
        $this->runOperations();
        self::assertSame(Operations::CLAIMED, $this->operationOf($uuid)->state);

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue((string) $operation->id);
        $this->runOperations();

        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::DONE, $operation->state, 'The runner read the deleted row and finished, also on a WAL host file.');
        self::assertNull($operation->awaiting_since);
        self::assertNull($this->row($uuid));
        self::assertSame(Fence::DELETED, $this->fenceState((string) $operation->id), 'The runner wrote no cancellation.');
        self::assertSame(1, $this->assertFailedDeletesNameIdAndUuid(), 'One delete in all.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function afterDelete(): array
    {
        return ['killed after the delete' => ['crash'], 'a mirror outage at the mirror delete' => ['outage']];
    }

    /**
     * Rejects: a mirror delete without the status and occurrence guard: when a native retry started a new cycle
     * after the record delete, the runner clears the hold, ends the operation done / row_moved and keeps the row.
     */
    #[DataProvider('movedRows')]
    public function test_moved_row_ends_the_forget_without_deleting_it(string $status): void
    {
        [$uuid, $occurrence] = $this->failedOccurrence();
        $this->crashAt(ForgetService::AFTER_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $id = (string) $this->operationOf($uuid)->id;
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => $status, 'occurrence_id' => $occurrence + 1]);

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($id);
        $this->runOperations();

        $operation = $this->operationOf($uuid);
        self::assertSame([Operations::DONE, 'row_moved'], [$operation->state, $operation->error_code]);
        $row = $this->row($uuid);
        self::assertNotNull($row, 'The moved row stays.');
        self::assertNull($row->operation_id, 'The hold is cleared.');
        self::assertSame($status, $row->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function movedRows(): array
    {
        return ['a new cycle' => ['pending'], 'a new occurrence, still failed' => ['failed']];
    }

    /**
     * Rejects: a recovery of mode none that waits for a failed-record fence or clock that does not exist:
     * the null failer and a record that is already gone, killed after the claim or failed by a
     * mirror outage at the mirror delete, are finished by the runner in mirror time.
     */
    #[DataProvider('modeNone')]
    public function test_mode_none_forget_recovery(string $failer, string $case): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();

        if ($failer === 'null') {
            $this->useNullFailerFor($uuid);
        } else {
            DB::table('failed_jobs')->where('id', $recordId)->delete();
            $this->failedDeletes = [];
        }

        $outage = null;

        if ($case === 'crash') {
            $this->crashAt(ForgetService::AFTER_CLAIM);
        } else {
            Hooks::$callback = function (string $point) use (&$outage): void {
                if ($point === ForgetService::AFTER_CLAIM) {
                    $outage = $this->mirrorOutage();
                }
            };
        }

        $result = $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;

        if ($case === 'outage') {
            self::assertSame(ForgetResult::MIRROR_PENDING, $result?->result);
            self::assertInstanceOf(MirrorOutage::class, $outage);
            $outage->end();
        }

        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::NONE, $operation->mode);
        self::assertNull($operation->record_connection, 'No failed-record target.');
        self::assertNull($operation->record_deadline, 'No failed-record clock.');

        // The deadline is in mirror time: the runner waits, then finishes.
        $this->makeDue((string) $operation->id);
        $this->runOperations();
        self::assertSame(Operations::CLAIMED, $this->operationOf($uuid)->state);
        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue((string) $operation->id);
        $this->runOperations();

        self::assertSame(Operations::DONE, $this->operationOf($uuid)->state);
        self::assertNull($this->row($uuid));
        self::assertSame(0, $this->primary()->table(Fence::TABLE)->count(), 'No fence row.');
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid());

        if ($failer === 'null') {
            self::assertNotNull($this->record($recordId, $uuid), 'The null failer never touches the table.');
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function modeNone(): array
    {
        return [
            'the null failer, killed after the claim' => ['null', 'crash'],
            'the null failer, a mirror outage' => ['null', 'outage'],
            'a gone record, killed after the claim' => ['gone', 'crash'],
            'a gone record, a mirror outage' => ['gone', 'outage'],
        ];
    }

    /**
     * Rejects: a forget whose read just after the claim fails in mode before, and that answers mirror_pending
     * ("the record is deleted") although no delete ran; it is uncertain / claim_uncertain, and the runner cancels it.
     */
    public function test_failed_read_after_the_claim_is_uncertain_before_the_delete(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $outage = null;
        Hooks::$callback = function (string $point) use (&$outage): void {
            if ($point === ForgetService::AFTER_CLAIM) {
                $outage = $this->mirrorOutage();
            }
        };

        $result = $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        self::assertInstanceOf(MirrorOutage::class, $outage);
        $outage->end();

        self::assertSame([ForgetResult::UNCERTAIN, 'claim_uncertain'], [$result?->result, $result?->code]);
        self::assertNotNull($this->record($recordId, $uuid), 'No delete ran.');
        $operation = $this->operationOf($uuid);
        self::assertSame([Operations::BEFORE, Operations::CLAIMED], [$operation->mode, $operation->state]);
    }

    /**
     * Rejects: a runner that decides an expired forget by time alone, deletes the record itself, or never
     * releases the row; on a host SQLite file one that writes the cancellation without an operator (D17).
     */
    public function test_runner_cancels_an_expired_forget(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $this->crashAt(ForgetService::BEFORE_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $id = (string) $this->operationOf($uuid)->id;

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($id);
        $this->runOperations();

        if ($this->recordsOnHostFile()) {
            $operation = $this->operationOf($uuid);
            self::assertSame(Operations::CLAIMED, $operation->state);
            self::assertSame(Operations::SQLITE_HOST_FILE, $operation->awaiting_reason, 'awaiting_resolution: the cancellation is a host write.');
            self::assertNull($this->fenceState($id), 'No automatic write on the host file.');
            $this->makeDue($id);
            self::assertSame(0, $this->runOperations()['inspected'], 'A sqlite_host_file row is operator-only.');
            self::assertSame(OperationStep::DECIDED, $this->resolveOperation($id)['step']);
        }

        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::ABORTED, $operation->state);
        self::assertSame('cancelled', $operation->error_code);
        self::assertNull($operation->awaiting_since);
        self::assertSame(Fence::DELETE_CANCELLED, $this->fenceState($id));
        self::assertNotNull($this->record($recordId, $uuid), 'Nothing was deleted.');
        $row = $this->row($uuid);
        self::assertNotNull($row);
        self::assertSame('failed', $row->status);
        self::assertNull($row->operation_id, 'The row is free again.');

        self::assertSame(ForgetResult::DELETED, $this->forgets()->forget($uuid, $occurrence, null)->result, 'A later forget succeeds.');
        self::assertNull($this->record($recordId, $uuid));
    }

    /**
     * Rejects: a runner that checks the target only before the claim: a failed-job table changed after the claim
     * (with the same id and uuid planted in the new target) gets no mutation, the operation waits with
     * target_changed, and it finishes once the configuration is restored (test_target_change_blocks_every_mutation,
     * the forget half).
     */
    #[DataProvider('claimedTargetChanges')]
    public function test_target_change_after_the_claim_blocks_every_mutation(string $change): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrenceFor($change);
        $this->crashAt(ForgetService::BEFORE_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $id = (string) $this->operationOf($uuid)->id;
        $this->plantDriftedTarget($change, $uuid, $occurrence, $recordId);

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($id);
        $this->runOperations();

        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::CLAIMED, $operation->state);
        self::assertSame('target_changed', $operation->error_code);
        self::assertSame(1, (int) $operation->checks);
        self::assertNull($this->fenceState($id), 'No fence row.');
        $this->assertPlantedRecordStays();
        self::assertNotNull($this->record($recordId, $uuid), 'The original record stays.');

        // The configuration is restored: the operation finishes.
        $this->restoreDriftedTarget();
        $this->makeDue($id);
        $this->runOperations();

        if ($this->recordsOnHostFile()) {
            $this->resolveOperation($id);
        }

        self::assertSame(Operations::ABORTED, $this->operationOf($uuid)->state);
        self::assertNotNull($this->record($recordId, $uuid));
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function claimedTargetChanges(): array
    {
        return [
            'a cached host connection, another prefix' => ['cached'],
            'another failed database' => ['database'],
            'connect_via_database (test_connect_via_drift_blocks_every_mutation)' => ['connect_via'],
            'another queue.failed.table' => ['failed_table'],
        ];
    }

    /**
     * Rejects: an abort that the runner counts as decided although its mirror transaction did not commit:
     * the operation stays claimed and undecided, and the next run aborts it.
     */
    public function test_failed_abort_is_not_decided(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $this->crashAt(ForgetService::BEFORE_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $id = (string) $this->operationOf($uuid)->id;
        $fail = true;
        $this->failMarkAborting($fail);

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($id);

        if ($this->recordsOnHostFile()) {
            // On a host file only the operator decides the delete; its abort fails the same way.
            $this->runOperations();
            $step = $this->resolveOperation($id);
            self::assertSame([OperationStep::UNDECIDED, 'mirror_error'], [$step['step'], $step['code']]);
            self::assertSame(Operations::CLAIMED, $this->operationOf($uuid)->state);
        } else {
            $report = $this->runOperations();
            self::assertSame(0, $report['decided']);
            $operation = $this->operationOf($uuid);
            self::assertSame([Operations::CLAIMED, 'mirror_error', 1], [$operation->state, $operation->error_code, (int) $operation->checks]);
        }

        $fail = false;
        $this->makeDue($id);
        $this->runOperations();

        self::assertSame(Operations::ABORTED, $this->operationOf($uuid)->state);
        self::assertNotNull($this->record($recordId, $uuid));
    }

    /**
     * Rejects: an operator's decision that leaves `sqlite_host_file` behind: after a resolve whose
     * mirror step failed, the runner (which may not write the host file) finishes the operation from the decided
     * fence row.
     */
    public function test_runner_finishes_after_a_resolve_whose_mirror_step_failed(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $this->crashAt(ForgetService::BEFORE_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $id = (string) $this->operationOf($uuid)->id;
        $this->treatRecordsAsHostFile(true);
        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($id);
        $this->runOperations();
        self::assertSame(Operations::SQLITE_HOST_FILE, $this->operationOf($uuid)->awaiting_reason);

        $fail = true;
        $this->failMarkAborting($fail);
        $step = $this->resolveOperation($id);

        self::assertSame([OperationStep::UNDECIDED, 'mirror_error'], [$step['step'], $step['code']]);
        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::CLAIMED, $operation->state);
        self::assertNull($operation->awaiting_since, 'The decision cleared the host-file wait.');
        self::assertSame(Fence::DELETE_CANCELLED, $this->fenceState($id));

        $fail = false;
        $this->makeDue($id);
        $this->runOperations();

        self::assertSame(Operations::ABORTED, $this->operationOf($uuid)->state, 'The automatic runner finished it with a read.');
        self::assertNotNull($this->record($recordId, $uuid));
    }

    /**
     * Rejects: a forget held by a deferral of its queue connection, and a forget step that ends that deferral:
     * a forget acts only on its failed-record connection.
     */
    public function test_forget_ignores_the_queue_deferral(): void
    {
        [$uuid, $occurrence] = $this->failedOccurrence();
        $this->crashAt(ForgetService::AFTER_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $id = (string) $this->operationOf($uuid)->id;
        $config = $this->app['config'];
        $config->set('database.connections.testbench_elsewhere', $config->get('database.connections.'.TestEnvironment::PRIMARY));
        $config->set('queue.connections.elsewhere', ['driver' => 'database', 'connection' => 'testbench_elsewhere', 'table' => 'jobs', 'queue' => 'default']);
        $this->mirror()->table(Operations::TABLE)->where('id', $id)->update(['queue_connection' => 'elsewhere']);
        $deferral = (string) json_encode(['until' => $this->mirrorNow() + 86400, 'failures' => 3]);
        $this->mirror()->table('orrery_settings')->insert(['key' => OperationRunner::DEFER_PREFIX.'testbench_elsewhere', 'value' => $deferral]);

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($id);
        $this->runOperations();

        self::assertSame(Operations::DONE, $this->operationOf($uuid)->state, 'The queue deferral does not hold a forget.');
        self::assertSame($deferral, $this->mirror()->table('orrery_settings')->where('key', OperationRunner::DEFER_PREFIX.'testbench_elsewhere')->value('value'), 'The forget does not end the queue deferral.');
    }

    /**
     * Rejects: a runner abort that overwrites the code of the caller's own error with `cancelled`.
     */
    public function test_runner_abort_keeps_the_callers_error_code(): void
    {
        [$uuid, $occurrence] = $this->failedOccurrence();
        $this->crashAt(ForgetService::BEFORE_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $id = (string) $this->operationOf($uuid)->id;
        $this->mirror()->table(Operations::TABLE)->where('id', $id)->update(['error_code' => 'record_delete_uncertain']);

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($id);
        $this->runOperations();

        if ($this->recordsOnHostFile()) {
            $this->resolveOperation($id);
        }

        self::assertSame([Operations::ABORTED, 'record_delete_uncertain'], [$this->operationOf($uuid)->state, $this->operationOf($uuid)->error_code]);
    }

    /**
     * Rejects: a later forget whose pending step finishes the earlier forget of the same occurrence and then
     * answers not_eligible, though the occurrence was just forgotten: it answers deleted with that operation.
     */
    public function test_later_forget_that_finishes_the_pending_forget_answers_deleted(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $this->crashAt(ForgetService::AFTER_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $first = (string) $this->operationOf($uuid)->id;
        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertSame([ForgetResult::DELETED, $first], [$result->result, $result->operationId], (string) $result->code);
        self::assertSame(Operations::DONE, $this->operation($first)->state, 'The pending step finished the first forget.');
        self::assertNull($this->row($uuid));
        self::assertNull($this->record($recordId, $uuid));
    }

    /**
     * Rejects: an awaiting step that only time ends: a later forget of an occurrence whose row an awaiting
     * operation holds runs that operation's pending step first (as an operator), then forgets the occurrence.
     */
    public function test_later_forget_runs_the_pending_step_first(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $this->crashAt(ForgetService::BEFORE_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $first = (string) $this->operationOf($uuid)->id;
        $this->treatRecordsAsHostFile(true);

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($first);
        $this->runOperations();
        self::assertSame(Operations::SQLITE_HOST_FILE, $this->operation($first)->awaiting_reason);

        $result = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertSame(ForgetResult::DELETED, $result->result, (string) $result->code);
        self::assertSame(Operations::ABORTED, $this->operation($first)->state, 'The pending step ran first.');
        self::assertNull($this->record($recordId, $uuid));
        self::assertSame(1, $this->assertFailedDeletesNameIdAndUuid());
    }

    /**
     * Rejects: a re-check that the runner's selection never reaches, a re-admission that authorises a host write,
     * and one that overwrites the abort's error_code (the forget half of
     * test_read_blocked_operations_resume_automatically).
     */
    public function test_read_blocked_forget_resumes_automatically(): void
    {
        // A forget (deleted, the mirror delete pending) and one killed before its delete.
        [$k10, $k10Occurrence] = $this->failedOccurrence();
        $this->crashAt(ForgetService::AFTER_RECORD_DELETE);
        $this->forgetCatching($k10, $k10Occurrence);
        [$held, $heldOccurrence, $heldRecord] = $this->failedOccurrence();
        $this->crashAt(ForgetService::BEFORE_RECORD_DELETE);
        $this->forgetCatching($held, $heldOccurrence);
        Hooks::$callback = null;
        $readBlocked = (string) $this->operationOf($k10)->id;
        $hostWrite = (string) $this->operationOf($held)->id;
        $this->mirror()->table(Operations::TABLE)->where('id', $readBlocked)->update(['error_code' => 'record_delete_uncertain']);

        $this->treatRecordsAsHostFile(false);
        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($readBlocked);
        $this->makeDue($hostWrite);
        $this->runOperations();

        foreach ([$readBlocked, $hostWrite] as $id) {
            $operation = $this->operation($id);
            self::assertSame(Operations::CLAIMED, $operation->state);
            self::assertSame(Operations::SQLITE_READS_NOT_ALLOWED, $operation->awaiting_reason);
            self::assertSame(1, (int) $operation->checks, 'A refused read counts a check and backs off.');
        }

        // The configuration is fixed: the automatic runner, with no operator, finishes the read-only step.
        $this->treatRecordsAsHostFile(true);
        $this->makeDue($readBlocked);
        $this->makeDue($hostWrite);
        $this->runOperations();

        $finished = $this->operation($readBlocked);
        self::assertSame(Operations::DONE, $finished->state);
        self::assertNull($finished->awaiting_since);
        self::assertSame('record_delete_uncertain', $finished->error_code, 'The re-admission keeps the error_code.');
        self::assertNull($this->row($k10));

        // The other needs a host write: it waits for an operator, and the runner no longer selects it.
        $waiting = $this->operation($hostWrite);
        self::assertSame(Operations::CLAIMED, $waiting->state);
        self::assertSame(Operations::SQLITE_HOST_FILE, $waiting->awaiting_reason);
        self::assertNull($this->fenceState($hostWrite), 'No automatic host write.');
        $this->makeDue($hostWrite);
        self::assertSame(0, $this->runOperations()['inspected']);
        self::assertNotNull($this->record($heldRecord, $held));
    }

    /**
     * Rejects: a runner that reads a host SQLite file whose configuration does not allow automatic reads (D18),
     * and a policy that refuses when the raw busy_timeout key is missing: the
     * matrix on a real SQLite file.
     *
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('readPolicies')]
    public function test_k10_follows_the_automatic_read_policy(array $settings, bool $automatic): void
    {
        if ($this->testEnvironment()->driver !== 'sqlite' || $this->testEnvironment()->inMemory()) {
            self::markTestSkipped('The read policy of D18 is a SQLite-file rule; run it through bin/test sqlite --sqlite-file.');
        }

        [$uuid, $occurrence] = $this->failedOccurrence();
        $this->crashAt(ForgetService::AFTER_RECORD_DELETE);
        $this->forgetCatching($uuid, $occurrence);
        Hooks::$callback = null;
        $id = (string) $this->operationOf($uuid)->id;

        $key = 'database.connections.'.TestEnvironment::PRIMARY;
        $config = (array) $this->app['config']->get($key);

        foreach ($settings as $name => $value) {
            if ($value === null) {
                unset($config[$name]);
            } else {
                $config[$name] = $value;
            }
        }

        $this->app['config']->set($key, $config);
        HostFiles::resetForTesting();
        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($id);
        $this->runOperations();

        $operation = $this->operation($id);

        if ($automatic) {
            self::assertSame(Operations::DONE, $operation->state, 'The runner finished without an operator.');
        } else {
            self::assertSame(Operations::CLAIMED, $operation->state);
            self::assertSame(Operations::SQLITE_READS_NOT_ALLOWED, $operation->awaiting_reason);
            self::assertSame(OperationStep::DECIDED, $this->resolveOperation($id)['step'], 'The operator resolve finishes it.');
            self::assertSame(Operations::DONE, $this->operation($id)->state);
        }

        self::assertNull($this->row($uuid));
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function readPolicies(): array
    {
        return [
            'WAL, both timeouts unset (the 60 s default)' => [['busy_timeout' => null, 'options' => null], true],
            'WAL, busy_timeout 0' => [['busy_timeout' => 0], false],
            'WAL, no busy_timeout, ATTR_TIMEOUT 0' => [['busy_timeout' => null, 'options' => [\PDO::ATTR_TIMEOUT => 0]], false],
            'a positive busy_timeout over ATTR_TIMEOUT 0' => [['busy_timeout' => 5000, 'options' => [\PDO::ATTR_TIMEOUT => 0]], true],
            'rollback journal' => [['journal_mode' => 'delete'], false],
        ];
    }

    /**
     * While $fail is true, the mirror statement of `claimed → aborting` throws (its first binding, the SET
     * state, is `aborting`; a WHERE state IN (…) list comes after the SET values).
     */
    private function failMarkAborting(bool &$fail): void
    {
        $name = $this->app->make(PackageSession::class)->mirrorName();
        $connection = str_starts_with($name, PackageSession::NAME_PREFIX) ? substr($name, strlen(PackageSession::NAME_PREFIX)) : $name;
        PackageStatements::beforeEach($this->app, $connection, static function (string $sql, array $bindings) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($sql), 'update') && str_contains($sql, Operations::TABLE) && ($bindings[0] ?? null) === Operations::ABORTING) {
                throw new RuntimeException('the abort fails');
            }
        });
    }

    private function forgetCatching(string $uuid, int $occurrence): ?ForgetResult
    {
        try {
            return $this->forgets()->forget($uuid, $occurrence, null);
        } catch (RuntimeException $crash) {
            self::assertStringStartsWith('crash at', $crash->getMessage());

            return null;
        }
    }

    /**
     * The failed-record database as a host SQLite file, with or without automatic reads (D17, D18).
     */
    private function treatRecordsAsHostFile(bool $allowsReads): void
    {
        HostFiles::useForTesting(
            static fn (string $connection): ?bool => $connection === TestEnvironment::PRIMARY ? true : null,
            static fn (string $connection): ?bool => $connection === TestEnvironment::PRIMARY ? $allowsReads : null,
        );
    }

    private function operation(string $id): stdClass
    {
        $row = $this->mirror()->table(Operations::TABLE)->where('id', $id)->sole();
        self::assertInstanceOf(stdClass::class, $row);

        return $row;
    }

    private function fenceState(string $operationId): ?string
    {
        $state = $this->primary()->table(Fence::TABLE)->where('operation_id', $operationId)->where('slot', Fence::DELETE)->value('state');

        return is_string($state) ? $state : null;
    }
}
