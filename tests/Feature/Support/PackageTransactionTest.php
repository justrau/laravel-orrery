<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Storage\PackageTransactionBusy;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageSessionHelpers;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PackageTransaction::run() and active().
 *
 * The SQLite contention tests use their own SQLite file in the default
 * rollback journal mode, so every SQLite run (in memory or with a file)
 * runs them: a second PDO on that file holds the locks.
 */
final class PackageTransactionTest extends TestCase
{
    use PackageSessionHelpers;

    private const BUSY = 'package_transaction.busy';

    /**
     * Rejects: plain BEGIN, which runs the read part of $work first.
     */
    public function test_sqlite_uses_begin_immediate_and_counts_statements(): void
    {
        $this->skipUnlessDriver('sqlite');

        [$file, $session] = $this->contendedSqliteSession();
        $holder = new PDO('sqlite:'.$file);
        $holder->exec('begin immediate');

        $log = [];
        Hooks::$callback = static function (string $point) use (&$log, $holder): void {
            if ($point !== self::BUSY) {
                return;
            }

            $log[] = 'busy';

            if (! in_array('holder commit', $log, true)) {
                $holder->exec('commit');
                $log[] = 'holder commit';
            }
        };

        PackageTransaction::resetCounters();

        $this->transactions()->run($session, static function (Connection $session) use (&$log): void {
            $log[] = 'work';
            $value = (int) $session->table('counter')->where('id', 1)->value('v');
            $session->table('counter')->where('id', 1)->update(['v' => $value + 10]);
        });

        self::assertSame(['busy', 'holder commit', 'work'], $log, '$work runs only after the holder released its lock.');
        self::assertSame(['begin' => 2, 'commit' => 1, 'rollback' => 0], PackageTransaction::sqliteCounters());
        self::assertSame(11, $this->counterValue($file));

        Hooks::$callback = null;
        PackageTransaction::resetCounters();

        $this->transactions()->run($session, static fn (Connection $session) => $session->table('counter')->where('id', 1)->update(['v' => 20]));

        try {
            $this->transactions()->run($session, static function (Connection $session): void {
                $session->table('counter')->where('id', 1)->update(['v' => 30]);

                throw new RuntimeException('work failed');
            });
            self::fail('The exception of $work reaches the caller.');
        } catch (RuntimeException $expected) {
            self::assertSame('work failed', $expected->getMessage());
        }

        self::assertSame(['begin' => 2, 'commit' => 1, 'rollback' => 1], PackageTransaction::sqliteCounters());
        self::assertSame(20, $this->counterValue($file), 'The rolled-back update left no trace.');
    }

    /**
     * Rejects: a busy rule that reads "database is locked" in a
     * QueryException's own message, which holds the bindings: a failed
     * write that binds that text (a job's exception text) is retried three
     * times and counts as a busy mirror. It would still pass a
     * rule that misses a busy error whose code is not in errorInfo; the
     * driver's message of the PDOException still counts.
     */
    public function test_a_binding_that_reads_busy_is_no_busy_error(): void
    {
        $this->skipUnlessDriver('sqlite');

        [, $session] = $this->contendedSqliteSession();
        $calls = 0;
        PackageTransaction::resetCounters();
        $failure = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed');
        $failure->errorInfo = ['23000', 19, 'NOT NULL constraint failed'];

        try {
            $this->transactions()->run($session, static function () use (&$calls, $session, $failure): never {
                $calls++;

                throw new QueryException((string) $session->getName(), 'insert into counter (v) values (?)', ['database is locked'], $failure);
            });
            self::fail('The work error reaches the caller.');
        } catch (QueryException) {
            // The data error, not retried.
        }

        self::assertSame(1, $calls, 'a binding that reads busy was retried');
        self::assertSame(['begin' => 1, 'commit' => 0, 'rollback' => 1], PackageTransaction::sqliteCounters());
    }

    /**
     * Rejects: retrying only the failed statement.
     */
    public function test_sqlite_busy_retries_the_whole_transaction_three_times(): void
    {
        $this->skipUnlessDriver('sqlite');

        [$file, $session] = $this->contendedSqliteSession();

        // 1. A holder that keeps its lock: three attempts, $work never runs.
        $holder = new PDO('sqlite:'.$file);
        $holder->exec('begin immediate');
        $busy = 0;
        Hooks::$callback = static function (string $point) use (&$busy): void {
            if ($point === self::BUSY) {
                $busy++;
            }
        };
        $calls = 0;
        PackageTransaction::resetCounters();

        try {
            $this->transactions()->run($session, static function () use (&$calls): void {
                $calls++;
            });
            self::fail('A lock that is never released ends in PackageTransactionBusy.');
        } catch (PackageTransactionBusy $expected) {
            self::assertStringContainsString('3 attempts', $expected->getMessage());
        }

        self::assertSame(0, $calls);
        self::assertSame(3, $busy);
        self::assertSame(['begin' => 3, 'commit' => 0, 'rollback' => 0], PackageTransaction::sqliteCounters());

        // 2. A holder that changes the row and commits at the first busy
        // hook: attempt 2 reads the committed change.
        $holder->exec('update counter set v = 2 where id = 1');
        $seen = [];
        $committed = false;
        Hooks::$callback = static function (string $point) use ($holder, &$committed): void {
            if ($point === self::BUSY && ! $committed) {
                $holder->exec('commit');
                $committed = true;
            }
        };

        $this->transactions()->run($session, static function (Connection $session) use (&$seen): void {
            $seen[] = $value = (int) $session->table('counter')->where('id', 1)->value('v');
            $session->table('counter')->where('id', 1)->update(['v' => $value + 10]);
        });

        self::assertSame([2], $seen);
        self::assertSame(12, $this->counterValue($file));

        // 3. A reader that holds a shared lock makes the COMMIT busy, after
        // $work read and wrote. The retry runs the whole transaction again
        // and reads the value that changed in between.
        $reader = new PDO('sqlite:'.$file);
        $reader->exec('begin');
        $reader->query('select v from counter')->fetchAll();

        $seen = [];
        $calls = 0;
        Hooks::$callback = static function (string $point) use ($reader): void {
            if ($point !== self::BUSY) {
                return;
            }

            $reader->exec('commit');
            $reader->exec('update counter set v = 100 where id = 1');
        };

        $this->transactions()->run($session, static function (Connection $session) use (&$seen, &$calls): void {
            $calls++;
            $seen[] = $value = (int) $session->table('counter')->where('id', 1)->value('v');
            $session->table('counter')->where('id', 1)->update(['v' => $value + 10]);
        });

        self::assertSame(2, $calls, 'The busy COMMIT repeats the whole transaction.');
        self::assertSame([12, 100], $seen);
        self::assertSame(110, $this->counterValue($file));
    }

    /**
     * Rejects: a run() that commits the part of $work that ran before the throw.
     */
    #[FreshSchema]
    public function test_server_engines_roll_back_on_a_throw(): void
    {
        $this->skipUnlessDriver('mysql', 'pgsql');

        $configured = $this->mirror();
        $this->createScratchTable($configured);
        $mirror = $this->sessions()->mirror();

        try {
            $this->transactions()->run($mirror, static function (Connection $session): void {
                $session->table('session_scratch')->insert(['id' => 1, 'value' => 'before the throw']);

                throw new RuntimeException('work failed');
            });
            self::fail('The exception of $work reaches the caller.');
        } catch (RuntimeException $expected) {
            self::assertSame('work failed', $expected->getMessage());
        }

        self::assertSame(0, $configured->table('session_scratch')->count());
        self::assertSame(0, $mirror->transactionLevel());
        self::assertFalse($this->transactions()->active($mirror));

        $result = $this->transactions()->run($mirror, static function (Connection $session): string {
            $session->table('session_scratch')->insert(['id' => 2, 'value' => 'committed']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['committed'], $configured->table('session_scratch')->pluck('value')->all());
    }

    /**
     * Rejects: reading transactionLevel(), which stays 0 inside a SQLite BEGIN IMMEDIATE.
     */
    public function test_active_reports_an_open_package_transaction(): void
    {
        $mirror = $this->sessions()->mirror();
        $transactions = $this->transactions();

        self::assertFalse($transactions->active($mirror));

        $inside = null;
        $level = null;
        $transactions->run($mirror, static function (Connection $session) use ($transactions, &$inside, &$level): void {
            $inside = $transactions->active($session);
            $level = $session->transactionLevel();
        });

        self::assertTrue($inside);
        self::assertFalse($transactions->active($mirror));

        if ($mirror->getDriverName() === 'sqlite') {
            self::assertSame(0, $level, 'SQLite package transactions bypass Laravel\'s level counter.');
        }
    }

    /**
     * Rejects: a nested SQLite run() that sends BEGIN IMMEDIATE inside an open transaction.
     */
    public function test_sqlite_inside_an_open_transaction_uses_a_savepoint(): void
    {
        $this->skipUnlessDriver('sqlite');

        [$file, $session] = $this->contendedSqliteSession();
        $transactions = $this->transactions();
        PackageTransaction::resetCounters();

        $transactions->run($session, static function (Connection $session) use ($transactions): void {
            $session->table('counter')->where('id', 1)->update(['v' => 5]);

            try {
                $transactions->run($session, static function (Connection $session): void {
                    $session->table('counter')->where('id', 1)->update(['v' => 6]);

                    throw new RuntimeException('inner failed');
                });
            } catch (RuntimeException) {
                // The inner savepoint rolled back; the outer transaction continues.
            }

            $transactions->run($session, static fn (Connection $session) => $session->table('counter')->insert(['id' => 2, 'v' => 7]));
        });

        self::assertSame(5, $this->counterValue($file));
        self::assertSame(7, (int) (new PDO('sqlite:'.$file))->query('select v from counter where id = 2')->fetchColumn());
        self::assertSame(['begin' => 1, 'commit' => 1, 'rollback' => 0], PackageTransaction::sqliteCounters());
    }

    /**
     * Rejects: a savepoint whose failed ROLLBACK TO hides the work's own
     * error from the caller (a busy error then is not retried, and the
     * caller sees "no such savepoint" instead of the cause).
     */
    public function test_a_failed_savepoint_rollback_keeps_the_error_of_the_work(): void
    {
        $this->skipUnlessDriver('sqlite');

        [, $session] = $this->contendedSqliteSession();
        $transactions = $this->transactions();
        $caught = null;

        try {
            $transactions->run($session, static function (Connection $session) use ($transactions): void {
                $transactions->run($session, static function (Connection $session): void {
                    // The work ends the whole transaction, so its savepoint is gone.
                    $session->getPdo()->exec('ROLLBACK');

                    throw new RuntimeException('the work failed');
                });
            });
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame('the work failed', $caught->getMessage());
        self::assertFalse(PackageTransaction::isOpen($session), 'the session still counts an open package transaction');
    }

    /**
     * Rejects: a clone that keeps ERRMODE_SILENT, so a busy BEGIN IMMEDIATE returns false and $work runs without the lock.
     */
    public function test_silent_error_mode_does_not_hide_a_busy_begin(): void
    {
        $this->skipUnlessDriver('sqlite');

        [$file, $session] = $this->contendedSqliteSession([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $holder = new PDO('sqlite:'.$file);
        $holder->exec('begin immediate');

        $log = [];
        Hooks::$callback = static function (string $point) use (&$log, $holder): void {
            if ($point !== self::BUSY) {
                return;
            }

            $log[] = 'busy';

            if (! in_array('holder commit', $log, true)) {
                $holder->exec('commit');
                $log[] = 'holder commit';
            }
        };
        PackageTransaction::resetCounters();

        $this->transactions()->run($session, static function (Connection $session) use (&$log): void {
            $log[] = 'work';
            $value = (int) $session->table('counter')->where('id', 1)->value('v');
            $session->table('counter')->where('id', 1)->update(['v' => $value + 10]);
        });

        self::assertSame(['busy', 'holder commit', 'work'], $log, '$work runs only after the busy BEGIN IMMEDIATE got its lock.');
        self::assertSame(['begin' => 2, 'commit' => 1, 'rollback' => 0], PackageTransaction::sqliteCounters());
        self::assertSame(11, $this->counterValue($file));
        self::assertSame(PDO::ERRMODE_EXCEPTION, $session->getPdo()->getAttribute(PDO::ATTR_ERRMODE));
    }

    /**
     * Rejects: a clone that keeps ERRMODE_SILENT, so a busy COMMIT returns false, run() reports success and the transaction stays open.
     */
    public function test_silent_error_mode_does_not_turn_a_busy_commit_into_success(): void
    {
        $this->skipUnlessDriver('sqlite');

        [$file, $session] = $this->contendedSqliteSession([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);

        // A reader keeps its shared lock, so every COMMIT is busy.
        $reader = new PDO('sqlite:'.$file);
        $reader->exec('begin');
        $reader->query('select v from counter')->fetchAll();

        $busy = 0;
        Hooks::$callback = static function (string $point) use (&$busy): void {
            if ($point === self::BUSY) {
                $busy++;
            }
        };
        $calls = 0;
        PackageTransaction::resetCounters();

        try {
            $this->transactions()->run($session, static function (Connection $session) use (&$calls): void {
                $calls++;
                $value = (int) $session->table('counter')->where('id', 1)->value('v');
                $session->table('counter')->where('id', 1)->update(['v' => $value + 10]);
            });
            self::fail('A COMMIT that is always busy never reports success.');
        } catch (PackageTransactionBusy $expected) {
            self::assertStringContainsString('3 attempts', $expected->getMessage());
        }

        self::assertSame(3, $calls, 'Each attempt runs the whole transaction.');
        self::assertSame(3, $busy);
        self::assertSame(['begin' => 3, 'commit' => 3, 'rollback' => 3], PackageTransaction::sqliteCounters());
        self::assertFalse($this->transactions()->active($session));

        $reader->exec('commit');

        self::assertSame(1, $this->counterValue($file), 'No attempt committed its update.');

        // No transaction is left: another connection takes the write lock at once.
        $other = new PDO('sqlite:'.$file);
        $other->exec('pragma busy_timeout = 0');
        $other->exec('begin immediate');
        $other->exec('rollback');
    }

    /**
     * Rejects: a run() that begins the transaction outside its cleanup, so a throwing TransactionBeginning listener leaves the cached mirror inside it.
     */
    #[FreshSchema]
    public function test_a_throwing_transaction_start_listener_leaves_no_open_transaction(): void
    {
        $this->skipUnlessDriver('mysql', 'pgsql');

        $configured = $this->mirror();
        $this->createScratchTable($configured);
        $mirror = $this->sessions()->mirror();

        $throw = true;
        Event::listen(TransactionBeginning::class, static function (TransactionBeginning $event) use (&$throw): void {
            if ($throw && $event->connectionName === self::mirrorSession()) {
                $throw = false;

                throw new RuntimeException('the listener failed');
            }
        });

        $ran = false;

        try {
            $this->transactions()->run($mirror, static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('The listener exception reaches the caller.');
        } catch (RuntimeException $expected) {
            self::assertSame('the listener failed', $expected->getMessage());
        }

        self::assertFalse($ran);
        self::assertSame(0, $mirror->transactionLevel());
        self::assertFalse($this->transactions()->active($mirror));

        $this->transactions()->run($mirror, static fn (Connection $session) => $session->table('session_scratch')->insert(['id' => 1, 'value' => 'after the listener']));

        self::assertSame(['after the listener'], $configured->table('session_scratch')->pluck('value')->all(), 'The next package transaction committed.');
        self::assertSame(0, $mirror->transactionLevel());
        self::assertFalse($this->transactions()->active($mirror));
    }

    private function transactions(): PackageTransaction
    {
        return $this->app->make(PackageTransaction::class);
    }

    /**
     * A mirror session on its own SQLite file with the table counter (id 1,
     * v 1) and a busy timeout of 50 ms.
     *
     * @param  array<int, mixed>  $options  the PDO options of the source connection
     * @return array{0: string, 1: Connection}
     */
    private function contendedSqliteSession(array $options = []): array
    {
        $file = SqliteFiles::create();
        $this->beforeApplicationDestroyed(static fn () => SqliteFiles::remove($file));

        $setup = new PDO('sqlite:'.$file);
        $setup->exec('create table counter (id integer primary key, v integer not null)');
        $setup->exec('insert into counter (id, v) values (1, 1)');
        unset($setup);

        $this->app['config']->set('database.connections.contended', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'options' => $options,
        ]);
        $this->usePackageConnection('contended');

        $session = $this->sessions()->mirror();
        $session->select('select 1');
        $session->getPdo()->exec('pragma busy_timeout = 50');

        return [$file, $session];
    }

    private function counterValue(string $file): int
    {
        return (int) (new PDO('sqlite:'.$file))->query('select v from counter where id = 1')->fetchColumn();
    }
}
