<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Bulk;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Feature\Bulk\Jobs\InnerBulkJob;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Each bulk scope is isolated from an outer bulk.
 * An outer bulk on Q classifies a job whose
 * tags() runs an inner bulk inside a transaction, on another database
 * connection R or on Q itself. The inner floor read has a data error, so
 * the inner bulk is not observed. Its records never enter the outer
 * capture, and its unarmed insert never satisfies the outer arming.
 *
 * These cases force the Laravel 12 path, so on Laravel 12 they run the real
 * parent::bulk() of that release.
 */
#[FreshSchema]
final class NestedBulkIsolationTest extends BulkTestCase
{
    private const R = 'hooks_r';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $r = (array) $config->get('database.connections.'.TestEnvironment::PRIMARY);

        if (($r['driver'] ?? null) === 'sqlite') {
            // R has its own in-memory database with a jobs table.
            $r['database'] = ':memory:';
            $r['url'] = null;
        }

        $config->set('database.connections.'.self::R, $r);
        $config->set('queue.connections.'.self::R, [
            'driver' => 'database',
            'connection' => self::R,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        InnerBulkJob::$during = null;

        if ($this->testEnvironment()->driver === 'sqlite') {
            $this->r()->getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedSmallInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    protected function tearDown(): void
    {
        InnerBulkJob::$during = null;
        Watcher::failOperationsForTesting([]);

        if ($this->app !== null) {
            $r = $this->app['db']->getConnections()[self::R] ?? null;

            if ($r instanceof Connection && $r->transactionLevel() > 0) {
                $r->rollBack(0);
            }

            if ($r instanceof Connection && $this->testEnvironment()->driver !== 'sqlite') {
                $r->table('jobs')->where('queue', 'inner')->delete();
            }
        }

        parent::tearDown();
    }

    /**
     * On another connection, the inner transaction decides nothing about
     * Q. On Q itself it was born and ended inside the outer call, so the
     * outer bulk is a counted drop.
     *
     * Rejects: an unobserved nested bulk whose records enter the outer
     * capture, and an outer bulk run at once although a transaction of Q
     * was born and ended inside its call.
     */
    #[DataProvider('innerBulks')]
    public function test_unobserved_inner_bulk_is_isolated_from_the_outer_bulk(bool $sameConnection, bool $commit): void
    {
        $this->usePath(self::PATH_12);
        $inner = $sameConnection ? $this->q() : $this->r();
        $innerQueue = $sameConnection ? self::QUEUE : self::R;

        InnerBulkJob::$during = static function () use ($inner, $innerQueue, $commit): void {
            $inner->beginTransaction();
            Watcher::failOperationsForTesting(['hook:bulk_floor']);

            try {
                Queue::connection($innerQueue)->bulk([new BasicJob], '', 'inner');
            } finally {
                Watcher::failOperationsForTesting([]);
            }

            if ($commit) {
                $inner->commit();
            } else {
                $inner->rollBack();
            }
        };

        $this->bulk([new InnerBulkJob]);

        self::assertNull(InnerBulkJob::$during, 'The outer bulk classified the job, which ran the inner bulk.');
        self::assertSame($commit ? 1 : 0, $inner->table('jobs')->where('queue', 'inner')->count(), 'The inner bulk committed or rolled back as the application said.');

        $outer = $this->q()->table('jobs')->where('queue', 'default')->get();
        self::assertCount(1, $outer, 'The outer bulk inserted its one job.');
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->where('queue', 'inner')->count(), 'The unobserved inner bulk has no P2 row.');
        $this->assertNothingDeferred();

        if ($sameConnection) {
            // The inner transaction on Q was born and ended inside the outer
            // bulk's call: the deferral cannot prove the outer insert's
            // outcome, so it drops and counts it, and R7
            // creates the row at the reservation.
            self::assertSame(0, $this->mirrorCount(), 'The outer bulk has no P2 row.');
            self::assertSame(1, self::unverified(), 'The outer bulk is counted once.');
            self::assertSame(0, self::rollbackUnverified());

            $job = $this->hooks()->pop();
            self::assertNotNull($job);
            self::assertSame('reserved', $this->rowOrFail(self::uuidOf((string) $outer[0]->payload))->status, 'R7 creates the row at the reservation.');

            return;
        }

        $row = $this->rowOrFail(self::uuidOf((string) $outer[0]->payload));
        self::assertSame('pending', $row->status, 'The committed outer job keeps its row.');
        self::assertSame(self::QUEUE, $row->connection);
        self::assertSame('default', $row->queue);
        self::assertSame((int) $outer[0]->id, (int) $row->job_id);
        self::assertFalse((bool) $row->job_id_unresolved);

        self::assertSame(0, $this->mirror()->table('orrery_jobs')->where('queue', 'inner')->count(), 'The unobserved inner bulk has no P2 row.');
        self::assertSame(1, $this->mirrorCount(), 'Only the outer job has a mirror row.');
        self::assertSame(0, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function innerBulks(): array
    {
        return [
            'another database connection, commit' => [false, true],
            'another database connection, rollback' => [false, false],
            'the same database connection, commit' => [true, true],
            'the same database connection, rollback' => [true, false],
        ];
    }

    private function r(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->app['db']->connection(self::R);

        return $connection;
    }
}
