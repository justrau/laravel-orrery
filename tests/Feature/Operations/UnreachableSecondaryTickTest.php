<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Maintenance\TickReport;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use PDO;
use PDOException;

/**
 * The breaker is the mirror's: a dependency error of another package
 * session (a secondary queue database that cannot be reached) is handled per
 * target (the runner's deferral, the unknown rows of the measurement) and
 * never stops the package's work on the mirror.
 *
 * The secondary connection is a MySQL configuration on a closed local port.
 * The connect seam of the package's connectors
 * (PackageSession::useConnectAttemptForTesting()) throws mysqlnd's Linux
 * connect timeout for it, and lets every other connect (the test database
 * of a server engine) through.
 */
final class UnreachableSecondaryTickTest extends RetryProtocolTestCase
{
    private const SECONDARY = 'orrery_secondary';

    private int $attempts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->app['config'];
        $config->set('database.connections.'.self::SECONDARY, [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 9, 'database' => 'orrery_secondary',
            'username' => 'probe', 'password' => 'probe', 'prefix' => '',
        ]);
        $config->set('queue.connections.'.self::SECONDARY, ['driver' => 'database', 'connection' => self::SECONDARY, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);

        PackageSession::useConnectAttemptForTesting(function (string $dsn): ?PDO {
            if (! str_contains($dsn, 'port=9;') && ! str_ends_with($dsn, 'port=9')) {
                return null; // The test database of a server engine connects for real.
            }

            $this->attempts++;
            // Each access to the secondary costs a whole connect timeout on the tick's clock.
            Clock::advanceMonotonicForTesting(PackageSession::CONNECT_TIMEOUT_SECONDS);

            throw new PDOException('SQLSTATE[HY000] [2002] Connection timed out', 2002);
        });

        $this->beforeApplicationDestroyed(function (): void {
            // The rollback of the fence migration visits every database queue connection: not this one.
            $config = $this->app['config'];
            $config->set('queue.connections', array_diff_key((array) $config->get('queue.connections'), [self::SECONDARY => true]));
        });
    }

    /**
     * Rejects: a process-wide breaker that any package session's dependency
     * error opens: the first phase that reads the secondary connection opens
     * it, and every later phase (the operations, the retention phases) is
     * skipped as dropped, so the due operation on the primary is never
     * decided. Still passes: a breaker that opens for a secondary error but
     * closes before the next phase.
     */
    public function test_an_unreachable_secondary_connection_keeps_the_breaker_closed(): void
    {
        $due = $this->claimed('database', $this->mirrorNow() - 100);

        $report = $this->tick();

        self::assertGreaterThan(0, $this->attempts, 'The tick reached for the secondary connection.');
        self::assertFalse(Watcher::instance()->breakerOpen(), 'The secondary connection does not open the mirror breaker.');
        self::assertSame([], $report->skipped());
        self::assertSame([], $report->overruns(), 'The tick stays within its deadline when each access to the secondary costs a connect timeout.');

        foreach (['operations', 'operation_retention', 'retention', 'sample_retention', 'marker_retention', 'health_retention'] as $phase) {
            self::assertContains($phase, $report->ran());
            self::assertNotContains($phase, $report->failed(), "The phase {$phase} succeeded.");
        }

        $operation = $this->operation($due);

        if ($this->queueIsHostFile()) {
            // D17: on a SQLite file the runner leaves the operation to the operator.
            self::assertNotNull($operation->awaiting_since, 'The runner reached the due operation on the primary.');
            self::assertContains($operation->awaiting_reason, [Operations::SQLITE_HOST_FILE, Operations::SQLITE_READS_NOT_ALLOWED]);
        } else {
            self::assertSame(Operations::ABORTED, $operation->state, 'The due operation on the primary was decided.');
        }
    }

    /**
     * Rejects: a breaker that only a named session can open, or that no
     * connect failure opens: the mirror's own connect failure still opens
     * it. Still passes: a breaker that every package session opens (the test
     * above rejects it).
     */
    public function test_an_unreachable_mirror_opens_the_breaker(): void
    {
        $config = $this->app['config'];
        $previous = $config->get('orrery.database');
        $config->set('orrery.database', self::SECONDARY);

        try {
            $result = Watcher::instance()->attempt('mirror_probe', fn (): mixed => $this->app->make(PackageSession::class)->mirror()->scalar('select 1'));
        } finally {
            $config->set('orrery.database', $previous);
        }

        self::assertFalse($result->succeeded());
        self::assertTrue(Watcher::instance()->breakerOpen());
    }

    private function tick(): TickReport
    {
        /** @var iterable<\Justrau\Orrery\Maintenance\TickPhase> $phases */
        $phases = $this->app->tagged(TickCommand::PHASES_TAG);

        return $this->app->make(TickRunner::class)->run($phases);
    }

    /**
     * A claimed retry of mode none on a queue connection, past its deadline in that database's clock.
     */
    private function claimed(string $queueConnection, float $nextCheckAt): string
    {
        $id = Operations::newId();
        $time = Clock::toDecimal(Clock::now());

        $this->mirror()->table(Operations::TABLE)->insert([
            'id' => $id, 'action' => Operations::RETRY, 'state' => Operations::CLAIMED, 'mode' => Operations::NONE,
            'job_uuid' => '0f5a3c1e-6b1d-4e57-9a3e-'.substr($id, 0, 12), 'occurrence_id' => 1, 'queue_connection' => $queueConnection, 'queue' => 'default',
            'queue_fingerprint' => $this->app->make(Publication::class)->queueFingerprint($queueConnection),
            'deadline' => Clock::toDecimal($nextCheckAt - 60), 'next_check_at' => Clock::toDecimal($nextCheckAt), 'checks' => 0, 'source' => 'cli',
            'created_at' => $time, 'updated_at' => $time,
        ]);

        return $id;
    }
}
