<?php

namespace Justrau\Orrery\Workers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\Clock;

/**
 * The worker liveness fact of a running row: a fact, not a
 * stuck signal. A running row carries `worker: {id, heartbeat_at,
 * liveness, silent_since}` and `lease_expires_at`.
 *
 * - **The lease.** `lease_expires_at` is `reserved_at` + retryAfter() of
 *   the row's queue connection: its own `retry_after`, the time after which
 *   Laravel's pop of that connection can give the job to another worker.
 *   Another connection on the same jobs table does not shorten it:
 *   Laravel's pop reads the worker's own queue names, so a connection with a
 *   short `retry_after` for queue `default` does not take a long job of
 *   queue `long` (a worker of the shorter connection that works the same
 *   queue name can, which this rule does not see). A missing or null
 *   `retry_after` is Laravel's 60. A row whose connection is not a
 *   configured `database` queue connection of this application has an
 *   unknown lease (null): no expiry is claimed, and its liveness comes from
 *   the heartbeat only.
 * - **The order of `liveness`**: `reserved_by` is null →
 *   `unknown`; else the lease is live (`lease_expires_at > now`) → `live`;
 *   else the worker's heartbeat is younger than
 *   `orrery.stuck.worker_silence_seconds` (60) → `live`; else a heartbeat
 *   row exists → `silent`; else (no heartbeat row) → `unknown`.
 * - `silent_since` is max(`lease_expires_at`, `heartbeat_at` + the silence)
 *   for a silent worker, else null.
 *
 * Reason: a worker writes heartbeats only on `Looping`, `JobProcessing` and
 * `pop()`, so a long job inside its lease has a stale heartbeat and is
 * still live; LiveWorkers counts it as live too.
 *
 * The configuration is read when it is used (a published configuration
 * without the `stuck` block gets the defaults). The class
 * holds no state.
 */
final class WorkerLiveness
{
    public const LIVE = 'live';

    public const SILENT = 'silent';

    public const UNKNOWN = 'unknown';

    /** The default of `orrery.stuck.worker_silence_seconds`. */
    public const SILENCE_SECONDS = 60;

    /** Laravel's DatabaseConnector fallback for a missing or null retry_after. */
    public const DEFAULT_RETRY_AFTER = 60;

    public function __construct(private readonly Repository $config)
    {
    }

    /**
     * `orrery.stuck.worker_silence_seconds`, a whole number of at least 1,
     * else the default.
     */
    public function silenceSeconds(): int
    {
        $value = $this->config->get('orrery.stuck.worker_silence_seconds', self::SILENCE_SECONDS);

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d{0,17}$/D', $value) === 1) {
            return (int) $value;
        }

        return self::SILENCE_SECONDS;
    }

    /**
     * The lease interval of a queue connection: its own `retry_after` (as
     * Laravel's DatabaseConnector reads it: 60 when missing or null), or
     * null when $connection is not a configured `database` queue
     * connection. With $leases (leases()), the map decides and the
     * configuration is not read.
     *
     * @param  array<string, int>|null  $leases
     */
    public function retryAfter(string $connection, ?array $leases = null): ?int
    {
        return ($leases ?? $this->leases())[$connection] ?? null;
    }

    /**
     * retryAfter() of every configured `database` queue connection, by
     * connection name: one walk of the queue configuration, for the rows
     * of one list read.
     *
     * @return array<string, int>
     */
    public function leases(): array
    {
        return $this->databaseConnections();
    }

    /**
     * The shortest lease interval of every configured `database` queue
     * connection, or null when there is none.
     */
    public function shortestRetryAfter(): ?int
    {
        $values = $this->databaseConnections();

        return $values === [] ? null : min($values);
    }

    /**
     * `reserved_at` + retryAfter() of the row's connection, or null without
     * a reserved_at or with an unknown lease. $row has `connection` and
     * `reserved_at`. $leases as for retryAfter().
     *
     * @param  array<string, int>|null  $leases
     */
    public function leaseExpiresAt(object $row, ?array $leases = null): ?float
    {
        $reservedAt = $row->reserved_at ?? null;
        $retryAfter = $this->retryAfter((string) ($row->connection ?? ''), $leases);

        return is_numeric($reservedAt) && $retryAfter !== null ? Clock::stored($reservedAt) + $retryAfter : null;
    }

    /**
     * The own `retry_after` of each configured `database` queue connection,
     * by connection name.
     *
     * @return array<string, int>
     */
    private function databaseConnections(): array
    {
        $connections = [];
        $all = $this->config->get('queue.connections', []);

        foreach (is_array($all) ? $all : [] as $name => $settings) {
            if (! is_array($settings) || ($settings['driver'] ?? null) !== 'database') {
                continue;
            }

            $retryAfter = $settings['retry_after'] ?? null;
            $connections[(string) $name] = is_numeric($retryAfter) ? (int) $retryAfter : self::DEFAULT_RETRY_AFTER;
        }

        return $connections;
    }

    /**
     * The worker fact of a running row. $heartbeats maps a worker id to its
     * last heartbeat (heartbeats()).
     *
     * @param  array<string, float>  $heartbeats
     * @return array{id: string|null, heartbeat_at: float|null, liveness: string, silent_since: float|null}
     */
    public function of(object $row, ?float $lease, array $heartbeats, float $now): array
    {
        $id = is_string($row->reserved_by ?? null) && $row->reserved_by !== '' ? $row->reserved_by : null;
        $heartbeat = $id === null ? null : ($heartbeats[$id] ?? null);
        $silence = (float) $this->silenceSeconds();

        $liveness = match (true) {
            $id === null => self::UNKNOWN,
            $lease !== null && $lease > $now => self::LIVE,
            $heartbeat !== null && $now - $heartbeat < $silence => self::LIVE,
            $heartbeat !== null => self::SILENT,
            default => self::UNKNOWN,
        };

        return [
            'id' => $id,
            'heartbeat_at' => $heartbeat,
            'liveness' => $liveness,
            'silent_since' => $liveness === self::SILENT ? max($lease ?? 0.0, (float) $heartbeat + $silence) : null,
        ];
    }

    /**
     * The last heartbeat of the workers of the running rows, by worker id:
     * one read of orrery_worker_heartbeats on $session (the mirror).
     *
     * @param  iterable<object>  $rows
     * @return array<string, float>
     */
    public function heartbeats(Connection $session, iterable $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (($row->status ?? null) === 'reserved' && is_string($row->reserved_by ?? null) && $row->reserved_by !== '') {
                $ids[$row->reserved_by] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $seen = [];

        foreach ($session->table('orrery_worker_heartbeats')->whereIn('worker_id', array_keys($ids))->get(['worker_id', 'last_seen_at']) as $heartbeat) {
            $seen[(string) $heartbeat->worker_id] = Clock::stored($heartbeat->last_seen_at);
        }

        return $seen;
    }
}
