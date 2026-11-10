<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\OperationStep;
use Justrau\Orrery\Tests\Feature\Operations\Fixtures\ForgetsCommandsProvider;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The crash points and stale callers of a forget (and the process
 * cases of the forget tests): a child process
 * (test:new-forget, test:forget-all) holds at a hook point, then is killed or
 * resumed. On a SQLite file the failed-job table is a host file: a step that
 * needs a write there waits for the operator's resolve (D17). Kind: new
 * behaviour.
 */
#[Group('process')]
#[Group('shared-database')]
#[FreshSchema]
final class ForgetsProcessTest extends TestCase
{
    use ForgetFixtures;

    private ?Barrier $barrier = null;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that it shares with the test: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $this->setUpForgets();
    }

    /**
     * Rejects: a recovery that only time ends, and a runner that deletes again: a forget killed right after
     * its delete's COMMIT is finished by the runner from the `deleted` fence row.
     */
    public function test_k10_killed_after_the_record_delete_is_finished_by_the_runner(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $child = $this->startForget($uuid, $occurrence, [ForgetService::AFTER_RECORD_DELETE]);
        $this->awaitHold($child, ForgetService::AFTER_RECORD_DELETE);
        $child->stop();

        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::CLAIMED, $operation->state);
        self::assertNull($this->record($recordId, $uuid), 'The delete committed before the kill.');
        self::assertSame(Fence::DELETED, $this->fenceState((string) $operation->id));

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue((string) $operation->id);
        $this->runOperations();

        $operation = $this->operationOf($uuid);
        self::assertSame(Operations::DONE, $operation->state, 'The runner read `deleted` and finished (a WAL host file allows the read).');
        self::assertNull($this->row($uuid));
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid(), 'The runner deletes nothing: the child\'s one delete was the only one.');
        self::assertSame(Fence::DELETED, $this->fenceState((string) $operation->id), 'The runner wrote no cancellation.');
        self::assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }

    /**
     * Rejects: a delete fence that only checks the deadline (the clocks are back within it when the child
     * resumes, so only the key stops it): a forget held before its delete, whose delete the runner cancelled after record_deadline, loses on the key when it resumes,
     * after a retry of the occurrence took the row; the record is not deleted.
     */
    public function test_s7_stale_forget_loses_its_delete_fence(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        $child = $this->startForget($uuid, $occurrence, [ForgetService::BEFORE_RECORD_DELETE]);
        $this->awaitHold($child, ForgetService::BEFORE_RECORD_DELETE);
        $id = (string) $this->operationOf($uuid)->id;

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue($id);
        $this->runOperations();

        if ($this->recordsOnHostFile()) {
            self::assertSame(Operations::SQLITE_HOST_FILE, $this->operationOf($uuid)->awaiting_reason, 'awaiting_resolution first.');
            self::assertSame(OperationStep::DECIDED, $this->resolveOperation($id)['step'], 'The operator resolve cancels.');
        }

        self::assertSame(Operations::ABORTED, $this->operationOf($uuid)->state);
        self::assertNull($this->row($uuid)?->operation_id, 'The runner released the row.');

        // A retry of the occurrence takes the row (the claim shape of a retry); the clocks are back within the deadline,
        // so only the fence key can stop the resumed forget.
        $retry = Operations::newId();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'retrying', 'operation_id' => $retry]);
        $this->shiftClocks(0.0);
        $this->release(ForgetService::BEFORE_RECORD_DELETE);

        $result = $this->childResult($child);
        self::assertSame(['failed', 'cancelled'], [$result['result'], $result['code']]);
        self::assertNotNull($this->record($recordId, $uuid), 'The record is not deleted.');
        self::assertSame(Fence::DELETE_CANCELLED, $this->fenceState($id));
        self::assertSame(Operations::ABORTED, $this->operationOf($uuid)->state, 'aborted stays terminal.');
        $row = $this->row($uuid);
        self::assertNotNull($row);
        self::assertSame([$retry, 'retrying'], [$row->operation_id, $row->status], 'The retry\'s row is untouched.');
    }

    /**
     * Rejects: a recovery of mode none that waits for a failed-record fence or clock: a null-failer
     * forget killed after its claim is finished by the runner in mirror time (test_mode_none_forget_recovery).
     */
    public function test_null_failer_forget_killed_after_the_claim_is_finished_in_mirror_time(): void
    {
        [$uuid, $occurrence, $recordId] = $this->failedOccurrence();
        // A failure under the null failer binds no record (a bound row is refused).
        $this->useNullFailerFor($uuid);
        $child = $this->startForget($uuid, $occurrence, [ForgetService::AFTER_CLAIM], nullFailer: true);
        $this->awaitHold($child, ForgetService::AFTER_CLAIM);
        $child->stop();

        $operation = $this->operationOf($uuid);
        self::assertSame([Operations::CLAIMED, Operations::NONE], [$operation->state, $operation->mode]);
        $this->makeDue((string) $operation->id);
        $this->runOperations();
        self::assertSame(Operations::CLAIMED, $this->operationOf($uuid)->state, 'Its mirror deadline is ahead.');

        $this->shiftClocks(ForgetService::WINDOW_SECONDS + 5);
        $this->makeDue((string) $operation->id);
        $this->runOperations();

        self::assertSame(Operations::DONE, $this->operationOf($uuid)->state);
        self::assertNull($this->row($uuid));
        self::assertNotNull($this->record($recordId, $uuid), 'The null failer never touches the table.');
        self::assertSame(0, $this->primary()->table(Fence::TABLE)->count());
    }

    /**
     * Rejects: selecting the record by uuid, or by uuid and generation: F1 is mirrored with its bound id R1;
     * Laravel's own queue:retry republishes it, and the re-failure F2 logs R2 while its observation is dropped;
     * --all skips F1 (occurrence_mismatch), a forget of F1 removes only the mirror row, and R2 stays.
     */
    public function test_stale_row_never_selects_a_refailure(): void
    {
        [$uuid, $occurrence, $r1] = $this->failedOccurrence();
        $this->nativeRetry($r1);

        // A worker with Laravel's own queue class fails it again while the package storage is out.
        $outage = $this->mirrorOutage();
        $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--tries=1'], $this->testEnvironment(), [
            'ORRERY_TEST_PROVIDERS' => ForgetsCommandsProvider::class,
            WorkerProcess::FOREIGN_QUEUE => '1',
        ]);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        $outage->end();

        $r2 = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        self::assertNotSame($r1, (int) $r2->id);
        $row = $this->row($uuid);
        self::assertNotNull($row);
        self::assertSame([$r1, $occurrence], [(int) $row->provider_id, (int) $row->occurrence_id], 'The stale row still names F1 and R1.');

        $report = $this->forgetAll()->run();
        self::assertSame(['occurrence_mismatch' => 1], $report['skipped']);
        self::assertSame(0, $report['provider_only']);

        $result = $this->forgets()->forget($uuid, $occurrence, null);
        self::assertSame(ForgetResult::DELETED, $result->result, (string) $result->code);
        self::assertSame(Operations::NONE, $this->operationOf($uuid)->mode, 'R1 is gone: the forget removes only the mirror row.');
        self::assertNull($this->row($uuid));
        self::assertSame(1, DB::table('failed_jobs')->where('id', $r2->id)->where('uuid', $uuid)->count(), 'R2 stays.');
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid());
    }

    /**
     * Rejects: the ids()/forget() loop, and a walk without an upper bound: --all held after its first batch; a
     * retry of U publishes and fails again (a new record with a higher id than the walk's bound); on resume F2's
     * record survives, a claimed uuid is skipped, and the provider-only records are deleted exactly.
     */
    public function test_forget_all_skips_open_operations_and_other_occurrences(): void
    {
        $seeded = $this->seedProviderOnly(ForgetAllRecords::BATCH);
        [$claimed, , $claimedRecord] = $this->failedOccurrence();
        $this->mirror()->table('orrery_jobs')->where('uuid', $claimed)->update(['operation_id' => str_repeat('f', 32)]);
        [$u, , $ru] = $this->failedOccurrence();

        $child = $this->startForgetAll([ForgetAllRecords::AFTER_BATCH]);
        $this->awaitHold($child, ForgetAllRecords::AFTER_BATCH);

        // The retry of U: the record goes, the job is published again and fails again (F2, a higher id).
        $this->nativeRetry($ru);
        $this->work();
        $f2 = DB::table('failed_jobs')->where('uuid', $u)->sole();
        self::assertGreaterThan($ru, (int) $f2->id);
        $this->release(ForgetAllRecords::AFTER_BATCH);

        $report = $this->childResult($child);
        self::assertSame(ForgetAllRecords::BATCH, $report['provider_only']);
        self::assertSame(['operation_in_progress' => 1], $report['skipped']);
        self::assertSame(0, $report['deleted']);
        self::assertSame(1, DB::table('failed_jobs')->where('id', $f2->id)->count(), 'F2 survives.');
        self::assertNotNull($this->row($u));
        self::assertNotNull($this->record($claimedRecord, $claimed), 'The claimed uuid is skipped.');
        self::assertSame(0, DB::table('failed_jobs')->whereIn('id', $seeded)->count(), 'Every provider-only record is deleted.');
    }

    /**
     * Laravel's own queue:retry of a record: it deletes the record and publishes its payload again through
     * Laravel's queue class, which the package does not observe.
     */
    private function nativeRetry(int $recordId): void
    {
        $record = DB::table('failed_jobs')->where('id', $recordId)->sole();
        DB::table('failed_jobs')->where('id', $recordId)->delete();
        $this->failedDeletes = [];
        $payload = json_decode((string) $record->payload, true);
        self::assertIsArray($payload);
        $payload['attempts'] = 0;

        $queue = new LaravelDatabaseQueue(DB::connection(TestEnvironment::PRIMARY), 'jobs', 'default', 90);
        $queue->setContainer($this->app);
        $queue->setConnectionName('database');
        $queue->pushRaw((string) json_encode($payload), 'default');
    }

    /**
     * @return list<int>
     */
    private function seedProviderOnly(int $count): array
    {
        $rows = [];

        foreach (range(1, $count) as $i) {
            $uuid = (string) Str::uuid();
            $rows[] = ['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => (string) json_encode(['uuid' => $uuid]), 'exception' => 'seed', 'failed_at' => Carbon::now()];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('failed_jobs')->insert($chunk);
        }

        return array_map('intval', DB::table('failed_jobs')->whereIn('uuid', array_column($rows, 'uuid'))->pluck('id')->all());
    }

    /**
     * @param  list<string>  $holds
     */
    private function startForget(string $uuid, int $occurrence, array $holds, bool $nullFailer = false): WorkerProcess
    {
        $arguments = ['test:new-forget', $uuid, (string) $occurrence];

        if ($nullFailer) {
            $arguments[] = '--null-failer';
        }

        return WorkerProcess::start($arguments, $this->testEnvironment(), ['ORRERY_TEST_PROVIDERS' => ForgetsCommandsProvider::class] + $this->holds()->holdEnvironment(...$holds));
    }

    /**
     * @param  list<string>  $holds
     */
    private function startForgetAll(array $holds): WorkerProcess
    {
        return WorkerProcess::start(['test:forget-all'], $this->testEnvironment(), ['ORRERY_TEST_PROVIDERS' => ForgetsCommandsProvider::class] + $this->holds()->holdEnvironment(...$holds));
    }

    private function holds(): Barrier
    {
        return $this->barrier ??= Barrier::forTest();
    }

    private function awaitHold(WorkerProcess $worker, string $point): void
    {
        $this->holds()->await($point.'.reached', 60, $worker->abandonIfExited());
    }

    private function release(string $point): void
    {
        $this->holds()->arrive($point.'.release');
    }

    /**
     * The JSON line of a finished child.
     *
     * @return array<string, mixed>
     */
    private function childResult(WorkerProcess $worker): array
    {
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        $lines = array_values(array_filter(explode("\n", trim($worker->output())), static fn (string $line): bool => str_starts_with($line, '{')));
        self::assertNotSame([], $lines, $worker->describe());
        $result = json_decode((string) end($lines), true);
        self::assertIsArray($result);

        return $result;
    }

    private function fenceState(string $operationId): ?string
    {
        $state = $this->primary()->table(Fence::TABLE)->where('operation_id', $operationId)->where('slot', Fence::DELETE)->value('state');

        return is_string($state) ? $state : null;
    }
}
