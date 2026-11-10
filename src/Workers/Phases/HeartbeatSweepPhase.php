<?php

namespace Justrau\Orrery\Workers\Phases;

use Illuminate\Container\Container;
use Justrau\Orrery\Database\Models\WorkerHeartbeat;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Workers\LiveWorkers;

/**
 * The heartbeat sweep of orrery:tick: it deletes the
 * heartbeats older than max(300 seconds, the largest `retry_after` of the
 * database-driver queue connections + 60, LiveWorkers::freshSeconds()). So a
 * worker that died holding a lease keeps its row while the lease can still
 * be active, and an idle worker with a long heartbeat interval keeps its row
 * while its heartbeat is fresh.
 */
final class HeartbeatSweepPhase implements TickPhase
{
    public const MIN_SECONDS = 300;

    public const LEASE_MARGIN_SECONDS = 60;

    /** Laravel's DatabaseConnector fallback for a missing or null retry_after. */
    private const DEFAULT_RETRY_AFTER = 60;

    public function name(): string
    {
        return 'heartbeat_sweep';
    }

    public function priority(): int
    {
        return 200;
    }

    public function run(TickBudget $budget): void
    {
        WorkerHeartbeat::query()
            ->where('last_seen_at', '<', Clock::toDecimal(Clock::now() - self::staleSeconds()))
            ->delete();
    }

    public static function staleSeconds(): int
    {
        $largest = 0;
        $connections = Container::getInstance()->make('config')->get('queue.connections', []);

        foreach (is_array($connections) ? $connections : [] as $connection) {
            if (! is_array($connection) || ($connection['driver'] ?? null) !== 'database') {
                continue;
            }

            $retryAfter = $connection['retry_after'] ?? self::DEFAULT_RETRY_AFTER;
            $largest = max($largest, is_numeric($retryAfter) ? (int) $retryAfter : self::DEFAULT_RETRY_AFTER);
        }

        return max(self::MIN_SECONDS, $largest + self::LEASE_MARGIN_SECONDS, LiveWorkers::freshSeconds());
    }
}
