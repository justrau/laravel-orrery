<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Justrau\Orrery\Storage\PackageSession;

/**
 * Records the round trips of the package sessions: each run()
 * statement, each begin (the combined begin of a pooled Postgres session
 * counts once), savepoint, commit, rollback and raw exec() through
 * PackageSession::exec(), on every engine.
 *
 * Package sessions have no event dispatcher, so QueryExecuted never shows
 * their statements. On in-memory SQLite the package session is the
 * application's connection; there a test keeps its QueryExecuted filter.
 *
 * PackageSession has one recorder. This class installs one that calls every
 * listener of the running test (record(), listen(), bridge()). The test
 * reset (PackageSession::resetForTesting()) removes the recorder, and
 * TestCase::setUp() forgets the listeners.
 */
final class PackageStatements
{
    /** @var array<int, Closure(string, string, ?string, ?Connection, array<mixed>): void> */
    private static array $listeners = [];

    private static int $next = 0;

    /**
     * @param  Closure(): mixed  $work
     * @return list<array{kind: string, sql: string, session: ?string}>
     */
    public static function record(Closure $work): array
    {
        $recorded = [];
        $id = self::add(static function (string $kind, string $sql, ?string $session) use (&$recorded): void {
            if ($kind !== PackageSession::EXECUTED) {
                $recorded[] = ['kind' => $kind, 'sql' => $sql, 'session' => $session];
            }
        });

        try {
            $work();
        } finally {
            unset(self::$listeners[$id]);
        }

        return $recorded;
    }

    /**
     * The recorded kinds only, in order.
     *
     * @param  list<array{kind: string, sql: string, session: ?string}>  $recorded
     * @return list<string>
     */
    public static function kinds(array $recorded): array
    {
        return array_map(static fn (array $entry): string => $entry['kind'], $recorded);
    }

    /**
     * Calls $listener for every round trip of the package's mirror session
     * from now until the test's reset: through the session recorder on a
     * package session, and through the events of the application's
     * connection on in-memory SQLite (there only the statements on package
     * tables count; PackageTransaction reports its own begin, commit and
     * rollback on every engine, and Laravel's transaction events of that
     * connection belong to the queue).
     *
     * @param  Closure(string $kind, string $sql): void  $listener
     */
    public static function listen(Application $app, Closure $listener): void
    {
        self::add(static function (string $kind, string $sql, ?string $session) use ($app, $listener): void {
            if ($kind !== PackageSession::EXECUTED && $session !== null && $session === $app->make(PackageSession::class)->mirrorName()) {
                $listener($kind, $sql);
            }
        });

        $memory = static function (?string $connection) use ($app): bool {
            $sessions = $app->make(PackageSession::class);

            return $sessions->isInMemoryConnection(null) && $connection === $sessions->mirrorName();
        };

        $app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($memory, $listener): void {
            if ($memory($event->connectionName) && str_contains($event->sql, 'orrery_')) {
                $listener(PackageSession::STATEMENT, $event->sql);
            }
        });
    }

    /**
     * For the tests that count package statements through Laravel's events:
     * each run() statement of a package session fires a QueryExecuted after
     * it ran (as Laravel does), and
     * each begin, commit and rollback of a session class fires
     * TransactionBeginning, TransactionCommitted or TransactionRolledBack on
     * the application's dispatcher, with the session's Connection (its name
     * is `orrery.session.<connection>`). PackageTransaction's raw SQLite
     * statements fire nothing, as before; PackageTransaction counts them.
     */
    public static function bridge(Application $app): void
    {
        self::add(static function (string $kind, string $sql, ?string $session, ?Connection $connection, array $bindings) use ($app): void {
            if ($connection === null) {
                return;
            }

            $event = match ($kind) {
                PackageSession::EXECUTED => new QueryExecuted($sql, $bindings, 0.0, $connection),
                PackageSession::BEGIN => new TransactionBeginning($connection),
                PackageSession::COMMIT => new TransactionCommitted($connection),
                PackageSession::ROLLBACK => new TransactionRolledBack($connection),
                default => null,
            };

            if ($event !== null) {
                $app['events']->dispatch($event);
            }
        });
    }

    /**
     * Calls $check before every run() statement of a package session of the
     * named database connection, in each access mode (an operator session of
     * a host SQLite file included); on in-memory SQLite before every
     * statement of that configured connection. A throw from $check stops the
     * statement, as a beforeExecuting callback does.
     *
     * @param  Closure(string $sql, array<mixed> $bindings): void  $check
     */
    public static function beforeEach(Application $app, string $connection, Closure $check): void
    {
        $sessions = $app->make(PackageSession::class);

        if ($sessions->isInMemoryConnection($connection)) {
            $sessions->for($connection)->beforeExecuting(static function (string $sql, array $bindings) use ($check): void {
                $check($sql, $bindings);
            });

            return;
        }

        self::add(static function (string $kind, string $sql, ?string $session, ?Connection $on = null, array $bindings = []) use ($connection, $check): void {
            if ($kind === PackageSession::STATEMENT && $session === PackageSession::NAME_PREFIX.$connection) {
                $check($sql, $bindings);
            }
        });
    }

    /**
     * Calls $listener for every entry of every package session (each kind,
     * on each session) until the test's reset, next to the other listeners.
     *
     * @param  Closure(string $kind, string $sql, ?string $session): void  $listener
     */
    public static function each(Closure $listener): void
    {
        self::add(static function (string $kind, string $sql, ?string $session) use ($listener): void {
            $listener($kind, $sql, $session);
        });
    }

    public static function reset(): void
    {
        self::$listeners = [];
    }

    /**
     * @param  Closure(string, string, ?string, ?Connection, array<mixed>): void  $listener
     */
    private static function add(Closure $listener): int
    {
        $id = self::$next++;
        self::$listeners[$id] = $listener;

        PackageSession::recordForTesting(static function (string $kind, string $sql, ?string $session, ?Connection $connection = null, array $bindings = []): void {
            foreach (self::$listeners as $each) {
                $each($kind, $sql, $session, $connection, $bindings);
            }
        });

        return $id;
    }
}
