<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Failed;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Failed\CapturingUuidFailedJobProvider;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

/**
 * The id binding of the package's provider: the id of
 * the record that log() inserted, bound to the occurrence that this
 * failure's F1 write created. The first halves of the acceptance tests: the
 * binding and the adapter's verdict; other tests add the forget and --all
 * refusals and the retry refusals. Kind: new behaviour.
 */
#[FreshSchema]
final class CapturingProviderTest extends TestCase
{
    use CapturingFailer;
    use FailedProviderFixtures;

    /**
     * Rejects: a binding by a later uuid lookup, a binding of the wrong record when other records exist, and a
     * target that is not the failed-record table's current one.
     */
    public function test_failure_binds_the_id_of_its_own_insert(): void
    {
        $this->useCapturingFailer();
        $this->seedFailedRecords(7);
        $this->logFailures();

        Queue::push(new FailingJob);
        $this->work();

        $row = $this->mirrorRow($this->onlyUuid());
        $record = DB::table('failed_jobs')->where('uuid', $row->uuid)->sole();
        $records = FailedRecords::for($this->app, $this->app['config']);

        self::assertInstanceOf(SqlFailedRecords::class, $records);
        self::assertSame((int) $record->id, $row->provider_id === null ? null : (int) $row->provider_id);
        self::assertNotSame((int) $row->id, (int) $record->id, 'The fixture keeps the provider id apart from the mirror id.');
        self::assertSame($records->target(), $row->provider_target);
        self::assertSame(0, $this->slot()->count(), 'log() took the slot entry.');
    }

    /**
     * Rejects: a guard by cycle_gen or uuid instead of occurrence_id (a republished job with the same
     * generation would bind its new record to the old occurrence), and a uuid lookup.
     *
     * @param  bool  $withGeneration  whether the foreign republish keeps the payload's orreryGen
     */
    #[DataProvider('generations')]
    public function test_binding_names_the_occurrence_of_this_failure(bool $withGeneration): void
    {
        $this->useCapturingFailer();
        $this->logFailures();

        // F1: its binding fails.
        Queue::push(new FailingJob);
        Watcher::failOperationsForTesting([CapturingUuidFailedJobProvider::OPERATION]);
        $this->work();
        Watcher::failOperationsForTesting([]);

        $uuid = $this->onlyUuid();
        $first = $this->mirrorRow($uuid);
        self::assertNull($first->provider_id, 'The failed binding left provider_id null.');
        self::assertSame(FailedRecords::SQL, FailedRecords::for($this->app, $this->app['config'])->kind());

        // A foreign queue:retry (Laravel's own queue class): it deletes R1 and publishes the payload again.
        $r1 = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        DB::table('failed_jobs')->where('id', $r1->id)->delete();
        $payload = json_decode($r1->payload, true);
        $payload['attempts'] = 0;

        if (! $withGeneration) {
            unset($payload['orreryGen']);
        }

        $foreign = new LaravelDatabaseQueue(DB::connection(TestEnvironment::PRIMARY), 'jobs', 'default', 90);
        $foreign->setContainer($this->app);
        $foreign->setConnectionName('database');
        $foreign->pushRaw((string) json_encode($payload), 'default');

        // A stale slot entry of another occurrence of the uuid, with the same generation: a guard by
        // uuid or by cycle_gen would bind R2 to F1's row.
        $this->slot()->put($uuid, (int) $first->occurrence_id + 7);

        // A package worker reserves and fails it again: R2.
        $this->work();

        $r2 = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        $row = $this->mirrorRow($uuid);
        self::assertNotSame((int) $r1->id, (int) $r2->id);

        // F2's F1 write is only accounted: no new occurrence, so nothing binds R2 to F1's occurrence.
        self::assertSame((int) $first->occurrence_id, (int) $row->occurrence_id);
        self::assertNull($row->provider_id, 'R2 is never bound to the occurrence of F1.');
        self::assertSame(FailedRecords::SQL, FailedRecords::for($this->app, $this->app['config'])->kind());

        // --all and a forget of F1 refuse the unprovable occurrence, and R2 survives.
        $this->assertForgetRefusesUnprovable($uuid, (int) $first->occurrence_id, 1);
        self::assertSame(1, DB::table('failed_jobs')->where('id', $r2->id)->count(), 'R2 survives.');
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function generations(): array
    {
        return ['the same generation' => [true], 'no generation' => [false]];
    }

    /**
     * Rejects: a binding guarded by uuid alone, so an older slot entry fills a row of a newer occurrence.
     */
    public function test_older_slot_entry_never_binds_a_newer_occurrence(): void
    {
        $this->useCapturingFailer();
        $this->logFailures();
        Queue::push(new FailingJob);
        Watcher::failOperationsForTesting([CapturingUuidFailedJobProvider::OPERATION]);
        $this->work();
        Watcher::failOperationsForTesting([]);

        $uuid = $this->onlyUuid();
        $row = $this->mirrorRow($uuid);
        $record = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        DB::table('failed_jobs')->where('id', $record->id)->delete();

        // The slot names another occurrence of the uuid than the row's.
        $this->slot()->put($uuid, (int) $row->occurrence_id + 1000);
        $this->app['queue.failer']->log('database', 'default', $record->payload, new RuntimeException('again'));

        self::assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count(), 'The record is logged as Laravel logs it.');
        self::assertNull($this->mirrorRow($uuid)->provider_id);
    }

    /**
     * Rejects: trusting a connection-wide last insert id after another statement ran during the insert:
     * the binding is the statement's own receipt (SQLite, Postgres) or nothing
     * (MySQL with another statement in the window), never the record R2 of the nested insert.
     */
    #[DataProvider('nestedStatements')]
    public function test_nested_insert_during_log_binds_nothing(string $case): void
    {
        $driver = DB::connection(TestEnvironment::PRIMARY)->getDriverName();

        if ($case === 'raw' && $driver !== 'sqlite') {
            self::markTestSkipped('The raw-PDO replacement is the SQLite case of the spec; this run uses '.$driver.'.');
        }

        $this->useCapturingFailer();
        $this->seedFailedRecords(3);
        $this->logFailures();
        $nested = null;

        DB::listen(static function (QueryExecuted $event) use ($case, &$nested): void {
            if ($nested !== null || $event->connectionName !== TestEnvironment::PRIMARY
                || ! str_starts_with(strtolower($event->sql), 'insert') || ! str_contains($event->sql, 'failed_jobs')) {
                return;
            }

            $uuid = $event->bindings[0];
            $nested = 'running';
            $connection = $event->connection;
            $record = ['connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'nested', 'failed_at' => Carbon::now()];

            if ($case === 'replace') {
                // Laravel deletes R1 and logs another failure R2 of the same uuid.
                $connection->table('failed_jobs')->where('uuid', $uuid)->delete();
                $nested = (int) $connection->table('failed_jobs')->insertGetId(['uuid' => $uuid] + $record);
            } elseif ($case === 'unrelated') {
                $nested = (int) $connection->table('failed_jobs')->insertGetId(['uuid' => '11111111-0000-4000-8000-000000000001'] + $record);
            } else {
                $pdo = $connection->getPdo();
                $pdo->prepare('delete from '.$connection->getTablePrefix().'failed_jobs where uuid = ?')->execute([$uuid]);
                $pdo->prepare('insert into '.$connection->getTablePrefix()."failed_jobs (uuid, connection, queue, payload, exception, failed_at) values (?, 'database', 'default', '{}', 'raw', '2026-01-01 00:00:00')")->execute([$uuid]);
                $nested = (int) $pdo->lastInsertId();
            }
        });

        Queue::push(new FailingJob);
        $this->work();

        $uuid = $this->onlyUuid();
        $row = $this->mirrorRow($uuid);
        self::assertIsInt($nested);
        self::assertNotSame($nested, $row->provider_id === null ? null : (int) $row->provider_id, 'The nested record R2 is never bound.');

        if (in_array($driver, ['mysql', 'mariadb'], true) && $case !== 'raw') {
            self::assertNull($row->provider_id, 'MySQL: another statement ran during the insert, so nothing is bound.');
        } elseif ($case === 'unrelated') {
            self::assertSame((int) DB::table('failed_jobs')->where('uuid', $uuid)->value('id'), (int) $row->provider_id, 'The receipt of the outer insert.');
        } else {
            // The receipt names R1, which the nested statements deleted: no record has that id and uuid.
            self::assertNotNull($row->provider_id, 'SQLite and Postgres bind the receipt of their own statement.');
            self::assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->where('id', (int) $row->provider_id)->count());
            self::assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count(), 'R2 is intact.');
        }

        // --all and a forget of F1 leave R2 intact: R2 is never selected through F1's row.
        $this->ensureFence();
        $report = $this->app->make(ForgetAllRecords::class)->run();
        self::assertSame(1, DB::table('failed_jobs')->where('id', $nested)->count(), '--all leaves R2 intact.');
        $result = $this->app->make(ForgetService::class)->forget($uuid, (int) $row->occurrence_id, null);

        if ($row->provider_id === null) {
            self::assertSame(['refused', 'occurrence_unprovable'], [$result->result, $result->code]);
            // The unbound row's record, the seeded records and R2 (no canonical uuid) are all skipped.
            self::assertSame([0, 0], [$report['deleted'], $report['provider_only']], '--all deletes nothing.');
        } elseif ($case === 'unrelated') {
            // F1's own record is bound correctly: --all forgets it, so the forget finds no row.
            self::assertSame(1, $report['deleted']);
            self::assertSame(['not_eligible', 'occurrence_mismatch'], [$result->result, $result->code]);
        } else {
            // The receipt names R1, which is gone: the forget removes only the mirror row.
            self::assertSame(0, $report['deleted']);
            self::assertSame(ForgetResult::DELETED, $result->result, (string) $result->code);
        }

        self::assertSame(1, DB::table('failed_jobs')->where('id', $nested)->count(), 'The forget leaves R2 intact.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nestedStatements(): array
    {
        return ['replace R1 by R2' => ['replace'], 'an unrelated insert' => ['unrelated'], 'raw PDO replacement' => ['raw']];
    }

    /**
     * Rejects: a binding before the commit of the host transaction (a rolled-back insert would leave an id that no
     * record has), a binding that a rollback does not count, and an extra statement inside a Postgres transaction.
     */
    public function test_binding_inside_a_host_transaction_waits_for_the_commit(): void
    {
        $this->useCapturingFailer();
        $this->logFailures();
        $driver = DB::connection(TestEnvironment::PRIMARY)->getDriverName();
        [$uuid, $payload, $occurrence] = $this->unboundFailure();

        // Rolled back: no record, nothing bound, counted.
        $this->slot()->put($uuid, $occurrence);
        $before = Degradation::record()['rollback_unverified'] ?? 0;
        DB::connection(TestEnvironment::PRIMARY)->beginTransaction();
        $this->app['queue.failer']->log('database', 'default', $payload, new RuntimeException('in a transaction'));
        DB::connection(TestEnvironment::PRIMARY)->rollBack();

        self::assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
        self::assertNull($this->mirrorRow($uuid)->provider_id);

        if ($driver !== 'pgsql') {
            self::assertGreaterThan($before, Degradation::record()['rollback_unverified'] ?? 0, 'The dropped binding is counted.');
        }

        // Committed: bound at the commit (MySQL, SQLite); Postgres binds nothing inside a transaction.
        $this->slot()->put($uuid, $occurrence);
        $statements = [];
        DB::listen(static function (QueryExecuted $event) use (&$statements): void {
            if ($event->connectionName === TestEnvironment::PRIMARY) {
                $statements[] = $event->sql;
            }
        });
        DB::connection(TestEnvironment::PRIMARY)->beginTransaction();
        $this->app['queue.failer']->log('database', 'default', $payload, new RuntimeException('in a transaction'));
        $inside = $statements;

        if ($driver !== 'pgsql' && ! $this->testEnvironment()->inMemory()) {
            self::assertNull($this->mirrorRow($uuid)->provider_id, 'Nothing is bound before the commit.');
        }

        DB::connection(TestEnvironment::PRIMARY)->commit();
        $record = DB::table('failed_jobs')->where('uuid', $uuid)->sole();

        if ($driver === 'pgsql') {
            self::assertCount(1, $inside, 'Postgres: Laravel\'s insert alone inside the host transaction.');
            self::assertNull($this->mirrorRow($uuid)->provider_id);
        } else {
            self::assertSame((int) $record->id, (int) $this->mirrorRow($uuid)->provider_id);
        }
    }

    /**
     * Rejects: filling a missing id later (a lookup by uuid), and a health count that reads 0 or omits the cap.
     */
    public function test_failed_binding_is_refused(): void
    {
        $this->useCapturingFailer();
        $this->logFailures();

        // The binding dropped (the watcher fails it), and a log() without a slot entry (no new occurrence).
        Queue::push(new FailingJob);
        Watcher::failOperationsForTesting([CapturingUuidFailedJobProvider::OPERATION]);
        $this->work();
        Watcher::failOperationsForTesting([]);
        [$uuid, $payload] = $this->unboundFailure();
        $this->app['queue.failer']->log('database', 'default', $payload, new RuntimeException('no slot entry'));

        foreach ($this->mirror()->table('orrery_jobs')->get() as $row) {
            self::assertNull($row->provider_id, "{$row->uuid} stays unbound.");
        }

        self::assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count());

        Orrery::auth(static fn (): bool => true);
        $this->app['config']->set('orrery.dashboard.count_cap', 5);
        $failures = $this->getJson('/orrery/api/health')->assertOk()->json('failed_records');
        self::assertSame('sql', $failures['adapter']);
        self::assertSame(['count' => 2, 'at_least' => false], $failures['unprovable_failures']);

        $this->app['config']->set('orrery.dashboard.count_cap', 1);
        $failures = $this->getJson('/orrery/api/health')->assertOk()->json('failed_records');
        self::assertSame(['count' => 1, 'at_least' => true], $failures['unprovable_failures']);

        // The third cause: an insert rolled back inside a host transaction.
        [$rolledBack, $rolledPayload, $rolledOccurrence] = $this->unboundFailure();
        $this->slot()->put($rolledBack, $rolledOccurrence);
        DB::connection(TestEnvironment::PRIMARY)->beginTransaction();
        $this->app['queue.failer']->log('database', 'default', $rolledPayload, new RuntimeException('rolled back'));
        DB::connection(TestEnvironment::PRIMARY)->rollBack();
        self::assertNull($this->mirrorRow($rolledBack)->provider_id);

        // Every unbound occurrence is refused by a forget and skipped by --all; nothing is deleted.
        $records = DB::table('failed_jobs')->count();
        $report = $this->app->make(ForgetAllRecords::class)->run();
        self::assertSame(['occurrence_unprovable' => 2], $report['skipped'], 'The dropped binding and the log without a slot entry.');

        foreach ($this->mirror()->table('orrery_jobs')->get() as $row) {
            $result = $this->app->make(ForgetService::class)->forget((string) $row->uuid, (int) $row->occurrence_id, null);
            self::assertSame(['refused', 'occurrence_unprovable'], [$result->result, $result->code], (string) $row->uuid);
            self::assertSame(ForgetService::UNPROVABLE_MESSAGE, $result->message);
        }

        self::assertSame($records, DB::table('failed_jobs')->count(), 'Nothing is deleted.');
    }

    /**
     * --all and a forget of an unbound occurrence: both refuse it (occurrence_unprovable), and $records
     * records of its uuid stay.
     */
    private function assertForgetRefusesUnprovable(string $uuid, int $occurrence, int $records): void
    {
        $report = $this->app->make(ForgetAllRecords::class)->run();
        self::assertSame(1, $report['skipped']['occurrence_unprovable'] ?? 0);
        self::assertSame(0, $report['provider_only']);
        $result = $this->app->make(ForgetService::class)->forget($uuid, $occurrence, null);
        self::assertSame(['refused', 'occurrence_unprovable'], [$result->result, $result->code]);
        self::assertSame($records, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }

    /**
     * The fence table in the failed-record database (the install writes it in production).
     */
    private function ensureFence(): void
    {
        $schema = DB::connection(TestEnvironment::PRIMARY)->getSchemaBuilder();

        if ($schema->hasTable(Fence::TABLE)) {
            return;
        }

        PackageSchema::create($schema, [Fence::TABLE]);
        $this->beforeApplicationDestroyed(fn () => $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->dropIfExists(Fence::TABLE));
    }

    /**
     * Rejects: one statement counter per connection (a nested log() from a host listener resets and unsets the
     * outer one, so the outer read throws out of log() after its insert committed), and a nested
     * log() that hides its statements from the outer one on MySQL.
     */
    public function test_nested_capturing_log_neither_throws_nor_binds_the_outer(): void
    {
        $this->useCapturingFailer();
        $driver = DB::connection(TestEnvironment::PRIMARY)->getDriverName();
        $u = '0f5a3c1e-6b1d-4e57-9a3e-00000000000a';
        $v = '0f5a3c1e-6b1d-4e57-9a3e-00000000000b';
        $this->failedRow($u, 11);
        $this->failedRow($v, 12);
        $this->slot()->put($u, 11);
        $this->slot()->put($v, 12);
        $nested = false;

        DB::listen(function (QueryExecuted $event) use (&$nested, $u, $v): void {
            if ($nested || $event->connectionName !== TestEnvironment::PRIMARY || ! str_contains($event->sql, 'failed_jobs')
                || ! str_starts_with(strtolower($event->sql), 'insert') || $event->bindings[0] !== $u) {
                return;
            }

            $nested = true;
            $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => $v]), new RuntimeException('nested'));
        });

        $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => $u]), new RuntimeException('outer'));

        self::assertTrue($nested);
        $uRecord = (int) DB::table('failed_jobs')->where('uuid', $u)->value('id');
        $vRecord = (int) DB::table('failed_jobs')->where('uuid', $v)->value('id');
        self::assertSame($vRecord, (int) $this->mirrorRow($v)->provider_id, 'V is bound to V.');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            self::assertNull($this->mirrorRow($u)->provider_id, 'MySQL: V ran inside U\'s window, so U binds nothing.');
        } else {
            self::assertSame($uRecord, (int) $this->mirrorRow($u)->provider_id, 'U is bound to its own receipt.');
        }
    }

    /**
     * Rejects: a provider_target computed from the current configuration instead of the connection object that
     * performed the insert: a cached multi-writer connection whose writers changed in the configuration
     * must bind a target that differs from the current one, so the first action refuses with target_changed.
     */
    public function test_cached_multi_writer_connection_after_a_host_change_is_refused(): void
    {
        $config = $this->app['config'];
        $primary = $config->get('database.connections.'.TestEnvironment::PRIMARY);

        if (($primary['driver'] ?? null) === 'sqlite') {
            self::markTestSkipped('A SQLite endpoint has no host; this run uses SQLite.');
        }

        // Two writers that both reach the test server; Laravel picks one when it resolves the connection.
        $config->set('database.connections.multi', array_merge($primary, ['write' => [['host' => $primary['host']], ['host' => $primary['host']]]]));
        $this->useCapturingFailer('failed_jobs', 'multi');
        $u = '0f5a3c1e-6b1d-4e57-9a3e-000000000010';
        $this->failedRow($u, 51);
        DB::connection('multi')->getPdo();

        // The configuration moves to other writers; the cached connection object stays.
        $config->set('database.connections.multi.write', [['host' => '10.255.0.1'], ['host' => '10.255.0.2']]);
        $this->slot()->put($u, 51);
        $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => $u]), new RuntimeException('cached writer'));

        $row = $this->mirrorRow($u);
        $current = new SqlFailedRecords($this->app->make(PackageSession::class), 'multi', 'failed_jobs');

        self::assertSame((int) DB::connection('multi')->table('failed_jobs')->where('uuid', $u)->value('id'), (int) $row->provider_id);
        self::assertNotNull($row->provider_target);
        self::assertNotSame($current->target(), $row->provider_target, 'The bound target is the cached writer\'s, not the current configuration\'s.');
    }

    /**
     * Rejects: a binding inside a raw PDO transaction that Laravel does not track (level 0): the deferral never
     * sees its rollback, so the id of a rolled-back insert would be bound.
     */
    public function test_raw_pdo_transaction_binds_nothing(): void
    {
        $this->useCapturingFailer();
        $u = '0f5a3c1e-6b1d-4e57-9a3e-00000000000c';
        $this->failedRow($u, 21);
        $this->slot()->put($u, 21);
        $pdo = DB::connection(TestEnvironment::PRIMARY)->getPdo();
        $pdo->beginTransaction();

        try {
            $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => $u]), new RuntimeException('raw transaction'));
        } finally {
            $pdo->rollBack();
        }

        self::assertSame(0, DB::table('failed_jobs')->where('uuid', $u)->count(), 'The raw transaction rolled the insert back.');
        self::assertNull($this->mirrorRow($u)->provider_id);
    }

    /**
     * Rejects: a binding in pretend mode, where no record exists and insertGetId() gives no real id.
     */
    public function test_pretend_mode_binds_nothing(): void
    {
        $this->useCapturingFailer();
        $u = '0f5a3c1e-6b1d-4e57-9a3e-00000000000d';
        $this->failedRow($u, 31);
        $this->slot()->put($u, 31);
        // A real insert first, so the connection's last insert id is a stale, real id.
        $this->seedFailedRecords(1);

        $pretended = DB::connection(TestEnvironment::PRIMARY)->pretend(function () use ($u): void {
            $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => $u]), new RuntimeException('pretend'));
        });

        self::assertCount(1, $pretended, 'Laravel\'s insert alone is pretended.');
        self::assertStringNotContainsString('returning', strtolower($pretended[0]['query']));
        self::assertSame(0, DB::table('failed_jobs')->where('uuid', $u)->count());
        self::assertNull($this->mirrorRow($u)->provider_id, 'Not even the stale id of the earlier insert.');
    }

    /**
     * Rejects: an F1 that starts a new occurrence but keeps the old occurrence's provider_target.
     */
    public function test_new_occurrence_clears_the_provider_target(): void
    {
        $this->useCapturingFailer();
        $this->logFailures();
        Queue::push(new FailingJob);
        $this->work();

        $uuid = $this->onlyUuid();
        $first = $this->mirrorRow($uuid);
        self::assertNotNull($first->provider_target);

        // Laravel's own queue:retry through the package's queue; the second failure's binding fails.
        $this->artisan('queue:retry', ['id' => [$uuid]])->assertSuccessful();
        Watcher::failOperationsForTesting([CapturingUuidFailedJobProvider::OPERATION]);
        $this->work();
        Watcher::failOperationsForTesting([]);

        $second = $this->mirrorRow($uuid);
        self::assertNotSame((int) $first->occurrence_id, (int) $second->occurrence_id, 'The second failure is a new occurrence.');
        self::assertNull($second->provider_id);
        self::assertNull($second->provider_target, 'The target went with the id.');
    }

    /**
     * Rejects: a binding that runs while the breaker is open, and one that a later failure or the closed breaker
     * fills in afterwards (a lookup).
     */
    public function test_open_breaker_binds_nothing_and_nothing_fills_it_later(): void
    {
        $this->useCapturingFailer();
        $u = '0f5a3c1e-6b1d-4e57-9a3e-00000000000e';
        $this->failedRow($u, 41);
        $this->slot()->put($u, 41);
        Watcher::instance()->run('test:lost', static function (): never {
            throw new SiblingFileUnavailable('test.sqlite', 'the test session is lost');
        });
        self::assertTrue(Watcher::instance()->breakerOpen());

        $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => $u]), new RuntimeException('breaker open'));
        self::assertSame(1, DB::table('failed_jobs')->where('uuid', $u)->count(), 'The record is logged as Laravel logs it.');

        Clock::advanceMonotonicForTesting(31.0);
        self::assertFalse(Watcher::instance()->breakerOpen());
        $this->app['queue.failer']->log('database', 'default', (string) json_encode(['uuid' => '0f5a3c1e-6b1d-4e57-9a3e-00000000000f']), new RuntimeException('later'));

        self::assertNull($this->mirrorRow($u)->provider_id, 'Nothing fills the id later.');
    }

    /**
     * A failed occurrence whose record is gone and whose row is unbound: its uuid, its payload and its
     * occurrence.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function unboundFailure(): array
    {
        Queue::push(new FailingJob);
        Watcher::failOperationsForTesting([CapturingUuidFailedJobProvider::OPERATION]);
        $this->work();
        Watcher::failOperationsForTesting([]);

        $uuids = $this->mirror()->table('orrery_jobs')->orderByDesc('id')->pluck('uuid')->all();
        $uuid = (string) $uuids[0];
        $record = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        DB::table('failed_jobs')->where('id', $record->id)->delete();

        return [$uuid, (string) $record->payload, (int) $this->mirrorRow($uuid)->occurrence_id];
    }

    /**
     * A failed mirror row of $uuid with the occurrence $occurrence and no bound record.
     */
    private function failedRow(string $uuid, int $occurrence): void
    {
        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => $uuid, 'cycle' => 1, 'occurrence_id' => $occurrence, 'connection' => 'database', 'queue' => 'default',
            'status' => 'failed', 'payload' => (string) json_encode(['uuid' => $uuid]), 'payload_bytes' => 2,
            'pushed_at' => '1790000000.000000', 'failed_at' => '1790000001.000000', 'updated_at' => '1790000001.000000',
        ]);
    }

    private function onlyUuid(): string
    {
        $row = $this->mirror()->table('orrery_jobs')->sole();
        self::assertInstanceOf(stdClass::class, $row);

        return (string) $row->uuid;
    }
}
