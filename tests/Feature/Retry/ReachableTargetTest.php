<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Maintenance\Phases\ReconcileFailedPhase;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Tests\Support\UsesAltServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The reachable second-server cases of the target fingerprint (the retargeting
 * on the operation
 * ledger). queue.failed.database is testbench_target, a copy of the primary
 * configuration, so the failer is in mode `before`; retargetToAlt() changes
 * only its `port`, to the alt server of the engine. The alt server has the
 * same database name and a failed_jobs record with the same id and uuid, so
 * only the endpoint identity (EndpointIdentity: the port) tells the two
 * targets apart. Every action refuses or waits with target_changed, and the
 * planted alt record survives. Run through bin/test <mysql|pgsql> --alt --
 * --group alt --exclude-group orrery-no-exclusion.
 *
 * The parent class carries #[FreshSchema] (the alt schema is migrated per
 * test).
 */
#[Group('docker')]
#[Group('alt')]
final class ReachableTargetTest extends RetryTestCase
{
    use UsesAltServer;

    private const TARGET = 'testbench_target';

    /**
     * @return array<string, array{string}>
     */
    public static function actions(): array
    {
        return ['the retry' => ['retry'], 'the forget' => ['forget'], 'orrery:forget --all' => ['all']];
    }

    /**
     * Rejects: a fingerprint without the port (the record with the same id
     * and uuid on the alt server would be deleted), and an --all whose
     * mirrored branch deletes on the configured target without the check.
     * Still passes: a check that refuses every change of the configuration
     * (EndpointIdentityTest pins which keys count).
     */
    #[DataProvider('actions')]
    public function test_a_port_change_before_the_action_is_refused(string $action): void
    {
        [$uuid, $occurrence, $id] = $this->failedOnTarget();
        $this->retargetToAlt();

        match ($action) {
            'retry' => self::assertResult(RetryResult::REFUSED, 'target_changed', $this->retry($uuid, $occurrence)),
            'forget' => self::assertForget(ForgetResult::REFUSED, 'target_changed', $this->forgetService()->forget($uuid, $occurrence, 'tester', 'cli')),
            'all' => (function (): void {
                $report = $this->app->make(ForgetAllRecords::class)->run();
                self::assertSame(['target_changed' => 1], $report['skipped']);
                self::assertSame([0, 0], [$report['deleted'], $report['provider_only']], 'Nothing is deleted on the alt server.');
            })(),
        };

        self::assertSame([], $this->operations(), 'Refused before a claim.');
        $this->assertBothRecordsStay($id, $uuid);
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a reconciliation that reads presence on the alt server: the
     * alt failed_jobs has no record there, so a reconciliation without the
     * fingerprint would mark the row absent and, after the grace, remove it.
     * The row stays unchecked, and the health event names target_changed.
     */
    public function test_reconcile_failed_does_not_check_rows_on_another_port(): void
    {
        [$uuid, , $id] = $this->failedOnTarget(plant: false);
        $this->retargetToAlt();
        Exceptions::fake();

        $this->runPhase(ReconcileFailedPhase::class);

        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', null, $id], [$row->status, $row->absent_since, (int) $row->provider_id], 'The row is not checked.');
        self::assertSame(Reconciliation::HEALTH_NOT_CHECKED, Degradation::record()['last_error_operation'] ?? null);
        Exceptions::assertReported(static fn (WatchedOperationFailed $reported): bool => str_contains($reported->getMessage(), 'target_changed'));
        self::assertSame(0, (int) DB::connection('testbench_alt')->table('failed_jobs')->count());
        self::assertSame(1, (int) DB::connection('testbench_primary')->table('failed_jobs')->where('id', $id)->where('uuid', $uuid)->count());
    }

    /**
     * Rejects: a retry runner that checks the record fingerprint only
     * before the claim: the port changes after the claim. The runner
     * cancels the publication on the queue's database (which did not move),
     * then waits in `aborting` with target_changed, and writes nothing on
     * the alt server; restored, it aborts on its own target.
     */
    public function test_a_port_change_after_the_retry_claim_blocks_the_recovery(): void
    {
        [$uuid, $occurrence, $id] = $this->failedOnTarget();
        $this->killAt(RetryService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_CLAIM);
        $operation = (string) $this->rowOrFail($uuid)->operation_id;

        $this->retargetToAlt();
        $this->moveDatabaseClocks(120);
        $this->runOnce();

        self::assertSame([Operations::ABORTING, RetryHandler::TARGET_CHANGED], [$this->operation($operation)->state, $this->operation($operation)->error_code]);
        $this->assertBothRecordsStay($id, $uuid);
        self::assertSame(0, (int) DB::connection('testbench_alt')->table(Fence::TABLE)->count(), 'No fence row on the alt server.');

        $this->restorePrimary();
        $this->moveDatabaseClocks(4000);
        $this->runOnce();

        self::assertSame(Operations::ABORTED, $this->operation($operation)->state);
        $this->assertBothRecordsStay($id, $uuid);
    }

    /**
     * Rejects: a restore that inserts on the configured target without the
     * record fingerprint: the record was deleted on the primary server, the
     * port changes while the restore is pending, and the alt record with
     * the same id and uuid is neither touched nor duplicated. Restored, the
     * operation puts the record back on the primary server once.
     */
    public function test_a_port_change_while_the_restore_is_pending_blocks_it(): void
    {
        [$uuid, $occurrence, $id] = $this->failedOnTarget();
        $this->killAt(RetryService::AFTER_RECORD_DELETE);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_RECORD_DELETE);
        $operation = (string) $this->rowOrFail($uuid)->operation_id;
        self::assertSame(0, $this->primaryRecords($id, $uuid), 'Mode before deleted the record first.');
        $this->app->make(Operations::class)->markAborting($this->mirror(), $operation, 'cancelled');

        $this->retargetToAlt();
        $this->moveDatabaseClocks(8000);
        $this->runOnce();

        self::assertSame([Operations::ABORTING, RetryHandler::TARGET_CHANGED], [$this->operation($operation)->state, $this->operation($operation)->error_code]);
        self::assertSame(1, $this->altRecords($id, $uuid), 'The alt record survives, once.');
        self::assertSame(0, $this->primaryRecords($id, $uuid));
        self::assertSame(0, (int) DB::connection('testbench_alt')->table(Fence::TABLE)->count(), 'No fence row on the alt server.');

        $this->restorePrimary();
        $this->moveDatabaseClocks(12000);
        $this->runOnce();

        self::assertSame(Operations::ABORTED, $this->operation($operation)->state);
        self::assertSame(1, $this->primaryRecords(null, $uuid), 'Restored on the primary server.');
        self::assertSame(1, $this->altRecords($id, $uuid));
    }

    /**
     * Rejects: a forget runner that deletes on the configured target
     * without the fingerprint: the port changes after the claim, the
     * operation stays claimed with target_changed, and both records stay.
     */
    public function test_a_port_change_after_the_forget_claim_blocks_the_recovery(): void
    {
        [$uuid, $occurrence, $id] = $this->failedOnTarget();
        $this->killAt(ForgetService::BEFORE_RECORD_DELETE);
        $this->expectKilled(fn () => $this->forgetService()->forget($uuid, $occurrence, 'tester', 'cli'), ForgetService::BEFORE_RECORD_DELETE);
        $operation = (string) $this->rowOrFail($uuid)->operation_id;

        $this->retargetToAlt();
        $this->moveDatabaseClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->runOnce();

        self::assertSame([Operations::CLAIMED, 'target_changed'], [$this->operation($operation)->state, $this->operation($operation)->error_code]);
        $this->assertBothRecordsStay($id, $uuid);

        $this->restorePrimary();
        $this->moveDatabaseClocks(4000);
        $this->runOnce();

        self::assertSame(Operations::ABORTED, $this->operation($operation)->state);
        $this->assertBothRecordsStay($id, $uuid);
    }

    /**
     * A failed job whose record is on testbench_target (the primary server),
     * and, with $plant, a record with the same id and uuid on the alt server.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function failedOnTarget(bool $plant = true): array
    {
        $this->skipUnlessEngine('mysql', 'pgsql');
        $this->useAltServer();
        $this->useCapturingFailer('failed_jobs', self::TARGET);
        [$uuid, $occurrence] = $this->failedJob();
        $record = (array) DB::connection(self::TARGET)->table('failed_jobs')->where('uuid', $uuid)->sole();
        self::assertSame((int) $record['id'], (int) $this->rowOrFail($uuid)->provider_id, 'The failure is bound to its record.');

        if ($plant) {
            DB::connection('testbench_alt')->table('failed_jobs')->insert($record);
        }

        return [$uuid, $occurrence, (int) $record['id']];
    }

    private function assertBothRecordsStay(int $id, string $uuid): void
    {
        self::assertSame(1, $this->altRecords($id, $uuid), 'The planted alt record survives.');
        self::assertSame(1, $this->primaryRecords($id, $uuid), 'The primary record waits for its own target.');
    }

    private function altRecords(int $id, string $uuid): int
    {
        return (int) DB::connection('testbench_alt')->table('failed_jobs')->where('id', $id)->where('uuid', $uuid)->count();
    }

    private function primaryRecords(?int $id, string $uuid): int
    {
        $query = DB::connection('testbench_primary')->table('failed_jobs')->where('uuid', $uuid);

        if ($id !== null) {
            $query->where('id', $id);
        }

        return (int) $query->count();
    }
}
