<?php

namespace Justrau\Orrery\Maintenance;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Justrau\Orrery\Monitoring\WaitTime;
use LogicException;

/**
 * The effective retention of a retention partition.
 *
 * - The effective state of a row is `completed` (not silenced), `silenced`
 *   (a silenced completed row), `failed` or `unknown`. Its retention is the
 *   effective value of that key in minutes.
 * - Connections compare by their primary alias (aliases()): an override
 *   on one queue connection applies to every queue connection on the same
 *   jobs table, as the samples and the settings API name them. While an
 *   entry names a connection, a database queue connection that cannot be
 *   resolved makes the configuration invalid (its aliases are unknown).
 * - The effective value of a key for a (connection, queue): the entry of
 *   `orrery.trim_overrides` with this connection and queue that sets the
 *   key, else the entry with this queue only, else the entry with this
 *   connection only, else `orrery.trim.<key>` (a list of
 *   entries, because a queue name can contain `:`). Names compare
 *   byte-exactly.
 * - A monitored row keeps max(its state's effective value, the effective
 *   `monitored`).
 * - 0 means "trim at the next tick". The overrides trim only mirror rows,
 *   never the application's jobs or failed-job records.
 * - validate() runs at the maintenance, install and read entry points (the
 *   retention phase, orrery:install, GET /api/settings/retention and
 *   /api/health), never in a queue hook. An entry whose connection is not
 *   configured (for example one removed later) is valid; it matches no
 *   known queue (RetentionSettings lists it as unmatched).
 * - shortest(): the horizon readers (the sample coverage, the throughput
 *   and failure-group `retention_from`, the job classes) use the shortest
 *   valid value of a key over the global value and every override, so a
 *   short override never makes a trimmed minute look covered.
 *
 * The configuration is read when it is used: this object is resolved at
 * boot, and an Octane request has its own configuration
 * sandbox. `trim_overrides` is a top-level key, so a configuration file
 * published before 2026-11 gets the package default through mergeConfigFrom();
 * a missing or null value means no overrides.
 */
final class RetentionPolicy
{
    /** The effective states, in the order of their configuration keys. */
    public const STATES = ['completed', 'silenced', 'failed', 'unknown'];

    /** The configuration keys under orrery.trim that the policy reads. */
    public const KEYS = ['completed', 'silenced', 'failed', 'unknown', 'monitored'];

    /** The rules of an effective value, most specific first. */
    public const RULE_BOTH = 'connection+queue';

    public const RULE_QUEUE = 'queue';

    public const RULE_CONNECTION = 'connection';

    public const RULE_GLOBAL = 'global';

    /** The configuration key of the overrides. */
    public const OVERRIDES = 'orrery.trim_overrides';

    /** The longest configuration value that an error message quotes. */
    private const QUOTE_LIMIT = 80;

    /**
     * The effective state of a mirror row, or null for a status that
     * retention never trims (`pending`, `reserved`, `retrying`).
     */
    public static function effectiveState(string $status, bool $silenced): ?string
    {
        return match ($status) {
            'completed' => $silenced ? 'silenced' : 'completed',
            'failed', 'unknown' => $status,
            default => null,
        };
    }

    /**
     * Retention in minutes for a partition; a monitored row uses max(state,
     * monitored). It refuses (LogicException) when a deciding value is not
     * a whole number of minutes: retention validates first, so this only
     * happens when the configuration changed during a run, and then the run
     * fails rather than trimming with a guessed value.
     *
     * @param  array<string, string>|null  $aliases  the primary aliases (aliases()), null to resolve them now
     */
    public function minutes(string $connection, string $queue, string $effectiveState, bool $monitored, ?array $aliases = null): int
    {
        $effective = $this->effective($connection, $queue, $aliases);
        $state = $effective[$effectiveState]['minutes'] ?? null;
        $watched = $effective['monitored']['minutes'];

        if ($state === null || ($monitored && $watched === null)) {
            throw new LogicException("The retention of {$effectiveState} rows is not a whole number of minutes; validate() refuses this configuration.");
        }

        return $monitored ? max($state, $watched) : $state;
    }

    /**
     * The primary alias of every database queue connection: the queue
     * connections whose queue objects share one jobs table map to one name
     * (WaitTime::primaryAliases(), as the samples name them). An override
     * entry and a row match by the primary alias of their connections, so
     * an override on one alias applies to every alias of its jobs table. A
     * connection whose queue cannot be resolved maps to itself.
     *
     * @return array<string, string>
     */
    public function aliases(): array
    {
        return Container::getInstance()->make(WaitTime::class)->primaryAliases();
    }

    /**
     * The effective value and its rule of every key for a (connection,
     * queue). `minutes` is null when the deciding value is not a whole
     * number of minutes (an invalid configuration, which retention refuses).
     *
     * @param  array<string, string>|null  $aliases  the primary aliases (aliases()), null to resolve them now
     * @return array<string, array{minutes: ?int, rule: string}>
     */
    public function effective(string $connection, string $queue, ?array $aliases = null): array
    {
        $aliases ??= $this->aliases();
        $overrides = $this->overrides();
        $result = [];

        foreach (self::KEYS as $key) {
            $result[$key] = ['minutes' => self::minutesOf($this->config()->get("orrery.trim.{$key}")), 'rule' => self::RULE_GLOBAL];

            foreach ([self::RULE_BOTH, self::RULE_QUEUE, self::RULE_CONNECTION] as $rule) {
                foreach ($overrides as $entry) {
                    if (self::level($entry) === $rule && array_key_exists($key, $entry['values']) && self::matches($entry, $connection, $queue, $aliases)) {
                        $result[$key] = ['minutes' => $entry['values'][$key], 'rule' => $rule];

                        continue 3;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * The global values (`orrery.trim`), null for an invalid one.
     *
     * @return array<string, ?int>
     */
    public function globalValues(): array
    {
        $values = [];

        foreach (self::KEYS as $key) {
            $values[$key] = self::minutesOf($this->config()->get("orrery.trim.{$key}"));
        }

        return $values;
    }

    /**
     * The override entries in configuration order (so an index names the
     * same entry as in the configuration and in validate()'s errors). An
     * entry that is not an array, a connection or queue that is not a
     * non-empty string, and an unknown key read as absent; a value that is
     * not a whole number of minutes reads as null. Empty when the value is
     * not a list.
     *
     * @return list<array{connection: ?string, queue: ?string, values: array<string, ?int>}>
     */
    public function overrides(): array
    {
        $raw = $this->config()->get(self::OVERRIDES);

        if (! is_array($raw) || ! array_is_list($raw)) {
            return [];
        }

        $entries = [];

        foreach ($raw as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $values = [];

            foreach (self::KEYS as $key) {
                if (array_key_exists($key, $entry)) {
                    $values[$key] = self::minutesOf($entry[$key]);
                }
            }

            $entries[] = [
                'connection' => self::name($entry['connection'] ?? null),
                'queue' => self::name($entry['queue'] ?? null),
                'values' => $values,
            ];
        }

        return $entries;
    }

    /**
     * Whether a well-formed override entry applies to a (connection, queue).
     * The connections compare by their primary alias. An entry without a
     * connection and a queue matches nothing.
     *
     * @param  array{connection: ?string, queue: ?string, values: array<string, ?int>}  $entry
     * @param  array<string, string>  $aliases  the primary aliases (aliases())
     */
    public static function matches(array $entry, string $connection, string $queue, array $aliases = []): bool
    {
        if ($entry['connection'] === null && $entry['queue'] === null) {
            return false;
        }

        return ($entry['connection'] === null || ($aliases[$entry['connection']] ?? $entry['connection']) === ($aliases[$connection] ?? $connection))
            && ($entry['queue'] === null || $entry['queue'] === $queue);
    }

    /**
     * The shortest valid value of a key over the global value and every
     * override entry, or null when none is valid (then retention trims
     * nothing by that key, so it moves no horizon). Every entry counts, also
     * one that matches no known queue: a shorter horizon only makes a
     * minute unknown, never a covered undercount.
     */
    public static function shortest(string $key): ?int
    {
        $policy = new self;
        $values = [self::minutesOf($policy->config()->get("orrery.trim.{$key}"))];

        foreach ($policy->overrides() as $entry) {
            $values[] = $entry['values'][$key] ?? null;
        }

        $values = array_values(array_filter($values, static fn (?int $value): bool => $value !== null));

        return $values === [] ? null : min($values);
    }

    /** @return list<string> validation errors; empty when valid */
    public function validate(): array
    {
        $errors = [];
        $trim = $this->config()->get('orrery.trim');
        $trim = is_array($trim) ? $trim : [];

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $trim) || $trim[$key] === null) {
                $errors[] = "orrery.trim.{$key} is missing: set it to a whole number of minutes (0 or more).";

                continue;
            }

            if (self::minutesOf($trim[$key]) === null) {
                $errors[] = sprintf('orrery.trim.%s must be a whole number of minutes (0 or more), %s given.', $key, self::quote($trim[$key]));
            }
        }

        return [...$errors, ...$this->validateOverrides()];
    }

    /**
     * @return list<string>
     */
    private function validateOverrides(): array
    {
        $raw = $this->config()->get(self::OVERRIDES);

        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            return ["orrery.trim_overrides must be a list of entries, for example [['queue' => 'emails', 'failed' => 20160]], ".self::quote($raw).' given.'];
        }

        $errors = [];
        // The (primary alias, queue) of each entry so far, by entry index: two aliases of one jobs table are one connection.
        $seen = [];
        $aliases = $this->aliases();
        $named = false;

        foreach ($raw as $index => $entry) {
            $at = self::OVERRIDES.'.'.$index;

            if (! is_array($entry)) {
                $errors[] = "{$at} must be an array with a connection, a queue or both, and one or more trim keys.";

                continue;
            }

            $names = ['connection' => null, 'queue' => null];
            $valid = true;

            foreach (['connection', 'queue'] as $field) {
                if (! array_key_exists($field, $entry) || $entry[$field] === null) {
                    continue;
                }

                $names[$field] = self::name($entry[$field]);

                if ($names[$field] === null) {
                    $errors[] = "{$at}.{$field} must be a non-empty string, ".self::quote($entry[$field]).' given.';
                    $valid = false;
                }
            }

            if ($valid && $names['connection'] === null && $names['queue'] === null) {
                $errors[] = "{$at} needs a connection, a queue or both.";
                $valid = false;
            }

            $keys = 0;

            foreach ($entry as $key => $value) {
                if ($key === 'connection' || $key === 'queue') {
                    continue;
                }

                if (! in_array($key, self::KEYS, true)) {
                    $errors[] = sprintf('%s.%s is not a trim key: use %s.', $at, self::clip((string) $key), implode(', ', self::KEYS));

                    continue;
                }

                $keys++;

                if (self::minutesOf($value) === null) {
                    $errors[] = sprintf('%s.%s must be a whole number of minutes (0 or more), %s given.', $at, $key, self::quote($value));
                }
            }

            if ($keys === 0) {
                $errors[] = "{$at} sets no trim key: add one or more of ".implode(', ', self::KEYS).'.';
            }

            if ($names['connection'] !== null) {
                $named = true;
                $driver = $this->config()->get('queue.connections.'.$names['connection']);

                // A connection that is not configured is not an error: its entry is unmatched.
                if (is_array($driver) && ($driver['driver'] ?? null) !== 'database') {
                    $errors[] = sprintf('%s.connection names the queue connection "%s", whose driver is %s: overrides apply only to connections with the database driver.', $at, self::clip($names['connection']), self::quote($driver['driver'] ?? null));
                }
            }

            if (! $valid) {
                continue;
            }

            $key = ['connection' => $names['connection'] === null ? null : ($aliases[$names['connection']] ?? $names['connection']), 'queue' => $names['queue']];

            foreach ($seen as $earlier => $pair) {
                if ($pair === $key) {
                    $errors[] = "{$at} repeats the connection and queue of entry {$earlier}: give each connection and queue one entry.";

                    break;
                }
            }

            $seen[$index] = $key;
        }

        if ($named) {
            // Fail closed: an unresolved connection may be an alias of a named one, so an override could miss its rows.
            foreach (Container::getInstance()->make(WaitTime::class)->unresolvedConnections() as $connection) {
                $errors[] = sprintf('orrery.trim_overrides: the queue connection "%1$s" cannot be resolved, so its jobs table and aliases are unknown; fix queue.connections.%1$s.', self::clip($connection));
            }
        }

        return $errors;
    }

    /**
     * The level of an entry: connection+queue, queue or connection.
     *
     * @param  array{connection: ?string, queue: ?string, values: array<string, ?int>}  $entry
     */
    private static function level(array $entry): ?string
    {
        return match (true) {
            $entry['connection'] !== null && $entry['queue'] !== null => self::RULE_BOTH,
            $entry['queue'] !== null => self::RULE_QUEUE,
            $entry['connection'] !== null => self::RULE_CONNECTION,
            default => null,
        };
    }

    /** A connection or queue name: a non-empty string, else null. */
    private static function name(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A configuration value for an error message, cut to QUOTE_LIMIT characters. */
    private static function quote(mixed $value): string
    {
        return is_scalar($value) || $value === null ? self::clip(var_export($value, true)) : get_debug_type($value);
    }

    private static function clip(string $text): string
    {
        // Configuration text can be any bytes: the errors reach JSON responses, so they are valid UTF-8.
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', mb_scrub($text, 'UTF-8'));

        return mb_strlen($text) > self::QUOTE_LIMIT ? mb_substr($text, 0, self::QUOTE_LIMIT).'…' : $text;
    }

    /**
     * A whole number of minutes, 0 or more: an int, or a string of digits.
     * Null for any other value.
     */
    public static function minutesOf(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d{1,9}$/D', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
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
