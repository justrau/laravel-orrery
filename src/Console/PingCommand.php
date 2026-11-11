<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Justrau\Orrery\Monitoring\Ping;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The checks of the monitoring ping (Monitoring\Ping) for a cron monitor:
 * exit 0 when every check passes, else 1 (also while the package is off).
 * `--json` prints the body of `GET {path}/ping`. It needs no token: it
 * runs on the server.
 */
#[AsCommand(name: 'orrery:ping')]
class PingCommand extends Command
{
    /** @var string */
    protected $signature = 'orrery:ping {--json : Print the JSON body of the ping route}';

    /** @var string */
    protected $description = 'Check that Orrery observes, ticks and reaches its database; exit 1 when a check fails';

    public function handle(Ping $ping): int
    {
        $result = $ping->run();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

            return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        if ($result['status'] === 'disabled') {
            $this->components->error('Orrery is disabled (ORRERY_ENABLED=false): nothing is checked.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($result['checks'] ?? [] as $name => $check) {
            $rows[] = [$name, $check['ok'] === true ? 'ok' : (string) $check['reason'], self::detail($check)];
        }

        $this->table(['Check', 'Status', 'Detail'], $rows);

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $check
     */
    private static function detail(array $check): string
    {
        $parts = [];

        if (array_key_exists('age', $check)) {
            $parts[] = $check['age'] === null ? 'no tick yet' : 'age '.$check['age'].' s';
        }

        if (isset($check['version'])) {
            $parts[] = 'version '.$check['version'];
        }

        if (array_key_exists('installed', $check)) {
            $parts[] = 'installed '.($check['installed'] ?? 'unknown').', expected '.$check['expected'];
        }

        if (isset($check['dropped'])) {
            $parts[] = (($check['at_least'] ?? false) === true ? 'at least ' : '').$check['dropped'].' dropped';
        }

        if (isset($check['queues']) && is_array($check['queues'])) {
            $parts[] = implode(', ', array_map(static fn (array $queue): string => $queue['connection'].':'.$queue['queue'], $check['queues']));
        }

        return implode('; ', $parts);
    }
}
