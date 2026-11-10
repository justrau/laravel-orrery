<?php

namespace Justrau\Orrery\Monitoring;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\DatabaseQueue;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\ConnectionName;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Workers\LiveWorkers;
use Throwable;

/**
 * Wait times of the database queues.
 *
 * - The queues checked: the `orrery.waits` keys (not `*`), the
 *   `orrery.connections` entries, every `jobs.queue` value of each
 *   database-driver queue connection (the one grouped backlog read on its
 *   observer session returns them), the queues of the recent samples (sampled(): the
 *   `queue` rows of orrery_samples of the last SAMPLE_SECONDS with a
 *   backlog, a worker or an outcome), the subscribed queues of the live
 *   workers, and every byte-exact queue that the backlog reads return. Only database-driver queue connections are
 *   checked.
 * - The executable backlog is the ready jobs (unreserved, available_at <=
 *   now) plus the expired reservations (reserved_at <= now - retry_after),
 *   as Laravel's DatabaseQueue::pop() selects them. A delayed job is not
 *   backlog.
 * - The oldest executable age is now - min(ready available_at, expired
 *   reserved_at + retry_after), in seconds, or null without backlog.
 * - The runtime estimate (ms) is SUM(runtime_ms_sum) / SUM(runtime_count) of
 *   the series' `queue` rows of the last SAMPLE_SECONDS, else
 *   unknown (null): a series without a known runtime has no estimate.
 * - wait (seconds) = backlog x estimate / 1000 / max(1, live subscribed
 *   workers) (LiveWorkers::capacity()), rounded; 0 without backlog; null
 *   when the estimate is unknown.
 * - threshold is `waits.<connection:queue>`, else `waits.*`, else 60. 0
 *   disables the alert (alerts()).
 *
 * The reads of one queue connection run inside the watcher. They read the
 * database, table and retry_after of the queue object that the queue manager
 * holds (target()), on that database connection's observer session. The
 * backlog read is one grouped read of the table, byte-exact: grouped by the
 * bytes of the queue (MySQL: GROUP BY CAST(queue AS BINARY), with that
 * expression selected; Postgres and SQLite compare bytes under their
 * default collations), so `HIGH` beside a requested `high` is a row of its
 * own.
 *
 * Unknown rows: a target whose backlog read fails, and a
 * database-driver connection whose target cannot be resolved, give one row
 * for each queue that is known without the jobs table (unknownQueues()). Such
 * a row has `backlog`, `oldest_age`, `oldest_at` and `wait` null and
 * `backlog_unknown` true. Every other row has `backlog_unknown` false. An
 * unresolved connection gives its own rows: its aliases cannot be merged
 * without a target.
 *
 * `oldest_at` is the oldest executable time as an absolute epoch (the
 * MeasurementCache stores it and derives the age at read time).
 *
 * @phpstan-type MeasuredRow array{connection: string, connections: list<string>, queue: string, backlog: ?int, oldest_age: ?float, oldest_at: ?float, runtime_ms: ?float, wait: ?int, threshold: int, backlog_unknown: bool}
 * @phpstan-type Target array{connection: string, table: string, retry_after: int}
 * @phpstan-type WorkerRow object{connection: string, subscribed_queues: list<string>}
 */
final class WaitTime
{
    public const DEFAULT_THRESHOLD = 60;

    /** Laravel's DatabaseConnector default. */
    private const DEFAULT_RETRY_AFTER = 60;

    /** The window of the sampled runtime and of the sampled queue names, in seconds. */
    public const SAMPLE_SECONDS = 3600;

    /** The most sampled series that one read returns. */
    public const ROW_CAP = 1000;

    /** @var array<string, Target|null> the targets of one measure() call */
    private array $targets = [];

    /**
     * The live workers of one measure() call: the caller's rows, else one
     * watched read for all queues. Null when that read failed: the workers
     * are unknown, and so is every wait that needs them.
     *
     * @var list<WorkerRow>|null
     */
    private ?array $workerRows = null;

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly LiveWorkers $workers,
    ) {
    }

    /**
     * One row per queue of each physical target: the database connection and
     * the table of the queue object (target()). Queue connections that share
     * a target are measured once: `connection` is the first of their names in
     * sorted order, and `connections` lists all of them. The backlog uses the
     * smallest retry_after of the connections (a reservation expires for the
     * first worker that can take it), the runtime estimate is the sampled
     * series of the primary alias (the samples count every alias under it),
     * the capacity counts the live workers of all of them, each worker once,
     * and the threshold is the smallest configured threshold of them (0 only
     * when all are 0).
     *
     * A caller that has read the live workers already (TickMeasurement: one
     * LiveWorkers::list() per tick run) passes them as $workers, so the waits
     * use those rows and measure() reads no workers itself.
     *
     * @param  list<WorkerRow>|null  $workers
     * @return list<MeasuredRow>
     */
    public function measure(?array $workers = null): array
    {
        $this->targets = [];
        $this->workerRows = $workers;
        $now = Clock::now();
        $samples = $this->sampled($now);
        // The live workers name the queues that they serve, also when nothing else does.
        $this->workerRows ??= Watcher::instance()->run('wait_time:workers', fn (): array => $this->workers->list(), null);
        $subscribed = $samples['names'];

        foreach ($this->workerRows ?? [] as $worker) {
            foreach ($worker->subscribed_queues as $queue) {
                $subscribed[$worker->connection][] = $queue;
            }
        }

        $rows = [];
        /** @var array<string, list<string>>|null $recent the queues of the recent queue state, the mirror and the signals, read once, only for a failure */
        $recent = null;

        foreach ($this->groups($this->queues($subscribed)) as $group) {
            $primary = $group['connections'][0];
            $target = $group['target'];

            $backlogs = $target === null ? null : Watcher::instance()->run('wait_time:'.$primary, function () use ($target, $group, $now): array {
                $names = array_values(array_unique($group['queues']));
                sort($names);

                return $this->backlogs($target, $names, $now);
            }, null);

            if ($backlogs === null) {
                $recent ??= self::merge($this->recentlySampled($now), $this->signalledQueues());
                $known = self::merge($recent, $this->mirroredQueues($group['connections']));

                foreach ($this->unknownQueues($group['connections'], $group['queues'], $workers, $known) as $queue) {
                    $rows[] = [
                        'connection' => $primary,
                        'connections' => $group['connections'],
                        'queue' => $queue,
                        'backlog' => null,
                        'oldest_age' => null,
                        'oldest_at' => null,
                        'runtime_ms' => $samples['runtime'][SampleCounts::series('queue', $primary, $queue)] ?? null,
                        'wait' => null,
                        'threshold' => $this->groupThreshold($group['connections'], $queue),
                        'backlog_unknown' => true,
                    ];
                }

                continue;
            }

            ksort($backlogs);

            foreach ($backlogs as $queue => [$backlog, $oldest]) {
                $queue = (string) $queue;
                $runtime = $samples['runtime'][SampleCounts::series('queue', $primary, $queue)] ?? null;

                $rows[] = [
                    'connection' => $primary,
                    'connections' => $group['connections'],
                    'queue' => $queue,
                    'backlog' => $backlog,
                    'oldest_age' => $oldest === null ? null : max(0.0, $now - $oldest),
                    'oldest_at' => $oldest,
                    'runtime_ms' => $runtime,
                    'wait' => $this->wait($group['connections'], $queue, $backlog, $runtime),
                    'threshold' => $this->groupThreshold($group['connections'], $queue),
                    'backlog_unknown' => false,
                ];
            }
        }

        return $rows;
    }

    /**
     * The estimated time to clear in seconds: backlog x runtime / 1000 /
     * max(1, workers), rounded; 0 without backlog; null when the runtime is
     * unknown. MeasurementCache derives the wait of a cached row with it.
     */
    public static function waitFor(int $backlog, ?float $runtime, int $workers): ?int
    {
        if ($backlog === 0) {
            return 0;
        }

        if ($runtime === null) {
            return null;
        }

        return (int) round($backlog * $runtime / 1000 / max(1, $workers));
    }

    /**
     * The resolved target of every database-driver queue connection, keyed
     * by the connection name (null: it cannot be resolved), in name order.
     * MeasurementCache fingerprints them. It starts a new set of targets, as
     * measure() does, so a later retryAfter() uses these.
     *
     * @return array<string, Target|null>
     */
    public function resolvedTargets(): array
    {
        $this->targets = [];
        $targets = [];

        foreach ($this->databaseConnections() as $connection) {
            if (DatabaseDriverFilter::isDatabase($connection)) {
                $targets[$connection] = $this->target($connection);
            }
        }

        ksort($targets);

        return $targets;
    }

    /**
     * The lease interval of a measured row's connections: the
     * smallest retry_after of their queue objects (target()), as measure()
     * uses it for the expired reservations. It reuses the targets of the last
     * measure() call. Laravel's default (60) when none of them resolves.
     *
     * @param  list<string>  $connections
     */
    public function retryAfter(array $connections): int
    {
        $values = [];

        foreach ($connections as $connection) {
            $target = $this->target($connection);

            if ($target !== null) {
                $values[] = $target['retry_after'];
            }
        }

        return $values === [] ? self::DEFAULT_RETRY_AFTER : min($values);
    }

    /**
     * The database-driver queue connections whose queue object cannot be
     * resolved (the queue manager throws, or the object is not a
     * DatabaseQueue with a connection and a table): their jobs table and
     * aliases are unknown. The retention overrides refuse while one exists
     * and an entry names a connection (RetentionPolicy::validate()).
     *
     * @return list<string>
     */
    public function unresolvedConnections(): array
    {
        $this->targets = [];
        $unresolved = [];

        foreach ($this->databaseConnections() as $connection) {
            if ($this->target($connection) === null) {
                $unresolved[] = $connection;
            }
        }

        return $unresolved;
    }

    /**
     * The primary alias of every database queue connection
     * (for the sample series): the queue connections whose queue objects
     * share one jobs table map to the first of their names in sorted order,
     * as measure() names their rows. A connection whose target cannot be
     * resolved maps to itself.
     *
     * @return array<string, string>
     */
    public function primaryAliases(): array
    {
        $this->targets = [];
        $aliases = [];

        foreach ($this->groups(array_fill_keys($this->databaseConnections(), [])) as $group) {
            foreach ($group['connections'] as $connection) {
                $aliases[$connection] = $group['connections'][0];
            }
        }

        return $aliases;
    }

    /**
     * The database queue connections whose queue objects share the jobs
     * table of $connection (its aliases, $connection included), in name
     * order, as measure() lists them in a row's `connections`. A connection
     * whose target cannot be resolved has only itself.
     *
     * @return list<string>
     */
    public function aliasesOf(string $connection): array
    {
        $this->targets = [];

        foreach ($this->groups(array_fill_keys($this->databaseConnections(), [])) as $group) {
            if ($group['target'] !== null && in_array($connection, $group['connections'], true)) {
                return $group['connections'];
            }
        }

        return [$connection];
    }

    /**
     * The queue connections whose queue objects share one jobs table with
     * different retry_after values (gate fix 4), one entry per table:
     * `connections` in name order, the `table`, and `values`, the
     * retry_after of each connection in the same order. A reservation made
     * through the longer one expires for a worker of the shorter one after
     * its shorter retry_after, so that worker can run the job a second time
     * while the first still holds it, as Laravel's own pop() does. The
     * backlog keeps the smallest retry_after (measure()); this only names
     * the hazard. A connection whose target cannot be resolved is left out.
     *
     * @return list<array{connections: list<string>, table: string, values: list<int>}>
     */
    public function sharedTableRetryAfter(): array
    {
        $this->targets = [];
        $shared = [];

        foreach ($this->groups(array_fill_keys($this->databaseConnections(), [])) as $group) {
            if ($group['target'] === null || count($group['connections']) < 2) {
                continue;
            }

            $values = [];

            foreach ($group['connections'] as $connection) {
                $values[] = $this->target($connection)['retry_after'] ?? self::DEFAULT_RETRY_AFTER;
            }

            if (count(array_unique($values)) > 1) {
                $shared[] = ['connections' => $group['connections'], 'table' => $group['target']['table'], 'values' => $values];
            }
        }

        return $shared;
    }

    /**
     * The queue connections grouped by their physical target, in the order
     * of their first connection name. A connection without a readable
     * database queue object is a group of its own with the target null.
     *
     * @param  array<string, list<string>>  $queues
     * @return list<array{target: Target|null, connections: list<string>, queues: list<string>}>
     */
    private function groups(array $queues): array
    {
        $groups = [];
        $unresolved = [];

        foreach ($queues as $connection => $names) {
            $connection = (string) $connection;
            $target = $this->target($connection);

            if ($target === null) {
                $unresolved[] = ['target' => null, 'connections' => [$connection], 'queues' => $names];

                continue;
            }

            $key = $target['connection']."\0".$target['table'];

            if (! isset($groups[$key])) {
                $groups[$key] = ['target' => $target, 'connections' => [], 'queues' => []];
            }

            $groups[$key]['target']['retry_after'] = min($groups[$key]['target']['retry_after'], $target['retry_after']);
            $groups[$key]['connections'][] = $connection;

            foreach ($names as $name) {
                $groups[$key]['queues'][] = $name;
            }
        }

        $result = $unresolved;

        foreach ($groups as $group) {
            sort($group['connections']);
            $result[] = $group;
        }

        usort($result, static fn (array $a, array $b): int => $a['connections'][0] <=> $b['connections'][0]);

        return $result;
    }

    /**
     * The queues of a failed or unresolved target that are known without its
     * jobs table: the configured and counted queues of its
     * connections, each connection's default queue, the subscribed queues of
     * the given live workers, the queues of the recent samples, and (gate
     * fix 3) the queues of its connections' mirror rows and queue-scope
     * signal rows (mirroredQueues(), signalledQueues()). A queue that Orrery
     * knows of is listed with unknown values, never hidden.
     *
     * @param  list<string>  $connections
     * @param  list<string>  $configured
     * @param  list<WorkerRow>|null  $workers
     * @param  array<string, list<string>>  $sampled
     * @return list<string>
     */
    private function unknownQueues(array $connections, array $configured, ?array $workers, array $sampled): array
    {
        $names = $configured;

        foreach ($connections as $connection) {
            $default = $this->config()->get('queue.connections.'.$connection.'.queue');
            $names[] = is_string($default) && $default !== '' ? $default : 'default';

            foreach ($sampled[$connection] ?? [] as $queue) {
                $names[] = $queue;
            }
        }

        foreach ($workers ?? [] as $worker) {
            if (in_array($worker->connection, $connections, true)) {
                foreach ($worker->subscribed_queues as $queue) {
                    $names[] = $queue;
                }
            }
        }

        $names = array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
        sort($names);

        return $names;
    }

    /**
     * The `queue` rows of orrery_samples of the last SAMPLE_SECONDS,
     * in one grouped read keyed by the byte-exact series digest:
     * - `runtime`: SUM(runtime_ms_sum) / SUM(runtime_count) per series, only
     *   where a runtime is known;
     * - `names`: the queues per stored (primary) connection whose rows had a
     *   backlog, a worker or an outcome in the window, at most ROW_CAP
     *   series. A queue that the samples only carried forward (no backlog,
     *   no worker, no outcome for SAMPLE_SECONDS) drops out, so a name never
     *   keeps itself alive through its own samples.
     * A failed read gives neither: the runtime is unknown, and the names come
     * from the other sources.
     *
     * @return array{runtime: array<string, float>, names: array<string, list<string>>}
     */
    private function sampled(float $now): array
    {
        return Watcher::instance()->run('wait_time:samples', static function () use ($now): array {
            $runtime = [];
            $names = [];

            foreach (Container::getInstance()->make(PackageSession::class)->mirror()->table(SampleCounts::TABLE)
                ->where('scope', 'queue')
                ->where('minute', '>=', SampleCounts::minuteOf($now - self::SAMPLE_SECONDS))
                ->where(static fn (Builder $query) => $query->where('completed', '>', 0)->orWhere('failed', '>', 0)->orWhere('ready', '>', 0)->orWhere('workers', '>', 0))
                ->groupBy('series')
                ->orderBy('series')
                ->limit(self::ROW_CAP)
                ->selectRaw('series, MIN(connection) AS c, MIN(queue) AS q, SUM(runtime_ms_sum) AS runtime_sum, SUM(runtime_count) AS runtime_count')
                ->get() as $row) {
                $count = (int) $row->runtime_count;

                if ($count > 0) {
                    $runtime[(string) $row->series] = max(0.0, (float) $row->runtime_sum / $count);
                }

                if ($row->c !== null && $row->q !== null) {
                    $names[(string) $row->c][] = (string) $row->q;
                }
            }

            return ['runtime' => $runtime, 'names' => $names];
        }, ['runtime' => [], 'names' => []]);
    }

    /**
     * The queues of the recent queue state (the sampled `queue` rows of the
     * last Samples::FRESH_SECONDS, so the queues that the last few ticks
     * measured), per stored connection, at most ROW_CAP series. One bounded
     * read, only on the failure path: the backlog of a queue that was not
     * sampled in the last minutes is not known from the samples either. A
     * failed read gives no names; the other names stay.
     *
     * @return array<string, list<string>>
     */
    private function recentlySampled(float $now): array
    {
        return Watcher::instance()->run('wait_time:sampled_queues', static function () use ($now): array {
            $names = [];

            foreach (Container::getInstance()->make(PackageSession::class)->mirror()->table(SampleCounts::TABLE)
                ->where('scope', 'queue')
                ->where('minute', '>=', (int) floor($now) - Samples::FRESH_SECONDS)
                ->whereNotNull('over_limit')
                ->distinct()
                ->orderBy('series')
                ->limit(self::ROW_CAP)
                ->get(['series', 'connection', 'queue']) as $row) {
                $names[(string) $row->connection][] = (string) $row->queue;
            }

            return $names;
        }, []);
    }

    /**
     * The queues of the mirror rows of the given connections (gate fix 3):
     * the jobs that Orrery observed and still keeps, so retention bounds them
     * to the trim windows. Only on the failure path, at most ROW_CAP queues
     * per connection. On SQLite and Postgres a recursive skip scan seeks the
     * next queue through the orrery_jobs indexes that start with
     * (connection, queue), so its cost follows the number of queues, not of
     * rows. MySQL has no such index skip in a CTE; it reads DISTINCT of the
     * queue's bytes (CAST AS BINARY), so `HIGH` and `high` stay two queues.
     * A failed read gives no names; the other names stay.
     *
     * @param  list<string>  $connections
     * @return array<string, list<string>>
     */
    private function mirroredQueues(array $connections): array
    {
        return Watcher::instance()->run('wait_time:mirrored_queues', static function () use ($connections): array {
            $mirror = Container::getInstance()->make(PackageSession::class)->mirror();
            $names = [];

            foreach ($connections as $connection) {
                foreach (self::queuesOf($mirror, $connection) as $queue) {
                    $names[$connection][] = $queue;
                }
            }

            return $names;
        }, []);
    }

    /**
     * The distinct queues of one connection's mirror rows, at most ROW_CAP.
     *
     * @return list<string>
     */
    private static function queuesOf(Connection $mirror, string $connection): array
    {
        if (in_array($mirror->getDriverName(), ['mysql', 'mariadb'], true)) {
            return array_values($mirror->table(JobStore::TABLE)
                ->where('connection', $connection)
                ->distinct()
                ->limit(self::ROW_CAP)
                ->selectRaw('CAST(`queue` AS BINARY) AS queue_bytes')
                ->pluck('queue_bytes')
                ->map(static fn ($queue): string => (string) $queue)
                ->all());
        }

        $table = $mirror->getQueryGrammar()->wrapTable(JobStore::TABLE);
        // A skip scan: each step seeks MIN(queue) above the last one through the (connection, queue) index.
        $sql = 'with recursive orrery_known (queue, n) as ('
            .'select (select min(queue) from '.$table.' where connection = ?), 1'
            .' union all'
            .' select (select min(queue) from '.$table.' where connection = ? and queue > orrery_known.queue), n + 1'
            .' from orrery_known where orrery_known.queue is not null and n < ?'
            .') select queue from orrery_known where queue is not null limit ?';
        $queues = [];

        foreach ($mirror->select($sql, [$connection, $connection, self::ROW_CAP, self::ROW_CAP]) as $row) {
            $queues[] = (string) $row->queue;
        }

        return $queues;
    }

    /**
     * The queues of the queue-scope signal rows (gate fix 3): an Orrery pause
     * or continue of one queue, and the annotation of a native pause, each
     * `<connection>:<queue>`. At most ROW_CAP rows, only on the failure path.
     * A failed read gives no names; the other names stay.
     *
     * @return array<string, list<string>>
     */
    private function signalledQueues(): array
    {
        return Watcher::instance()->run('wait_time:signalled_queues', static function (): array {
            $names = [];

            foreach (Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_signals')
                ->where('scope', 'queue')
                // The newest rows: a name that a recent pause or annotation names.
                ->orderBy('id', 'desc')
                ->limit(self::ROW_CAP)
                ->get(['id', 'scope_value']) as $row) {
                $parts = explode(':', (string) $row->scope_value, 2);

                if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                    $names[$parts[0]][] = $parts[1];
                }
            }

            return $names;
        }, []);
    }

    /**
     * The union of name maps, per connection.
     *
     * @param  array<string, list<string>>  ...$maps
     * @return array<string, list<string>>
     */
    private static function merge(array ...$maps): array
    {
        $names = [];

        foreach ($maps as $map) {
            foreach ($map as $connection => $queues) {
                foreach ($queues as $queue) {
                    $names[(string) $connection][] = $queue;
                }
            }
        }

        return $names;
    }

    /**
     * The smallest positive threshold of the connections, or 0 when every one is 0.
     *
     * @param  list<string>  $connections
     */
    private function groupThreshold(array $connections, string $queue): int
    {
        $positive = [];

        foreach ($connections as $connection) {
            $threshold = $this->threshold($connection.':'.$queue);

            if ($threshold > 0) {
                $positive[] = $threshold;
            }
        }

        return $positive === [] ? 0 : min($positive);
    }

    /**
     * The rows whose oldest executable age or estimated time to clear
     * reaches their threshold (a threshold of 0 disables).
     *
     * @return list<MeasuredRow>
     */
    public function alerts(): array
    {
        return self::alerting($this->measure());
    }

    /**
     * The rows of a measure() result that alerts() returns.
     *
     * An unknown row (backlog_unknown) never alerts: its age and wait are null.
     *
     * @template TRow of array{oldest_age: ?float, wait: ?int, threshold: int}
     *
     * @param  list<TRow>  $rows
     * @return list<TRow>
     */
    public static function alerting(array $rows): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => self::reachesThreshold($row)));
    }

    /**
     * @param  array{oldest_age: ?float, wait: ?int, threshold: int}  $row
     */
    private static function reachesThreshold(array $row): bool
    {
        return $row['threshold'] > 0
            && (($row['oldest_age'] !== null && $row['oldest_age'] >= $row['threshold']) || ($row['wait'] !== null && $row['wait'] >= $row['threshold']));
    }

    /**
     * The over-limit predicate: the oldest executable age
     * reaches a threshold that is not 0. It ignores the estimated time to
     * clear, which alerts() also checks.
     *
     * @param  array{threshold: int, oldest_age: ?float}  $row
     */
    public static function overLimit(array $row): bool
    {
        return $row['threshold'] > 0 && $row['oldest_age'] !== null && $row['oldest_age'] >= $row['threshold'];
    }

    /**
     * @param  list<string>  $connections
     */
    private function wait(array $connections, string $queue, int $backlog, ?float $runtime): ?int
    {
        if ($backlog === 0 || $runtime === null) {
            return self::waitFor($backlog, $runtime, 0);
        }

        // The watched read of measure() failed: the workers are unknown, so the wait is too (never a read outside the watcher).
        if ($this->workerRows === null) {
            return null;
        }

        return self::waitFor($backlog, $runtime, LiveWorkers::subscribed($this->workerRows, $connections, $queue));
    }

    private function threshold(string $key): int
    {
        $waits = (array) $this->config()->get('orrery.waits', []);
        $value = $waits[$key] ?? $waits['*'] ?? self::DEFAULT_THRESHOLD;

        return is_numeric($value) ? max(0, (int) $value) : self::DEFAULT_THRESHOLD;
    }

    /**
     * The queues to check, per database-driver queue connection.
     *
     * @param  array<string, list<string>>  $sampled  the queues of the recent samples and of the live workers, per connection
     * @return array<string, list<string>>
     */
    private function queues(array $sampled): array
    {
        $names = [];

        foreach (array_keys((array) $this->config()->get('orrery.waits', [])) as $key) {
            if ($key !== '*') {
                $names[] = (string) $key;
            }
        }

        foreach ((array) $this->config()->get('orrery.connections', []) as $key => $value) {
            if (is_string($key) && is_array($value)) {
                foreach ($value as $queue) {
                    $names[] = $key.':'.$queue;
                }
            } elseif (is_string($value)) {
                $names[] = $value;
            }
        }

        $queues = [];

        foreach ($names as $name) {
            $parts = explode(':', $name, 2);

            if (count($parts) === 2 && $parts[1] !== '') {
                $queues[$parts[0]][] = $parts[1];
            }
        }

        foreach ($sampled as $connection => $list) {
            foreach ($list as $queue) {
                if ($queue !== '') {
                    $queues[(string) $connection][] = $queue;
                }
            }
        }

        // Every database queue connection is checked; measure() reads the distinct queues of each target once.
        foreach ($this->databaseConnections() as $connection) {
            $queues[$connection] ??= [];
        }

        $result = [];

        foreach ($queues as $connection => $list) {
            if (! DatabaseDriverFilter::isDatabase((string) $connection)) {
                continue;
            }

            $list = array_values(array_unique($list));
            sort($list);
            $result[(string) $connection] = $list;
        }

        ksort($result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function databaseConnections(): array
    {
        $names = [];

        foreach ((array) $this->config()->get('queue.connections', []) as $name => $settings) {
            if (is_array($settings) && ($settings['driver'] ?? null) === 'database') {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * [backlog, oldest executable time] per byte-exact queue: the requested
     * queues (0 and null without a job) and every queue of the table, from
     * one grouped read without a WHERE clause: it visits every row of the
     * table once (the distinct read and the two backlog reads before it
     * visited the table three times without the optional index). With the
     * optional index (`queue`, `reserved_at`, `available_at`) it visits
     * every entry of that index instead of the table rows: the index holds
     * every column that the read needs, so the read is smaller, but its cost
     * still grows with all the rows, the delayed and reserved ones included,
     * not with the backlog alone.
     *
     * @param  Target  $target
     * @param  list<string>  $queues
     * @return array<string, array{0: int, 1: ?float}>
     */
    private function backlogs(array $target, array $queues, float $now): array
    {
        $time = (int) floor($now);
        $retryAfter = $target['retry_after'];
        $result = [];

        foreach ($queues as $queue) {
            $result[$queue] = [0, null];
        }

        $binary = in_array($this->sessions->for($target['connection'])->getDriverName(), ['mysql', 'mariadb'], true);
        $expiredBefore = $time - $retryAfter;
        // The grouping by the queue's bytes keeps HIGH and high apart.
        $rows = $this->jobs($target)
            ->when($binary, static fn (Builder $query) => $query->groupByRaw('CAST(`queue` AS BINARY)')->selectRaw(
                'CAST(`queue` AS BINARY) AS queue_bytes, '
                .'SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS ready, '
                .'MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) AS ready_oldest, '
                .'SUM(CASE WHEN reserved_at IS NOT NULL AND reserved_at <= ? THEN 1 ELSE 0 END) AS expired, '
                .'MIN(CASE WHEN reserved_at IS NOT NULL AND reserved_at <= ? THEN reserved_at END) AS expired_oldest',
                [$time, $time, $expiredBefore, $expiredBefore],
            ))
            ->when(! $binary, static fn (Builder $query) => $query->groupBy('queue')->selectRaw(
                'queue AS queue_bytes, '
                .'SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS ready, '
                .'MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) AS ready_oldest, '
                .'SUM(CASE WHEN reserved_at IS NOT NULL AND reserved_at <= ? THEN 1 ELSE 0 END) AS expired, '
                .'MIN(CASE WHEN reserved_at IS NOT NULL AND reserved_at <= ? THEN reserved_at END) AS expired_oldest',
                [$time, $time, $expiredBefore, $expiredBefore],
            ))
            ->get();

        foreach ($rows as $row) {
            $ready = (int) $row->ready;
            $expired = (int) $row->expired;
            $oldest = $row->ready_oldest === null ? null : (float) $row->ready_oldest;

            if ($row->expired_oldest !== null) {
                $since = (float) $row->expired_oldest + $retryAfter;
                $oldest = $oldest === null ? $since : min($oldest, $since);
            }

            $result[(string) $row->queue_bytes] = [$ready + $expired, $oldest];
        }

        return $result;
    }

    /**
     * @param  Target  $target
     */
    private function jobs(array $target): Builder
    {
        return $this->sessions->for($target['connection'])->table($target['table']);
    }

    /**
     * The database target of the queue object that the queue manager holds
     * for the connection, as BulkResolver::target() reads it: the base
     * name of its connection instance, its table and its retry_after. So a
     * later change of the default database connection or of the queue
     * configuration never moves the reads to another database than the one
     * that the queue uses. The reads still run on the observer session of
     * that database connection.
     *
     * @return Target|null
     */
    private function target(string $connection): ?array
    {
        if (array_key_exists($connection, $this->targets)) {
            return $this->targets[$connection];
        }

        try {
            $queue = Container::getInstance()->make('queue')->connection($connection);
        } catch (Throwable) {
            return $this->targets[$connection] = null;
        }

        if (! $queue instanceof DatabaseQueue) {
            return $this->targets[$connection] = null;
        }

        $name = (string) $queue->getDatabase()->getName();
        [$table, $retryAfter] = Closure::bind(static fn (DatabaseQueue $queue): array => [(string) $queue->table, $queue->retryAfter], null, DatabaseQueue::class)($queue);

        if ($name === '' || $table === '') {
            return $this->targets[$connection] = null;
        }

        return $this->targets[$connection] = [
            'connection' => ConnectionName::base(Container::getInstance()->make('db'), $name),
            'table' => $table,
            'retry_after' => is_numeric($retryAfter) ? (int) $retryAfter : self::DEFAULT_RETRY_AFTER,
        ];
    }

    /**
     * The configuration of the running request, read when it is used: this
     * object is resolved at boot, and an Octane request has its
     * own configuration sandbox.
     */
    private function config(): Repository
    {
        return Container::getInstance()->make('config');
    }
}
