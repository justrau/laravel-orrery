<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\AwaitingOperator;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Phases\OperationRetentionPhase;
use Justrau\Orrery\Retry\Phases\OperationsPhase;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestClock;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The retry on an application SQLite file (D17, D18):
 * the package writes a host file only inside an operator
 * action, and reads one automatically only in WAL mode with a positive busy
 * timeout. The application file is the test's own (`app`, the default
 * connection, so the package keeps its tables in the sibling file), so the
 * test runs on every engine. Kind: new behaviour.
 */
final class HostFileRetryTest extends RetryProtocolTestCase
{
    /** A second connection name on the application file: mode before. */
    private const APP_FAILED = 'app_failed';

    /** @var list<string> */
    private array $appFiles = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    /** @var list<array{session: string|null, sql: string, operator: bool}> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::releaseAll();
            DB::purge('app');
            DB::purge(self::APP_FAILED);
            $this->app['config']->set($this->saved);

            foreach ($this->appFiles as $file) {
                SqliteFiles::remove($file);
            }
        });
    }

    /**
     * Rejects: a retry that needs an automatic read or write of the host file: on a rollback-journal
     * file without a busy timeout, a failure stays retryable, and its retry is accepted and finished, with every
     * write to the file inside the operator action; the runner afterwards writes nothing there.
     */
    public function test_retry_of_a_failure_on_a_rollback_host_file_finishes(): void
    {
        $this->useHostFile(['journal_mode' => 'delete', 'busy_timeout' => 0]);
        self::assertFalse($this->app->make(HostFiles::class)->allowsReads('app'));
        $this->recordHostWrites();

        [$uuid, $occurrence] = $this->failedJob();
        self::assertSame([], $this->writes, 'The failure path writes nothing to the host file.');

        $outcome = $this->retry($uuid, $occurrence);

        self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
        self::assertNotSame([], $this->writes, 'The operator action wrote the publication.');
        self::assertSame([], array_values(array_filter($this->writes, static fn (array $write): bool => ! $write['operator'])), 'Every host-file write is inside the operator action.');
        $operation = $this->operation((string) $outcome->operationId);
        self::assertSame([Operations::DONE, Operations::IN_TX], [$operation->state, $operation->mode]);
        self::assertSame(0, (int) DB::connection('app')->table('failed_jobs')->count());
        self::assertSame(1, (int) DB::connection('app')->table('jobs')->count());
        self::assertSame(['pending', 2], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->cycle]);

        $this->writes = [];
        $this->moveDatabaseClocks(4000);
        $this->runOnce();
        self::assertSame([], $this->writes, 'The runner writes nothing to the host file.');
    }

    /**
     * Rejects: the unconditional `awaiting_since IS NULL` filter (a read-blocked operation is never checked again), a
     * re-admission that authorises a host write, and an overwritten abort error_code: a published
     * retry and an aborting mode-before retry wait as sqlite_reads_not_allowed without a report; once the file allows
     * reads, the runner finishes the published one with host reads and mirror writes only, and the aborting one moves
     * to sqlite_host_file with its error_code kept and stays operator-only until the resolve.
     */
    public function test_read_blocked_operations_resume_automatically(): void
    {
        $this->useHostFile(['journal_mode' => 'delete', 'busy_timeout' => 0]);
        $this->app['config']->set('database.connections.'.self::APP_FAILED, $this->app['config']->get('database.connections.app'));
        $this->useCapturingFailer('failed_jobs', self::APP_FAILED);

        // A: published (stopped after its COMMIT); B: its record deleted, then aborting with the code `cancelled`.
        [$published, $occurrenceA] = $this->failedJob();
        [$aborting, $occurrenceB] = $this->failedJob();
        $this->killAt(RetryService::AFTER_COMMIT);
        $this->expectKilled(fn () => $this->retry($published, $occurrenceA), RetryService::AFTER_COMMIT);
        $this->killAt(RetryService::AFTER_RECORD_DELETE);
        $this->expectKilled(fn () => $this->retry($aborting, $occurrenceB), RetryService::AFTER_RECORD_DELETE);
        $a = (string) $this->rowOrFail($published)->operation_id;
        $b = (string) $this->rowOrFail($aborting)->operation_id;
        $this->app->make(Operations::class)->markAborting($this->mirror(), $b, 'cancelled');

        $this->moveDatabaseClocks(120);
        $this->recordHostWrites();
        $errors = Degradation::record()['errors'] ?? [];
        $this->runOnce();

        foreach ([$a, $b] as $id) {
            $operation = $this->operation($id);
            self::assertSame(Operations::SQLITE_READS_NOT_ALLOWED, $operation->awaiting_reason, $id);
            self::assertSame(1, (int) $operation->checks, 'A read-blocked operation counts a check and backs off.');
        }

        self::assertSame('cancelled', $this->operation($b)->error_code);
        self::assertSame($errors, Degradation::record()['errors'] ?? [], 'No error is recorded for a refused read.');

        // The configuration allows reads now: WAL with a busy timeout.
        $this->switchToWal();
        self::assertTrue($this->app->make(HostFiles::class)->allowsReads('app'));
        $this->moveDatabaseClocks(4000);
        $this->runOnce();

        $operationA = $this->operation($a);
        self::assertSame([Operations::DONE, null], [$operationA->state, $operationA->awaiting_since]);
        self::assertSame('pending', $this->rowOrFail($published)->status);
        $operationB = $this->operation($b);
        self::assertSame([Operations::ABORTING, Operations::SQLITE_HOST_FILE, 'cancelled'], [$operationB->state, $operationB->awaiting_reason, $operationB->error_code]);
        self::assertSame([], $this->writes, 'The runner wrote nothing to the host file.');

        // A sqlite_host_file operation stays operator-only.
        $checks = (int) $operationB->checks;
        $this->moveDatabaseClocks(8000);
        $this->runOnce();
        self::assertSame($checks, (int) $this->operation($b)->checks);

        $this->resolveAsOperator($b);
        self::assertSame(Operations::ABORTED, $this->operation($b)->state);
        self::assertSame(1, (int) DB::connection('app')->table('failed_jobs')->where('uuid', $aborting)->count(), 'The operator restored the record.');
    }

    /**
     * Rejects: an automatic write on a host file, and an awaiting step that only time ends (D17; the
     * runner and the fence retention): an uncertain publication, a due abort with a restore and old fence rows of a
     * finished operation; the tick's operations and operation_retention phases write nothing to the host file, the
     * operations wait for an operator, and the resolve route, orrery:resolve and a later retry of the occurrence each
     * finish one.
     */
    public function test_sqlite_host_file_gets_no_automatic_write(): void
    {
        $this->useHostFile(['journal_mode' => 'wal', 'busy_timeout' => 5000]);
        $this->app['config']->set('database.connections.'.self::APP_FAILED, $this->app['config']->get('database.connections.app'));

        // An uncertain publication (stopped after its claim) and a later one of another job.
        [$first, $occurrence1] = $this->failedJob();
        $this->killAt(RetryService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->retry($first, $occurrence1), RetryService::AFTER_CLAIM);
        [$third, $occurrence3] = $this->failedJob();
        $this->killAt(RetryService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->retry($third, $occurrence3), RetryService::AFTER_CLAIM);

        // A due abort with a restore (mode before).
        $this->useCapturingFailer('failed_jobs', self::APP_FAILED);
        [$second, $occurrence2] = $this->failedJob();
        $this->killAt(RetryService::AFTER_RECORD_DELETE);
        $this->expectKilled(fn () => $this->retry($second, $occurrence2), RetryService::AFTER_RECORD_DELETE);

        // Old fence rows of a finished operation.
        $old = Operations::newId();
        DB::connection('app')->table(Fence::TABLE)->insert(['operation_id' => $old, 'slot' => Fence::PUBLISH, 'state' => Fence::CANCELLED, 'job_id' => null, 'deadline' => Clock::toDecimal(1000.0), 'created_at' => Clock::toDecimal(1000.0)]);

        $ids = [(string) $this->rowOrFail($first)->operation_id, (string) $this->rowOrFail($second)->operation_id, (string) $this->rowOrFail($third)->operation_id];
        $this->moveDatabaseClocks(120);
        $this->recordHostWrites();

        foreach ([OperationsPhase::class, OperationRetentionPhase::class] as $phase) {
            $this->app->make($phase)->run(new TickBudget(Clock::monotonic() + 30));
        }

        self::assertSame([], $this->writes, 'The phases wrote nothing to the host file.');
        self::assertSame(1, (int) DB::connection('app')->table(Fence::TABLE)->where('operation_id', $old)->count(), 'The fence retention left the host file alone.');

        foreach ($ids as $id) {
            self::assertSame(Operations::SQLITE_HOST_FILE, $this->operation($id)->awaiting_reason, $id);
        }

        $health = $this->getJson('/orrery/api/health')->assertOk()->json('awaiting_resolution');
        self::assertSame(3, $health['count']);
        self::assertSame([Operations::SQLITE_HOST_FILE], array_values(array_unique(array_column($health['operations'], 'reason'))));

        // The resolve route, orrery:resolve, and a later retry of the occurrence.
        $this->resolveAsOperator($ids[0]);
        self::assertSame(Operations::ABORTED, $this->operation($ids[0])->state);

        self::assertSame(0, Artisan::call('orrery:resolve', ['id' => $ids[1]]));
        self::assertSame(Operations::ABORTED, $this->operation($ids[1])->state);
        self::assertSame(1, (int) DB::connection('app')->table('failed_jobs')->where('uuid', $second)->count(), 'The record was restored.');

        $this->useCapturingFailer('failed_jobs', 'app');
        $outcome = $this->retry($third, $occurrence3);
        self::assertSame(Operations::ABORTED, $this->operation($ids[2])->state, 'The later retry ran the pending step first.');
        self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
        self::assertSame(0, (int) $this->getJson('/orrery/api/health')->json('awaiting_resolution.count'));
    }

    /**
     * Rejects: a policy that refuses only when the raw busy_timeout key is missing, or that ignores the PDO timeout:
     * a claimed retry with an already published fence row is finished by the runner only under WAL with a positive
     * effective busy timeout; otherwise it waits as sqlite_reads_not_allowed.
     *
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('readPolicies')]
    public function test_published_fence_row_is_read_only_under_the_read_policy(array $changes, bool $automatic): void
    {
        $this->useHostFile($changes);
        [$uuid, $occurrence] = $this->failedJob();
        $this->killAt(RetryService::AFTER_COMMIT);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_COMMIT);
        $id = (string) $this->rowOrFail($uuid)->operation_id;

        $this->moveDatabaseClocks(120);
        $this->recordHostWrites();
        $this->runOnce();

        $operation = $this->operation($id);
        self::assertSame([], $this->writes, 'The runner never writes the host file.');

        if ($automatic) {
            self::assertSame(Operations::DONE, $operation->state);
            self::assertSame('pending', $this->rowOrFail($uuid)->status);
        } else {
            self::assertSame([Operations::CLAIMED, Operations::SQLITE_READS_NOT_ALLOWED], [$operation->state, $operation->awaiting_reason]);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function readPolicies(): array
    {
        return [
            'WAL, both timeouts unset (60 s default)' => [['journal_mode' => 'wal', 'busy_timeout' => null], true],
            'WAL, busy_timeout 0' => [['journal_mode' => 'wal', 'busy_timeout' => 0], false],
            'WAL, no busy_timeout, PDO timeout 0' => [['journal_mode' => 'wal', 'busy_timeout' => null, 'options' => [\PDO::ATTR_TIMEOUT => 0]], false],
            'WAL, busy_timeout over a zero PDO timeout' => [['journal_mode' => 'wal', 'busy_timeout' => 5000, 'options' => [\PDO::ATTR_TIMEOUT => 0]], true],
            'rollback journal, any timeout' => [['journal_mode' => 'delete', 'busy_timeout' => 5000], false],
        ];
    }

    /**
     * Records every statement other than a read on a package session of the host file.
     */
    private function recordHostWrites(): void
    {
        $sessions = $this->app->make(PackageSession::class);
        $this->writes = [];
        $writes = &$this->writes;
        PackageSession::recordForTesting(static function (string $kind, string $sql, ?string $session) use (&$writes, $sessions): void {
            if ($kind === PackageSession::EXECUTED || $session === null || str_contains($session, '#sibling')) {
                return;
            }

            if (! in_array($session, [PackageSession::NAME_PREFIX.'app', PackageSession::NAME_PREFIX.self::APP_FAILED], true)) {
                return;
            }

            $verb = strtolower((string) strtok(ltrim($sql), " \n"));

            if ($kind !== PackageSession::STATEMENT || ! in_array($verb, ['select', 'pragma'], true)) {
                $writes[] = ['session' => $session, 'sql' => $sql, 'operator' => $sessions->operatorContext() !== null];
            }
        });
    }

    private function switchToWal(): void
    {
        DB::connection('app')->statement('pragma journal_mode = wal');
        foreach (['app', self::APP_FAILED] as $name) {
            $this->app['config']->set("database.connections.{$name}.journal_mode", 'wal');
            $this->app['config']->set("database.connections.{$name}.busy_timeout", 5000);
        }

        HostFiles::endUnit();
        PackageSession::releaseAll();
    }

    /**
     * Rejects: an immediate abort whose step waits for an operator reported as a failure (restore_failed): the
     * publication of a mode-before retry on a host SQLite file fails after the record delete, and the restore of the
     * immediate abort defers to an operator (the step's AwaitingOperator; the handler's own check never raises it
     * inside the service's operator action, so the hook stands in for it). The result is uncertain with
     * awaiting_operator and the reason, the operation waits with that reason and keeps its cause (publish_failed),
     * and the operator's resolve restores the record.
     * Still passes: a result that names the awaiting state but leaves the operation without its awaiting fields
     * until the next tick (the assertion on awaiting_reason rejects that).
     */
    public function test_an_immediate_abort_that_waits_for_an_operator_is_uncertain(): void
    {
        $this->useHostFile(['journal_mode' => 'wal', 'busy_timeout' => 5000]);
        $this->app['config']->set('database.connections.'.self::APP_FAILED, $this->app['config']->get('database.connections.app'));
        $this->useCapturingFailer('failed_jobs', self::APP_FAILED);
        [$uuid, $occurrence] = $this->failedJob();

        Hooks::$callback = static function (string $point): void {
            match ($point) {
                Publication::BEFORE_COMMIT => throw new RuntimeException('The publication broke.'),
                RetryHandler::BEFORE_RESTORE => throw new AwaitingOperator(Operations::SQLITE_HOST_FILE, 'app'),
                default => null,
            };
        };

        try {
            $result = $this->retry($uuid, $occurrence);
        } finally {
            Hooks::$callback = null;
        }

        self::assertSame([RetryResult::UNCERTAIN, 'awaiting_operator', Operations::SQLITE_HOST_FILE], [$result->result, $result->code, $result->awaitingReason]);
        self::assertNotNull($result->operationId);
        self::assertStringContainsString('operator', (string) $result->message);
        $operation = $this->operation((string) $result->operationId);
        self::assertSame([Operations::ABORTING, Operations::SQLITE_HOST_FILE, 'publish_failed'], [$operation->state, $operation->awaiting_reason, $operation->error_code]);
        self::assertSame(0, (int) DB::connection('app')->table('failed_jobs')->where('uuid', $uuid)->count(), 'Mode before deleted the record.');

        $this->resolveAsOperator((string) $result->operationId);

        self::assertSame(Operations::ABORTED, $this->operation((string) $result->operationId)->state);
        self::assertSame(1, (int) DB::connection('app')->table('failed_jobs')->where('uuid', $uuid)->count(), 'The operator restored the record.');
    }

    /**
     * Rejects: a sqlite_host_file mark that stays after an operator resolve whose host-file writes succeeded and
     * whose mirror transaction then failed: only mirror work was left, but the runner never takes a
     * marked operation, so it stayed open until a second Resolve. The mark goes, and the next tick ends the
     * abort without a second restore.
     * Still passes: a handler that clears the mark also when the mirror work was not reached (the test above
     * rejects that for a failed host write).
     */
    public function test_a_resolve_whose_mirror_step_fails_leaves_the_rest_to_the_runner(): void
    {
        $this->useHostFile(['journal_mode' => 'wal', 'busy_timeout' => 5000]);
        $this->app['config']->set('database.connections.'.self::APP_FAILED, $this->app['config']->get('database.connections.app'));
        $this->useCapturingFailer('failed_jobs', self::APP_FAILED);
        [$uuid, $occurrence] = $this->failedJob();

        Hooks::$callback = static function (string $point): void {
            match ($point) {
                Publication::BEFORE_COMMIT => throw new RuntimeException('The publication broke.'),
                RetryHandler::BEFORE_RESTORE => throw new AwaitingOperator(Operations::SQLITE_HOST_FILE, 'app'),
                default => null,
            };
        };

        try {
            $result = $this->retry($uuid, $occurrence);
        } finally {
            Hooks::$callback = null;
        }

        $id = (string) $result->operationId;
        self::assertSame(Operations::SQLITE_HOST_FILE, $this->operation($id)->awaiting_reason);

        // The restore commits on the host file; the mirror's abort transaction then fails once.
        Hooks::$callback = static function (string $point): void {
            if ($point === RetryHandler::AFTER_RESTORE) {
                throw new RuntimeException('The mirror broke.');
            }
        };

        try {
            $this->resolveAsOperator($id);
        } finally {
            Hooks::$callback = null;
        }

        $operation = $this->operation($id);
        self::assertSame([Operations::ABORTING, null], [$operation->state, $operation->awaiting_reason]);
        self::assertSame(1, (int) DB::connection('app')->table('failed_jobs')->where('uuid', $uuid)->count(), 'The restore committed.');

        $this->moveDatabaseClocks(3600);
        $this->app->make(OperationsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));

        self::assertSame(Operations::ABORTED, $this->operation($id)->state);
        self::assertSame(1, (int) DB::connection('app')->table('failed_jobs')->where('uuid', $uuid)->count(), 'No second restore.');
    }

    /**
     * Rejects: a handler that clears the sqlite_host_file mark after any step that did not wait: an operator
     * resolve whose step fails changes nothing, so the operation stays claimed, keeps its mark and stays in
     * /api/health awaiting_resolution (the runner never takes a sqlite_host_file row).
     */
    public function test_a_failed_operator_resolve_keeps_the_awaiting_mark(): void
    {
        $this->useHostFile(['journal_mode' => 'wal', 'busy_timeout' => 5000]);
        [$uuid, $occurrence] = $this->failedJob();
        $this->killAt(RetryService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_CLAIM);
        $id = (string) $this->rowOrFail($uuid)->operation_id;
        $this->moveDatabaseClocks(120);
        $this->app->make(OperationsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));
        self::assertSame(Operations::SQLITE_HOST_FILE, $this->operation($id)->awaiting_reason);

        // The resolve's fence write fails.
        DB::connection('app')->getSchemaBuilder()->drop(Fence::TABLE);
        $this->resolveAsOperator($id);

        $operation = $this->operation($id);
        self::assertSame([Operations::CLAIMED, Operations::SQLITE_HOST_FILE], [$operation->state, $operation->awaiting_reason]);
        self::assertSame(1, (int) $this->getJson('/orrery/api/health')->json('awaiting_resolution.count'));
    }

    /**
     * An application SQLite file `app` with the queue and failed-job tables and the fence, as the default
     * connection and the queue connection `database`.
     *
     * @param  array<string, mixed>  $changes
     */
    private function useHostFile(array $changes): void
    {
        $file = $this->appFiles[] = SqliteFiles::create();
        $config = $this->app['config'];

        foreach (['database.default', 'database.connections.app', 'database.connections.'.self::APP_FAILED, 'queue.connections', 'queue.failed', 'orrery.database'] as $key) {
            $this->saved[$key] = $config->get($key);
        }

        // A null change removes the key (the setting is unset).
        $app = array_replace(['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000, 'journal_mode' => 'wal'], $changes);

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($app[$key]);
            }
        }

        $config->set('database.connections.app', $app);
        $config->set('queue.connections.database', ['driver' => 'database', 'connection' => 'app', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
        $config->set('orrery.database', null);
        PackageSession::releaseAll();

        $connection = DB::connection('app');

        if (($changes['journal_mode'] ?? 'wal') === 'wal') {
            $connection->statement('pragma journal_mode = wal');
        }

        $schema = $connection->getSchemaBuilder();
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        PackageSchema::create($schema, [Fence::TABLE]);

        $config->set('database.default', 'app');
        $this->app->forgetInstance('queue');
        $this->app->forgetInstance('queue.connection');
        Queue::clearResolvedInstance('queue');
        $this->useCapturingFailer('failed_jobs', 'app');
        Orrery::auth(static fn (): bool => true);
        TestClock::shift(TestClock::EVERY, 0.0);
    }

    /**
     * The package's own mirror session (the sibling of the application file).
     */
    protected function mirror(): Connection
    {
        return $this->app->make(PackageSession::class)->mirror();
    }

    /**
     * The jobs rows of a retry, on the application file.
     */
    protected function jobsOf(string $operationId): int
    {
        return (int) DB::connection('app')->table('jobs')->where('payload', 'like', '%"orreryGen":"'.$operationId.'"%')->count();
    }

    protected function failedRecords(?string $uuid = null): int
    {
        $query = DB::connection('app')->table('failed_jobs');

        return (int) ($uuid === null ? $query->count() : $query->where('uuid', $uuid)->count());
    }
}
