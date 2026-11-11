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
 * database-driver queue connections + 60, LiveWorkers::freshSeconds()), and
 * older than the row's own listing window
 * (LiveWorkers::listedSeconds(quietSeconds($options))). So a worker that
 * died holding a lease keeps its row while the lease can still be active,
 * and an idle worker keeps its row while LiveWorkers lists it, also with a
 * long --sleep or a longer interval on its own host.
 *
 * One read of the rows older than the common cutoff (worker id, options,
 * last seen: no index on options, but only stale rows), then one delete per
 * 500 ids. Each delete repeats the cutoff, so a row that its worker wrote
 * again after the read stays.
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
        $now = Clock::now();
        $cutoff = Clock::toDecimal($now - self::staleSeconds());
        $expired = [];

        $rows = WorkerHeartbeat::query()->where('last_seen_at', '<', $cutoff)->get(['worker_id', 'options', 'last_seen_at']);

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->options, true);
            /** @var array<string, mixed> $options */
            $options = is_array($decoded) ? $decoded : [];

            if (Clock::stored($row->last_seen_at) < $now - LiveWorkers::listedSeconds(LiveWorkers::quietSeconds($options))) {
                $expired[] = (string) $row->worker_id;
            }
        }

        foreach (array_chunk($expired, 500) as $ids) {
            WorkerHeartbeat::query()->whereIn('worker_id', $ids)->where('last_seen_at', '<', $cutoff)->delete();
        }
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
