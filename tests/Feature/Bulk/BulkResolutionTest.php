<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Bulk;

use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Bulk\Fixtures\ServerErrorFloorResolver;
use Justrau\Orrery\Tests\Feature\Bulk\Jobs\BulkProducerJob;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\HookedJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Throwable;

/**
 * The bulk resolution:
 * after the parent returns, unresolved rows, the bulk committed-read check):
 * the floor, the match by uuid and generation, the
 * bounded tick retries, and the resolution on the observer session.
 */
#[FreshSchema]
final class BulkResolutionTest extends BulkTestCase
{
    private const HOLD = 'bulk.before_resolution';

    /**
     * Rejects: a floor read after the insert.
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_concurrent_bulk_producers_resolve_their_own_ids(): void
    {
        $this->requireSharedDatabase();
        $this->queue();

        Queue::push(new BulkProducerJob(3, 'bulk'));
        Queue::push(new BulkProducerJob(2, 'bulk'));

        $first = Barrier::forTest();
        $second = Barrier::forTest();
        $a = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--queue=default'], $this->testEnvironment(), $first->holdEnvironment(self::HOLD));
        $first->await(self::HOLD.'.reached', 60, $a->abandonIfExited());

        // A inserted its jobs and its P2 rows, and holds before its
        // resolution. B reads its floor above A's rows, inserts, and holds.
        $b = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--queue=default'], $this->testEnvironment(), $second->holdEnvironment(self::HOLD));
        $second->await(self::HOLD.'.reached', 60, $b->abandonIfExited());

        self::assertSame(5, DB::table('jobs')->where('queue', 'bulk')->count(), 'Both producers inserted their jobs.');
        self::assertSame(5, $this->mirror()->table('orrery_jobs')->where('job_id_unresolved', true)->count(), 'Both producers inserted their P2 rows before the resolution.');

        $first->arrive(self::HOLD.'.release');
        $second->arrive(self::HOLD.'.release');
        self::assertSame(0, $a->waitForExit(60), $a->describe());
        self::assertSame(0, $b->waitForExit(60), $b->describe());

        $floors = [];

        foreach (DB::table('jobs')->where('queue', 'bulk')->orderBy('id')->get() as $job) {
            $row = $this->rowOrFail(self::uuidOf((string) $job->payload));
            self::assertSame('pending', $row->status);
            self::assertSame((int) $job->id, (int) $row->job_id, 'Each producer resolved its own ids.');
            self::assertFalse((bool) $row->job_id_unresolved);
            self::assertLessThan((int) $job->id, (int) $row->bulk_floor_id, 'The floor is below the job: it was read before the insert.');
            $floors[(int) $row->bulk_floor_id] = true;
        }

        self::assertCount(2, $floors, 'Each producer read its own floor.');
    }

    /**
     * Rejects: a resolution that overwrites the reserved row.
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_immediate_consumer_before_resolution(): void
    {
        $this->requireSharedDatabase();
        $this->queue();

        $consumer = Barrier::forTest();
        $point = 'bulk.consumer_running';
        $worker = null;
        $snapshot = null;
        $problem = null;

        Hooks::$callback = function (string $reached) use ($consumer, $point, &$worker, &$snapshot, &$problem): void {
            if ($reached !== self::HOLD || $worker !== null) {
                return;
            }

            try {
                $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--queue=bulk'], $this->testEnvironment(), $consumer->holdEnvironment($point));
                $consumer->await($point.'.reached', 60, $worker->abandonIfExited());
                $first = DB::table('jobs')->where('queue', 'bulk')->orderBy('id')->first();
                $snapshot = (array) $this->rowOrFail(self::uuidOf((string) $first->payload));
            } catch (Throwable $error) {
                $problem = $error;
            }
        };

        Queue::connection('database')->bulk([new HookedJob($point), new BasicJob], '', 'bulk');
        Hooks::$callback = null;

        self::assertNull($problem, 'The consumer reserved the first job while the producer held before its resolution.');
        self::assertIsArray($snapshot);
        self::assertSame('reserved', $snapshot['status'], 'R1 resolved the reserved row before the resolution.');

        $jobs = DB::table('jobs')->where('queue', 'bulk')->orderBy('id')->get();
        self::assertCount(2, $jobs);
        self::assertSame($snapshot, (array) $this->rowOrFail(self::uuidOf((string) $jobs[0]->payload)), 'The resolution left the reserved row as it was.');

        $second = $this->rowOrFail(self::uuidOf((string) $jobs[1]->payload));
        self::assertSame('pending', $second->status);
        self::assertSame((int) $jobs[1]->id, (int) $second->job_id, 'The resolution resolved the waiting job.');

        $consumer->arrive($point.'.release');
        self::assertInstanceOf(WorkerProcess::class, $worker);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame('completed', $this->rowOrFail(self::uuidOf((string) $jobs[0]->payload))->status);
    }

    /**
     * Rejects: a resolution on the caller's connection.
     */
    public function test_resolution_failure_inside_an_outer_postgres_transaction_leaves_it_untouched(): void
    {
        if ($this->testEnvironment()->driver !== 'pgsql') {
            self::markTestSkipped('Only Postgres aborts a whole transaction after a failed statement: run it through bin/test pgsql.');
        }

        $resolver = $this->app->make(ServerErrorFloorResolver::class);
        $this->app->instance(BulkResolver::class, $resolver);
        $q = $this->q();

        $q->beginTransaction();
        $before = $this->push(new BasicJob);
        $this->bulk([new BasicJob, new BasicJob]);
        $after = $this->push(new BasicJob);
        $q->commit();

        self::assertSame(1, $resolver->floorReads, 'The bulk read its floor once, and the server rejected it.');
        self::assertArrayHasKey('hook:bulk_floor', Degradation::record()['errors'] ?? [], 'The watcher recorded the failed read.');
        self::assertCount(4, $this->qJobs(), 'The application transaction committed every insert.');
        self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($before))->status);
        self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($after))->status, 'The transaction stayed usable after the failed read.');
        self::assertSame(2, $this->mirrorCount(), 'The bulk without a floor is not observed: its jobs get their rows at reservation.');
        $this->assertNothingDeferred();
    }

    /**
     * Rejects: unbounded retries, or `unknown` by absence for an unresolved row.
     */
    public function test_tick_retries_three_times_then_leaves_rows_unresolved(): void
    {
        Watcher::failOperationsForTesting(['bulk:resolution']);
        $this->bulk([new BasicJob, new BasicJob]);
        Watcher::failOperationsForTesting([]);

        $jobs = $this->qJobs();
        self::assertSame(2, $this->mirror()->table('orrery_jobs')->where('job_id_unresolved', true)->whereNull('job_id')->count(), 'The first resolution failed: both rows are unresolved.');

        // The jobs rows leave the queue unobserved (for example queue:clear).
        $this->q()->table('jobs')->delete();

        $reads = $this->resolutionReads();

        for ($tick = 1; $tick <= 4; $tick++) {
            self::assertSame(0, Artisan::call('orrery:tick'));
            self::assertCount(min($tick, BulkResolver::TICK_ATTEMPTS), $reads, "Tick {$tick} reads the jobs table only while the rows have attempts left.");
        }

        foreach ($jobs as $job) {
            $uuid = self::uuidOf((string) $job->payload);
            $row = $this->rowOrFail($uuid);
            self::assertSame('pending', $row->status, 'An unresolved row is never marked unknown by absence.');
            self::assertTrue((bool) $row->job_id_unresolved);
            self::assertNull($row->job_id);
            self::assertFalse($this->app->make(JobStore::class)->markUnknown($uuid, (int) $job->id, 1, 'absent', Clock::now()), 'U1 rejects an unresolved row.');
        }
    }

    /**
     * New behaviour (a known limit):
     * two bulk jobs leave the queue unobserved (queue:clear) before their
     * resolution, and a third one waits. The tick reads fail during the
     * attempts, so the group is read on after them; no row is marked until
     * a read reaches the end of the queue. That read resolves the live job,
     * and the next tick marks the two others `unknown` / `bulk_unresolved`,
     * past the grace of max(retry_after, 600) s. A later reservation of a
     * marked row whose job did exist repairs it (R3).
     *
     * Rejects: rows left pending forever, marking a live job without the
     * grace, and marking before a read reached the end of the queue.
     */
    public function test_unresolvable_bulk_rows_become_unknown(): void
    {
        Watcher::failOperationsForTesting(['bulk:resolution']);
        $this->bulk([new BasicJob, new BasicJob], 'gone');
        $this->bulk([new BasicJob], 'live');
        Watcher::failOperationsForTesting([]);

        $gone = $this->q()->table('jobs')->where('queue', 'gone')->orderBy('id')->get()->all();
        $live = $this->q()->table('jobs')->where('queue', 'live')->first();
        self::assertCount(2, $gone);
        self::assertNotNull($live);
        self::assertSame(3, $this->mirror()->table('orrery_jobs')->where('job_id_unresolved', true)->whereNull('job_id')->count(), 'Every row is unresolved.');

        self::assertSame(0, Artisan::call('queue:clear', ['connection' => self::QUEUE, '--queue' => 'gone', '--force' => true]));
        self::assertSame(0, $this->q()->table('jobs')->where('queue', 'gone')->count(), 'queue:clear deleted the two jobs.');

        // The attempts' reads fail.
        Watcher::failOperationsForTesting(['bulk:tick_read']);

        for ($tick = 1; $tick <= BulkResolver::TICK_ATTEMPTS; $tick++) {
            self::assertSame(0, Artisan::call('orrery:tick'));
        }

        self::assertSame(3, $this->mirror()->table('orrery_jobs')->where('status', 'pending')->where('job_id_unresolved', true)->count(), 'Within the grace, the rows stay pending after the last attempt.');

        // Past the grace (retry_after 90, so 600 s), but no read reached the end.
        $this->backdate(601);
        self::assertSame(0, Artisan::call('orrery:tick'));
        Watcher::failOperationsForTesting([]);
        self::assertSame(3, $this->mirror()->table('orrery_jobs')->where('status', 'pending')->count(), 'No row is marked before a read reached the end of the queue.');

        // The read reaches the end and resolves the live job; the next tick marks.
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame((int) $live->id, (int) $this->rowOrFail(self::uuidOf((string) $live->payload))->job_id, 'The read after the attempts resolved the live job.');
        self::assertSame(0, Artisan::call('orrery:tick'));

        foreach ($gone as $job) {
            $row = $this->rowOrFail(self::uuidOf((string) $job->payload));
            self::assertSame('unknown', $row->status, 'An unresolvable row becomes unknown.');
            self::assertSame('bulk_unresolved', $row->unknown_reason);
            self::assertNotNull($row->unknown_since);
            self::assertNull($row->job_id);
            self::assertFalse((bool) $row->job_id_unresolved, 'A marked row leaves the unresolved index.');
        }

        self::assertSame('pending', $this->rowOrFail(self::uuidOf((string) $live->payload))->status, 'The live job is never marked.');
        self::assertNotNull($this->hooks()->pop('live'));
        self::assertSame('reserved', $this->rowOrFail(self::uuidOf((string) $live->payload))->status, 'The live job is reserved later.');

        // A marked row whose job did exist (here: the same payload again).
        $id = (int) $this->q()->table('jobs')->insertGetId(['queue' => 'gone', 'payload' => (string) $gone[0]->payload, 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        self::assertNotNull($this->hooks()->pop('gone'));
        $row = $this->rowOrFail(self::uuidOf((string) $gone[0]->payload));
        self::assertSame('reserved', $row->status, 'The reservation repairs the marked row (R3).');
        self::assertSame($id, (int) $row->job_id);
        self::assertNull($row->unknown_reason);
        self::assertSame('unknown', $this->rowOrFail(self::uuidOf((string) $gone[1]->payload))->status, 'The other deleted job stays unknown.');
    }

    /**
     * A group whose jobs rows above the floor exceed the three attempts'
     * reads (15,000 rows), and whose attempts' reads failed: the reads go
     * on from the cursor after the attempts and resolve every member, and
     * the gone member is marked only after the read that reached the end.
     *
     * Rejects: marking after the attempts while the scan has not reached
     * the end of the queue.
     */
    public function test_large_group_is_read_to_the_end_before_marking(): void
    {
        $floor = (int) ($this->q()->table('jobs')->max('id') ?? 0);
        [$gone] = $this->insertMember($floor, false);
        $this->insertFillers(BulkResolver::TICK_ATTEMPTS * BulkResolver::TICK_ROWS);
        [$member, $memberId] = $this->insertMember($floor);
        $this->backdate(601);

        Watcher::failOperationsForTesting(['bulk:tick_read']);

        for ($tick = 1; $tick <= BulkResolver::TICK_ATTEMPTS; $tick++) {
            self::assertSame(0, Artisan::call('orrery:tick'));
        }

        Watcher::failOperationsForTesting([]);

        // Three full reads after the attempts, then the short read.
        for ($tick = 1; $tick <= BulkResolver::TICK_ATTEMPTS; $tick++) {
            self::assertSame(0, Artisan::call('orrery:tick'));
            self::assertSame('pending', $this->rowOrFail($gone)->status, "Read {$tick} after the attempts did not reach the end: nothing is marked.");
        }

        self::assertNull($this->rowOrFail($member)->job_id);
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame($memberId, (int) $this->rowOrFail($member)->job_id, 'The read past 15,000 rows resolved the member.');
        self::assertSame('pending', $this->rowOrFail($gone)->status);

        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame('unknown', $this->rowOrFail($gone)->status, 'Marked after the read that reached the end.');
    }

    /**
     * The ceiling of the `scanned` rule: a target whose reads always fail
     * is marked once MAX_ATTEMPTS reads, failed ones included, are used.
     *
     * Rejects: a group that stays pending for ever when no read succeeds,
     * and a failed read that does not count toward the ceiling.
     */
    public function test_failing_target_is_marked_at_the_ceiling(): void
    {
        Watcher::failOperationsForTesting(['bulk:resolution']);
        $this->bulk([new BasicJob], 'failing');
        $uuid = self::uuidOf((string) $this->q()->table('jobs')->where('queue', 'failing')->value('payload'));
        $floor = (int) $this->rowOrFail($uuid)->bulk_floor_id;
        $this->backdate(601);
        $this->storeState('failing', $floor, BulkResolver::MAX_ATTEMPTS - 1, null);

        Watcher::failOperationsForTesting(['bulk:tick_read']);

        try {
            self::assertSame(0, Artisan::call('orrery:tick'));
            self::assertSame('pending', $this->rowOrFail($uuid)->status, 'One read is left below the ceiling; it fails.');
            self::assertSame(BulkResolver::MAX_ATTEMPTS, $this->storedState('failing', $floor)['attempts'] ?? null, 'The failed read counts.');

            self::assertSame(0, Artisan::call('orrery:tick'));
            self::assertSame('unknown', $this->rowOrFail($uuid)->status, 'Marked at the ceiling.');
        } finally {
            Watcher::failOperationsForTesting([]);
        }
    }

    /**
     * The ceiling for a busy queue that never gives a short read: the
     * stored state has used MAX_ATTEMPTS full reads.
     *
     * Rejects: a group that stays pending for ever while every read is full.
     */
    public function test_queue_without_a_short_read_is_marked_at_the_ceiling(): void
    {
        $uuid = $this->unresolvableGroup([new BasicJob], 'busy', 0);
        $floor = (int) $this->rowOrFail($uuid)->bulk_floor_id;
        $this->backdate(601);
        $this->storeState('busy', $floor, BulkResolver::MAX_ATTEMPTS - 1, $floor + 5_000);

        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame('pending', $this->rowOrFail($uuid)->status, 'Below the ceiling the group is read on.');
        $this->storeState('busy', $floor, BulkResolver::MAX_ATTEMPTS, $floor + 10_000);

        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame('unknown', $this->rowOrFail($uuid)->status, 'Marked at the ceiling.');
    }

    /**
     * A queue name that is not valid UTF-8 (SQLite stores its bytes): the
     * attempt state is stored, so the attempts end and the row is marked.
     *
     * Rejects: an attempt state whose JSON encoding fails, which loses the
     * attempt count on every tick.
     */
    public function test_attempt_state_survives_a_queue_name_that_is_not_utf8(): void
    {
        if ($this->testEnvironment()->driver !== 'sqlite') {
            self::markTestSkipped('Only SQLite stores a queue name that is not valid UTF-8.');
        }

        // A P2 row whose stored queue name has an invalid byte (the package
        // cleans the names it writes; an older row or another writer may not).
        $queue = "bad\xff";
        $floor = (int) ($this->q()->table('jobs')->max('id') ?? 0);
        $uuid = strtolower((string) Str::uuid());
        $this->app->make(JobStore::class)->observe(Observation::fromPayload(Observation::BULK_PUSH, self::rawPayload($uuid), self::QUEUE, 'bad', ['bulkFloor' => $floor]) ?? self::fail('The payload has a uuid.'));
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['queue' => $queue]);
        self::assertSame(bin2hex($queue), bin2hex((string) $this->rowOrFail($uuid)->queue), 'SQLite keeps the bytes.');

        for ($tick = 0; $tick < BulkResolver::TICK_ATTEMPTS; $tick++) {
            self::assertSame(0, Artisan::call('orrery:tick'));
        }

        $setting = $this->mirror()->table('orrery_settings')->where('key', 'like', BulkResolver::ATTEMPTS_KEY.'%')->first();
        self::assertNotNull($setting);
        $value = json_decode((string) $setting->value, true);
        self::assertIsArray($value, 'The attempt state is valid JSON.');
        self::assertSame(BulkResolver::TICK_ATTEMPTS, $value['attempts'], 'Every attempt was counted.');

        $this->backdate(601);
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame('unknown', $this->rowOrFail($uuid)->status);
    }

    /**
     * A stored value whose names or floor differ from the group's (for
     * example a hash collision of the key): the group starts fresh.
     *
     * Rejects: an attempt state taken from another group.
     */
    public function test_attempt_state_of_another_group_is_a_fresh_key(): void
    {
        // B used its attempts and read to the end; A has no state yet.
        $b = $this->unresolvableGroup([new BasicJob], 'other');
        $a = $this->unresolvableGroup([new BasicJob], 'own', 0);
        $bValue = (string) $this->mirror()->table('orrery_settings')->where('key', 'like', BulkResolver::ATTEMPTS_KEY.'%')->value('value');
        $aFloor = (int) $this->rowOrFail($a)->bulk_floor_id;

        // A's key holds B's state (for example a hash collision).
        $aKey = BulkResolver::ATTEMPTS_KEY.sha1(self::QUEUE."\0own\0".$aFloor);
        $this->mirror()->table('orrery_settings')->insert(['key' => $aKey, 'value' => $bValue]);

        $this->backdate(601);
        self::assertSame(0, Artisan::call('orrery:tick'));

        self::assertSame('unknown', $this->rowOrFail($b)->status, 'B is marked.');
        self::assertSame('pending', $this->rowOrFail($a)->status, 'A starts fresh: it has attempts left.');
        $fresh = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', $aKey)->value('value'), true);
        self::assertSame(1, $fresh['attempts'] ?? null);
        self::assertSame($aFloor, $fresh['floor'] ?? null);
    }

    /**
     * A delayed bulk job gets its grace from available_at: pushed long ago,
     * but available only later, it stays pending until the grace after its
     * available_at passed.
     *
     * Rejects: a grace measured from pushed_at alone.
     */
    public function test_delayed_unresolvable_row_waits_for_the_grace_after_available_at(): void
    {
        $uuid = $this->unresolvableGroup([(new BasicJob)->delay(3600)], 'delayed');
        $this->mirror()->table('orrery_jobs')->update(['pushed_at' => Clock::toDecimal(Clock::now() - 7200)]);

        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame('pending', $this->rowOrFail($uuid)->status, 'Available in the future: no grace has passed.');

        $this->backdate(601);
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame('unknown', $this->rowOrFail($uuid)->status);
    }

    /**
     * The grace is max(retry_after, 600) s: retry_after is 90 here, so a
     * row 100 s old stays pending.
     *
     * Rejects: a grace of retry_after alone.
     */
    public function test_unresolvable_rows_wait_at_least_600_seconds(): void
    {
        $uuid = $this->unresolvableGroup([new BasicJob], 'floor');

        $this->backdate(100);
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame('pending', $this->rowOrFail($uuid)->status, 'Older than retry_after, younger than 600 s.');

        $this->backdate(601);
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame('unknown', $this->rowOrFail($uuid)->status);
    }

    /**
     * More rows than one batch of a group are marked in one tick, and a
     * group that still has attempts left is not touched.
     *
     * Rejects: one batch per tick, and a marking that ignores the group.
     */
    public function test_unresolvable_rows_are_marked_in_batches_and_only_their_group(): void
    {
        $jobs = [];

        for ($i = 0; $i <= BulkResolver::UNKNOWN_BATCH; $i++) {
            $jobs[] = new BasicJob;
        }

        $this->unresolvableGroup($jobs, 'many');
        $other = $this->unresolvableGroup([new BasicJob], 'other', 1);

        $this->backdate(601);
        self::assertSame(0, Artisan::call('orrery:tick'));

        self::assertSame(BulkResolver::UNKNOWN_BATCH + 1, $this->mirror()->table('orrery_jobs')->where('queue', 'many')->where('status', 'unknown')->where('unknown_reason', BulkResolver::UNRESOLVED_REASON)->count(), 'Every row of the group, over two batches.');
        $row = $this->rowOrFail($other);
        self::assertSame('pending', $row->status, 'The group with attempts left keeps its row pending.');
        self::assertTrue((bool) $row->job_id_unresolved);
    }

    /**
     * A group whose job lies beyond the first TICK_ROWS jobs rows above the
     * floor: the first attempt reads from the floor, and the next one
     * continues above the highest id that the first read returned.
     *
     * Rejects: a tick read that always starts at the floor.
     */
    public function test_tick_read_continues_above_the_previous_read(): void
    {
        $floor = (int) ($this->q()->table('jobs')->max('id') ?? 0);
        $this->insertFillers(BulkResolver::TICK_ROWS);
        [$uuid, $id] = $this->insertMember($floor);

        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertNull($this->rowOrFail($uuid)->job_id, 'The first attempt reads only the first TICK_ROWS jobs rows above the floor.');

        self::assertSame(0, Artisan::call('orrery:tick'));
        $row = $this->rowOrFail($uuid);
        self::assertSame($id, (int) $row->job_id, 'The second attempt continued above the first read.');
        self::assertFalse((bool) $row->job_id_unresolved);
    }

    /**
     * A read of fewer than TICK_ROWS rows sends the next attempt back to
     * the floor: a member whose P2 row appeared after the first attempt,
     * with its job among the first TICK_ROWS rows, resolves at the third.
     *
     * Rejects: a cursor that never wraps to the floor.
     */
    public function test_tick_read_wraps_to_the_floor_after_a_short_read(): void
    {
        $floor = (int) ($this->q()->table('jobs')->max('id') ?? 0);
        // A member whose job is gone keeps the group unresolved.
        $this->insertMember($floor, false);
        [$late, $lateId] = $this->insertMember($floor, true, false);
        $this->insertFillers(BulkResolver::TICK_ROWS);

        self::assertSame(0, Artisan::call('orrery:tick'));
        $this->observeMember($late, $floor);

        // The second attempt reads above the first, full read: nothing.
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertNull($this->rowOrFail($late)->job_id);

        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame($lateId, (int) $this->rowOrFail($late)->job_id, 'The third attempt read from the floor again.');
    }

    /**
     * A reserved and released member moves its job_id to the new jobs id
     * and keeps its floor; the other members still resolve.
     *
     * Rejects: a cursor derived from the mirror's job_id, which reads above
     * the other members.
     */
    public function test_released_member_does_not_hide_the_other_members(): void
    {
        Watcher::failOperationsForTesting(['bulk:resolution']);
        $this->bulk([new BasicJob, new BasicJob, new BasicJob]);
        Watcher::failOperationsForTesting([]);
        $jobs = $this->qJobs();

        $job = $this->hooks()->pop();
        self::assertNotNull($job);
        $job->release(0);

        $released = $this->rowOrFail(self::uuidOf((string) $jobs[0]->payload));
        self::assertSame('pending', $released->status);
        self::assertGreaterThan((int) $jobs[2]->id, (int) $released->job_id, 'The release moved the job_id above the other members.');

        self::assertSame(0, Artisan::call('orrery:tick'));

        foreach (array_slice($jobs, 1) as $member) {
            self::assertSame((int) $member->id, (int) $this->rowOrFail(self::uuidOf((string) $member->payload))->job_id, 'The other members resolved in the tick.');
        }
    }

    /**
     * The tick's deadline passes after the first batch of
     * markUnresolvable(): the remaining row waits for the next tick.
     *
     * Rejects: a marking loop that ignores the cooperative deadline.
     */
    public function test_marking_stops_at_the_deadline_between_batches(): void
    {
        $jobs = [];

        for ($i = 0; $i <= BulkResolver::UNKNOWN_BATCH; $i++) {
            $jobs[] = new BasicJob;
        }

        $this->unresolvableGroup($jobs, 'many');
        $this->backdate(601);

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event): void {
            $sql = strtolower(ltrim($event->sql));

            if (str_starts_with($sql, 'update') && str_contains($sql, 'orrery_jobs') && in_array(BulkResolver::UNRESOLVED_REASON, $event->bindings, true)) {
                Clock::advanceMonotonicForTesting(120);
            }
        });

        $this->app->make(BulkResolver::class)->resolveUnresolved(new TickBudget(Clock::monotonic() + 60));

        $unknown = fn (): int => $this->mirror()->table('orrery_jobs')->where('queue', 'many')->where('status', 'unknown')->count();
        self::assertSame(BulkResolver::UNKNOWN_BATCH, $unknown(), 'One batch before the deadline.');

        $this->app->make(BulkResolver::class)->resolveUnresolved(new TickBudget(Clock::monotonic() + 60));
        self::assertSame(BulkResolver::UNKNOWN_BATCH + 1, $unknown(), 'The next tick marks the rest.');
    }

    /**
     * Rejects: a tick phase that never retries a missed resolution.
     */
    public function test_tick_resolves_rows_that_the_first_resolution_missed(): void
    {
        Watcher::failOperationsForTesting(['bulk:resolution']);
        $this->bulk([new BasicJob, new BasicJob]);
        Watcher::failOperationsForTesting([]);

        self::assertSame(2, $this->mirror()->table('orrery_jobs')->where('job_id_unresolved', true)->count());

        self::assertSame(0, Artisan::call('orrery:tick'));

        $this->assertPendingParity('The tick resolved both rows.');
        self::assertSame(0, $this->mirror()->table('orrery_settings')->where('key', 'like', BulkResolver::ATTEMPTS_KEY.'%')->count(), 'A resolved group leaves no attempt counter.');
    }

    /**
     * Rejects: a group whose queue connection cannot be resolved (removed
     * or renamed since the push) that throws out of the phase, so no later
     * group is attempted for as long as the bad group lasts.
     */
    public function test_an_unresolvable_target_skips_only_its_own_group(): void
    {
        // The removed connection's group comes first (the lowest floor).
        $this->insertFillers(1);
        $payload = self::rawPayload(strtolower((string) Str::uuid()), ['orreryGen' => str_repeat('d', 32)]);
        $this->app->make(JobStore::class)->observe(Observation::fromPayload(Observation::BULK_PUSH, $payload, 'removed_connection', 'default', ['bulkFloor' => 0]) ?? self::fail('The payload has a uuid.'));

        Watcher::failOperationsForTesting(['bulk:resolution']);
        $this->bulk([new BasicJob, new BasicJob]);
        Watcher::failOperationsForTesting([]);
        self::assertSame(3, $this->mirror()->table('orrery_jobs')->where('job_id_unresolved', true)->count());

        $this->app->make(BulkResolver::class)->resolveUnresolved(new TickBudget(Clock::monotonic() + 60));

        self::assertSame(['removed_connection'], $this->mirror()->table('orrery_jobs')->where('job_id_unresolved', true)->pluck('connection')->all(), 'The group after the removed connection was not resolved.');
        self::assertSame(2, $this->mirror()->table('orrery_jobs')->where('connection', self::QUEUE)->whereNotNull('job_id')->count());
    }

    /**
     * Rejects: an attempt-key cleanup that always reads the same first page of retained keys.
     */
    public function test_attempt_key_cleanup_advances_past_retained_keys(): void
    {
        // One unresolved group: its keys are retained.
        Watcher::failOperationsForTesting(['bulk:resolution']);
        $this->bulk([new BasicJob]);
        Watcher::failOperationsForTesting([]);
        // The jobs row leaves the queue unobserved, so the group stays unresolved.
        $this->q()->table('jobs')->delete();
        $row = $this->mirror()->table('orrery_jobs')->where('job_id_unresolved', true)->first();
        self::assertNotNull($row);
        $retained = json_encode(['connection' => self::QUEUE, 'queue' => 'default', 'floor' => (int) $row->bulk_floor_id, 'attempts' => BulkResolver::TICK_ATTEMPTS]);
        $orphan = json_encode(['connection' => self::QUEUE, 'queue' => 'default', 'floor' => 999_999, 'attempts' => 1]);

        // A full first page of retained keys, then orphan keys after it.
        $settings = [];

        for ($i = 0; $i < BulkResolver::PRUNE_PAGE; $i++) {
            $settings[] = ['key' => BulkResolver::ATTEMPTS_KEY.sprintf('0%039d', $i), 'value' => $retained];
        }

        foreach (['e', 'f'] as $suffix) {
            $settings[] = ['key' => BulkResolver::ATTEMPTS_KEY.str_repeat($suffix, 40), 'value' => $orphan];
        }

        foreach (array_chunk($settings, 200) as $chunk) {
            $this->mirror()->table('orrery_settings')->insert($chunk);
        }

        $resolver = $this->app->make(BulkResolver::class);
        $orphans = fn (): int => $this->mirror()->table('orrery_settings')->whereIn('key', [BulkResolver::ATTEMPTS_KEY.str_repeat('e', 40), BulkResolver::ATTEMPTS_KEY.str_repeat('f', 40)])->count();

        // An exhausted budget examines nothing.
        $resolver->resolveUnresolved(new TickBudget(Clock::monotonic() - 1));
        self::assertSame(2, $orphans(), 'The cleanup checks the cooperative deadline.');
        self::assertSame(0, $this->mirror()->table('orrery_settings')->where('key', BulkResolver::CURSOR_KEY)->count(), 'An exhausted budget examines no key, so the cursor does not move.');

        for ($call = 1; $call <= 3 && $orphans() > 0; $call++) {
            $resolver->resolveUnresolved(new TickBudget(Clock::monotonic() + 60));
        }

        self::assertSame(0, $orphans(), 'The later orphan keys are removed.');
        self::assertSame(BulkResolver::PRUNE_PAGE, $this->mirror()->table('orrery_settings')->where('value', $retained)->count(), 'The retained keys stay.');
    }

    /**
     * Rejects: a match by uuid only.
     */
    public function test_resolution_matches_uuid_and_generation(): void
    {
        $uuid = strtolower((string) Str::uuid());
        $generation = str_repeat('a', 32);
        $other = str_repeat('b', 32);
        $floor = (int) ($this->q()->table('jobs')->max('id') ?? 0);

        // Another publication of the same uuid, above the floor and first.
        $impostor = $this->insertJobsRow($uuid, $other);
        $payload = self::rawPayload($uuid, ['orreryGen' => $generation]);
        $own = $this->insertJobsRow($uuid, $generation, $payload);
        self::assertLessThan($own, $impostor);

        $resolver = $this->app->make(BulkResolver::class);
        $run = $resolver->observe(self::QUEUE, 'default', [$payload], $floor);
        self::assertNotNull($run);
        $run();

        $row = $this->rowOrFail($uuid);
        self::assertSame($own, (int) $row->job_id, 'The resolution of the insert took the row with the same uuid and generation.');
        self::assertSame($generation, $row->cycle_gen);

        // The tick resolution uses the same match.
        $this->mirror()->table('orrery_jobs')->delete();
        $this->app->make(JobStore::class)->observe(Observation::fromPayload(Observation::BULK_PUSH, $payload, self::QUEUE, 'default', ['bulkFloor' => $floor]) ?? self::fail('The payload has a uuid.'));
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame($own, (int) $this->rowOrFail($uuid)->job_id, 'The tick took the row with the same uuid and generation.');

        // A payload without a generation compares the uuid only.
        $this->mirror()->table('orrery_jobs')->delete();
        $plain = self::rawPayload($uuid);
        $run = $resolver->observe(self::QUEUE, 'default', [$plain], $floor);
        self::assertNotNull($run);
        $run();
        self::assertSame($impostor, (int) $this->rowOrFail($uuid)->job_id, 'Without a generation, the first row of the uuid matches.');
    }

    /**
     * The P2 row of one job of a bulk fails: the later rows and the
     * resolution of the insert still run.
     *
     * Rejects: the P2 rows of one insert in one watched run, where the
     * first error skips the rest and the resolution.
     */
    public function test_failing_p2_row_skips_neither_the_next_row_nor_the_resolution(): void
    {
        $floor = (int) ($this->q()->table('jobs')->max('id') ?? 0);
        $uuids = [];
        $payloads = [];
        $ids = [];

        foreach (range(1, 3) as $index) {
            $uuids[$index] = strtolower((string) Str::uuid());
            $payloads[] = self::rawPayload($uuids[$index], ['orreryGen' => str_repeat((string) $index, 32)]);
            $ids[$index] = $this->insertJobsRow($uuids[$index], str_repeat((string) $index, 32), end($payloads));
        }

        $failing = $uuids[2];
        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(static function (string $query, array $bindings) use ($failing): void {
            if (str_starts_with(strtolower(ltrim($query)), 'insert') && in_array($failing, $bindings, true)) {
                throw new RuntimeException('p2 row refused');
            }
        });

        $run = $this->app->make(BulkResolver::class)->observe(self::QUEUE, 'default', $payloads, $floor);
        self::assertNotNull($run);
        $run();

        self::assertNull($this->row($failing), 'The failing row has no P2 row.');

        foreach ([1, 3] as $index) {
            $row = $this->rowOrFail($uuids[$index]);
            self::assertSame($ids[$index], (int) $row->job_id, "Row {$index} is inserted and resolved.");
            self::assertFalse((bool) $row->job_id_unresolved);
        }
    }

    /**
     * Writes the attempt state of the group (connection `hooks`) as the
     * resolver stores it, with no read that reached the end.
     */
    private function storeState(string $queue, int $floor, int $attempts, ?int $cursor): void
    {
        $this->mirror()->table('orrery_settings')->updateOrInsert(['key' => self::stateKey($queue, $floor)], ['value' => (string) json_encode([
            'connection' => self::QUEUE,
            'queue' => $queue,
            'names' => base64_encode(self::QUEUE."\0".$queue),
            'floor' => $floor,
            'attempts' => $attempts,
            'cursor' => $cursor,
            'scanned' => false,
        ])]);
    }

    /**
     * @return array<mixed>
     */
    private function storedState(string $queue, int $floor): array
    {
        $value = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', self::stateKey($queue, $floor))->value('value'), true);

        return is_array($value) ? $value : [];
    }

    private static function stateKey(string $queue, int $floor): string
    {
        return BulkResolver::ATTEMPTS_KEY.sha1(self::QUEUE."\0".$queue."\0".$floor);
    }

    /**
     * TICK_ROWS other jobs rows on the queue `default`.
     */
    private function insertFillers(int $count): void
    {
        $fillers = [];

        for ($i = 0; $i < $count; $i++) {
            $fillers[] = ['queue' => 'default', 'payload' => self::rawPayload(strtolower((string) Str::uuid())), 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()];
        }

        foreach (array_chunk($fillers, 500) as $chunk) {
            $this->q()->table('jobs')->insert($chunk);
        }
    }

    /**
     * A bulk member above $floor: its jobs row (unless $withJob is false)
     * and its P2 row (unless $observe is false). Returns its uuid and id.
     *
     * @return array{string, int}
     */
    private function insertMember(int $floor, bool $withJob = true, bool $observe = true): array
    {
        $uuid = strtolower((string) Str::uuid());
        $id = $withJob ? $this->insertJobsRow($uuid, str_repeat('c', 32), self::rawPayload($uuid, ['orreryGen' => str_repeat('c', 32)])) : 0;

        if ($observe) {
            $this->observeMember($uuid, $floor);
        }

        return [$uuid, $id];
    }

    private function observeMember(string $uuid, int $floor): void
    {
        $payload = self::rawPayload($uuid, ['orreryGen' => str_repeat('c', 32)]);
        $this->app->make(JobStore::class)->observe(Observation::fromPayload(Observation::BULK_PUSH, $payload, self::QUEUE, 'default', ['bulkFloor' => $floor]) ?? self::fail('The payload has a uuid.'));
    }

    /**
     * One bulk on $queue whose resolution failed and whose jobs left the
     * queue unobserved, after $ticks ticks (default: every attempt).
     * Returns the uuid of its first job.
     *
     * @param  list<object>  $jobs
     */
    private function unresolvableGroup(array $jobs, string $queue, int $ticks = BulkResolver::TICK_ATTEMPTS): string
    {
        Watcher::failOperationsForTesting(['bulk:resolution']);
        $this->bulk($jobs, $queue);
        Watcher::failOperationsForTesting([]);

        $first = $this->q()->table('jobs')->where('queue', $queue)->orderBy('id')->first();
        self::assertNotNull($first);
        $this->q()->table('jobs')->where('queue', $queue)->delete();

        for ($tick = 0; $tick < $ticks; $tick++) {
            self::assertSame(0, Artisan::call('orrery:tick'));
        }

        return self::uuidOf((string) $first->payload);
    }

    /**
     * Moves pushed_at and available_at of every mirror row $seconds into
     * the past (the rows' age for the grace).
     */
    private function backdate(int $seconds, ?string $queue = null): void
    {
        $time = Clock::toDecimal(Clock::now() - $seconds);
        $this->mirror()->table('orrery_jobs')
            ->when($queue !== null, static fn ($query) => $query->where('queue', $queue))
            ->update(['pushed_at' => $time, 'available_at' => $time]);
    }

    private function requireSharedDatabase(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }
    }

    private function insertJobsRow(string $uuid, string $generation, ?string $payload = null): int
    {
        return (int) $this->q()->table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => $payload ?? self::rawPayload($uuid, ['orreryGen' => $generation]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    /**
     * The resolution reads of the jobs table (the id range above a floor),
     * on any session.
     *
     * @return \ArrayObject<int, string>
     */
    private function resolutionReads(): \ArrayObject
    {
        $reads = new \ArrayObject;

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($reads): void {
            $sql = strtolower($event->sql);

            if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'jobs') && ! str_contains($sql, 'orrery_jobs') && (str_contains($sql, '"id" >') || str_contains($sql, '`id` >'))) {
                $reads[] = $event->sql;
            }
        });

        return $reads;
    }
}
