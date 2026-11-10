<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider as LaravelUuidProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PooledSessionRefused;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\RecordsFailedRecordDeletes;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * orrery:forget --all:
 * ForgetAllRecords in one process on every engine. The command switch is
 * the test's. Kind: new behaviour.
 */
#[FreshSchema]
final class ForgetAllTest extends TestCase
{
    use ForgetFixtures;
    use PooledSchemaDrift;
    use RecordsFailedRecordDeletes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpForgets();
        // Every delete on a failed-job table of these tests names id and uuid.
        $this->recordFailedRecordDeletes();
    }

    /**
     * Rejects: a delete that does not name exactly id and uuid (the recorder, so also a uuid-keyed forget()
     * loop), a provider-only branch that deletes a record without a canonical uuid, and a walk that leaves the
     * mirror rows or their dependents.
     */
    public function test_forget_all_deletes_mirrored_and_provider_only_records_exactly(): void
    {
        [$first, , $firstRecord] = $this->failedOccurrence();
        [$second, , $secondRecord] = $this->failedOccurrence();
        $this->mirror()->table('orrery_job_tags')->insert(['tag' => 't', 'job_uuid' => $first, 'pushed_at' => '1.000000']);
        $providerOnly = [$this->providerOnly(), $this->providerOnly()];
        $legacy = (int) DB::table('failed_jobs')->insertGetId(['uuid' => 'legacy-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => Carbon::now()]);

        $report = $this->forgetAll()->run();

        self::assertSame(ForgetAllRecords::DONE, $report['result']);
        self::assertSame(2, $report['deleted']);
        self::assertSame(2, $report['provider_only']);
        self::assertSame(['occurrence_unprovable' => 1], $report['skipped']);
        self::assertNull($this->record($firstRecord, $first));
        self::assertNull($this->record($secondRecord, $second));
        self::assertNull($this->row($first));
        self::assertNull($this->row($second));
        self::assertSame(0, $this->mirror()->table('orrery_job_tags')->count());

        foreach ($providerOnly as [$id, $uuid]) {
            self::assertNull($this->record($id, $uuid));
        }

        self::assertSame(1, DB::table('failed_jobs')->where('id', $legacy)->count(), 'A record without a canonical uuid stays.');
        self::assertSame(4, $this->assertFailedDeletesNameIdAndUuid(), 'One exact delete per record.');
        self::assertSame(0, $this->mirror()->table(Operations::TABLE)->count(), 'No operation per record.');
    }

    /**
     * Rejects: a separate, weaker bulk predicate, and a mirrored record that fails the predicate
     * and falls through to the provider-only branch.
     */
    #[DataProvider('predicateCases')]
    public function test_forget_all_applies_the_single_action_predicate(string $case, string $reason): void
    {
        [$uuid, , $recordId] = $this->failedOccurrence();
        $survivor = $recordId;

        match ($case) {
            'refailure' => $survivor = $this->replaceWithUnobservedRefailure($uuid, $recordId),
            'unbound' => $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['provider_id' => null]),
            'held' => $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['operation_id' => str_repeat('c', 32)]),
            default => $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'completed']),
        };

        $report = $this->forgetAll()->run();

        self::assertSame([$reason => 1], $report['skipped']);
        self::assertSame(0, $report['deleted']);
        self::assertSame(0, $report['provider_only'], 'Never counted provider-only.');
        self::assertSame(1, DB::table('failed_jobs')->where('id', $survivor)->count(), 'Nothing is deleted.');
        self::assertNotNull($this->row($uuid));
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function predicateCases(): array
    {
        return [
            'an unobserved re-failure R2 of a row bound to R1' => ['refailure', 'occurrence_mismatch'],
            'a row with a null provider_id' => ['unbound', 'occurrence_unprovable'],
            'a row held by an operation' => ['held', 'operation_in_progress'],
            'a row that is not failed' => ['notfailed', 'not_failed'],
        ];
    }

    /**
     * Rejects: trusting the SQL prefilter's collation: `high` beside `HIGH`, an accent variant and
     * a trailing-space variant, each as a provider-only record and a mirrored record, with --queue alone and with
     * --connection; only the requested scope changes.
     */
    #[DataProvider('scopes')]
    public function test_scope_is_byte_exact(?string $connection): void
    {
        $mirrored = [];
        $providerOnly = [];

        foreach (self::QUEUES as $queue) {
            $mirrored[$queue] = $this->failedOccurrence($queue);
            $providerOnly[$queue] = $this->providerOnly($queue);
        }

        $foreignConnection = $this->providerOnly('high', 'DATABASE');

        $report = $this->forgetAll()->run($connection, 'high');

        self::assertSame(1, $report['deleted']);
        self::assertSame($connection === null ? 2 : 1, $report['provider_only'], '--queue alone matches the queue name on every connection.');

        foreach (self::QUEUES as $queue) {
            [$uuid, , $recordId] = $mirrored[$queue];
            [$id, $recordUuid] = $providerOnly[$queue];
            $gone = $queue === 'high';
            self::assertSame($gone, $this->record($recordId, $uuid) === null, "The mirrored record of [{$queue}].");
            self::assertSame($gone, $this->row($uuid) === null, "The mirror row of [{$queue}].");
            self::assertSame($gone, $this->record($id, $recordUuid) === null, "The provider-only record of [{$queue}].");
        }

        self::assertSame($connection === null, $this->record(...$foreignConnection) === null, 'Another connection name: only --queue alone reaches it.');
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function scopes(): array
    {
        return ['--queue alone' => [null], '--queue with --connection' => ['database']];
    }

    /**
     * Rejects: a null-failer branch without the byte-exact recheck, or one that deletes a held row.
     */
    public function test_null_failer_forget_all_deletes_free_failed_rows_of_the_scope(): void
    {
        $rows = [];

        foreach (self::QUEUES as $queue) {
            $rows[$queue] = $this->failedOccurrence($queue)[0];
        }

        [$held] = $this->failedOccurrence('high');
        $this->mirror()->table('orrery_jobs')->where('uuid', $held)->update(['operation_id' => str_repeat('d', 32)]);
        $records = DB::table('failed_jobs')->count();
        $this->useNullFailerFor($held, ...array_values($rows));

        $report = $this->forgetAll()->run(null, 'high');

        self::assertSame(1, $report['deleted']);
        self::assertSame(['operation_in_progress' => 1], $report['skipped']);

        foreach (self::QUEUES as $queue) {
            self::assertSame($queue === 'high', $this->row($rows[$queue]) === null, "The row of [{$queue}].");
        }

        self::assertNotNull($this->row($held));
        self::assertSame($records, DB::table('failed_jobs')->count(), 'The null failer never touches a failed-job table.');
    }

    /**
     * Rejects: a walk without an upper bound: a record logged during the walk (after the first batch) is never
     * reached; also a walk that deletes a held row's record.
     */
    public function test_forget_all_walk_is_bounded_by_its_start(): void
    {
        $seeded = [];

        foreach (range(1, ForgetAllRecords::BATCH) as $i) {
            $seeded[] = $this->providerOnly();
        }

        [$held, , $heldRecord] = $this->failedOccurrence();
        $this->mirror()->table('orrery_jobs')->where('uuid', $held)->update(['operation_id' => str_repeat('e', 32)]);
        [$free, , $freeRecord] = $this->failedOccurrence();
        $late = null;
        Hooks::$callback = function (string $point) use (&$late): void {
            if ($point === ForgetAllRecords::AFTER_BATCH && $late === null) {
                $late = $this->providerOnly();
            }
        };

        $report = $this->forgetAll()->run();
        Hooks::$callback = null;

        self::assertIsArray($late);
        self::assertSame(ForgetAllRecords::BATCH, $report['provider_only']);
        self::assertSame(1, $report['deleted']);
        self::assertSame(['operation_in_progress' => 1], $report['skipped']);
        self::assertNotNull($this->record(...$late), 'A record logged during the walk is never reached.');
        self::assertNotNull($this->record($heldRecord, $held));
        self::assertNull($this->record($freeRecord, $free));
        self::assertNull($this->record(...$seeded[0]));
    }

    /**
     * Rejects: a record delete before the guarded mirror delete: a row that a retry claims during the
     * walk keeps its record, and the record is counted as skipped, not deleted.
     */
    public function test_claim_during_the_walk_keeps_the_record(): void
    {
        [$uuid, , $recordId] = $this->failedOccurrence();
        $claim = str_repeat('9', 32);
        Hooks::$callback = function (string $point) use ($uuid, $claim): void {
            if ($point === ForgetAllRecords::BEFORE_DELETE) {
                Hooks::$callback = null;
                $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'retrying', 'operation_id' => $claim]);
            }
        };

        $report = $this->forgetAll()->run();

        self::assertSame(0, $report['deleted']);
        self::assertSame(['operation_in_progress' => 1], $report['skipped']);
        self::assertNotNull($this->record($recordId, $uuid), 'The claimed occurrence keeps its record.');
        self::assertSame($claim, $this->row($uuid)?->operation_id);
        self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid());
    }

    /**
     * Rejects: an --all that trusts a binding other than the verified one, or calls the provider.
     */
    public function test_forget_all_of_a_read_only_failer_is_refused_without_a_statement(): void
    {
        [$uuid, , $recordId] = $this->failedOccurrence();
        $this->app->instance('queue.failer', new class($this->app['db'], TestEnvironment::PRIMARY, 'failed_jobs') extends LaravelUuidProvider {});
        $statements = 0;
        DB::listen(static function (QueryExecuted $event) use (&$statements): void {
            if (str_contains($event->sql, 'failed_jobs')) {
                $statements++;
            }
        });

        $report = $this->forgetAll()->run();

        self::assertSame([ForgetAllRecords::REFUSED, FailedRecords::PROVIDER_READ_ONLY], [$report['result'], $report['code']]);
        self::assertSame(0, $statements);
        self::assertNotNull($this->record($recordId, $uuid));
        self::assertNotNull($this->row($uuid));
    }

    /**
     * Rejects: an --all whose mirrored branch skips the target check: the planted record with the
     * same id and uuid in the new target stays (test_target_drift_before_the_first_action_is_refused, the --all half).
     */
    #[DataProvider('targetChanges')]
    public function test_target_drift_is_skipped_by_forget_all(string $change): void
    {
        $this->plantDriftedTarget($change, ...$this->failedOccurrenceFor($change));
        [$uuid, , $recordId] = $this->drifted;

        if ($this->pooledSchemaDrift($change)) {
            // Pooled mode refuses the drifted search_path itself (PooledSchemaDrift): --all stops before
            // its first statement on the failed table, and the command exits non-zero with the message.
            try {
                $this->forgetAll()->run();
                self::fail('The pooled session of the drifted connection is refused.');
            } catch (PooledSessionRefused $refused) {
                self::assertPooledRefusal($refused);
            }

            self::assertSame(0, $this->assertFailedDeletesNameIdAndUuid(), 'No failed record is deleted.');
            self::assertNotNull($this->record($recordId, $uuid), 'The original record stays.');
        } else {
            $report = $this->forgetAll()->run();

            self::assertSame(['target_changed' => 1], $report['skipped']);
            self::assertSame(0, $report['provider_only']);
        }

        $this->assertPlantedRecordStays();
        self::assertNotNull($this->row($uuid));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targetChanges(): array
    {
        return ForgetsTest::targetChanges();
    }

    /**
     * Rejects: identity by id on a table that can reuse ids (the --all and forget halves of
     * test_reused_sqlite_ids_are_refused): F2 reuses F1's id, and both actions leave it intact.
     */
    public function test_reused_sqlite_ids_are_refused(): void
    {
        if ($this->primary()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('The id reuse of a plain INTEGER PRIMARY KEY is a SQLite rule; this run uses '.$this->primary()->getDriverName().'.');
        }

        $this->primary()->getSchemaBuilder()->create('reusing_failed', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $this->beforeApplicationDestroyed(fn () => $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->dropIfExists('reusing_failed'));
        $this->useCapturingFailer('reusing_failed');
        DB::table('failed_jobs')->delete();
        [$uuid, $occurrence] = $this->failedOccurrence();
        $record = DB::table('reusing_failed')->sole();
        DB::table('reusing_failed')->delete();
        DB::table('reusing_failed')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $record->payload, 'exception' => 'F2', 'failed_at' => Carbon::now()]);
        self::assertSame((int) $record->id, (int) DB::table('reusing_failed')->value('id'), 'The table reused the id.');

        $report = $this->forgetAll()->run();
        $single = $this->forgets()->forget($uuid, $occurrence, null);

        self::assertSame([ForgetAllRecords::REFUSED, FailedRecords::IDS_MAY_BE_REUSED], [$report['result'], $report['code']]);
        self::assertSame(['refused', FailedRecords::IDS_MAY_BE_REUSED], [$single->result, $single->code]);
        self::assertSame('F2', DB::table('reusing_failed')->value('exception'), 'F2 is intact.');
    }

    private const QUEUES = ['high', 'HIGH', 'hígh', 'high '];

    /**
     * A failed record that no mirror row names: its id and uuid.
     *
     * @return array{0: int, 1: string}
     */
    private function providerOnly(string $queue = 'default', string $connection = 'database'): array
    {
        $uuid = (string) Str::uuid();
        $id = (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => $uuid, 'connection' => $connection, 'queue' => $queue,
            'payload' => (string) json_encode(['uuid' => $uuid, 'job' => 'x', 'data' => []]), 'exception' => 'provider-only', 'failed_at' => Carbon::now(),
        ]);

        return [$id, $uuid];
    }

    /**
     * Laravel's own queue:retry of R1 and an unobserved re-failure R2 of the same uuid; R2's id.
     */
    private function replaceWithUnobservedRefailure(string $uuid, int $recordId): int
    {
        $record = DB::table('failed_jobs')->where('id', $recordId)->sole();
        DB::table('failed_jobs')->where('id', $recordId)->delete();
        $id = (int) DB::table('failed_jobs')->insertGetId(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $record->payload, 'exception' => 'R2', 'failed_at' => Carbon::now()]);
        $this->failedDeletes = [];

        return $id;
    }
}
