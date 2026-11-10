<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Control;

use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Overlapping pause and continue commands: every scope
 * replacement runs in a serial order, also on MySQL and Postgres at READ
 * COMMITTED, and a pause and a continue at one scope never both decide.
 */
#[Group('process')]
final class ScopeCommandRaceTest extends TestCase
{
    /**
     * Rejects: pause and continue writes without a common lock, so the second command (a connection continue) runs between the first command's read and its insert, deletes nothing, and the connection pause survives the later continue.
     */
    #[Group('shared-database')]
    public function test_overlapping_scope_commands_run_in_a_serial_order(): void
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('The second command needs its own process and session: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $signals = new SignalState;
        $signals->pause('global', '');

        // After the first command (a connection pause) took the control_version
        // lock and before its upsert, a second session runs a connection
        // continue.
        $second = null;
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$second, $env): void {
            // The control_version lock read inside the first command's transaction (not the exists() check before it).
            $sql = strtolower(ltrim($event->sql));

            if ($second !== null || $event->connectionName !== self::mirrorSession() || ! str_contains($sql, 'orrery_settings') || ! str_starts_with($sql, 'select') || str_contains($sql, 'exists')) {
                return;
            }

            $second = WorkerProcess::start(['orrery:continue', '--connection=database'], $env);

            // SQLite serialises the writers at BEGIN IMMEDIATE: the second
            // command cannot begin before this one commits. On a server, the
            // second command either blocks on the scope lock or, without a
            // lock, runs to its end now.
            if ($env->driver !== 'sqlite') {
                $process = $second;
                $second->waitUntil(fn (): bool => ! $process->isRunning() || $this->lockWaits() > 0, 60, 'the second command blocked on the scope lock or ended');
            }
        });

        $signals->pause('connection', 'database');

        self::assertNotNull($second, 'the first command took no control_version lock');
        self::assertSame(0, $second->waitForExit(60), $second->describe());

        // The serial order: the connection pause, then the connection continue
        // that removes it. The global pause stays (a continue
        // lifts exactly its scope).
        $rows = $this->mirror()->table('orrery_signals')->orderBy('scope')->orderBy('signal')->get(['signal', 'scope', 'scope_value'])
            ->map(static fn (object $row): string => $row->signal.' '.$row->scope.' '.$row->scope_value)->all();
        self::assertSame(['pause global '], $rows, 'the overlapping commands did not run in a serial order. '.$second->describe());
        self::assertStringNotContainsString('could not be read', $second->describe(), 'the continue did not read Laravel\'s pause state');

        SignalState::flushCache();
        self::assertTrue((new SignalState)->connectionPaused('database'));
        self::assertTrue((new SignalState)->connectionPaused('other'));
    }

    /**
     * The transactions of this database that wait for a lock.
     */
    private function lockWaits(): int
    {
        $mirror = $this->mirror();

        if ($mirror->getDriverName() === 'pgsql') {
            return (int) $mirror->selectOne("select count(*) as waits from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()")->waits;
        }

        // MySQL: a session of this database that runs a locking read now.
        return (int) $mirror->selectOne("select count(*) as waits from information_schema.processlist where db = database() and id <> connection_id() and command in ('Query', 'Execute') and info like '%for update%'")->waits;
    }
}
