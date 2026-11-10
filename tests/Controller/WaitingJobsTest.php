<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Actions\WaitingJobs;
use Justrau\Orrery\Events\OperatorActionPerformed;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Hooks\HooksTestCase;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Retention\Jobs\TaggedJob;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * The writes on waiting jobs: POST /api/jobs/{uuid}/delete,
 * POST /api/queues/{connection}/{queue}/waiting and
 * POST /api/queues/{connection}/{queue}/clear (Actions\WaitingJobs, the
 * service that orrery:clear shares). The 403 of a viewer without the
 * operate ability is in OperateAbilityTest and AuthorizationTest.
 */
final class WaitingJobsTest extends HooksTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A second queue connection on the same jobs table: an alias.
        $app['config']->set('queue.connections.database-twin', [
            'driver' => 'database',
            'connection' => TestEnvironment::PRIMARY,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
        Orrery::actorUsing(static fn (): string => 'ops@example.test');
    }

    /**
     * Rejects: a delete that leaves the mirror row or its tags, and a delete without its audit (the event with the
     * actor and the info log line).
     * Still passes: a log line whose wording changes (only its action and actor are checked).
     */
    public function test_delete_removes_a_waiting_job_with_its_mirror_rows_and_records_it(): void
    {
        $uuid = $this->pushed(new TaggedJob(['orders']));
        $other = $this->pushed(new BasicJob);
        self::assertSame(1, $this->mirror()->table('orrery_job_tags')->where('job_uuid', $uuid)->count());
        [$events, $logs] = $this->recordAudit();

        $this->deleteJob($uuid)->assertOk()->assertExactJson(['id' => $uuid, 'result' => 'deleted']);

        self::assertNull($this->hostRow($uuid));
        self::assertNull($this->row($uuid));
        self::assertSame(0, $this->mirror()->table('orrery_job_tags')->where('job_uuid', $uuid)->count(), 'its tags go too');
        self::assertNotNull($this->hostRow($other), 'the other job stays');
        self::assertSame('pending', $this->rowOrFail($other)->status);

        self::assertCount(1, $events);
        self::assertSame(['delete', ['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'batch_id' => null], 'ops@example.test', 'deleted'], [$events[0]->action, $events[0]->target, $events[0]->actor, $events[0]->result]);
        self::assertCount(1, $logs);
        self::assertSame('info', $logs[0]->level);
        self::assertStringContainsString('delete by ops@example.test', $logs[0]->message);
    }

    /**
     * Rejects: a delete without the re-checked `reserved_at IS NULL`, which deletes a job that a worker reserved
     * after the mirror row was read; and a mirror status read as the decision (the mirror row is still pending).
     */
    public function test_delete_keeps_a_job_that_a_worker_reserves_meanwhile(): void
    {
        $uuid = $this->pushed(new BasicJob);
        $id = (int) $this->hostRow($uuid)?->id;
        $done = false;

        DB::connection()->beforeExecuting(static function (string $sql) use (&$done, $id): void {
            if (! $done && str_contains($sql, 'payload') && str_contains($sql, 'reserved_at')) {
                $done = true;
                DB::table('jobs')->where('id', $id)->update(['reserved_at' => time(), 'attempts' => 1]);
            }
        });

        $this->deleteJob($uuid)->assertOk()->assertJson(['result' => 'not_pending']);

        self::assertTrue($done, 'the reservation ran between the mirror read and the lock');
        self::assertNotNull($this->hostRow($uuid), 'the reserved job stays');
        self::assertSame('pending', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: `deleted` for a host row that is already gone, and `gone` for a job that ran meanwhile (its mirror row
     * is no longer pending), or for a reserved one; and a delete of another job whose id holds another payload.
     */
    public function test_delete_answers_gone_and_not_pending(): void
    {
        $gone = $this->pushed(new BasicJob);
        DB::table('jobs')->where('id', $this->hostRow($gone)?->id)->delete();
        $this->deleteJob($gone)->assertOk()->assertJson(['result' => 'gone']);
        self::assertSame('pending', $this->rowOrFail($gone)->status, 'the mirror row stays for reconciliation');

        $ran = $this->pushed(new BasicJob);
        $id = (int) $this->hostRow($ran)?->id;
        DB::connection()->beforeExecuting(function (string $sql) use ($id, $ran): void {
            if (str_contains($sql, 'payload') && str_contains($sql, 'reserved_at') && DB::table('jobs')->where('id', $id)->exists()) {
                // A worker ran the job meanwhile: the host row is gone, the mirror row completed.
                DB::table('jobs')->where('id', $id)->delete();
                $this->mirror()->table('orrery_jobs')->where('uuid', $ran)->update(['status' => 'completed']);
            }
        });
        $this->deleteJob($ran)->assertOk()->assertJson(['result' => 'not_pending']);

        $reserved = $this->pushed(new BasicJob);
        self::assertNotNull(Queue::connection('database')->pop());
        $this->deleteJob($reserved)->assertOk()->assertJson(['result' => 'not_pending']);
        self::assertNotNull($this->hostRow($reserved));

        $replaced = $this->pushed(new BasicJob);
        $payload = (array) json_decode((string) $this->hostRow($replaced)?->payload, true);
        $payload['uuid'] = '00000000-0000-4000-8000-0000000000aa';
        DB::table('jobs')->where('id', $this->hostRow($replaced)?->id)->update(['payload' => json_encode($payload)]);
        $this->deleteJob($replaced)->assertOk()->assertJson(['result' => 'gone']);
        self::assertSame(1, DB::table('jobs')->where('payload', json_encode($payload))->count(), 'the row with the other payload stays');
    }

    /**
     * Rejects: a delete of an unresolved bulk row (job_id NULL) by a guess, and a missing job answered as success.
     */
    public function test_delete_refuses_an_unresolved_row_and_an_unknown_job(): void
    {
        $uuid = $this->pushed(new BasicJob);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['job_id' => null, 'job_id_unresolved' => true]);

        $this->deleteJob($uuid)->assertOk()->assertJson(['result' => 'job_id_unknown']);
        self::assertNotNull($this->hostRow($uuid));

        $this->deleteJob('00000000-0000-4000-8000-000000000001')->assertNotFound()->assertJson(['error' => 'not_found']);
    }

    /**
     * Rejects: `deleted` when the mirror delete failed: the job is gone, its row here stays, and the answer says so.
     */
    public function test_delete_says_when_the_mirror_row_stays(): void
    {
        $uuid = $this->pushed(new BasicJob);
        Watcher::failOperationsForTesting(['delete:mirror']);

        $this->deleteJob($uuid)->assertOk()->assertJson(['result' => 'mirror_failed']);

        Watcher::failOperationsForTesting([]);
        self::assertNull($this->hostRow($uuid));
        self::assertSame('pending', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a count of 0 for a part that could not be read, a count that mixes in reserved jobs, and a delayed job
     * counted as ready.
     */
    public function test_the_waiting_count_splits_ready_delayed_and_batched_jobs(): void
    {
        $this->pushed(new BasicJob);
        self::assertNotNull(Queue::connection('database')->pop(), 'one job is reserved');
        $this->pushed(new BasicJob);
        $batched = $this->pushed(new BasicJob);
        $this->pushed(new BasicJob, 3600);
        $this->mirror()->table('orrery_jobs')->where('uuid', $batched)->update(['batch_id' => '00000000-0000-4000-8000-0000000000bb']);

        $this->postJson('/orrery/api/queues/database/default/waiting')->assertOk()->assertExactJson(['waiting' => 3, 'ready' => 2, 'delayed' => 1, 'in_batches' => 1]);

        Watcher::failOperationsForTesting(['clear:count_batches']);
        $unknown = $this->postJson('/orrery/api/queues/database/default/waiting')->assertOk();
        self::assertSame(3, $unknown->json('waiting'));
        self::assertNull($unknown->json('in_batches'), 'unknown, never 0');
    }

    /**
     * Rejects: a clear that deletes reserved jobs, answers without its counts, or is not recorded.
     */
    public function test_clear_answers_its_counts_and_records_it(): void
    {
        $reserved = $this->pushed(new BasicJob);
        self::assertNotNull(Queue::connection('database')->pop());
        $this->pushed(new BasicJob);
        $this->pushed(new BasicJob, 3600);
        [$events] = $this->recordAudit();

        $this->postJson('/orrery/api/queues/database/default/clear')->assertOk()->assertExactJson([
            'deleted' => 2, 'mirror_rows' => 2, 'unresolved_rows' => 0, 'undecodable' => 0, 'count_mismatches' => 0,
            'mirror_failed' => false, 'complete' => true, 'reason' => null,
        ]);

        self::assertSame(1, DB::table('jobs')->count(), 'the reserved job stays');
        self::assertNotNull($this->hostRow($reserved));
        self::assertCount(1, $events);
        self::assertSame(['clear', ['connection' => 'database', 'queue' => 'default'], 'ops@example.test', 'done', 2], [$events[0]->action, $events[0]->target, $events[0]->actor, $events[0]->result, $events[0]->counts['deleted']]);
    }

    /**
     * Rejects: a clear that matches the mirror rows of its own connection name only, so the rows of the other alias
     * on the same jobs table stay `pending` for jobs that are gone; also for unresolved bulk rows, which only the probe
     * over every alias finds.
     */
    public function test_clear_removes_the_mirror_rows_of_every_alias(): void
    {
        $own = $this->pushed(new BasicJob);
        $twin = $this->pushedOn('database-twin', new BasicJob);
        $before = (int) DB::table('jobs')->max('id');
        Watcher::failOperationsForTesting(['bulk:resolution']);

        try {
            Queue::connection('database-twin')->bulk([new BasicJob]);
        } finally {
            Watcher::failOperationsForTesting([]);
        }

        $bulk = self::uuidOf((string) DB::table('jobs')->where('id', '>', $before)->value('payload'));
        self::assertTrue((bool) $this->rowOrFail($bulk)->job_id_unresolved, 'the bulk row is unresolved');
        self::assertSame('database-twin', $this->rowOrFail($twin)->connection);

        $this->postJson('/orrery/api/queues/database/default/clear')->assertOk()->assertJson(['deleted' => 3, 'mirror_rows' => 3, 'unresolved_rows' => 1, 'complete' => true]);

        self::assertSame(0, DB::table('jobs')->count());
        self::assertNull($this->row($own));
        self::assertNull($this->row($twin), 'the row of the other alias goes too');
        self::assertNull($this->row($bulk), 'and its unresolved bulk row');
    }

    /**
     * Rejects: a clear that compares the queue name by the collation, so a clear of `high` also deletes `HIGH`
     * (MySQL); on the other engines the comparison is byte-exact anyway.
     */
    public function test_clear_matches_the_queue_name_byte_exactly(): void
    {
        $this->pushed(new BasicJob, 0, 'high');
        $upper = $this->pushed(new BasicJob, 0, 'HIGH');

        $this->postJson('/orrery/api/queues/database/high/clear')->assertOk()->assertJson(['deleted' => 1]);

        self::assertSame(1, DB::table('jobs')->count());
        self::assertNotNull($this->hostRow($upper));
        self::assertSame('pending', $this->rowOrFail($upper)->status);
    }

    /**
     * Rejects: a clear that runs every batch however long it takes, and a clear that stops before its first batch.
     */
    public function test_clear_stops_at_its_time_budget(): void
    {
        $this->pushed(new BasicJob);
        $this->pushed(new BasicJob);
        $this->pushed(new BasicJob);
        WaitingJobs::useBatchForTesting(1);
        Clock::freezeMonotonicForTesting();
        DB::connection()->beforeExecuting(static function (string $sql): void {
            if (str_starts_with(strtolower($sql), 'delete from')) {
                Clock::advanceMonotonicForTesting(WaitingJobs::BUDGET_SECONDS + 1);
            }
        });

        $this->postJson('/orrery/api/queues/database/default/clear')->assertOk()->assertJson(['deleted' => 1, 'complete' => false, 'reason' => 'time_budget']);

        self::assertSame(2, DB::table('jobs')->count(), 'the later batches stay');
    }

    /**
     * Rejects: a clear that deletes a batch whose unresolved bulk rows could not be checked, and that says complete.
     */
    public function test_clear_keeps_the_batch_when_the_probe_fails(): void
    {
        $this->pushed(new BasicJob);
        Watcher::failOperationsForTesting(['clear:mirror_probe']);

        $this->postJson('/orrery/api/queues/database/default/clear')->assertOk()->assertJson(['deleted' => 0, 'complete' => false, 'reason' => 'probe_failed']);

        self::assertSame(1, DB::table('jobs')->count());
    }

    /**
     * Rejects: a clear inside an open transaction of the queue connection (its batches would not commit), a clear of
     * a connection that is not a database queue, and a raw 500 on an error of the queue connection.
     */
    public function test_clear_refuses_and_fails_with_codes(): void
    {
        $this->pushed(new BasicJob);
        DB::connection()->beginTransaction();

        try {
            $this->postJson('/orrery/api/queues/database/default/clear')->assertStatus(409)->assertJson(['error' => 'in_transaction']);
        } finally {
            DB::connection()->rollBack();
        }

        $this->app['config']->set('queue.connections.sync', ['driver' => 'sync']);
        $this->postJson('/orrery/api/queues/sync/default/clear')->assertStatus(409)->assertJson(['error' => 'not_database_queue']);
        $this->postJson('/orrery/api/queues/sync/default/waiting')->assertStatus(409)->assertJson(['error' => 'not_database_queue']);

        $failing = true;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$failing): void {
            if ($failing && str_starts_with(strtolower($sql), 'delete from')) {
                throw new QueryException('testbench', $sql, [], new \PDOException('SQLSTATE[HY000]: General error: 1 disk I/O error'));
            }
        });
        $this->postJson('/orrery/api/queues/database/default/clear')->assertStatus(503)->assertJson(['error' => 'write_failed']);
        $failing = false;
        self::assertSame(1, DB::table('jobs')->count());
    }

    /**
     * Rejects: a Delete offered without the facts its sheet needs: the chain that a delete drops, and the
     * unresolved bulk row whose delete must wait (job_id_unknown).
     * Still passes: a chain detected only by the outer command's first `chained` property (a job property that holds
     * another queued job could come first).
     */
    public function test_the_detail_offers_delete_with_its_facts(): void
    {
        $plain = $this->pushed(new BasicJob);
        $detail = $this->getJson("/orrery/api/jobs/{$plain}")->assertOk();
        self::assertTrue($detail->json('actions.delete'));
        self::assertNull($detail->json('actions.pending_reason'));
        self::assertFalse($detail->json('chained'));

        \Illuminate\Support\Facades\Bus::chain([new BasicJob, new BasicJob])->onConnection('database')->dispatch();
        $head = self::uuidOf((string) DB::table('jobs')->orderByDesc('id')->value('payload'));
        self::assertTrue($this->getJson("/orrery/api/jobs/{$head}")->assertOk()->json('chained'));

        $this->mirror()->table('orrery_jobs')->where('uuid', $plain)->update(['job_id' => null, 'job_id_unresolved' => true]);
        self::assertSame('job_id_unknown', $this->getJson("/orrery/api/jobs/{$plain}")->assertOk()->json('actions.pending_reason'));
    }

    private function deleteJob(string $uuid): TestResponse
    {
        return $this->postJson("/orrery/api/jobs/{$uuid}/delete");
    }

    private function pushed(object $job, int $delay = 0, string $queue = 'default'): string
    {
        $queues = Queue::connection('database');
        $id = $delay > 0 ? $queues->later($delay, $job, '', $queue) : $queues->push($job, '', $queue);

        return self::uuidOf((string) DB::table('jobs')->where('id', $id)->value('payload'));
    }

    private function pushedOn(string $connection, object $job): string
    {
        $id = Queue::connection($connection)->push($job);

        return self::uuidOf((string) DB::table('jobs')->where('id', $id)->value('payload'));
    }

    private function hostRow(string $uuid): ?\stdClass
    {
        foreach (DB::table('jobs')->get(['id', 'payload']) as $row) {
            if ((json_decode((string) $row->payload, true)['uuid'] ?? null) === $uuid) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{0: \ArrayObject<int, OperatorActionPerformed>, 1: \ArrayObject<int, MessageLogged>}
     */
    private function recordAudit(): array
    {
        $events = new \ArrayObject;
        $logs = new \ArrayObject;
        Event::listen(OperatorActionPerformed::class, static function (OperatorActionPerformed $event) use ($events): void {
            $events[] = $event;
        });
        Event::listen(MessageLogged::class, static function (MessageLogged $message) use ($logs): void {
            if (str_starts_with($message->message, 'Orrery operator action')) {
                $logs[] = $message;
            }
        });

        return [$events, $logs];
    }
}
