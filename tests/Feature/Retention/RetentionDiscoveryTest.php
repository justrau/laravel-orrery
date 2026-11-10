<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retention;

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use LogicException;
use PDO;
use UnexpectedValueException;

/**
 * The partition discovery of the retention on every driver:
 * the seek text per driver, and the guard that stops a seek that does not
 * move forward.
 */
final class RetentionDiscoveryTest extends RetentionTestCase
{
    /**
     * The `mariadb` driver gets the nested OR terms of `mysql`. MariaDB
     * without ANSI_QUOTES reads a double-quoted name as a string literal, so
     * the row-value text would compare constants. No MariaDB server runs
     * here, so the test compiles the seek on a connection that only names
     * the driver.
     *
     * Rejects: a driver check that sends `mariadb` the row-value text with
     * double-quoted names, or any seek text for `mariadb` that differs from
     * the `mysql` text. Would still pass: a `mysql` text that is itself
     * wrong (RetentionDiscoveryPlanTest runs it on MySQL).
     */
    public function test_the_mariadb_seek_uses_the_mysql_nested_terms(): void
    {
        $after = ['database', 'default', 'completed', false, false];
        $mariadb = Retention::discovery($this->pretend(MariaDbConnection::class, 'mariadb'), $after);
        $mysql = Retention::discovery($this->pretend(MySqlConnection::class, 'mysql'), $after);

        self::assertStringNotContainsString('"', $mariadb->toSql(), 'No double-quoted name reaches MariaDB.');
        self::assertStringContainsString('`connection` > ?', $mariadb->toSql());
        self::assertSame($mysql->toSql(), $mariadb->toSql());
        self::assertSame($mysql->getBindings(), $mariadb->getBindings());
    }

    /**
     * A seek whose predicate is a constant true (the effect of the old
     * MariaDB text with a connection name that sorts before "connection")
     * returns the first tuple again. The discovery throws instead of
     * looping for ever.
     *
     * Rejects: a discovery loop without the guard (the grammar below stops
     * it after 20 seeks with another exception), and a guard that compares
     * only the first seek. Would still pass: a guard that throws on a
     * repeat but lets a predicate that is a constant false end the
     * discovery after the first tuple (the driver test above covers that
     * text).
     */
    public function test_a_seek_that_does_not_move_forward_throws(): void
    {
        $now = Clock::now();
        $this->jobRow(['queue' => 'a', 'finished_at' => $now - 7200]);
        $this->jobRow(['queue' => 'b', 'finished_at' => $now - 7200]);
        $mirror = $this->app->make(PackageSession::class)->mirror();

        if ($mirror->getDriverName() !== 'sqlite') {
            self::markTestSkipped('The row-value seek runs on SQLite and Postgres; the grammar below is the SQLite one.');
        }

        $mirror->setQueryGrammar(new class($mirror) extends SQLiteGrammar
        {
            private int $seeks = 0;

            /**
             * @param  array<string, mixed>  $where
             */
            protected function whereRaw(Builder $query, $where): string
            {
                if (! str_contains((string) $where['sql'], '("connection"')) {
                    return parent::whereRaw($query, $where);
                }

                if (++$this->seeks > 20) {
                    throw new LogicException('The discovery looped.');
                }

                // A constant true with the five bindings of the seek.
                return '(? IS NOT NULL AND ? IS NOT NULL AND ? IS NOT NULL AND ? IS NOT NULL AND ? IS NOT NULL)';
            }
        });

        try {
            $this->retention()->run();
            self::fail('The discovery did not throw.');
        } catch (UnexpectedValueException $e) {
            self::assertStringContainsString('did not move forward', $e->getMessage());
        } finally {
            $mirror->useDefaultQueryGrammar();
        }

        self::assertSame(2, $this->mirror()->table('orrery_jobs')->count(), 'Nothing was trimmed.');
    }

    /**
     * @param  class-string<Connection>  $class
     */
    private function pretend(string $class, string $driver): Connection
    {
        return new $class(static fn (): PDO => throw new LogicException('The pretend connection never connects.'), 'pretend', '', ['driver' => $driver, 'name' => 'pretend']);
    }
}
