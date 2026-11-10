<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Registration\Modules\StorageModule;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\TestClock;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;

/**
 * The test seam of the database clock.
 * Kind: new behaviour.
 */
final class DatabaseClockOffsetTest extends TestCase
{
    /**
     * Rejects: an offset that only now() applies (a conditional statement through expression() would
     * then compare with the real clock), and an offset written into the SQL text instead of a binding.
     */
    public function test_offset_shifts_now_and_the_expression_by_connection(): void
    {
        $queue = $this->queueSession();
        $before = DatabaseClock::now($queue);

        DatabaseClock::$offsetForTesting = static fn (Connection $connection): float => 3600.0;
        $shifted = DatabaseClock::now($queue);
        [$expression, $bindings] = DatabaseClock::expression($queue);

        self::assertEqualsWithDelta($before + 3600, $shifted, 30, 'now() reads the shifted clock.');
        self::assertSame([3600.0], $bindings, 'The offset is one bound number.');
        self::assertStringNotContainsString('3600', (string) $expression->getValue($queue->getQueryGrammar()));

        $value = $queue->selectOne('select '.$expression->getValue($queue->getQueryGrammar()).' as t', $bindings)->t;
        self::assertEqualsWithDelta($shifted, (float) $value, 30, 'A statement that uses expression() sees the same shifted clock.');
    }

    /**
     * Rejects: a seam that shifts every clock (the mirror's and the queue database's must be able to differ),
     * and a reader that caches the file instead of reading it at every call.
     */
    public function test_clock_file_shifts_one_connection_and_is_read_at_every_call(): void
    {
        $queue = $this->queueSession();
        $base = DatabaseClock::now($queue);

        TestClock::shift(TestEnvironment::PRIMARY, 120.0);
        self::assertEqualsWithDelta($base + 120, DatabaseClock::now($queue), 30);

        $other = $this->app['db']->connection(TestEnvironment::PRIMARY);
        self::assertInstanceOf(Connection::class, $other);
        $offset = DatabaseClock::$offsetForTesting;
        self::assertNotNull($offset);
        self::assertSame(0.0, $offset($this->renamed($other, 'orrery.session.another')), 'Another connection keeps the real clock.');

        TestClock::shift(TestEnvironment::PRIMARY, -120.0);
        self::assertEqualsWithDelta($base - 120, DatabaseClock::now($queue), 30, 'The next read sees the new offset.');
        self::assertNotNull(TestClock::file());
    }

    /**
     * Rejects: a seam that the module reset leaves in place, so one test's clock leaks into the next.
     */
    public function test_module_reset_clears_the_offset(): void
    {
        TestClock::shift(TestClock::EVERY, 60.0);
        self::assertNotNull(DatabaseClock::$offsetForTesting);

        StorageModule::resetForTesting();

        self::assertNull(DatabaseClock::$offsetForTesting);
        self::assertNull(TestClock::file(), 'A reset clock has no active file, so no child inherits it.');
        [, $bindings] = DatabaseClock::expression($this->queueSession());
        self::assertSame([], $bindings);
    }

    /**
     * Rejects: a now() that sends the offset as a second statement.
     */
    public function test_shifted_now_is_one_statement(): void
    {
        $queue = $this->queueSession();
        DatabaseClock::$offsetForTesting = static fn (Connection $connection): float => 5.0;
        $statements = [];
        $queue->listen(static function (QueryExecuted $event) use (&$statements): void {
            $statements[] = $event->sql;
        });

        DatabaseClock::now($queue);

        // Package sessions fire no events; in-memory SQLite is the application connection.
        self::assertLessThanOrEqual(1, count($statements));
    }

    private function queueSession(): Connection
    {
        return $this->app->make(PackageSession::class)->forQueue('database');
    }

    private function renamed(Connection $connection, string $name): Connection
    {
        $clone = clone $connection;
        (fn () => $this->config['name'] = $name)->call($clone);

        return $clone;
    }
}
