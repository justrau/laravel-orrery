<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The swapped hooks capture the connection instance that runs the queue
 * statement, not the instance that the database manager resolves for its
 * name (the deferral tracks each instance; `q::write` and purge cases):
 * - a queue on `hooks_q::write`: Laravel holds a distinct instance whose
 *   name is `hooks_q`;
 * - a queue resolved on `hooks_q`, then `DB::purge('hooks_q')` and
 *   `hooks_q` resolved again: the cached queue keeps its original instance.
 * Both need a second instance of one database, so they skip on in-memory
 * SQLite, where a second instance is another database.
 */
#[FreshSchema]
final class QueueInstanceTest extends EndToEndTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set after the migrations, as before (the old fence migration refused
        // `hooks_q::write`; the main migration reads no queue connection now).
        $this->app['config']->set('queue.connections.hooks_write', [
            'driver' => 'database',
            'connection' => self::Q.'::write',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
    }

    protected function tearDown(): void
    {
        // The migrations roll back with the same fence migration.
        $this->app?->make('config')->offsetUnset('queue.connections.hooks_write');

        parent::tearDown();
    }

    /**
     * Rejects: a capture that resolves the queue's connection by its name.
     */
    #[DataProvider('instances')]
    public function test_hooks_observe_the_queue_objects_connection_instance(string $case, bool $commit): void
    {
        $this->skipOnInMemory();
        [$queue, $db] = $this->queueUnderTest($case);

        $db->beginTransaction();
        $id = (int) $queue->push(new BasicJob);
        $uuid = self::uuidOf((string) $db->table('jobs')->where('id', $id)->value('payload'));
        $job = $queue->pop();
        self::assertInstanceOf(DatabaseJob::class, $job);

        if (! $commit) {
            $db->rollBack();

            self::assertSame(0, $db->table('jobs')->count());
            self::assertSame(0, $this->mirrorCount(), 'The rolled-back push and reservation leave no row.');
            self::assertSame(0, self::unverified(), 'Each statement was decided by its own instance: nothing is unverified.');
            self::assertSame(2, self::rollbackUnverified(), 'The push and the reservation of the rolled-back root are counted as a rollback.');
            $this->assertNothingDeferred();

            return;
        }

        $db->commit();

        $row = $this->rowOrFail($uuid);
        self::assertSame('reserved', $row->status, 'The push and the reservation are mirrored.');
        self::assertSame($id, (int) $row->job_id);
        self::assertSame(1, (int) $row->attempts);
        self::assertSame(ProcessContext::current()->identity(), $row->reserved_by, 'The verified reservation names its worker.');

        $job->release(0);

        $replacement = (int) $db->table('jobs')->value('id');
        self::assertNotSame($id, $replacement);
        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'The release is mirrored.');
        self::assertSame($replacement, (int) $row->job_id);
        self::assertSame(1, (int) $row->releases);
        self::assertSame(0, self::unverified(), 'No observation was dropped.');
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function instances(): array
    {
        return [
            'a q::write queue, commit' => ['write', true],
            'a q::write queue, rollback' => ['write', false],
            'a queue kept across a purge, commit' => ['purge', true],
            'a queue kept across a purge, rollback' => ['purge', false],
        ];
    }

    /**
     * @return array{0: LaravelDatabaseQueue, 1: Connection}
     */
    private function queueUnderTest(string $case): array
    {
        if ($case === 'write') {
            $queue = $this->queue('hooks_write');
            $db = $queue->getDatabase();
            self::assertNotSame($this->q(), $db, 'The write instance is not the base instance.');
            self::assertSame(self::Q, $db->getName(), 'Both instances have one name.');

            return [$queue, $db];
        }

        $queue = $this->queue(self::QUEUE);
        $db = $queue->getDatabase();
        DB::purge(self::Q);
        $replacement = DB::connection(self::Q);
        self::assertNotSame($db, $replacement, 'The purge made a replacement instance.');
        self::assertSame($db, $this->queue(self::QUEUE)->getDatabase(), 'The cached queue keeps its instance.');

        return [$queue, $db];
    }
}
