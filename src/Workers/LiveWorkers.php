<?php

namespace Justrau\Orrery\Workers;

use Illuminate\Container\Container;
use Justrau\Orrery\Database\Models\WorkerHeartbeat;
use Justrau\Orrery\Listeners\RecordWorkerHeartbeat;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The live workers, from the heartbeat rows and the
 * active leases of the mirror.
 *
 * - Two kinds of liveness evidence, exposed separately: a fresh heartbeat
 *   (`last_seen_at` within freshSeconds()) and active leases (`reserved`
 *   rows with `reserved_by` = the worker id and `reserved_at + retry_after`
 *   later than now). A reservation without a verified worker (`reserved_by`
 *   null) gives no lease to any worker, and no worker row exists for
 *   it. A worker is live when it has either kind of evidence.
 * - list() gives one row per daemon or once worker, and one row per
 *   queue:listen parent with its children. A `listen-child` row is grouped by
 *   host, ppid and the parent's start time; a row without a ppid or a parent
 *   start is never grouped.
 * - The listen parent row: `mode` `listen`, `worker_id`
 *   `<host>:<ppid>:<parent start>`, `pid` the parent pid, `ppid` null,
 *   `started_at` the parent start. Its `last_seen_at`, queues and options
 *   come from the newest child, `heartbeat_fresh` is true when a child is
 *   fresh, and `active_leases` sums the children.
 * - `state` is `live` or `quiet`. A worker is quiet when its last heartbeat
 *   is older than quietSeconds() and it holds no active lease: a killed
 *   worker writes nothing, so it stays listed until the fresh window ends,
 *   but it no longer looks healthy. A worker inside a long job writes no
 *   heartbeat, and its lease keeps it live. `last_seen_at` is never null, so
 *   the state is always known. A listen parent is live when a child is live.
 * - The times are epoch seconds.
 *
 * @phpstan-type WorkerRow object{worker_id: string, host: string, pid: int, ppid: ?int, started_at: float, mode: string,
 *     connection: string, subscribed_queues: list<string>, current_queue: ?string, options: array<string, mixed>,
 *     last_seen_at: float, heartbeat_fresh: bool, active_leases: int, state: string, children: list<object>}
 */
final class LiveWorkers
{
    public const FRESH_SECONDS = 60;

    /**
     * How old a heartbeat may be and still count as fresh: FRESH_SECONDS, or
     * two heartbeat intervals when `orrery.workers.heartbeat_seconds` is
     * longer than half of it (an idle worker writes once per
     * interval, so a shorter window would drop it half of the time).
     */
    public static function freshSeconds(): int
    {
        return max(self::FRESH_SECONDS, (int) ceil(2 * RecordWorkerHeartbeat::interval()));
    }

    public const LISTEN = 'listen';

    public const LIVE = 'live';

    public const QUIET = 'quiet';

    /**
     * How old a heartbeat may be before a worker without an active lease is
     * quiet: two `orrery.workers.heartbeat_seconds`. An interval of 0 (no
     * throttle) takes the default interval, so a worker that writes on
     * every loop is not quiet between two loops.
     */
    public static function quietSeconds(): float
    {
        $interval = RecordWorkerHeartbeat::interval();

        return 2 * ($interval > 0 ? $interval : RecordWorkerHeartbeat::DEFAULT_INTERVAL);
    }

    /** Laravel's DatabaseConnector fallback for a missing or null retry_after. */
    private const DEFAULT_RETRY_AFTER = 60;

    /**
     * One row per daemon or once worker, and one row per queue:listen parent with its children.
     *
     * @return list<WorkerRow>
     */
    public function list(): array
    {
        $now = Clock::now();
        $fresh = $now - self::freshSeconds();
        $quiet = $now - self::quietSeconds();

        /** @var list<WorkerHeartbeat> $heartbeats */
        $heartbeats = WorkerHeartbeat::query()->orderBy('host')->orderBy('pid')->orderBy('worker_id')->get()->all();
        $leases = $this->leases($heartbeats, $now);

        $rows = [];
        $groups = [];

        foreach ($heartbeats as $heartbeat) {
            $row = $this->row($heartbeat, (float) $heartbeat->last_seen_at >= $fresh, $leases[$heartbeat->worker_id] ?? 0, $quiet);

            if (! $row->heartbeat_fresh && $row->active_leases === 0) {
                continue;
            }

            $key = $this->groupKey($heartbeat);

            if ($key === null) {
                $rows[] = $row;

                continue;
            }

            $groups[$key] ??= [];
            $groups[$key][] = [$heartbeat, $row];
        }

        foreach ($groups as $key => $members) {
            $rows[] = $this->parentRow($key, $members);
        }

        usort(
            $rows,
            /**
             * @param  WorkerRow  $a
             * @param  WorkerRow  $b
             */
            static fn (object $a, object $b): int => [$a->host, $a->pid, $a->worker_id] <=> [$b->host, $b->pid, $b->worker_id],
        );

        return $rows;
    }

    /**
     * The live workers subscribed to the queue of the connection, or of any
     * of the connections (queue connections that share one jobs table). Each
     * worker row has one connection, so a worker counts once.
     *
     * @param  string|list<string>  $connection
     */
    public function capacity(string|array $connection, string $queue): int
    {
        return self::subscribed($this->list(), $connection, $queue);
    }

    /**
     * capacity() over rows that list() returned before. A caller that needs
     * the capacity of several queues reads the workers once.
     *
     * @param  list<object{connection: string, subscribed_queues: list<string>}>  $workers
     * @param  string|list<string>  $connection
     */
    public static function subscribed(array $workers, string|array $connection, string $queue): int
    {
        $connections = (array) $connection;
        $count = 0;

        foreach ($workers as $worker) {
            if (in_array($worker->connection, $connections, true) && in_array($queue, $worker->subscribed_queues, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The job that each worker of $rows holds now, keyed by
     * worker id: the mirror row with `status` reserved, `reserved_by` = the
     * worker id and a live lease (`reserved_at + retry_after` of the worker's
     * own connection later than now, the rule of the active leases). When
     * several rows match, the latest `reserved_at` wins, then the larger id.
     *
     * - The children of a queue:listen parent are matched by their own ids,
     *   and the parent gets the job of its child with the latest
     *   reservation.
     * - A worker without an entry has no known job. `reserved_by` is known
     *   only with the connector swap on, so with the swap off every worker
     *   has no entry: unknown, never idle.
     * - One statement per consuming connection and chunk of 500 ids, on the
     *   index (`status`, `reserved_by`). Each worker holds its few live
     *   reservations only.
     *
     * @param  list<WorkerRow>  $rows  rows of list()
     * @return array<string, array{name: string, started_at: float}>
     */
    public function currentJobs(array $rows, ?float $now = null): array
    {
        $now ??= Clock::now();
        $byConnection = [];

        foreach ($rows as $row) {
            $members = $row->mode === self::LISTEN ? $row->children : [$row];

            foreach ($members as $member) {
                /** @var WorkerRow $member */
                $byConnection[$member->connection][] = $member->worker_id;
            }
        }

        $best = [];

        foreach ($byConnection as $connection => $ids) {
            $cutoff = Clock::toDecimal($now - $this->retryAfter((string) $connection));

            foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
                $jobs = Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_jobs')
                    ->where('status', 'reserved')
                    ->whereIn('reserved_by', $chunk)
                    ->where('reserved_at', '>', $cutoff)
                    ->get(['id', 'reserved_by', 'name', 'reserved_at']);

                foreach ($jobs as $job) {
                    $candidate = [(float) $job->reserved_at, (int) $job->id, (string) $job->name];
                    $id = (string) $job->reserved_by;

                    if (! isset($best[$id]) || $candidate > $best[$id]) {
                        $best[$id] = $candidate;
                    }
                }
            }
        }

        $jobs = [];

        foreach ($rows as $row) {
            $members = $row->mode === self::LISTEN ? $row->children : [$row];
            $parent = null;

            foreach ($members as $member) {
                /** @var WorkerRow $member */
                if (! isset($best[$member->worker_id])) {
                    continue;
                }

                $jobs[$member->worker_id] = ['name' => $best[$member->worker_id][2], 'started_at' => $best[$member->worker_id][0]];

                if ($parent === null || $best[$member->worker_id] > $parent) {
                    $parent = $best[$member->worker_id];
                }
            }

            if ($row->mode === self::LISTEN && $parent !== null) {
                $jobs[$row->worker_id] = ['name' => $parent[2], 'started_at' => $parent[0]];
            }
        }

        return $jobs;
    }

    /**
     * The active leases per worker id. The verified worker id alone names the
     * owner: the mirror row keeps the producer's connection name, which can
     * differ from the consuming worker's name for the same jobs table. The
     * lease interval is the retry_after of the consuming worker's
     * connection, so there is one statement per consuming connection.
     *
     * @param  list<WorkerHeartbeat>  $heartbeats
     * @return array<string, int>
     */
    private function leases(array $heartbeats, float $now): array
    {
        $byConnection = [];

        foreach ($heartbeats as $heartbeat) {
            $byConnection[(string) $heartbeat->connection][] = (string) $heartbeat->worker_id;
        }

        $leases = [];

        foreach ($byConnection as $connection => $ids) {
            $cutoff = Clock::toDecimal($now - $this->retryAfter($connection));

            foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
                $counts = Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_jobs')
                    ->where('status', 'reserved')
                    ->whereIn('reserved_by', $chunk)
                    ->where('reserved_at', '>', $cutoff)
                    ->groupBy('reserved_by')
                    ->selectRaw('reserved_by, COUNT(*) AS leases')
                    ->get();

                foreach ($counts as $count) {
                    $leases[(string) $count->reserved_by] = (int) $count->leases;
                }
            }
        }

        return $leases;
    }

    private function retryAfter(string $connection): int
    {
        $value = Container::getInstance()->make('config')->get("queue.connections.{$connection}.retry_after", self::DEFAULT_RETRY_AFTER);

        return is_numeric($value) ? (int) $value : self::DEFAULT_RETRY_AFTER;
    }

    /**
     * The group of a queue:listen child, or null: a row without a ppid or a
     * parent start is never grouped.
     */
    private function groupKey(WorkerHeartbeat $heartbeat): ?string
    {
        if ($heartbeat->mode !== ProcessContext::LISTEN_CHILD || $heartbeat->ppid === null || $heartbeat->ppid <= 0 || $heartbeat->parent_started_at === null) {
            return null;
        }

        return $heartbeat->host.':'.$heartbeat->ppid.':'.Clock::toDecimal((float) $heartbeat->parent_started_at);
    }

    /**
     * @return WorkerRow
     */
    private function row(WorkerHeartbeat $heartbeat, bool $fresh, int $leases, float $quiet): object
    {
        $seen = Clock::stored($heartbeat->last_seen_at);

        $options = json_decode((string) $heartbeat->options, true);

        return (object) [
            'worker_id' => (string) $heartbeat->worker_id,
            'host' => (string) $heartbeat->host,
            'pid' => (int) $heartbeat->pid,
            'ppid' => $heartbeat->ppid === null ? null : (int) $heartbeat->ppid,
            'started_at' => (float) $heartbeat->started_at,
            'mode' => (string) $heartbeat->mode,
            'connection' => (string) $heartbeat->connection,
            'subscribed_queues' => array_values(array_filter(explode(',', (string) $heartbeat->subscribed_queues), static fn (string $queue): bool => $queue !== '')),
            'current_queue' => $heartbeat->current_queue === null ? null : (string) $heartbeat->current_queue,
            'options' => is_array($options) ? $options : [],
            'last_seen_at' => (float) $heartbeat->last_seen_at,
            'heartbeat_fresh' => $fresh,
            'active_leases' => $leases,
            'state' => $leases === 0 && $seen < $quiet ? self::QUIET : self::LIVE,
            'children' => [],
        ];
    }

    /**
     * @param  non-empty-list<array{0: WorkerHeartbeat, 1: WorkerRow}>  $members
     * @return WorkerRow
     */
    private function parentRow(string $key, array $members): object
    {
        $children = array_map(static fn (array $member): object => $member[1], $members);
        usort(
            $children,
            /**
             * @param  WorkerRow  $a
             * @param  WorkerRow  $b
             */
            static fn (object $a, object $b): int => [$b->last_seen_at, $b->worker_id] <=> [$a->last_seen_at, $a->worker_id],
        );
        $newest = $children[0];
        $first = $members[0][0];

        return (object) [
            'worker_id' => $key,
            'host' => (string) $first->host,
            'pid' => (int) $first->ppid,
            'ppid' => null,
            'started_at' => (float) $first->parent_started_at,
            'mode' => self::LISTEN,
            'connection' => $newest->connection,
            'subscribed_queues' => $newest->subscribed_queues,
            'current_queue' => $newest->current_queue,
            'options' => $newest->options,
            'last_seen_at' => $newest->last_seen_at,
            'heartbeat_fresh' => in_array(true, array_map(
                /** @param  WorkerRow  $child */
                static fn (object $child): bool => $child->heartbeat_fresh,
                $children,
            ), true),
            'active_leases' => array_sum(array_map(
                /** @param  WorkerRow  $child */
                static fn (object $child): int => $child->active_leases,
                $children,
            )),
            'state' => in_array(self::LIVE, array_map(
                /** @param  WorkerRow  $child */
                static fn (object $child): string => $child->state,
                $children,
            ), true) ? self::LIVE : self::QUIET,
            'children' => $children,
        ];
    }
}
