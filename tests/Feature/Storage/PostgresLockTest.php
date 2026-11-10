<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PostgresSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\KillsServerSessions;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use Throwable;

/**
 * The lock bounds of the Postgres package sessions: a direct
 * session sets its timeouts at connect, and the idle-in-transaction timeout
 * ends a stalled package transaction in both modes.
 */
final class PostgresLockTest extends TestCase
{
    use KillsServerSessions;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->testEnvironment()->driver !== 'pgsql') {
            self::markTestSkipped('This test needs pgsql, and this run uses '.$this->testEnvironment()->driver.'.');
        }

        $this->app->singleton(PackageSession::class);
        $this->beforeApplicationDestroyed(static function (): void {
            PackageSession::resetForTesting();
        });
    }

    /**
     * Rejects: autocommit hot-path statements without a lock timeout on a direct session.
     */
    public function test_direct_postgres_sets_its_timeouts_at_connect(): void
    {
        // A direct session (the pooled harness run sets orrery.sessions.pooled).
        $this->app['config']->set('orrery.sessions.pooled', false);
        $host = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $session = $this->sessions()->for(TestEnvironment::PRIMARY);

        self::assertInstanceOf(PostgresSession::class, $session);
        self::assertFalse($session->isPooled());
        self::assertSame(['5s', '10s', '30s'], $this->timeouts($session), 'Outside a transaction, after connect.');
        self::assertSame('0', $host->scalar('show lock_timeout'), "The control: the host's own connection has no lock timeout.");

        $this->endServerSession($session, $host);

        self::assertSame(['5s', '10s', '30s'], $this->timeouts($session), 'After a reconnect.');
        self::assertSame('0', $host->scalar('show lock_timeout'));
    }

    /**
     * Rejects: no idle-in-transaction timeout, so a stalled package transaction holds its row lock without a limit.
     */
    #[FreshSchema]
    public function test_stalled_package_transaction_is_ended(): void
    {
        $host = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $schema = $host->getSchemaBuilder();
        $schema->create('lock_scratch', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('status', 16);
        });
        $this->beforeApplicationDestroyed(static function () use ($schema): void {
            PackageSession::releaseAll();
            $schema->dropIfExists('lock_scratch');
        });
        $host->table('lock_scratch')->insert(['id' => 1, 'status' => 'pending']);

        // The real limit is 30 s; the test shortens it to keep the suite fast.
        self::assertStringContainsString("idle_in_transaction_session_timeout = '30s'", $this->pooledSession('pooled_default')->beginStatement());
        PackageSession::releaseAll();
        PackageSession::useIdleInTransactionSecondsForTesting(1);

        foreach (['direct' => TestEnvironment::PRIMARY, 'pooled' => $this->pooledConnection('pooled_stall')] as $mode => $name) {
            PackageSession::releaseAll();
            $session = $this->sessions()->for($name);
            $session->beginTransaction();
            self::assertSame('1s', $session->scalar('show idle_in_transaction_session_timeout'), "The {$mode} package transaction has the limit.");
            $session->table('lock_scratch')->where('id', 1)->lockForUpdate()->value('status');
            $started = microtime(true);

            // The package session now sits idle. A worker's reservation waits
            // for the row; its own lock_timeout only keeps a failing test short.
            $reserved = $host->transaction(static function (Connection $tx): int {
                $tx->statement("set local lock_timeout = '15s'");

                return $tx->table('lock_scratch')->where('id', 1)->update(['status' => 'reserved']);
            });
            $waited = microtime(true) - $started;

            self::assertSame(1, $reserved, "The reservation proceeds after the server ended the {$mode} package transaction.");
            self::assertGreaterThan(0.5, $waited, 'The reservation waited for the package lock.');
            self::assertLessThan(5.0, $waited, 'The server ended the stalled transaction at its 1 s limit.');

            try {
                $session->table('lock_scratch')->count();
                self::fail('The ended package session cannot continue its transaction.');
            } catch (Throwable $ended) {
                self::assertInstanceOf(QueryException::class, $ended);
            }

            $this->sessions()->disconnect($session);
            $host->table('lock_scratch')->where('id', 1)->update(['status' => 'pending']);
        }
    }

    private function sessions(): PackageSession
    {
        return $this->app->make(PackageSession::class);
    }

    private function pooledConnection(string $name): string
    {
        $config = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY);
        unset($config['search_path']);
        $this->app['config']->set("database.connections.{$name}", $config);
        $pooled = $this->app['config']->get('orrery.sessions.pooled');
        $this->app['config']->set('orrery.sessions.pooled', [...(is_array($pooled) ? $pooled : []), $name]);

        return $name;
    }

    private function pooledSession(string $name): PostgresSession
    {
        $session = $this->sessions()->for($this->pooledConnection($name));
        self::assertInstanceOf(PostgresSession::class, $session);
        self::assertTrue($session->isPooled());

        return $session;
    }

    /**
     * @return list<string>
     */
    private function timeouts(Connection $session): array
    {
        return [
            (string) $session->scalar('show lock_timeout'),
            (string) $session->scalar('show statement_timeout'),
            (string) $session->scalar('show idle_in_transaction_session_timeout'),
        ];
    }
}
