<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\AutocommitBounds;
use Justrau\Orrery\Tests\Support\LostAckCommitPdo;
use Justrau\Orrery\Tests\Support\SessionPdo;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PDO;

/**
 * The target fingerprints of an operation, the error codes, the exact
 * deletes and the replay of a committed restore.
 * Kind: new behaviour.
 */
final class RetryTargetTest extends RetryProtocolTestCase
{
    /**
     * The deletes of src/ through a table variable or a guarded query
     * variable, by file: each pattern matches the statement, and each names
     * only package tables or the queue's jobs table.
     *
     * @var array<string, list<string>>
     */
    /** A delete of a failed table; a pooled session names the table with its schema (`"public"."failed_jobs"`). */
    private const FAILED_DELETE = '/^\s*delete from\s+(?:["`]?\w+["`]?\.)?["`]?\w*failed_jobs/i';

    private const VARIABLE_TABLE_DELETES = [
        // The queue's own jobs table (orrery:clear deletes waiting jobs), never a failed table.
        'src/Console/ClearCommand.php' => ['/^\$deleted = \$database->table\(\$table\)->whereIn\(\'id\', \$locked\)/', '/->table\(\$dependent\)/', '/->table\(self::TABLE\)|->table\(JobStore::TABLE\)|\$tx->table\(self::/'],
        // Retention's own method, and its dependent tables (DEPENDENT_TABLES: package tables).
        'src/Maintenance/Retention.php' => ['/^return \$this->delete\($/', '/^\$tx->table\(\$table\)->whereIn\(\'job_uuid\', \$chunk\)/', '/^self::belowFloor\(\$this->partition\(\$tx, \$partition\), \$partition\)/'],
        // The forget's mirror deletes: the guarded orrery_jobs query and JOB_TABLES (package tables).
        'src/Retry/ForgetService.php' => ['/\$query->delete\($/', '/^\$tx->table\(\$table\)->where\(\'job_uuid\', \$uuid\)/', '/\$guard\(\)->delete\($/'],
        'src/Retry/ForgetAllRecords.php' => ['/\$guard->delete\($/', '/^\$tx->table\(\$table\)->where\(\'job_uuid\', \$uuid\)/'],
        // Package tables of the queue samples, markers and the incident table (their own TABLE constants through a session).
        'src/Lifecycle/IncidentCleanup.php' => ['/IncidentCleanup|self::TABLE|->table\(self::TABLE\)/'],
    ];

    /** @var list<string> */
    private array $sqliteFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::releaseAll();

            foreach ($this->sqliteFiles as $file) {
                SqliteFiles::remove($file);
            }
        });
    }

    /**
     * Rejects: a fingerprint checked only in the resolution, and one combined fingerprint for every effect:
     * (1) Q's database moves between the claim and the recovery, with an empty planted queue there; (2)
     * the failed-record database moves while a mode-before restore is pending, with the same id and uuid planted
     * there. Nothing in the new targets changes, the operation waits with target_changed (listed in
     * awaiting_resolution) until the configuration is restored, then finishes. (3) Q alone moves while a mode-before
     * restore is pending: the restore checks only the record fingerprint, so it restores once and ends aborted.
     */
    public function test_target_change_blocks_every_mutation(): void
    {
        $this->useSplitFailedConnection();

        if ($this->testEnvironment()->separate() && $this->testEnvironment()->isServer()) {
            self::markTestSkipped('The other database of a server run is the separate mirror; this test plants tables in it.');
        }

        $other = $this->otherDatabase();
        $config = $this->app['config'];
        \Justrau\Orrery\Orrery::auth(static fn (): bool => true);
        \Justrau\Orrery\Orrery::operateUsing(static fn (): bool => true);

        // (1) Q moves after the claim.
        [$uuid, $occurrence] = $this->failedJob();
        $this->killAt(RetryService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_CLAIM);
        $first = (string) $this->rowOrFail($uuid)->operation_id;

        $config->set('database.connections.testbench_moved', array_replace((array) $config->get('database.connections.'.TestEnvironment::PRIMARY), ['database' => $other]));
        $this->plantQueue('testbench_moved');
        $queue = (array) $config->get('queue.connections.database');
        $config->set('queue.connections.database.connection', 'testbench_moved');
        PackageSession::releaseAll();
        $this->moveDatabaseClocks(120);
        $this->runOnce();

        $operation = $this->operation($first);
        self::assertSame([Operations::CLAIMED, RetryHandler::TARGET_CHANGED], [$operation->state, $operation->error_code]);
        self::assertSame(0, (int) DB::connection('testbench_moved')->table(Fence::TABLE)->count(), 'Nothing is written in the moved queue database.');
        $health = $this->getJson('/orrery/api/health')->json('awaiting_resolution');
        self::assertContains($first, array_column($health['operations'], 'id'));
        self::assertSame(RetryHandler::TARGET_CHANGED, $health['operations'][array_search($first, array_column($health['operations'], 'id'), true)]['reason']);
        $refused = $this->postJson('/orrery/api/operations/'.$first.'/resolve');
        self::assertSame([409, 'target_changed'], [$refused->status(), $refused->json('error')], 'The resolve refuses while the target differs.');

        $config->set('queue.connections.database', $queue);
        PackageSession::releaseAll();
        self::assertNotContains($first, array_column((array) $this->getJson('/orrery/api/health')->json('awaiting_resolution.operations'), 'id'), 'A restored configuration ends target_changed at once.');
        $this->moveDatabaseClocks(4000);
        $this->finishByRunnerOrOperator($first);
        self::assertSame(Operations::ABORTED, $this->operation($first)->state);
        self::assertSame(0, (int) DB::connection('testbench_moved')->table('jobs')->count());

        // (2) The failed-record database moves while a restore is pending.
        [$uuid2, $occurrence2] = $this->failedJob();
        $record = (array) DB::connection(self::FAILED_CONNECTION)->table('failed_jobs')->where('uuid', $uuid2)->sole();
        $this->killAt(RetryService::AFTER_RECORD_DELETE);
        $this->expectKilled(fn () => $this->retry($uuid2, $occurrence2), RetryService::AFTER_RECORD_DELETE);
        $second = (string) $this->rowOrFail($uuid2)->operation_id;
        $this->app->make(Operations::class)->markAborting($this->mirror(), $second, 'cancelled');

        $failed = (array) $config->get('database.connections.'.self::FAILED_CONNECTION);
        $config->set('database.connections.'.self::FAILED_CONNECTION.'.database', $other);
        PackageSession::releaseAll();
        DB::purge(self::FAILED_CONNECTION);
        DB::connection(self::FAILED_CONNECTION)->table('failed_jobs')->insert($record);
        $this->moveDatabaseClocks(8000);
        $this->runOnce();

        $operation = $this->operation($second);
        self::assertSame([Operations::ABORTING, RetryHandler::TARGET_CHANGED], [$operation->state, $operation->error_code]);
        self::assertSame(1, (int) DB::connection(self::FAILED_CONNECTION)->table('failed_jobs')->where('uuid', $uuid2)->count(), 'The planted record stays.');
        self::assertSame(0, (int) DB::connection(self::FAILED_CONNECTION)->table(Fence::TABLE)->count(), 'No fence row in the moved database.');

        $config->set('database.connections.'.self::FAILED_CONNECTION, $failed);
        PackageSession::releaseAll();
        DB::purge(self::FAILED_CONNECTION);
        $this->moveDatabaseClocks(12000);
        $this->finishByRunnerOrOperator($second);
        self::assertSame(Operations::ABORTED, $this->operation($second)->state);
        self::assertSame(1, $this->failedRecords($uuid2), 'Restored in the original target.');

        // (3) Q alone moves while a restore is pending: the restore needs only the record fingerprint.
        [$uuid3, $occurrence3] = $this->failedJob();
        $this->killAt(RetryService::AFTER_RECORD_DELETE);
        $this->expectKilled(fn () => $this->retry($uuid3, $occurrence3), RetryService::AFTER_RECORD_DELETE);
        $third = (string) $this->rowOrFail($uuid3)->operation_id;
        $this->app->make(Operations::class)->markAborting($this->mirror(), $third, 'cancelled');
        $config->set('queue.connections.database.connection', 'testbench_moved');
        PackageSession::releaseAll();
        $this->moveDatabaseClocks(16000);
        $this->finishByRunnerOrOperator($third);

        self::assertSame(Operations::ABORTED, $this->operation($third)->state);
        self::assertSame(1, $this->failedRecords($uuid3), 'Restored once.');
        $config->set('queue.connections.database', $queue);
    }

    /**
     * Rejects: a fingerprint without the effective endpoint (Postgres): only connect_via_database of the
     * failed-record connection changes between the claim and the recovery, with the same id and uuid planted in the
     * new target; the restore leaves it untouched and reports target_changed, and a retry is refused before its claim.
     */
    public function test_connect_via_drift_blocks_every_mutation(): void
    {
        if (DB::connection(TestEnvironment::PRIMARY)->getDriverName() !== 'pgsql' || $this->testEnvironment()->separate()) {
            self::markTestSkipped('connect_via_database is a Postgres key; the test plants a record in the _mirror database of a same-mode run.');
        }

        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        [$other, $otherOccurrence] = $this->failedJob();
        $record = (array) DB::connection(self::FAILED_CONNECTION)->table('failed_jobs')->where('uuid', $uuid)->sole();
        $this->killAt(RetryService::AFTER_RECORD_DELETE);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_RECORD_DELETE);
        $id = (string) $this->rowOrFail($uuid)->operation_id;
        $this->app->make(Operations::class)->markAborting($this->mirror(), $id, 'cancelled');

        $config = $this->app['config'];
        $config->set('database.connections.'.self::FAILED_CONNECTION.'.connect_via_database', $this->otherDatabase());
        PackageSession::releaseAll();
        DB::purge(self::FAILED_CONNECTION);
        $this->plantFailedTable(self::FAILED_CONNECTION);
        DB::connection(self::FAILED_CONNECTION)->table('failed_jobs')->insert($record);
        $this->moveDatabaseClocks(4000);
        $this->runOnce();

        self::assertSame([Operations::ABORTING, RetryHandler::TARGET_CHANGED], [$this->operation($id)->state, $this->operation($id)->error_code]);
        self::assertSame(1, (int) DB::connection(self::FAILED_CONNECTION)->table('failed_jobs')->where('uuid', $uuid)->count());
        self::assertOutcome(RetryResult::REFUSED, 'target_changed', $this->retry($other, $otherOccurrence));
    }

    /**
     * Rejects: an error text or a binding that leaves the service: a QueryException with the payload as a binding
     * inside the publication gives publish_failed; the ledger holds SafeError::summary('retry:publish', …), the
     * reported exception is a WatchedOperationFailed without a previous, and neither carries the payload.
     */
    public function test_errors_leave_the_service_as_codes(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        Exceptions::fake();
        $schema = DB::connection(TestEnvironment::PRIMARY)->getSchemaBuilder();
        Hooks::$callback = static function (string $point) use ($schema): void {
            if ($point === Publication::BEFORE_FENCE_INSERT) {
                $schema->rename('jobs', 'jobs_away');
            }
        };

        try {
            $outcome = $this->retry($uuid, $occurrence);
        } finally {
            Hooks::$callback = null;
            $schema->rename('jobs_away', 'jobs');
        }

        self::assertOutcome(RetryResult::FAILED, 'publish_failed', $outcome);
        self::assertNull($outcome->message);
        $operation = $this->operation((string) $outcome->operationId);
        self::assertSame([Operations::ABORTED, 'publish_failed'], [$operation->state, $operation->error_code]);
        self::assertStringStartsWith("Orrery's watched operation [retry:publish] failed:", (string) $operation->error);
        self::assertStringNotContainsString($uuid, (string) $operation->error, 'No payload binding in the ledger.');
        self::assertStringNotContainsString('RetryableJob', (string) $operation->error);

        Exceptions::assertReported(static function (WatchedOperationFailed $reported) use ($uuid): bool {
            return $reported->operation === 'retry:publish' && $reported->getPrevious() === null && ! str_contains($reported->getMessage(), $uuid);
        });
        self::assertSame(1, $this->failedRecords($uuid), 'The record stays: nothing was published.');
    }

    /**
     * Rejects: `forget(uuid)` and uuid-only deletes: every delete on a failed table during an in_tx retry, a
     * mode-before retry and a mode-before restore binds `id` and `uuid`; and the new protocol's sources delete a failed
     * record only through SqlFailedRecords::delete().
     */
    public function test_every_failed_record_delete_names_id_and_uuid(): void
    {
        $deletes = [];
        PackageSession::recordForTesting(static function (string $kind, string $sql) use (&$deletes): void {
            if ($kind === PackageSession::STATEMENT && preg_match(self::FAILED_DELETE, AutocommitBounds::strip($sql)) === 1) {
                $deletes[] = $sql;
            }
        });
        $this->app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, static function (\Illuminate\Database\Events\QueryExecuted $event) use (&$deletes): void {
            if (! PackageSession::isPackageSession($event->connectionName) && preg_match(self::FAILED_DELETE, $event->sql) === 1) {
                $deletes[] = $event->sql;
            }
        });

        [$uuid, $occurrence] = $this->failedJob();
        self::assertOutcome(RetryResult::ACCEPTED, null, $this->retry($uuid, $occurrence));
        $this->work();

        if (! $this->testEnvironment()->inMemory()) {
            $this->useSplitFailedConnection();
            [$uuid, $occurrence] = $this->failedJob();
            self::assertOutcome(RetryResult::ACCEPTED, null, $this->retry($uuid, $occurrence));
            $this->work();

            // A mode-before restore: the record is deleted, the operation aborts, the runner restores it.
            [$uuid, $occurrence] = $this->failedJob();
            $this->killAt(RetryService::AFTER_RECORD_DELETE);
            $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_RECORD_DELETE);
            $id = (string) $this->rowOrFail($uuid)->operation_id;
            $this->app->make(Operations::class)->markAborting($this->mirror(), $id, 'cancelled');
            $this->moveDatabaseClocks(4000);
            $this->finishByRunnerOrOperator($id);
            self::assertSame(Operations::ABORTED, $this->operation($id)->state);
            self::assertSame(1, $this->failedRecords($uuid), 'The record is restored.');
        }

        self::assertNotSame([], $deletes);

        foreach ($deletes as $sql) {
            $normalised = strtolower(str_replace(['"', '`'], '', $sql));
            // \bid: `uuid = ?` alone must not pass for `id = ?`.
            self::assertMatchesRegularExpression('/\bid = \?/', $normalised, $sql);
            self::assertStringContainsString('uuid = ?', $normalised, $sql);
        }

        self::assertSame([], self::failedTableDeletesOutsideTheExactDelete(), 'a delete in src/ that may reach a failed-job table other than SqlFailedRecords::delete()');
    }

    /**
     * The statement that ends at $offset: back to the previous `;`, `{` or
     * `}` outside parentheses, so a closure inside the chain stays part of
     * it. Whitespace runs become one space.
     */
    private static function statementBefore(string $code, int $offset): string
    {
        $depth = 0;

        for ($i = $offset - 1; $i >= 0; $i--) {
            $character = $code[$i];

            if ($character === ')') {
                $depth++;
            } elseif ($character === '(') {
                $depth--;
            } elseif ($depth <= 0 && ($character === ';' || $character === '{' || $character === '}')) {
                break;
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', substr($code, $i + 1, $offset - $i - 1)));
    }

    /**
     * The source scan of test_every_failed_record_delete_names_id_and_uuid
     * ("a source scan finds no other delete on a failed table
     * in src/"). Every delete statement of src/ (a `->delete(` call, with its
     * chain back to the start of the statement, and every SQL `delete from`
     * text) must be one of:
     * - `$records->delete(`: SqlFailedRecords::delete(), the exact delete;
     * - SqlFailedRecords::delete() itself, which names `id` and `uuid`;
     * - a delete on a named package table (a `::TABLE` or `::SETTINGS`
     *   constant, a model of the package, or an `orrery_` literal);
     * - one of the package-table deletes through a variable listed in
     *   VARIABLE_TABLE_DELETES, each with the reason it never names a failed
     *   table.
     *
     * @return list<string> the deletes that are none of these
     */
    private static function failedTableDeletesOutsideTheExactDelete(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        $seen = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());
            // Comments name deletes in prose; only code counts.
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

            if (preg_match('/[\'"]\s*delete\s+from\b/i', $code) === 1) {
                $found[] = $relative.': a raw `delete from` statement';
            }

            if (preg_match_all('/->delete\(/', $code, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as [, $offset]) {
                $statement = self::statementBefore($code, $offset).'->delete(';
                $allowed = self::VARIABLE_TABLE_DELETES[$relative] ?? [];
                $listed = array_filter($allowed, static fn (string $pattern): bool => preg_match($pattern, $statement) === 1);

                if ($listed !== []) {
                    foreach ($listed as $pattern) {
                        $seen[$relative.' '.$pattern] = true;
                    }

                    continue;
                }

                if (preg_match('/\$records->delete\($/', $statement) === 1) {
                    continue;
                }

                if ($relative === 'src/Failed/SqlFailedRecords.php') {
                    if (preg_match("/->table\(\\\$this->table\)->where\('id', \\\$id\)->where\('uuid', \\\$uuid\)->delete\($/", $statement) !== 1) {
                        $found[] = $relative.': a delete that does not name id and uuid: '.$statement;
                    }

                    continue;
                }

                if (preg_match('/->table\(\s*(?:self|static|[A-Z]\w*)::(?:TABLE|SETTINGS)\b|->table\(\s*[\'"]orrery_|\b(?:FailedTag|Job|JobTag|MetricCounter|MetricSnapshot|MonitoredTag|Setting|Signal|WorkerHeartbeat)::query\(\)/', $statement) === 1) {
                    continue;
                }

                $found[] = $relative.': '.$statement;
            }
        }

        foreach (self::VARIABLE_TABLE_DELETES as $relative => $patterns) {
            foreach ($patterns as $pattern) {
                if (! isset($seen[$relative.' '.$pattern])) {
                    $found[] = "{$relative}: the listed delete {$pattern} no longer exists";
                }
            }
        }

        return $found;
    }

    /**
     * Rejects: a restore decided by the unique uuid after a lost acknowledgement: the restore's COMMIT
     * acknowledgement is lost; the retry is uncertain; the runner reads the restore row, carries its id to the row and
     * ends aborted, never restore_conflict.
     */
    public function test_lost_restore_acknowledgement_is_replayed(): void
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $sessions = $this->app->make(PackageSession::class);
        $config = (array) $this->app['config']->get('database.connections.'.self::FAILED_CONNECTION);
        $queue = $this->queueClockName();
        $shift = fn () => $this->shiftClock($queue, 120);
        Hooks::$callback = static function (string $point) use ($sessions, $config, $shift): void {
            if ($point === RetryService::AFTER_RECORD_DELETE) {
                $shift();
            }

            if ($point === RetryHandler::BEFORE_RESTORE) {
                $session = $sessions->forAction(self::FAILED_CONNECTION);
                $session->setPdo(SessionPdo::for($session, LostAckCommitPdo::forConfig($config)));
            }
        };

        $outcome = $this->retry($uuid, $occurrence);
        Hooks::$callback = null;

        self::assertSame(RetryResult::UNCERTAIN, $outcome->result);
        $id = (string) $outcome->operationId;
        self::assertSame(Operations::ABORTING, $this->operation($id)->state);
        self::assertSame(1, $this->failedRecords($uuid), 'The restore committed.');

        $this->moveDatabaseClocks(4000);
        $this->runOnce();

        $operation = $this->operation($id);
        self::assertSame(Operations::ABORTED, $operation->state);
        self::assertNotSame(RetryHandler::RESTORE_CONFLICT, $operation->error_code);
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame((int) $this->failedRecordId($uuid), (int) $this->rowOrFail($uuid)->provider_id);
    }

    /**
     * Rejects: a restore whose id is lost when the mirror fails after it: the mirror is down right after the restore's
     * COMMIT; the operation stays aborting; after the outage the runner carries the restore row's id to the row.
     */
    public function test_mirror_outage_after_the_restore_is_replayed(): void
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $queue = $this->queueClockName();
        $shift = fn () => $this->shiftClock($queue, 120);
        $outage = null;
        $start = function () use (&$outage): void {
            $outage = $this->mirrorOutage();
        };
        Hooks::$callback = static function (string $point) use ($shift, $start): void {
            if ($point === RetryService::AFTER_RECORD_DELETE) {
                $shift();
            }

            if ($point === RetryHandler::AFTER_RESTORE) {
                $start();
            }
        };

        $outcome = $this->retry($uuid, $occurrence);
        Hooks::$callback = null;
        self::assertNotNull($outage);
        $outage->end();

        $id = (string) $outcome->operationId;
        self::assertSame(Operations::ABORTING, $this->operation($id)->state);
        self::assertSame(1, $this->failedRecords($uuid));

        $this->moveDatabaseClocks(4000);
        $this->runOnce();

        self::assertSame(Operations::ABORTED, $this->operation($id)->state);
        self::assertSame(1, $this->failedRecords($uuid), 'One restored record.');
        self::assertSame((int) $this->failedRecordId($uuid), (int) $this->rowOrFail($uuid)->provider_id);
    }

    private function otherDatabase(): string
    {
        $environment = $this->testEnvironment();

        if (! $environment->isServer()) {
            return $this->sqliteFiles[] = SqliteFiles::create();
        }

        return (string) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.database').'_mirror';
    }

    /**
     * The queue, failed and fence tables on another connection's database (dropped afterwards).
     */
    private function plantQueue(string $connection): void
    {
        $schema = DB::connection($connection)->getSchemaBuilder();

        foreach (['jobs', 'failed_jobs', Fence::TABLE] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index('orrery_planted_jobs_queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $this->plantFailedTable($connection);
        \Justrau\Orrery\Database\Schema\PackageSchema::create($schema, [Fence::TABLE]);
        $this->beforeApplicationDestroyed(static function () use ($connection): void {
            $schema = DB::connection($connection)->getSchemaBuilder();

            foreach (['jobs', Fence::TABLE] as $table) {
                $schema->dropIfExists($table);
            }
        });
    }

    private function plantFailedTable(string $connection): void
    {
        $schema = DB::connection($connection)->getSchemaBuilder();
        $schema->dropIfExists('failed_jobs');
        $schema->create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique('orrery_planted_failed_uuid');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $this->beforeApplicationDestroyed(static function () use ($connection): void {
            DB::connection($connection)->getSchemaBuilder()->dropIfExists('failed_jobs');
        });
    }
}
