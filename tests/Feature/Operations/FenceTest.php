<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Connection;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestClock;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;

/**
 * The fence primitives of the operations (`orrery_fences`), on
 * the queue database's own package session. Kind: new behaviour.
 */
#[FreshSchema]
final class FenceTest extends TestCase
{
    private bool $createdFence = false;

    protected function setUp(): void
    {
        parent::setUp();

        // The fence of the queue database: the mirror's own table when the mirror shares the
        // database, else a table that this test creates (the install writes it in production).
        $schema = $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder();

        if (! $schema->hasTable(Fence::TABLE)) {
            PackageSchema::create($schema, [Fence::TABLE]);
            $this->createdFence = true;
        }

        $this->beforeApplicationDestroyed(function (): void {
            if ($this->createdFence) {
                $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->dropIfExists(Fence::TABLE);
            }
        });
    }

    /**
     * Rejects: a fence insert that ignores its deadline, and a created_at from PHP's clock.
     */
    public function test_conditional_insert_inserts_within_the_deadline(): void
    {
        $this->operator(function (Connection $q): void {
            $id = Operations::newId();
            $now = DatabaseClock::now($q);

            self::assertSame(Fence::INSERTED, $this->fence()->insertBefore($q, $id, Fence::PUBLISH, Fence::PUBLISHED, $now + 60));

            $row = $this->fence()->read($q, $id, Fence::PUBLISH);
            self::assertNotNull($row);
            self::assertSame(Fence::PUBLISHED, $row->state);
            self::assertNull($row->job_id);
            self::assertEqualsWithDelta($now + 60, (float) $row->deadline, 0.001);
            self::assertEqualsWithDelta($now, (float) $row->created_at, 30);
        });
    }

    /**
     * Rejects: a deadline checked against PHP's clock or the mirror's clock instead of this database's
     * clock, and a check that the test offset (the bound number of DatabaseClock::expression()) misses.
     */
    public function test_conditional_insert_refuses_after_the_deadline_by_the_database_clock(): void
    {
        $this->operator(function (Connection $q): void {
            $now = DatabaseClock::now($q);

            // Only the queue database's clock passes the deadline; PHP's clock does not move.
            TestClock::shift(TestEnvironment::PRIMARY, 120.0);
            $id = Operations::newId();

            self::assertSame(Fence::DEADLINE_PASSED, $this->fence()->insertBefore($q, $id, Fence::DELETE, Fence::DELETED, $now + 60));
            self::assertNull($this->fence()->read($q, $id, Fence::DELETE), 'Nothing was inserted.');

            // A deadline still ahead of the shifted clock inserts.
            self::assertSame(Fence::INSERTED, $this->fence()->insertBefore($q, $id, Fence::DELETE, Fence::DELETED, $now + 600));
        });
    }

    /**
     * Rejects: a fence insert that overwrites an earlier row of the same operation and slot.
     */
    public function test_conditional_insert_loses_on_the_key(): void
    {
        $this->operator(function (Connection $q): void {
            $id = Operations::newId();
            $now = DatabaseClock::now($q);

            $this->fence()->insertOrIgnore($q, $id, Fence::PUBLISH, Fence::CANCELLED, $now + 60);

            self::assertSame(Fence::DUPLICATE, $this->fence()->insertBefore($q, $id, Fence::PUBLISH, Fence::PUBLISHED, $now + 60));
            self::assertSame(Fence::CANCELLED, $this->fence()->read($q, $id, Fence::PUBLISH)?->state);
        });
    }

    /**
     * Rejects: an insertOrIgnore that replaces the row (a resolver that cancels after the publisher won).
     */
    public function test_insert_or_ignore_keeps_the_first_row(): void
    {
        $this->operator(function (Connection $q): void {
            $id = Operations::newId();
            $now = DatabaseClock::now($q);

            self::assertSame(Fence::INSERTED, $this->fence()->insertBefore($q, $id, Fence::PUBLISH, Fence::PUBLISHED, $now + 60));
            self::assertSame(1, $this->fence()->setJobId($q, $id, 42));

            $this->fence()->insertOrIgnore($q, $id, Fence::PUBLISH, Fence::CANCELLED, $now + 60);

            $row = $this->fence()->read($q, $id, Fence::PUBLISH);
            self::assertSame(Fence::PUBLISHED, $row?->state);
            self::assertSame(42, (int) $row->job_id);

            // Another slot of the same operation is another key.
            $this->fence()->insertOrIgnore($q, $id, Fence::DELETE, Fence::DELETE_CANCELLED, $now + 60);
            self::assertSame(Fence::DELETE_CANCELLED, $this->fence()->read($q, $id, Fence::DELETE)?->state);
        });
    }

    /**
     * Rejects: a restore row without its new record id, and a plain insert that ignores a duplicate.
     */
    public function test_insert_writes_the_job_id_and_refuses_a_duplicate(): void
    {
        $this->operator(function (Connection $q): void {
            $id = Operations::newId();
            $now = DatabaseClock::now($q);

            $this->fence()->insert($q, $id, Fence::RESTORE, Fence::RESTORED, $now + 60, 77);
            self::assertSame(77, (int) $this->fence()->read($q, $id, Fence::RESTORE)?->job_id);

            $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
            $this->fence()->insert($q, $id, Fence::RESTORE, Fence::RESTORED, $now + 60, 78);
        });
    }

    /**
     * Rejects: an availability check on every call (a retry would read the schema each time), and an
     * answer kept forever (a table created later stays missing).
     */
    public function test_available_checks_the_table_and_keeps_the_answer_for_60_seconds(): void
    {
        $this->operator(function (): void {
            self::assertTrue($this->fence()->available(TestEnvironment::PRIMARY));

            $schema = $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder();
            $schema->rename(Fence::TABLE, 'orrery_fences_away');

            try {
                self::assertTrue($this->fence()->available(TestEnvironment::PRIMARY), 'The answer is kept.');

                Clock::advanceMonotonicForTesting(Fence::AVAILABILITY_SECONDS + 1);
                self::assertFalse($this->fence()->available(TestEnvironment::PRIMARY), 'After 60 s the table is checked again.');
            } finally {
                $schema->rename('orrery_fences_away', Fence::TABLE);
            }

            self::assertFalse($this->fence()->available(TestEnvironment::PRIMARY), 'A missing table is kept too.');
            Clock::advanceMonotonicForTesting(Fence::AVAILABILITY_SECONDS + 1);
            self::assertTrue($this->fence()->available(TestEnvironment::PRIMARY));
        });
    }

    private function fence(): Fence
    {
        return $this->app->make(Fence::class);
    }

    /**
     * Runs $work as an operator action on the queue database's session (a host SQLite file
     * takes writes only there).
     *
     * @param  callable(Connection): void  $work
     */
    private function operator(callable $work): void
    {
        $sessions = $this->app->make(PackageSession::class);
        $sessions->operator(function () use ($sessions, $work): void {
            $work($sessions->forAction(TestEnvironment::PRIMARY));
        });
    }
}
