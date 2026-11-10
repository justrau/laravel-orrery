<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Events\OperatorActionPerformed;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Hooks\HooksTestCase;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\TestClock;

/**
 * POST /api/jobs/{uuid}/run-now: a delayed job becomes available now, on
 * the clock of the workers (PHP's), with the mirror row's available_at and
 * a `run_now` history entry. The 403 of a viewer without the operate
 * ability is in OperateAbilityTest and AuthorizationTest.
 */
final class RunNowTest extends HooksTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
        Orrery::actorUsing(static fn (): string => 'ops@example.test');
    }

    /**
     * Rejects: a run-now that moves only one side (the queue's available_at or the mirror's), that changes the
     * release count, the status or the jobs id (a stuck signal could fire), that writes no history entry, or that is
     * not recorded.
     * Still passes: an available_at a second earlier than the request's time (both are whole seconds of PHP's clock).
     */
    public function test_a_delayed_job_becomes_available_now(): void
    {
        $uuid = $this->pushedLater(3600);
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['releases' => 2]);
        $before = $this->rowOrFail($uuid);
        $events = new \ArrayObject;
        Event::listen(OperatorActionPerformed::class, static function (OperatorActionPerformed $event) use ($events): void {
            $events[] = $event;
        });
        $start = time();

        $this->runNow($uuid)->assertOk()->assertExactJson(['id' => $uuid, 'result' => 'available']);

        $host = (int) DB::table('jobs')->where('id', $before->job_id)->value('available_at');
        self::assertGreaterThanOrEqual($start, $host);
        self::assertLessThanOrEqual(time(), $host);
        $after = $this->rowOrFail($uuid);
        self::assertSame($host, (int) Clock::stored($after->available_at), 'the mirror row shows the same time');
        self::assertSame(['pending', 2, (int) $before->job_id, (int) $before->cycle], [$after->status, (int) $after->releases, (int) $after->job_id, (int) $after->cycle]);
        self::assertSame(['run_now'], $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->pluck('event')->all());
        self::assertSame('dashboard', $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->value('source'));

        self::assertCount(1, $events);
        self::assertSame(['run_now', $uuid, 'ops@example.test', 'available'], [$events[0]->action, $events[0]->target['uuid'], $events[0]->actor, $events[0]->result]);

        // The job is now the worker's: it runs.
        $this->work();
        self::assertSame('completed', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a run-now on the database clock instead of the workers' clock: with the database an hour ahead, the
     * job delayed by 30 minutes reads as not delayed and stays delayed for the workers.
     */
    public function test_it_uses_the_workers_clock_not_the_database_clock(): void
    {
        $uuid = $this->pushedLater(1800);
        TestClock::shift('testbench', 3600);
        TestClock::shift(TestClock::EVERY, 3600);

        $this->runNow($uuid)->assertOk()->assertJson(['result' => 'available']);

        self::assertLessThanOrEqual(time(), (int) DB::table('jobs')->value('available_at'));
    }

    /**
     * Rejects: `available` for a job that is ready already, a reserved job, a job whose row is gone, an unresolved
     * bulk row and an unknown job; and a job that changes in the queue table.
     */
    public function test_it_answers_every_other_result(): void
    {
        $ready = $this->pushedLater(0);
        $readyAt = (int) DB::table('jobs')->value('available_at');
        $this->runNow($ready)->assertOk()->assertJson(['result' => 'not_delayed']);
        self::assertSame($readyAt, (int) DB::table('jobs')->value('available_at'));
        self::assertNotNull(Queue::connection('database')->pop(), 'the ready job is reserved');
        $this->runNow($ready)->assertOk()->assertJson(['result' => 'not_pending']);

        $gone = $this->pushedLater(3600);
        DB::table('jobs')->where('id', $this->rowOrFail($gone)->job_id)->delete();
        $this->runNow($gone)->assertOk()->assertJson(['result' => 'gone']);

        $unresolved = $this->pushedLater(3600);
        $this->mirror()->table('orrery_jobs')->where('uuid', $unresolved)->update(['job_id' => null, 'job_id_unresolved' => true]);
        $this->runNow($unresolved)->assertOk()->assertJson(['result' => 'job_id_unknown']);

        $this->runNow('00000000-0000-4000-8000-000000000001')->assertNotFound()->assertJson(['error' => 'not_found']);
        self::assertSame(0, $this->mirror()->table('orrery_job_history')->where('event', 'run_now')->count(), 'no result but available writes history');
    }

    /**
     * Rejects: a run-now without the re-checked `reserved_at IS NULL`, which moves the available_at of a job that a
     * worker reserved after the mirror row was read.
     */
    public function test_a_reservation_meanwhile_wins(): void
    {
        $uuid = $this->pushedLater(3600);
        $id = (int) $this->rowOrFail($uuid)->job_id;
        $at = (int) DB::table('jobs')->where('id', $id)->value('available_at');
        $done = false;

        DB::connection()->beforeExecuting(static function (string $sql) use (&$done, $id): void {
            // The lock of the queue's row (it reads the payload), never a statement of orrery_jobs.
            if (! $done && str_contains($sql, 'payload') && preg_match('/from\s+[`"]?(?!\w*orrery_)\w*jobs[`"]?(\s|$)/i', $sql) === 1) {
                $done = true;
                DB::table('jobs')->where('id', $id)->update(['reserved_at' => time(), 'attempts' => 1]);
            }
        });

        $this->runNow($uuid)->assertOk()->assertJson(['result' => 'not_pending']);

        self::assertTrue($done, 'the reservation ran before the update');
        self::assertSame($at, (int) DB::table('jobs')->where('id', $id)->value('available_at'));
    }

    /**
     * Rejects: a Run now offered on a job that is ready already, or not offered on a delayed one.
     */
    public function test_the_detail_offers_run_now_on_a_delayed_job_only(): void
    {
        $delayed = $this->pushedLater(3600);
        $ready = $this->pushedLater(0);

        self::assertTrue($this->getJson("/orrery/api/jobs/{$delayed}")->assertOk()->json('actions.run_now'));
        self::assertFalse($this->getJson("/orrery/api/jobs/{$ready}")->assertOk()->json('actions.run_now'));
    }

    /**
     * Rejects: a run-now inside an open transaction of the queue connection (its update would commit or roll back
     * with the application's), and a run-now that fails at once on SQLITE_BUSY instead of running again.
     * Still passes: a retry after another error code (none is raised here).
     */
    public function test_it_refuses_a_transaction_and_runs_again_after_sqlite_busy(): void
    {
        $uuid = $this->pushedLater(3600);
        $at = (int) DB::table('jobs')->value('available_at');
        DB::connection()->beginTransaction();

        try {
            self::assertSame('in_transaction', $this->runNow($uuid)->assertStatus(409)->json('error'));
        } finally {
            DB::connection()->rollBack();
        }

        self::assertSame($at, (int) DB::table('jobs')->value('available_at'));

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $busy = 1;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$busy): void {
            if ($busy > 0 && preg_match('/^update "\w*jobs" set "available_at"/i', $sql) === 1) {
                $busy--;
                $pdo = new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
                $pdo->errorInfo = ['HY000', 5, 'database is locked'];

                throw new \Illuminate\Database\QueryException('testbench', $sql, [], $pdo);
            }
        });

        self::assertSame('available', $this->runNow($uuid)->assertOk()->json('result'));
        self::assertSame(0, $busy);
    }

    private function runNow(string $uuid): TestResponse
    {
        return $this->postJson("/orrery/api/jobs/{$uuid}/run-now");
    }

    private function pushedLater(int $delay): string
    {
        $queue = Queue::connection('database');
        $id = $delay > 0 ? $queue->later($delay, new BasicJob) : $queue->push(new BasicJob);

        return self::uuidOf((string) DB::table('jobs')->where('id', $id)->value('payload'));
    }
}
