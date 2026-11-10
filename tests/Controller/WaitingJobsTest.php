<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Actions\OperatorAudit;
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
 * POST /api/queues/waiting and POST /api/queues/clear (the connection and
 * the queue in the JSON body; Actions\WaitingJobs, the
 * service that orrery:clear shares). The 403 of a viewer without the
 * operate ability is in OperateAbilityTest and AuthorizationTest.
 */
final class WaitingJobsTest extends HooksTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A queue connection on another jobs table: not an alias.
        $app['config']->set('queue.connections.database-other', [
            'driver' => 'database',
            'connection' => TestEnvironment::PRIMARY,
            'table' => 'other_jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);

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
        self::assertSame('Orrery operator action delete by "ops@example.test" on uuid="'.$uuid.'" connection="database" queue="default" batch_id=null: deleted', $logs[0]->message);
    }

    /**
     * Rejects: a log line that writes a queue name or an actor as it is, so a line break, a carriage return or an
     * escape sequence in it forges another log line or rewrites the terminal; and an event that carries the escaped
     * text instead of the value.
     * Still passes: an escape of the visible characters (only control characters are checked).
     */
    public function test_the_log_line_escapes_control_characters(): void
    {
        $queue = "evil\nOrrery operator action clear by \"admin\"\r\x1b[2K";
        [$events, $logs] = $this->recordAudit();

        $this->queueAction('clear', 'database', $queue)->assertOk();

        self::assertCount(1, $logs);
        $line = $logs[0]->message;
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F\x{80}-\x{9F}]/u', $line, 'no control character reaches the log line');
        self::assertStringContainsString('queue="evil\\nOrrery operator action clear by \\"admin\\"\\r\\u001b[2K"', $line, 'each one is visibly escaped');
        self::assertSame($queue, $events[0]->target['queue'], 'the event keeps the value');

        // DEL and the C1 controls, which JSON leaves as they are, and an actor (Orrery::actor() cleans it already).
        $direct = OperatorAudit::line(new OperatorActionPerformed('clear', ['queue' => "a\u{85}\x7fb"], "x\ny", 0.0, 'done'));
        self::assertSame('Orrery operator action clear by "x\\ny" on queue="a\\u0085\\u007fb": done', $direct);
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

        $this->queueAction('waiting', 'database', 'default')->assertOk()->assertExactJson(['waiting' => 3, 'ready' => 2, 'delayed' => 1, 'in_batches' => 1, 'in_batches_at_least' => false]);

        // A waiting job without a row here (a bulk push not mirrored yet): the batch count is a lower bound.
        $this->mirror()->table('orrery_jobs')->where('uuid', $batched)->delete();
        $lower = $this->queueAction('waiting', 'database', 'default')->assertOk();
        self::assertSame([3, 0, true], self::facts($lower, 'waiting', 'in_batches', 'in_batches_at_least'));

        Watcher::failOperationsForTesting(['clear:count_batches']);
        $unknown = $this->queueAction('waiting', 'database', 'default')->assertOk();
        self::assertSame(3, $unknown->json('waiting'));
        self::assertNull($unknown->json('in_batches'), 'unknown, never 0');
        self::assertFalse($unknown->json('in_batches_at_least'));
    }

    /**
     * Rejects: a queue named in the request path, where `.` and `..` segments are resolved (a browser does), so a
     * clear of `x/../default` deletes the jobs of `default`; and a missing, empty or non-text name read as a default.
     */
    public function test_a_queue_name_with_dot_segments_is_cleared_as_sent(): void
    {
        $default = $this->pushed(new BasicJob);
        $names = ['x/../default', 'reports/./daily', '../other/q'];

        foreach ($names as $name) {
            $this->pushed(new BasicJob, 0, $name);
        }

        foreach ($names as $name) {
            self::assertSame([1, 1], self::facts($this->queueAction('waiting', 'database', $name)->assertOk(), 'waiting', 'ready'), $name);
            self::assertSame(1, $this->queueAction('clear', 'database', $name)->assertOk()->json('deleted'), $name);
        }

        self::assertSame(['default'], DB::table('jobs')->pluck('queue')->all(), 'only the job of default stays');
        self::assertSame('pending', $this->rowOrFail($default)->status);

        foreach ([[], ['connection' => 'database'], ['connection' => 'database', 'queue' => ''], ['connection' => '', 'queue' => 'default'], ['connection' => 'database', 'queue' => ['default']], ['connection' => 'database', 'queue' => 7]] as $body) {
            foreach (['waiting', 'clear'] as $action) {
                $this->postJson("/orrery/api/queues/{$action}", $body)->assertStatus(422)->assertJsonPath('error', 'invalid');
            }
        }

        self::assertSame(1, DB::table('jobs')->count());
    }

    /**
     * Rejects: a clear whose later batch fails after earlier batches committed, answered as a 503 without counts and
     * without its audit, although jobs are gone.
     */
    public function test_a_later_batch_that_fails_ends_the_clear_with_its_counts(): void
    {
        $this->pushed(new BasicJob);
        $this->pushed(new BasicJob);
        $this->pushed(new BasicJob);
        WaitingJobs::useBatchForTesting(1);
        [$events] = $this->recordAudit();
        $deletes = 0;
        $failing = true;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$deletes, &$failing): void {
            if ($failing && preg_match('/^delete from [`"]?(?!\w*orrery_)\w*jobs[`"]?\s/i', $sql) === 1 && ++$deletes === 2) {
                throw new QueryException('testbench', $sql, [], new \PDOException('SQLSTATE[HY000]: General error: 1 disk I/O error'));
            }
        });

        $response = $this->queueAction('clear', 'database', 'default')->assertOk();
        $failing = false;

        self::assertSame([1, 1, false, 'write_failed'], self::facts($response, 'deleted', 'mirror_rows', 'complete', 'reason'));
        self::assertSame(2, DB::table('jobs')->count(), 'the failed batch and the later one stay');
        self::assertCount(1, $events);
        self::assertSame(['write_failed', 1], [$events[0]->result, $events[0]->counts['deleted']]);
    }

    /**
     * Rejects: a delete inside an open transaction of the queue connection, whose delete would commit or roll back
     * with the application's transaction instead of on its own.
     */
    public function test_delete_refuses_a_queue_connection_inside_a_transaction(): void
    {
        $uuid = $this->pushed(new BasicJob);
        DB::connection()->beginTransaction();

        try {
            self::assertSame('in_transaction', $this->deleteJob($uuid)->assertStatus(409)->json('error'));
        } finally {
            DB::connection()->rollBack();
        }

        self::assertNotNull($this->hostRow($uuid));
        self::assertSame('pending', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a delete that fails at once when SQLite answers SQLITE_BUSY (in WAL, a transaction that read first
     * cannot write after another process committed); it runs again, as a batch of the clear does.
     * Still passes: a retry after another error code (none is raised here).
     */
    public function test_delete_runs_again_after_sqlite_busy(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('SQLITE_BUSY is a SQLite answer; a server engine never repeats the transaction.');
        }

        $uuid = $this->pushed(new BasicJob);
        $busy = 1;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$busy): void {
            if ($busy > 0 && preg_match('/^delete from "\w*jobs"/i', $sql) === 1) {
                $busy--;
                $pdo = new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
                $pdo->errorInfo = ['HY000', 5, 'database is locked'];

                throw new QueryException('testbench', $sql, [], $pdo);
            }
        });

        self::assertSame('deleted', $this->deleteJob($uuid)->assertOk()->json('result'));
        self::assertSame(0, $busy);
        self::assertNull($this->hostRow($uuid));
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

        $this->queueAction('clear', 'database', 'default')->assertOk()->assertExactJson([
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

        // A row of another jobs table (not an alias) with the same job id and queue: it is another job.
        $foreign = '00000000-0000-4000-8000-0000000000cc';
        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => $foreign, 'connection' => 'database-other', 'queue' => 'default', 'name' => BasicJob::class, 'type' => 'job',
            'status' => 'pending', 'silenced' => false, 'monitored' => false, 'job_id' => $this->rowOrFail($own)->job_id, 'payload' => '{}', 'payload_bytes' => 2,
            'pushed_at' => Clock::toDecimal(Clock::now()), 'updated_at' => Clock::toDecimal(Clock::now()),
        ]);

        self::assertSame([3, 3, 1, true], self::facts($this->queueAction('clear', 'database', 'default')->assertOk(), 'deleted', 'mirror_rows', 'unresolved_rows', 'complete'));
        self::assertSame('pending', $this->rowOrFail($foreign)->status, 'the row of the other table stays');

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

        self::assertSame(1, $this->queueAction('clear', 'database', 'high')->assertOk()->json('deleted'));

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

        self::assertSame([1, false, 'time_budget'], self::facts($this->queueAction('clear', 'database', 'default')->assertOk(), 'deleted', 'complete', 'reason'));

        self::assertSame(2, DB::table('jobs')->count(), 'the later batches stay');
    }

    /**
     * Rejects: a clear that deletes a batch whose unresolved bulk rows could not be checked, and that says complete.
     */
    public function test_clear_keeps_the_batch_when_the_probe_fails(): void
    {
        $this->pushed(new BasicJob);
        Watcher::failOperationsForTesting(['clear:mirror_probe']);

        self::assertSame([0, false, 'probe_failed'], self::facts($this->queueAction('clear', 'database', 'default')->assertOk(), 'deleted', 'complete', 'reason'));

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
            $this->queueAction('clear', 'database', 'default')->assertStatus(409)->assertJson(['error' => 'in_transaction']);
        } finally {
            DB::connection()->rollBack();
        }

        $this->app['config']->set('queue.connections.sync', ['driver' => 'sync']);
        $this->queueAction('clear', 'sync', 'default')->assertStatus(409)->assertJson(['error' => 'not_database_queue']);
        $this->queueAction('waiting', 'sync', 'default')->assertStatus(409)->assertJson(['error' => 'not_database_queue']);

        $failing = true;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$failing): void {
            if ($failing && str_starts_with(strtolower($sql), 'delete from')) {
                throw new QueryException('testbench', $sql, [], new \PDOException('SQLSTATE[HY000]: General error: 1 disk I/O error'));
            }
        });
        $this->queueAction('clear', 'database', 'default')->assertStatus(503)->assertJson(['error' => 'write_failed']);
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

        // A chain that is not the first `chained` property of the text (a property holds another job first).
        $nested = 'O:1:"A":2:{s:5:"inner";O:1:"B":1:{s:7:"chained";a:0:{}}s:7:"chained";a:1:{i:0;s:1:"x";}}';
        self::assertTrue(\Justrau\Orrery\Http\Controllers\JobController::chained((string) json_encode(['data' => ['command' => $nested]])));
        self::assertNull(\Justrau\Orrery\Http\Controllers\JobController::chained((string) json_encode(['data' => ['command' => 'eyJpdiI6']])), 'an encrypted command');

        $this->mirror()->table('orrery_jobs')->where('uuid', $plain)->update(['job_id' => null, 'job_id_unresolved' => true]);
        self::assertSame('job_id_unknown', $this->getJson("/orrery/api/jobs/{$plain}")->assertOk()->json('actions.pending_reason'));
    }

    /**
     * POST /api/queues/{waiting|clear} with the connection and the queue in the body.
     */
    private function queueAction(string $action, string $connection, string $queue): TestResponse
    {
        return $this->postJson("/orrery/api/queues/{$action}", ['connection' => $connection, 'queue' => $queue]);
    }

    /**
     * The named values of a JSON answer, in order, compared strictly by the caller.
     *
     * @return list<mixed>
     */
    private static function facts(TestResponse $response, string ...$keys): array
    {
        return array_map(static fn (string $key): mixed => $response->json($key), $keys);
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
