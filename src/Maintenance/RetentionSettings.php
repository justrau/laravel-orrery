<?php

namespace Justrau\Orrery\Maintenance;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Workers\LiveWorkers;

/**
 * The retention configuration as the dashboard reads it:
 * GET /api/settings/retention and the `retention_config` field of
 * /api/health.
 *
 * - `global`: the five `orrery.trim` values, null for an invalid one.
 * - `overrides`: the `orrery.trim_overrides` entries in configuration order
 *   (RetentionPolicy::overrides()), so an index names the same entry as an
 *   error.
 * - `errors`: RetentionPolicy::validate(). The read API validates; the
 *   queue hooks never do.
 * - The known queues: the pairs (connection, queue) of the database-driver
 *   queue connections that are configured now, from: each connection's
 *   default queue (`queue.connections.<name>.queue`, else `default`), the
 *   `orrery.waits` keys and the `orrery.connections` entries (as WaitTime
 *   reads them: split at the first ':'), the `queue` rows of the latest
 *   sampled minute of orrery_samples (stored under the primary alias, so
 *   each row names every alias of it), and the subscribed queues of the
 *   live workers. A connection that is not configured (for example one
 *   removed later) has no known queue.
 * - `unmatched`: the indexes of the override entries that match no known
 *   queue. Null (unknown, never "none") when a mirror source could not be
 *   read or was cut at MAX_SAMPLE_ROWS.
 * - `effective`: the effective values and rules of the first MAX_ROWS
 *   known queues in byte order (RetentionPolicy::effective()).
 *   `truncated`: true when there are more; null when a source could not be
 *   read (more may exist); else false.
 *
 * Each mirror read runs in the watcher, so a failure gives an unknown part,
 * never an error response. A refused mirror reads nothing.
 */
final class RetentionSettings
{
    /** The most `effective` rows in one answer. */
    public const MAX_ROWS = 1000;

    /** The most `queue` rows of the latest sampled minute that one answer reads. */
    public const MAX_SAMPLE_ROWS = 10_000;

    public function __construct(
        private readonly RetentionPolicy $policy,
        private readonly LiveWorkers $workers,
    ) {}

    /**
     * The body of GET /api/settings/retention.
     *
     * @return array{global: array<string, ?int>, overrides: list<array{connection: ?string, queue: ?string, values: array<string, ?int>}>, errors: list<string>, unmatched: list<int>|null, effective: list<array{connection: string, queue: string, values: array<string, array{minutes: ?int, rule: string}>}>, truncated: bool|null}
     */
    public function report(bool $readMirror): array
    {
        $overrides = $this->policy->overrides();
        [$known, $complete, $aliases] = $this->knownQueues($readMirror);
        $effective = [];

        foreach (array_slice($known, 0, self::MAX_ROWS) as [$connection, $queue]) {
            $effective[] = ['connection' => $connection, 'queue' => $queue, 'values' => $this->policy->effective($connection, $queue, $aliases ?? [])];
        }

        return [
            'global' => $this->policy->globalValues(),
            'overrides' => $overrides,
            'errors' => $this->policy->validate(),
            'unmatched' => $complete ? self::unmatched($overrides, $known, $aliases ?? []) : null,
            'effective' => $effective,
            'truncated' => count($known) > self::MAX_ROWS ? true : ($complete ? false : null),
        ];
    }

    /**
     * The `retention_config` field of /api/health.
     *
     * @return array{valid: bool, errors: list<string>, unmatched: list<int>|null}
     */
    public function health(bool $readMirror): array
    {
        $errors = $this->policy->validate();
        [$known, $complete, $aliases] = $this->knownQueues($readMirror);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'unmatched' => $complete ? self::unmatched($this->policy->overrides(), $known, $aliases ?? []) : null,
        ];
    }

    /**
     * @param  list<array{connection: ?string, queue: ?string, values: array<string, ?int>}>  $overrides
     * @param  list<array{0: string, 1: string}>  $known
     * @param  array<string, string>  $aliases
     * @return list<int>
     */
    private static function unmatched(array $overrides, array $known, array $aliases): array
    {
        $unmatched = [];

        foreach ($overrides as $index => $entry) {
            foreach ($known as [$connection, $queue]) {
                if (RetentionPolicy::matches($entry, $connection, $queue, $aliases)) {
                    continue 2;
                }
            }

            $unmatched[] = $index;
        }

        return $unmatched;
    }

    /**
     * The known queues in byte order of (connection, queue), whether every
     * source was read whole, and the primary aliases (null when they could
     * not be resolved; then the effective values use the names as they are,
     * and `unmatched` is unknown).
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: bool, 2: array<string, string>|null}
     */
    private function knownQueues(bool $readMirror): array
    {
        $config = Container::getInstance()->make('config');
        $connections = self::databaseConnections($config);
        $pairs = [];
        $add = static function (string $connection, string $queue) use (&$pairs, $connections): void {
            if ($queue !== '' && in_array($connection, $connections, true)) {
                $pairs[$connection."\0".$queue] = [$connection, $queue];
            }
        };

        foreach ($connections as $connection) {
            $default = $config->get('queue.connections.'.$connection.'.queue');
            $add($connection, is_string($default) && $default !== '' ? $default : 'default');
        }

        foreach (self::configuredNames($config) as $name) {
            $parts = explode(':', $name, 2);

            if (count($parts) === 2) {
                $add($parts[0], $parts[1]);
            }
        }

        $complete = $readMirror;
        // No statement: the queue objects name their jobs tables.
        $aliases = Watcher::instance()->run('retention_settings:aliases', fn (): array => $this->policy->aliases(), null);

        if ($readMirror) {
            $sampled = Watcher::instance()->run('retention_settings:samples', static fn (): array => self::latestSampled(), null);
            $workers = Watcher::instance()->run('retention_settings:workers', fn (): array => $this->workers->list(), null);
            $complete = $aliases !== null && $sampled !== null && $workers !== null && count($sampled) <= self::MAX_SAMPLE_ROWS;

            foreach (array_slice($sampled ?? [], 0, self::MAX_SAMPLE_ROWS) as [$primary, $queue]) {
                foreach ($connections as $connection) {
                    if (($aliases[$connection] ?? $connection) === $primary) {
                        $add($connection, $queue);
                    }
                }
            }

            foreach ($workers ?? [] as $worker) {
                foreach ($worker->subscribed_queues as $queue) {
                    $add($worker->connection, $queue);
                }
            }
        }

        uasort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return [array_values($pairs), $complete && $aliases !== null, $aliases];
    }

    /**
     * The (connection, queue) of the `queue` rows of the latest sampled
     * minute, at most MAX_SAMPLE_ROWS + 1 (so a cut is visible).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function latestSampled(): array
    {
        $session = Container::getInstance()->make(PackageSession::class)->mirror();
        $latest = $session->table(SampleCounts::TABLE)->where('scope', 'queue')->max('minute');

        if (! is_numeric($latest)) {
            return [];
        }

        $rows = [];

        foreach ($session->table(SampleCounts::TABLE)
            ->where('minute', (int) $latest)
            ->where('scope', 'queue')
            ->limit(self::MAX_SAMPLE_ROWS + 1)
            ->get(['connection', 'queue']) as $row) {
            if ($row->connection !== null && $row->queue !== null) {
                $rows[] = [(string) $row->connection, (string) $row->queue];
            }
        }

        return $rows;
    }

    /**
     * The names of the database-driver queue connections.
     *
     * @return list<string>
     */
    private static function databaseConnections(Repository $config): array
    {
        $names = [];

        foreach ((array) $config->get('queue.connections', []) as $name => $settings) {
            if (is_array($settings) && ($settings['driver'] ?? null) === 'database') {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The `connection:queue` names of `orrery.waits` (not `*`) and
     * `orrery.connections`, as WaitTime reads them.
     *
     * @return list<string>
     */
    private static function configuredNames(Repository $config): array
    {
        $names = [];

        foreach (array_keys((array) $config->get('orrery.waits', [])) as $key) {
            if ($key !== '*') {
                $names[] = (string) $key;
            }
        }

        foreach ((array) $config->get('orrery.connections', []) as $key => $value) {
            if (is_string($key) && is_array($value)) {
                foreach ($value as $queue) {
                    if (is_string($queue)) {
                        $names[] = $key.':'.$queue;
                    }
                }
            } elseif (is_string($value)) {
                $names[] = $value;
            }
        }

        return $names;
    }
}
