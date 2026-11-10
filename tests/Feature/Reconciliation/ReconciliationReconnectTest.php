<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Reconciliation;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\KillsServerSessions;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * The absence checks: the
 * session loses its server session after the check read the target of
 * database A, and the configuration names database B now, where the id is
 * absent: the read never reaches B. MySQL and
 * Postgres only: SQLite has no server session to end.
 */
#[FreshSchema]
final class ReconciliationReconnectTest extends ReconciliationTestCase
{
    use KillsServerSessions;

    /**
     * Rejects: a transparent reconnect that reads B.
     */
    public function test_lost_observer_after_measurement_is_not_checked(): void
    {
        $this->skipUnlessEngine('mysql', 'pgsql');

        // The failer uses its own database connection, so the configuration change reaches only its sessions.
        $this->copyConnection('testbench_forget');
        $this->useFailer('database-uuids', 'failed_jobs', 'testbench_forget');
        [$uuid, $occurrence] = $this->failedJob($this->job(['orders']));
        $before = (array) $this->rowOrFail($uuid);
        $rows = $this->jobRows($uuid);

        // Database B has a provider table without that record.
        $this->copyConnection('testbench_other', ['database' => $this->otherDatabase()]);
        $this->createFailedTable('failed_jobs', true, 'testbench_other');

        $reached = $this->loseSessionBeforeTheCheck('testbench_forget', fn () => $this->runFailed());

        self::assertSame(1, $reached, 'the phase measured A and reached its absence check');
        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', $occurrence], [$row->status, (int) $row->occurrence_id]);
        self::assertNotNull($row->provider_id);
        self::assertEquals($before, (array) $row, 'the mirror row is unchanged');
        self::assertSame($rows, $this->jobRows($uuid), 'its tags, failed tags and history are unchanged');
        self::assertArrayHasKey(Reconciliation::HEALTH_NOT_CHECKED, $this->healthErrors(), 'a reported read failure');
        self::assertNotNull($this->providerRecord($uuid, 'testbench_forget'));

        // The next tick measures A again on a new session: the record exists, and nothing changes.
        $this->runFailed();
        self::assertEquals($before, (array) $this->rowOrFail($uuid));
    }

    /**
     * Rejects: a transparent reconnect in the active-row check.
     */
    public function test_lost_absence_check_never_reaches_another_database(): void
    {
        $this->skipUnlessEngine('mysql', 'pgsql');

        // The queue uses its own database connection, so the configuration change reaches only its sessions.
        $this->copyConnection('testbench_queue');
        $this->useQueue('reconcile', 'testbench_queue');
        [$uuid, $jobId] = $this->pushed('reconcile');

        // Database B has a jobs table without that job.
        $other = $this->defineOtherDatabaseWithFence();
        self::assertSame(0, $other->table('jobs')->where('id', $jobId)->count());

        $reached = $this->loseSessionBeforeTheCheck('testbench_queue', fn () => $this->runActive());

        self::assertSame(1, $reached);
        $row = $this->rowOrFail($uuid);
        self::assertSame(['pending', $jobId, null], [$row->status, (int) $row->job_id, $row->absent_since], 'the row keeps its state and gets no absent_since');
        self::assertArrayHasKey(Reconciliation::HEALTH_NOT_CHECKED, $this->healthErrors(), 'a reported read failure');
        self::assertSame(1, (int) DB::connection('testbench_queue')->table('jobs')->where('id', $jobId)->count());
    }

    /**
     * Runs $work. At the phase's first absence check, a reconnect of the
     * connection's sessions would reach the other database, and the server
     * ends the observer session. Returns how often the point was reached.
     */
    private function loseSessionBeforeTheCheck(string $connection, callable $work): int
    {
        $config = $this->app['config'];
        $original = $config->get("database.connections.{$connection}");
        $other = $this->otherDatabase();
        $reached = 0;

        Hooks::$callback = function (string $point) use ($config, $connection, $other, &$reached): void {
            if ($point !== Reconciliation::BEFORE_ABSENCE_CHECK || $reached > 0) {
                return;
            }

            $reached++;
            $config->set("database.connections.{$connection}.database", $other);
            /** @var Connection $killer */
            $killer = $this->app['db']->connection(TestEnvironment::PRIMARY);
            $this->endServerSession($this->packageSessions()->for($connection), $killer);
        };

        try {
            $work();
        } finally {
            Hooks::$callback = null;
            $config->set("database.connections.{$connection}", $original);
            // A reopened session was built from the changed configuration.
            PackageSession::releaseAll();
        }

        return $reached;
    }
}
