<?php

namespace Justrau\Orrery\Workers;

use Illuminate\Contracts\Config\Repository;

/**
 * Reads and validates `orrery.workers.pools`: an array of pools keyed by
 * name (`[a-z0-9-]+`). Each pool has `connection` (a configured queue
 * connection with the `database` driver), `queues` (a non-empty list of
 * queue names, in priority order), `processes` (a whole number of at least
 * 1) and optionally the queue:work options of Pool::OPTIONS. Validation is
 * strict: an unknown key, a wrong type or a value out of range is an error
 * that names the pool and the field. A pool with an error is not returned.
 *
 * The job timeout (`timeout`, else Laravel's 60) must be shorter than the
 * connection's `retry_after` (else Laravel's 60): Laravel requires --timeout
 * to be several seconds shorter than retry_after, or a job can run twice.
 * A timeout that reaches retry_after is an error; one within
 * RETRY_AFTER_MARGIN seconds of it, and no timeout (0), are warnings, and
 * the pool stays valid.
 *
 * PHP keeps only the last of two equal array keys, so two pools with the
 * same name cannot reach this class: the config file holds one of them.
 */
final class Pools
{
    public const KEY = 'orrery.workers.pools';

    private const REQUIRED = ['connection', 'queues', 'processes'];

    /** Laravel's default retry_after of a database queue connection. */
    public const DEFAULT_RETRY_AFTER = 60;

    /** A timeout closer to retry_after than this is a warning. */
    public const RETRY_AFTER_MARGIN = 10;

    private const TIMEOUT_RULE = 'Laravel requires --timeout to be several seconds shorter than retry_after, or a job can run twice.';

    /**
     * @return array{pools: list<Pool>, errors: list<string>, warnings: list<string>}
     */
    public static function read(Repository $config): array
    {
        $declared = $config->get(self::KEY);

        if ($declared === null) {
            return ['pools' => [], 'errors' => [], 'warnings' => []];
        }

        if (! is_array($declared)) {
            return ['pools' => [], 'errors' => [self::KEY.': must be an array of pools keyed by name (got '.self::describe($declared).').'], 'warnings' => []];
        }

        $pools = [];
        $errors = [];
        $warnings = [];

        foreach ($declared as $name => $pool) {
            $poolErrors = [];
            $field = static fn (string $key): string => self::KEY.'.'.$name.($key === '' ? '' : '.'.$key);

            if (! is_string($name) || preg_match('/^[a-z0-9-]+$/D', $name) !== 1) {
                $errors[] = $field('').': the pool name must be lowercase letters, digits and dashes ([a-z0-9-]+).';

                continue;
            }

            if (! is_array($pool)) {
                $errors[] = $field('').': must be an array with connection, queues and processes (got '.self::describe($pool).').';

                continue;
            }

            foreach (array_keys($pool) as $key) {
                if (! is_string($key) || (! in_array($key, self::REQUIRED, true) && ! array_key_exists($key, Pool::OPTIONS))) {
                    $poolErrors[] = $field((string) $key).': unknown key; the keys are '.implode(', ', [...self::REQUIRED, ...array_keys(Pool::OPTIONS)]).'.';
                }
            }

            foreach (self::REQUIRED as $key) {
                if (! array_key_exists($key, $pool)) {
                    $poolErrors[] = $field($key).': is required.';
                }
            }

            $connection = $pool['connection'] ?? null;

            if (array_key_exists('connection', $pool)) {
                $problem = self::connectionProblem($config, $connection);

                if ($problem !== null) {
                    $poolErrors[] = $field('connection').': '.$problem;
                }
            }

            $queues = $pool['queues'] ?? null;

            if (array_key_exists('queues', $pool)) {
                $problem = self::queuesProblem($queues);

                if ($problem !== null) {
                    $poolErrors[] = $field('queues').': '.$problem;
                }
            }

            $processes = $pool['processes'] ?? null;

            if (array_key_exists('processes', $pool) && (! is_int($processes) || $processes < 1)) {
                $poolErrors[] = $field('processes').': must be a whole number of at least 1 (got '.self::describe($processes).').';
            }

            $options = [];

            foreach (Pool::OPTIONS as $key => $flag) {
                if (! array_key_exists($key, $pool)) {
                    continue;
                }

                $problem = self::optionProblem($key, $pool[$key]);

                if ($problem !== null) {
                    $poolErrors[] = $field($key).': '.$problem.' (got '.self::describe($pool[$key]).').';

                    continue;
                }

                $options[$key] = $pool[$key];
            }

            if ($poolErrors === []) {
                /** @var string $connection */
                $timeoutProblem = self::timeoutProblem($config, $connection, $options['timeout'] ?? Pool::DEFAULT_TIMEOUT);

                if ($timeoutProblem !== null) {
                    if ($timeoutProblem[0] === 'error') {
                        $poolErrors[] = $field('timeout').': '.$timeoutProblem[1];
                    } else {
                        $warnings[] = $field('timeout').': '.$timeoutProblem[1];
                    }
                }
            }

            if ($poolErrors !== []) {
                array_push($errors, ...$poolErrors);

                continue;
            }

            /** @var string $connection */
            /** @var list<string> $queues */
            /** @var int $processes */
            /** @var array<string, int|float|bool|list<int>> $options */
            $pools[] = new Pool($name, $connection, $queues, $processes, $options);
        }

        return ['pools' => $pools, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return array{'error'|'warning', string}|null
     */
    private static function timeoutProblem(Repository $config, string $connection, mixed $timeout): ?array
    {
        $retryAfter = $config->get("queue.connections.{$connection}.retry_after");
        $retryAfter = is_numeric($retryAfter) ? (int) $retryAfter : self::DEFAULT_RETRY_AFTER;
        $timeout = is_int($timeout) ? $timeout : Pool::DEFAULT_TIMEOUT;
        $named = "the job timeout {$timeout} s, the retry_after of \"{$connection}\" {$retryAfter} s";

        if ($timeout === 0) {
            return ['warning', "no job timeout (0): a job that runs longer than the retry_after of \"{$connection}\" ({$retryAfter} s) runs twice. ".self::TIMEOUT_RULE];
        }

        if ($timeout >= $retryAfter) {
            return ['error', "must be shorter than retry_after ({$named}). ".self::TIMEOUT_RULE];
        }

        if ($retryAfter - $timeout < self::RETRY_AFTER_MARGIN) {
            return ['warning', 'is within '.self::RETRY_AFTER_MARGIN." s of retry_after ({$named}). ".self::TIMEOUT_RULE];
        }

        return null;
    }

    private static function connectionProblem(Repository $config, mixed $connection): ?string
    {
        if (! is_string($connection) || $connection === '') {
            return 'must be the name of a queue connection (got '.self::describe($connection).').';
        }

        $connections = $config->get('queue.connections');
        $settings = is_array($connections) && array_key_exists($connection, $connections) ? $connections[$connection] : null;

        if (! is_array($settings)) {
            return self::describe($connection).' is not a queue connection of queue.connections.';
        }

        $driver = $settings['driver'] ?? null;

        if ($driver !== 'database') {
            return self::describe($connection).' is not a database queue connection (its driver is '.self::describe($driver).').';
        }

        return null;
    }

    private static function queuesProblem(mixed $queues): ?string
    {
        if (! is_array($queues) || $queues === [] || ! array_is_list($queues)) {
            return 'must be a non-empty list of queue names, in priority order (got '.self::describe($queues).').';
        }

        foreach ($queues as $queue) {
            if (! is_string($queue) || $queue === '') {
                return 'every queue name must be a non-empty string (got '.self::describe($queue).').';
            }

            if (str_contains($queue, ',')) {
                return 'the queue name '.self::describe($queue).' holds a comma, which --queue reads as a separator.';
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $queue) === 1) {
                return 'the queue name '.self::describe($queue).' holds a control character.';
            }
        }

        if (count(array_unique($queues)) !== count($queues)) {
            return 'lists a queue twice.';
        }

        return null;
    }

    private static function optionProblem(string $key, mixed $value): ?string
    {
        return match ($key) {
            'tries', 'timeout', 'max_jobs', 'max_time' => is_int($value) && $value >= 0 ? null : 'must be a whole number of at least 0',
            'memory' => is_int($value) && $value >= 1 ? null : 'must be a whole number of megabytes, at least 1',
            'sleep', 'rest' => (is_int($value) || (is_float($value) && is_finite($value))) && $value >= 0 ? null : 'must be a number of seconds, at least 0',
            'backoff' => self::backoffValid($value) ? null : 'must be a whole number of seconds, or a non-empty list of them',
            'force' => is_bool($value) ? null : 'must be true or false',
            default => 'unknown key',
        };
    }

    private static function backoffValid(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_int($item) || $item < 0) {
                return false;
            }
        }

        return true;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => (string) json_encode(mb_strlen($value) > 60 ? mb_substr($value, 0, 60).'…' : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }
}
