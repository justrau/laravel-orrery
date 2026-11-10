<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository as LaravelDatabaseBatchRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Events\OperatorActionPerformed;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Tests\Controller\Fakes\AuthorizationJob;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * POST /api/batches/{id}/cancel: one guarded update of the batch table,
 * as Laravel's Batch::cancel() does, without building a Batch. The 403 of a
 * viewer without the operate ability is in OperateAbilityTest and
 * AuthorizationTest.
 */
final class BatchCancelTest extends ControllerTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Orrery::actorUsing(static fn (): string => 'ops@example.test');
    }

    /**
     * Rejects: a cancel that sets only cancelled_at (Laravel's Batch::cancel() also finishes the batch), a time
     * from another clock than PHP's, a batch that Laravel does not read as cancelled, and a cancel without its audit.
     */
    public function test_a_running_batch_is_cancelled_and_finished(): void
    {
        $id = $this->batch();
        $events = new \ArrayObject;
        Event::listen(OperatorActionPerformed::class, static function (OperatorActionPerformed $event) use ($events): void {
            $events[] = $event;
        });
        $start = time();

        $response = $this->cancel($id)->assertOk();

        $row = $this->batchRow($id);
        self::assertNotNull($row);
        self::assertSame((int) $row->cancelled_at, (int) $row->finished_at);
        self::assertGreaterThanOrEqual($start, (int) $row->cancelled_at);
        self::assertLessThanOrEqual(time(), (int) $row->cancelled_at);
        self::assertSame(['id' => $id, 'result' => 'cancelled', 'cancelled_at' => (int) $row->cancelled_at], $response->json());

        $batch = $this->app->make(BatchRepository::class)->find($id);
        self::assertTrue($batch?->cancelled(), "Laravel's repository reads the batch as cancelled");
        self::assertTrue($batch->finished());

        self::assertCount(1, $events);
        self::assertSame(['cancel_batch', ['batch_id' => $id], 'ops@example.test', 'cancelled'], [$events[0]->action, $events[0]->target, $events[0]->actor, $events[0]->result]);
    }

    /**
     * Rejects: a second cancel that answers 200 again or moves the time, a cancel of a finished batch, and an unknown
     * batch answered as anything but 404.
     */
    public function test_a_cancelled_finished_or_unknown_batch_is_refused(): void
    {
        $cancelled = $this->batch();
        $this->cancel($cancelled)->assertOk();
        $first = $this->batchRow($cancelled)?->cancelled_at;
        $this->cancel($cancelled)->assertStatus(409)->assertJson(['error' => 'already_cancelled']);
        self::assertSame($first, $this->batchRow($cancelled)?->cancelled_at);

        $finished = $this->batch();
        $this->batchTable()->where('id', $finished)->update(['finished_at' => 1000]);
        $this->cancel($finished)->assertStatus(409)->assertJson(['error' => 'already_finished']);
        self::assertNull($this->batchRow($finished)?->cancelled_at);

        $this->cancel('00000000-0000-4000-8000-000000000001')->assertNotFound()->assertJson(['error' => 'not_found']);
    }

    /**
     * Rejects: a cancel of a batch that finished between the request and the update (an unguarded update), and a
     * 404 or a 200 for that race: the batch is finished, so it is refused as already_finished.
     */
    public function test_a_batch_that_finishes_meanwhile_is_not_cancelled(): void
    {
        $id = $this->batch();
        $done = false;
        PackageStatements::beforeEach($this->app, TestEnvironment::PRIMARY, function (string $sql) use (&$done, $id): void {
            if (! $done && str_starts_with(strtolower(ltrim($sql)), 'update') && str_contains($sql, 'cancelled_at')) {
                $done = true;
                $this->batchTable()->where('id', $id)->update(['finished_at' => 1000, 'pending_jobs' => 0]);
            }
        });

        $this->cancel($id)->assertStatus(409)->assertJson(['error' => 'already_finished']);

        self::assertTrue($done, 'the batch finished just before the update');
        self::assertNull($this->batchRow($id)?->cancelled_at);
    }

    /**
     * Rejects: an id compared by the collation, so another letter case cancels the batch (MySQL); on the other
     * engines the comparison is byte-exact anyway.
     */
    public function test_the_id_is_byte_exact(): void
    {
        $id = $this->batch();

        $this->cancel(strtoupper($id))->assertNotFound();

        self::assertNull($this->batchRow($id)?->cancelled_at);
    }

    /**
     * Rejects: a cancel through a repository that Orrery does not read (it could store batches elsewhere).
     */
    public function test_an_unsupported_repository_is_refused(): void
    {
        $id = $this->batch();
        $this->app->instance(BatchRepository::class, new class($this->app->make(\Illuminate\Bus\BatchFactory::class), $this->app['db']->connection(), 'job_batches') extends LaravelDatabaseBatchRepository {
        });

        $this->cancel($id)->assertStatus(409)->assertJson(['error' => 'unsupported_repository']);

        self::assertNull($this->batchRow($id)?->cancelled_at);
    }

    private function cancel(string $id): TestResponse
    {
        return $this->postJson('/orrery/api/batches/'.rawurlencode($id).'/cancel');
    }

    private function batch(): string
    {
        return Bus::batch([new AuthorizationJob])->allowFailures()->onConnection('database')->dispatch()->id;
    }

    private function batchRow(string $id): ?\stdClass
    {
        /** @var \stdClass|null */
        return $this->batchTable()->where('id', $id)->first(['id', 'cancelled_at', 'finished_at']);
    }

    private function batchTable(): \Illuminate\Database\Query\Builder
    {
        return DB::connection(TestEnvironment::PRIMARY)->table('job_batches');
    }
}
