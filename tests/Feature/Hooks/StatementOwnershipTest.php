<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobQueueing;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * The transaction of a queue statement end to end, with real queue calls,
 * decided by the commit deferral.
 */
#[FreshSchema]
final class StatementOwnershipTest extends EndToEndTestCase
{
    /**
     * The level rose during the call, so the item is ambiguous: it waits for
     * T, and T's rollback drops it and counts it as unverified.
     *
     * Rejects: a transaction level read only before the call, and an
     * ambiguous item counted as a plain rollback.
     */
    public function test_swap_on_insert_after_before_executing_transaction_rolls_back_with_it(): void
    {
        $began = false;

        $this->q()->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use (&$began): void {
            if (! $began && self::isJobsInsert($query)) {
                $began = true;
                $connection->beginTransaction();
            }
        });

        $id = $this->push(new BasicJob);

        self::assertTrue($began);
        self::assertSame(1, $this->q()->transactionLevel(), 'The application callback began T before the insert.');
        $uuid = $this->uuidOfJob($id);
        self::assertNull($this->row($uuid), 'The observation waits for T.');

        $this->q()->rollBack();

        self::assertNull($this->qJob($id), 'The insert ran inside T.');
        self::assertNull($this->row($uuid), 'T\'s rollback discards the observation.');
        self::assertSame(0, $this->mirrorCount());
        self::assertSame(1, self::unverified(), 'The ambiguous item of the rolled-back T is counted as unverified.');
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();
    }

    /**
     * Rejects: an observation that runs at once inside a transaction.
     */
    public function test_insert_inside_a_rolled_back_queue_transaction_is_discarded(): void
    {
        $this->q()->beginTransaction();
        $id = $this->push(new BasicJob);
        $uuid = $this->uuidOfJob($id);
        self::assertNull($this->row($uuid), 'Nothing runs while Q is in the transaction.');
        $this->q()->rollBack();

        self::assertSame([], $this->qJobs());
        self::assertSame(0, $this->mirrorCount());
        self::assertSame(0, self::unverified());
        self::assertSame(1, self::rollbackUnverified(), 'The rolled-back push is counted as a rollback.');

        $this->q()->beginTransaction();
        $committed = $this->push(new BasicJob);
        $this->q()->commit();

        self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($committed))->status, 'The control: a committed insert is mirrored.');
        self::assertSame(1, self::rollbackUnverified());
        $this->assertNothingDeferred();
    }

    /**
     * The outer push raised at level 0 and returned at level 1 (the listener
     * began T inside the call), so its item is ambiguous and waits for T;
     * T's rollback drops it and counts it unverified, although its insert
     * committed in autocommit (R7
     * creates its row at the reservation). The nested push ran inside T: T's
     * rollback discards it and counts it as a rollback.
     *
     * Rejects: one capture shared by nested dispatches, and an outer item
     * run at once because the call began at level 0.
     *
     * The nested push runs inside the outer insert's QueryExecuted. Laravel's
     * insertGetId() then reads lastInsertId() after the nested insert (except
     * on Postgres, which returns the id), so the outer push returns the
     * nested id. The test therefore names each job by the uuid of its
     * JobQueueing payload, not by the returned id.
     */
    public function test_nested_dispatch_from_a_query_listener_keeps_each_owner(): void
    {
        $nested = false;
        $test = $this;
        $uuids = [];

        $this->app['events']->listen(JobQueueing::class, static function (JobQueueing $event) use (&$uuids): void {
            $uuids[] = self::uuidOf((string) $event->payload);
        });
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$nested, $test): void {
            if (! $nested && $event->connectionName === self::Q && self::isJobsInsert($event->sql)) {
                $nested = true;
                $event->connection->beginTransaction();
                $test->push(new BasicJob);
            }
        });

        $this->push(new BasicJob);

        self::assertTrue($nested);
        self::assertSame(1, $this->q()->transactionLevel(), 'The nested push runs inside T.');
        self::assertCount(2, $uuids);
        [$outerUuid, $nestedUuid] = $uuids;

        self::assertNull($this->row($outerUuid), 'The ambiguous outer item waits for T.');
        self::assertNull($this->row($nestedUuid), 'The nested insert waits for T.');

        $this->q()->rollBack();

        $jobs = $this->qJobs();
        self::assertCount(1, $jobs);
        self::assertSame($outerUuid, self::uuidOf((string) $jobs[0]->payload), 'Only the outer insert committed.');
        self::assertNull($this->row($outerUuid), "T's rollback drops the ambiguous outer item.");
        self::assertNull($this->row($nestedUuid), "T's rollback discards the nested observation.");
        self::assertSame(0, $this->mirrorCount());
        self::assertSame(1, self::unverified(), 'The ambiguous outer item is counted as unverified.');
        self::assertSame(1, self::rollbackUnverified(), 'The nested item is counted as a rollback.');
        $this->assertNothingDeferred();

        // A later observation repairs the outer row: R7 at its reservation.
        $job = $this->popJob();
        self::assertSame($outerUuid, self::uuidOf($job->getRawBody()));
        self::assertSame('reserved', $this->rowOrFail($outerUuid)->status, 'R7 creates the dropped row at the reservation.');
    }
}
