<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\OperatorContext;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use WeakReference;

/**
 * The teardown closes the database sessions of its test. The application of
 * a test is a graph of reference cycles, so without an explicit release its
 * PDOs (one server connection each) stay open until PHP's cycle collector
 * runs. On a server engine those connections accumulated past
 * max_connections (151 on MySQL) and failed every later test with
 * "Too many connections" until the collector ran.
 *
 * The first test opens sessions with the cycle collector off, and the second
 * test checks that the first test's teardown released every PDO. The second
 * test turns the collector on again.
 *
 * With ORRERY_TEST_SQLITE_JOURNAL=delete the harness's SQLite file is in
 * rollback mode, so the package opens no automatic session on it (D18): the
 * queue and failed-record sessions of that host file exist only as operator
 * sessions, which the operator action itself releases when it ends. There the
 * first test also proves the refusal, and the teardown proof covers the
 * sessions that can outlive a unit of work (the application's connection and
 * the mirror sessions).
 */
#[Group('harness')]
final class ConnectionReleaseTest extends TestCase
{
    /** @var list<WeakReference<PDO>> */
    private static array $opened = [];

    /**
     * Rejects: a teardown that leaves the PDOs of the test's connections and
     * package sessions to the cycle collector, and a session cache outside
     * the manager without PackageSession::releaseAll(); in rollback
     * mode also an automatic session on the host SQLite file (D18), and an
     * operator action that leaves its sessions open. Still passes: in
     * rollback mode, a teardown that leaves the operator sessions open (the
     * operator action released them already).
     */
    public function test_package_sessions_are_released_at_teardown(): void
    {
        gc_disable();
        self::$opened = [];

        /** @var PackageSession $sessions */
        $sessions = $this->app->make(PackageSession::class);
        $failed = (string) $this->app['config']->get('queue.failed.database');

        foreach ([$this->app['db']->connection(), $this->mirror(), $sessions->mirror()] as $connection) {
            $this->open($connection);
        }

        if (! self::rollbackJournal()) {
            $this->open($sessions->forQueue('database'));
            $this->open($sessions->for($failed));
        } else {
            foreach (['database' => static fn () => $sessions->forQueue('database'), $failed => static fn () => $sessions->for($failed)] as $name => $automatic) {
                try {
                    $automatic();
                    self::fail("An automatic session of [{$name}] opened on a SQLite file in rollback mode.");
                } catch (HostFileAccessNotAllowed) {
                    // D18: no automatic access to a host file in rollback mode.
                }
            }

            $sessions->operator(function (OperatorContext $context) use ($sessions, $failed): void {
                $this->open($sessions->forQueue('database', $context));
                $this->open($sessions->for($failed, $context));
            });
        }

        self::assertCount(5, self::$opened);
    }

    /**
     * Rejects: a teardown that leaves the PDOs of the test's connections and
     * package sessions to the cycle collector.
     */
    #[Depends('test_package_sessions_are_released_at_teardown')]
    public function test_the_teardown_of_the_previous_test_released_every_pdo(): void
    {
        try {
            self::assertCount(5, self::$opened);

            foreach (self::$opened as $index => $reference) {
                self::assertNull($reference->get(), "The PDO {$index} of the previous test is still open after its teardown.");
            }
        } finally {
            self::$opened = [];
            gc_enable();
        }
    }

    private function open(Connection $connection): void
    {
        $pdo = $connection->getPdo();
        self::assertInstanceOf(PDO::class, $pdo);
        self::$opened[] = WeakReference::create($pdo);
    }

    /**
     * Whether the harness's SQLite file is in rollback mode
     * (ORRERY_TEST_SQLITE_JOURNAL=delete): then the package makes no
     * automatic access to it (D18).
     */
    private static function rollbackJournal(): bool
    {
        $environment = TestEnvironment::fromEnv();

        return $environment->driver === 'sqlite' && $environment->database !== ':memory:' && $environment->sqliteJournal === 'delete';
    }

    /**
     * The collector is on again also when the first test failed and the
     * second one was skipped.
     */
    public static function tearDownAfterClass(): void
    {
        self::$opened = [];
        gc_enable();

        parent::tearDownAfterClass();
    }
}
