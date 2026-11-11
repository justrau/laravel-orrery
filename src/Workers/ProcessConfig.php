<?php

namespace Justrau\Orrery\Workers;

use InvalidArgumentException;

/**
 * The process-manager configuration of the declared worker pools
 * (orrery:workers). It only renders text: Orrery never starts workers.
 *
 * Every value is quoted for the format that reads it:
 * - list: POSIX shell words (a word outside [A-Za-z0-9_@%+=:,./-] gets
 *   single quotes, and a quote inside becomes '\'').
 * - supervisor: supervisord splits `command` like a POSIX shell (shlex),
 *   so the same words, then every `%` doubled (supervisord expands
 *   `%(name)s` in its values). Its configuration parser cuts a value at `;`
 *   or `#` after whitespace, which no quoting avoids: such a value is
 *   refused.
 * - systemd: ExecStart words in double quotes with `\` and `"` escaped,
 *   then `%` doubled (unit specifiers) and `$` doubled (variables).
 *   WorkingDirectory and User take the raw value with `%` doubled.
 * A value with a line break or another control character is refused in
 * every format. A refusal is an InvalidArgumentException that names the
 * pool or the option.
 */
final class ProcessConfig
{
    public function __construct(
        private readonly string $php,
        private readonly string $path,
        private readonly string $user,
        private readonly string $prefix,
        private readonly string $logDirectory,
    ) {}

    /**
     * The default PHP binary: PHP_BINARY under the CLI SAPI, else `php`
     * (under PHP-FPM, PHP_BINARY is the FPM binary, which cannot run
     * artisan).
     */
    public static function defaultPhp(string $sapi, string $binary): string
    {
        return $sapi === 'cli' && $binary !== '' ? $binary : 'php';
    }

    /**
     * One line per pool: its process count and the command to run from the
     * application's directory.
     *
     * @param  list<Pool>  $pools
     */
    public function list(array $pools): string
    {
        $lines = [];

        foreach ($pools as $pool) {
            self::refuseControl($pool);
            $lines[] = $pool->name.' ('.$pool->processes.' '.($pool->processes === 1 ? 'process' : 'processes').'): '.self::shellLine(['php', 'artisan', ...$pool->arguments()]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * One supervisord program per pool.
     *
     * @param  list<Pool>  $pools
     */
    public function supervisor(array $pools): string
    {
        $this->refuseSettings();
        self::absolute('--path', $this->path);
        self::absolute('--log-dir', $this->logDirectory);
        $out = "; Orrery worker pools for supervisord (php artisan orrery:workers --format=supervisor).\n"
            ."; Orrery never starts workers. Save this as /etc/supervisor/conf.d/{$this->prefix}-workers.conf,\n"
            ."; then run: supervisorctl reread && supervisorctl update\n";

        foreach ($pools as $pool) {
            self::refuseControl($pool);
            $timeout = $pool->timeout();
            $values = [
                'command' => self::shellLine([$this->php, $this->path.'/artisan', ...$pool->arguments()]),
                'process_name' => '%(program_name)s_%(process_num)02d',
                'numprocs' => (string) $pool->processes,
                'autostart' => 'true',
                'autorestart' => 'true',
                // A worker that exits within a second (a lost database connection) is a failed start:
                // 60 retries with growing pauses (1 s, 2 s, ...) ride out an outage of about 30 minutes.
                'startsecs' => '1',
                'startretries' => '60',
                'stopasgroup' => 'true',
                'killasgroup' => 'true',
                'user' => $this->user,
                'redirect_stderr' => 'true',
                'stdout_logfile' => $this->logDirectory.'/'.$this->prefix.'-'.$pool->name.'.log',
                'stopwaitsecs' => (string) ($timeout === 0 ? 3600 : $timeout + 30),
            ];

            $out .= "\n[program:{$this->prefix}-{$pool->name}]\n";

            foreach ($values as $key => $value) {
                if ($key !== 'process_name') {
                    $value = self::supervisordValue($pool, $key, $value);
                }

                if ($key === 'stopwaitsecs' && $timeout === 0) {
                    $out .= "; --timeout=0 (no job timeout): raise stopwaitsecs above your longest job.\n";
                }

                $out .= "{$key}={$value}\n";
            }
        }

        return $out;
    }

    /**
     * One systemd template unit per pool, with its enable line.
     *
     * @param  list<Pool>  $pools
     */
    public function systemd(array $pools): string
    {
        $this->refuseSettings();
        self::absolute('--path', $this->path);
        self::absolute('--php', $this->php);
        $out = "# Orrery worker pools for systemd (php artisan orrery:workers --format=systemd).\n"
            ."# Orrery never starts workers. Save each unit under /etc/systemd/system/,\n"
            ."# then run systemctl daemon-reload and the unit's enable line.\n";

        foreach ($pools as $pool) {
            self::refuseControl($pool);
            $unit = "{$this->prefix}-{$pool->name}";
            $timeout = $pool->timeout();
            $exec = str_replace(['%', '$'], ['%%', '$$'], implode(' ', array_map(self::systemdWord(...), [$this->php, $this->path.'/artisan', ...$pool->arguments()])));

            $out .= "\n# /etc/systemd/system/{$unit}@.service\n"
                ."# systemctl enable --now {$unit}@{1..{$pool->processes}}\n"
                ."[Unit]\n"
                ."Description={$this->prefix} queue worker {$pool->name} %i (Orrery pool)\n"
                ."After=network.target\n"
                // No start limit: a database restart of a few seconds would otherwise stop the restarts.
                ."StartLimitIntervalSec=0\n"
                ."\n"
                ."[Service]\n"
                .'User='.str_replace('%', '%%', $this->user)."\n"
                .'WorkingDirectory='.str_replace('%', '%%', $this->path)."\n"
                ."ExecStart={$exec}\n"
                ."Restart=always\n"
                // 1 s: every clean recycle (queue:restart, --max-jobs, --max-time) waits this long too.
                ."RestartSec=1\n"
                ."KillSignal=SIGTERM\n"
                .'TimeoutStopSec='.($timeout === 0 ? 'infinity' : (string) ($timeout + 30))."\n"
                ."\n"
                ."[Install]\n"
                ."WantedBy=multi-user.target\n";
        }

        return $out;
    }

    /**
     * Per pool, the fields of Forge's "Add background process" form: the
     * Custom tab, which keeps --name, and the values of the Queue worker
     * tab, which has no field for --name, --max-jobs or --max-time.
     *
     * @param  list<Pool>  $pools
     * @return list<array{pool: string, custom: list<array{string, string}>, queue_worker: list<array{string, string}>}>
     */
    public function forge(array $pools): array
    {
        $this->refuseSettings();
        self::absolute('--path', $this->path);
        $forms = [];
        $defaults = ['backoff' => '0', 'sleep' => '3', 'rest' => '0', 'timeout' => '60', 'tries' => '1', 'memory' => '128'];

        foreach ($pools as $pool) {
            self::refuseControl($pool);
            $timeout = $pool->timeout();
            $worker = [['Queue Connection', $pool->connection], ['Number of Processes', (string) $pool->processes], ['Queue', implode(',', $pool->queues)]];

            foreach (['backoff' => 'Backoff', 'sleep' => 'Sleep', 'rest' => 'Rest', 'timeout' => 'Timeout', 'tries' => 'Tries', 'memory' => 'Memory'] as $key => $label) {
                $value = $pool->options[$key] ?? null;
                $worker[] = [$label, $value === null || is_bool($value) ? "not set (Laravel's default: {$defaults[$key]})" : Pool::value($value)];
            }

            $worker[] = ['Force', ($pool->options['force'] ?? false) === true ? 'yes' : 'no'];

            foreach (['max_jobs' => '--max-jobs', 'max_time' => '--max-time'] as $key => $flag) {
                if (isset($pool->options[$key])) {
                    $worker[] = [$flag, 'no field: use the Custom tab'];
                }
            }

            $forms[] = [
                'pool' => $pool->name,
                'custom' => [
                    ['Name', "{$this->prefix}-{$pool->name}"],
                    // Forge writes the command into a supervisord program: the same `%` and cut rules.
                    ['Command', self::supervisordValue($pool, 'command', self::shellLine(['php', 'artisan', ...$pool->arguments()]))],
                    ['Working Directory', $this->path],
                    ['User', $this->user],
                    ['Processes', (string) $pool->processes],
                    ['Stop Seconds', (string) ($timeout === 0 ? 3600 : $timeout + 30)],
                    ['Stop Signal', 'SIGTERM'],
                ],
                'queue_worker' => $worker,
            ];
        }

        return $forms;
    }

    /**
     * A supervisord value: every `%` doubled (supervisord expands
     * `%(name)s`), refused when its parser would cut it at a `;` or `#`
     * after whitespace.
     */
    private static function supervisordValue(Pool $pool, string $key, string $value): string
    {
        $value = str_replace('%', '%%', $value);

        if (preg_match('/\s[;#]/', $value) === 1) {
            throw new InvalidArgumentException("Pool {$pool->name}: supervisord would cut its {$key} value at a ; or # after a space; rename the queue or move the path.");
        }

        return $value;
    }

    private static function absolute(string $option, string $value): void
    {
        if (! str_starts_with($value, '/')) {
            throw new InvalidArgumentException("{$option} must be an absolute path for this format (got \"{$value}\").");
        }
    }

    /** A POSIX shell word: bare when it has only safe characters, else single-quoted. */
    public static function shellWord(string $word): string
    {
        if ($word !== '' && preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#D', $word) === 1) {
            return $word;
        }

        return "'".str_replace("'", "'\\''", $word)."'";
    }

    /** A systemd ExecStart word, before the `%` and `$` escapes of the whole line. */
    public static function systemdWord(string $word): string
    {
        if ($word !== '' && preg_match('#^[A-Za-z0-9_@%+=:,./$-]+$#D', $word) === 1) {
            return $word;
        }

        return '"'.addcslashes($word, '\\"').'"';
    }

    /**
     * @param  list<string>  $words
     */
    private static function shellLine(array $words): string
    {
        return implode(' ', array_map(self::shellWord(...), $words));
    }

    private function refuseSettings(): void
    {
        foreach (['--php' => $this->php, '--path' => $this->path, '--user' => $this->user, '--log-dir' => $this->logDirectory] as $option => $value) {
            if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException("{$option} must be a non-empty value without line breaks or other control characters.");
            }
        }
    }

    private static function refuseControl(Pool $pool): void
    {
        foreach ($pool->arguments() as $argument) {
            if (preg_match('/[\x00-\x1F\x7F]/', $argument) === 1) {
                throw new InvalidArgumentException("Pool {$pool->name}: a value holds a line break or another control character.");
            }
        }
    }
}
