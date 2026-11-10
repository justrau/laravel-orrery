<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Config\Repository;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Workers\LiveWorkers;
use Throwable;

/**
 * One measurement shared by the HTTP readers for a few seconds:
 * every `/api/workload` request reads WaitTime through it. The tick
 * never does (TickMeasurement measures every run).
 *
 * - The store is the default cache store. The entry lives for
 *   `orrery.dashboard.measure_cache_seconds` (default 5); 0 turns the cache
 *   off.
 * - The key is KEY_PREFIX plus a sha256 fingerprint, without credentials, of
 *   the mirror connection name, its host, port, database, prefix and
 *   (Postgres) schema, the resolved targets (base connection, table,
 *   retry_after, and the host, port and database of the base connection) of
 *   the database queue connections, and `orrery.waits` and
 *   `orrery.connections`. So another configuration, another server or
 *   another target never reads this entry.
 * - The entry holds `measured_at` and per row: the names, `backlog`, the
 *   oldest executable time (an absolute epoch, never an age), `runtime_ms`,
 *   `threshold`, `backlog_unknown` and `native` (NativePause::read() of the
 *   primary alias on a miss: the queue's own flag, Laravel's global pause
 *   and why a value is unknown).
 * - On a hit, `oldest_age` is derived from the cached oldest time and the
 *   current time, and `wait` from the request's own live workers, so the
 *   capacity is never older than the request.
 * - An entry of the wrong shape, or one older than the lifetime, is a miss.
 *   `measured_at` is taken before the measurement, so a measurement that
 *   lasts the whole lifetime is never served; it records the degradation
 *   `measure_cache:slow`, so the health events show it.
 * - The native pause of the rows is read with NativePause::read(): a
 *   bounded number of store round trips, and none after a failed one.
 *   A pause or continue of this process forgets the entry (forget()), so
 *   the next request measures the new native state.
 *   A key, get or put error is reported (`measure_cache`), and the request
 *   measures directly.
 *
 * @phpstan-import-type MeasuredRow from WaitTime
 * @phpstan-import-type WorkerRow from WaitTime
 * @phpstan-import-type State from NativePause
 *
 * @phpstan-type CachedRow array{connection: string, connections: list<string>, queue: string, backlog: ?int, oldest_age: ?float, oldest_at: ?float, runtime_ms: ?float, wait: ?int, threshold: int, backlog_unknown: bool, native: State}
 * @phpstan-type StoredRow array{connection: string, connections: list<string>, queue: string, backlog: ?int, oldest_at: ?float, runtime_ms: ?float, threshold: int, backlog_unknown: bool, native: State}
 */
final class MeasurementCache
{
    /** v2: the runtime of a row comes from the samples. */
    public const KEY_PREFIX = 'orrery:measure:v2:';

    public const DEFAULT_SECONDS = 5;

    public function __construct(
        private readonly Repository $config,
        private readonly WaitTime $waits,
        private readonly NativePause $pause,
    ) {}

    /**
     * The rows of WaitTime::measure() with `native`, from the cache
     * when a fresh entry of this configuration exists.
     *
     * @param  list<WorkerRow>  $workers  the request's live workers
     * @return list<CachedRow>
     */
    public function measure(array $workers): array
    {
        $seconds = $this->seconds();

        // D17: no package cache write (or read) on a host SQLite file.
        if ($seconds <= 0 || ! Container::getInstance()->make(HostFiles::class)->cacheIsSafe(null)) {
            return $this->fresh($workers)[0];
        }

        $now = Clock::now();

        try {
            $key = $this->key();
            $entry = $this->store()->get($key);
        } catch (Throwable $error) {
            Watcher::instance()->report('measure_cache', $error);

            return $this->fresh($workers)[0];
        }

        $stored = self::storedRows($entry, $now, $seconds);

        if ($stored !== null) {
            return self::derive($stored, $workers, $now);
        }

        $started = Clock::monotonic();
        [$rows, $entry] = $this->fresh($workers);
        $elapsed = Clock::monotonic() - $started;

        if ($elapsed >= $seconds) {
            Degradation::error('measure_cache:slow', sprintf('The queue measurement took %.1F s, at least the cache lifetime of %d s, so it is never served from the cache.', $elapsed, $seconds));
        }

        try {
            $this->store()->put($key, $entry, $seconds);
        } catch (Throwable $error) {
            Watcher::instance()->report('measure_cache', $error);
        }

        return $rows;
    }

    /**
     * Forgets the entry of the current configuration (after a pause or a
     * continue). A failure is reported, never thrown: the entry then ends
     * with its lifetime.
     */
    public function forget(): void
    {
        if ($this->seconds() <= 0 || ! Container::getInstance()->make(HostFiles::class)->cacheIsSafe(null)) {
            return;
        }

        try {
            $this->store()->forget($this->key());
        } catch (Throwable $error) {
            Watcher::instance()->report('measure_cache', $error);
        }
    }

    /**
     * The cache key of the current configuration.
     */
    public function key(): string
    {
        $mirror = Container::getInstance()->make(PackageSession::class)->mirror();

        $targets = [];

        foreach ($this->waits->resolvedTargets() as $name => $target) {
            $targets[$name] = $target === null ? null : [
                ...$target,
                'server' => [
                    $this->config->get('database.connections.'.$target['connection'].'.host'),
                    $this->config->get('database.connections.'.$target['connection'].'.port'),
                    $this->config->get('database.connections.'.$target['connection'].'.database'),
                ],
            ];
        }

        $fingerprint = [
            'mirror' => [
                $mirror->getName(),
                $mirror->getConfig('host'),
                $mirror->getConfig('port'),
                $mirror->getDatabaseName(),
                $mirror->getTablePrefix(),
                $mirror->getConfig('search_path') ?? $mirror->getConfig('schema'),
            ],
            'targets' => $targets,
            'waits' => $this->config->get('orrery.waits', []),
            'connections' => $this->config->get('orrery.connections', []),
        ];

        return self::KEY_PREFIX.hash('sha256', serialize($fingerprint));
    }

    private function seconds(): int
    {
        $value = $this->config->get('orrery.dashboard.measure_cache_seconds', self::DEFAULT_SECONDS);

        return is_numeric($value) ? max(0, (int) $value) : self::DEFAULT_SECONDS;
    }

    private function store(): \Illuminate\Contracts\Cache\Repository
    {
        /** @var Factory $cache */
        $cache = Container::getInstance()->make('cache');

        return $cache->store();
    }

    /**
     * A new measurement, and its cache entry.
     *
     * @param  list<WorkerRow>  $workers
     * @return array{0: list<CachedRow>, 1: array{measured_at: float, rows: list<StoredRow>}}
     */
    private function fresh(array $workers): array
    {
        $measuredAt = Clock::now();
        $rows = [];
        $stored = [];
        $measured = $this->waits->measure($workers);
        $pauses = $this->pause->read(array_map(static fn (array $row): array => [$row['connection'], $row['queue']], $measured));

        foreach ($measured as $row) {
            $native = $pauses[NativePause::key($row['connection'], $row['queue'])] ?? ['queue' => null, 'global' => null, 'unknown' => NativePause::FAILED];
            $rows[] = [...$row, 'native' => $native];
            $stored[] = [
                'connection' => $row['connection'],
                'connections' => $row['connections'],
                'queue' => $row['queue'],
                'backlog' => $row['backlog'],
                'oldest_at' => $row['oldest_at'],
                'runtime_ms' => $row['runtime_ms'],
                'threshold' => $row['threshold'],
                'backlog_unknown' => $row['backlog_unknown'],
                'native' => $native,
            ];
        }

        return [$rows, ['measured_at' => $measuredAt, 'rows' => $stored]];
    }

    /**
     * The rows of a fresh entry of the right shape, else null (a miss).
     *
     * @return list<StoredRow>|null
     */
    private static function storedRows(mixed $entry, float $now, int $seconds): ?array
    {
        if (! is_array($entry) || ! is_float($entry['measured_at'] ?? null) || ! is_array($entry['rows'] ?? null) || ! array_is_list($entry['rows'])) {
            return null;
        }

        $age = $now - $entry['measured_at'];

        if ($age < 0 || $age >= $seconds) {
            return null;
        }

        $rows = [];

        foreach ($entry['rows'] as $row) {
            $valid = is_array($row)
                && is_string($row['connection'] ?? null)
                && is_string($row['queue'] ?? null)
                && is_array($row['connections'] ?? null) && array_is_list($row['connections']) && $row['connections'] !== []
                && array_filter($row['connections'], static fn (mixed $name): bool => ! is_string($name)) === []
                && array_key_exists('backlog', $row) && ($row['backlog'] === null || is_int($row['backlog']))
                && array_key_exists('oldest_at', $row) && ($row['oldest_at'] === null || is_float($row['oldest_at']))
                && array_key_exists('runtime_ms', $row) && ($row['runtime_ms'] === null || is_float($row['runtime_ms']))
                && is_int($row['threshold'] ?? null)
                && is_bool($row['backlog_unknown'] ?? null)
                && self::validNative($row['native'] ?? null)
                && ($row['backlog_unknown'] === ($row['backlog'] === null));

            if (! $valid) {
                return null;
            }

            /** @var StoredRow $row */
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Whether a stored native state has the shape of NativePause::read().
     */
    private static function validNative(mixed $native): bool
    {
        return is_array($native)
            && array_key_exists('queue', $native) && ($native['queue'] === null || is_bool($native['queue']))
            && array_key_exists('global', $native) && ($native['global'] === null || is_bool($native['global']))
            && array_key_exists('unknown', $native) && in_array($native['unknown'], [null, NativePause::MASKED, NativePause::UNSAFE, NativePause::FAILED], true)
            && count($native) === 3;
    }

    /**
     * The rows of a cached entry, with the age at $now and the wait of the request's workers.
     *
     * @param  list<StoredRow>  $stored
     * @param  list<WorkerRow>  $workers
     * @return list<CachedRow>
     */
    private static function derive(array $stored, array $workers, float $now): array
    {
        $rows = [];

        foreach ($stored as $row) {
            $rows[] = [
                'connection' => $row['connection'],
                'connections' => $row['connections'],
                'queue' => $row['queue'],
                'backlog' => $row['backlog'],
                'oldest_age' => $row['oldest_at'] === null ? null : max(0.0, $now - $row['oldest_at']),
                'oldest_at' => $row['oldest_at'],
                'runtime_ms' => $row['runtime_ms'],
                'wait' => $row['backlog'] === null ? null : WaitTime::waitFor($row['backlog'], $row['runtime_ms'], LiveWorkers::subscribed($workers, $row['connections'], $row['queue'])),
                'threshold' => $row['threshold'],
                'backlog_unknown' => $row['backlog_unknown'],
                'native' => $row['native'],
            ];
        }

        return $rows;
    }
}
