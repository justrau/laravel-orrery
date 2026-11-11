<?php

namespace Justrau\Orrery\Workers;

/**
 * One declared worker pool of `orrery.workers.pools` (Pools::read()):
 * `processes` workers of `php artisan queue:work <connection>
 * --queue=<queues> --name=<name>` with the given options. Orrery never
 * starts them; orrery:workers prints the process-manager configuration.
 * The name is the pool's stable identity: every worker gets
 * `--name=<name>`, so a heartbeat (its `options.name`) names its pool.
 */
final class Pool
{
    /** The queue:work options of a pool, in the order of the command line. */
    public const OPTIONS = [
        'tries' => '--tries',
        'timeout' => '--timeout',
        'sleep' => '--sleep',
        'backoff' => '--backoff',
        'memory' => '--memory',
        'max_jobs' => '--max-jobs',
        'max_time' => '--max-time',
        'rest' => '--rest',
        'force' => '--force',
    ];

    /** Laravel's queue:work default of --timeout, for the stop wait of a pool without its own. */
    public const DEFAULT_TIMEOUT = 60;

    /**
     * @param  list<string>  $queues  in priority order
     * @param  array<string, int|float|bool|list<int>>  $options  the set options only, keyed as OPTIONS
     */
    public function __construct(
        public readonly string $name,
        public readonly string $connection,
        public readonly array $queues,
        public readonly int $processes,
        public readonly array $options,
    ) {}

    /**
     * The arguments of `artisan`: queue:work, the connection, --queue,
     * --name, then each set option in the order of OPTIONS (Laravel's
     * defaults apply to the others).
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        $arguments = ['queue:work', $this->connection, '--queue='.implode(',', $this->queues), '--name='.$this->name];

        foreach (self::OPTIONS as $key => $flag) {
            if (! array_key_exists($key, $this->options)) {
                continue;
            }

            $value = $this->options[$key];

            if ($value === false) {
                continue;
            }

            $arguments[] = $value === true ? $flag : $flag.'='.self::value($value);
        }

        return $arguments;
    }

    /** The job timeout of the workers: --timeout, else Laravel's default; 0 means none. */
    public function timeout(): int
    {
        $timeout = $this->options['timeout'] ?? null;

        return is_int($timeout) ? $timeout : self::DEFAULT_TIMEOUT;
    }

    /**
     * @param  int|float|list<int>  $value
     */
    public static function value(int|float|array $value): string
    {
        if (is_array($value)) {
            return implode(',', array_map(static fn (int $item): string => (string) $item, $value));
        }

        return (string) $value;
    }
}
