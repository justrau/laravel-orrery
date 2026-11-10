<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\HostFile;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The application SQLite file of the D18 process tests in the worker CLI
 * (tests/Support/bin/artisan loads it when ORRERY_TEST_PROVIDERS names it).
 *
 * - ORRERY_TEST_HOST_FILE: the file; HostFileFixture::apply() configures
 *   `app` and `app_queue` on it in boot().
 * - ORRERY_TEST_HOST_CONFIG: a JSON object of the connection's extra keys
 *   (journal_mode, busy_timeout, pragmas).
 * - ORRERY_TEST_HOST_ALIAS: a JSON object of the extra keys of `app_alias`
 *   on the same file.
 * - ORRERY_TEST_HOST_SECOND: a second WAL file (`second`, `second_queue`).
 * - ORRERY_TEST_HOST_TABLES=1: the failed jobs, the batches and the default
 *   cache store on the file (HostFileFixture::useHostTables()), applied when
 *   a command starts, after the worker CLI's identity check. The schedule is
 *   resolved again then, so its mutex store follows the cache settings.
 * - ORRERY_TEST_HOST_SPY: a log file. Each statement of a package session of
 *   `app` or `app_alias` (with its access mode), each Laravel statement on
 *   those connections, and each log message of level error or higher is
 *   one JSON line, with the process's command.
 * - ORRERY_TEST_HOST_SPY_NAMES: more connection names (comma-separated)
 *   whose package and Laravel statements the spy logs.
 * - ORRERY_TEST_HOST_FAILED: a JSON object of more connections by name
 *   (`connections`: database.connections.<name>) and `queue.failed` on the
 *   connection `failed_connection`, and more configuration keys (`config`,
 *   for example the cache stores), applied with the host tables.
 *
 * Its commands: test:host-batch, test:host-reader, test:host-native and
 * test:host-schedule.
 */
class HostFileProvider extends ServiceProvider
{
    public const FILE = 'ORRERY_TEST_HOST_FILE';

    public const CONFIG = 'ORRERY_TEST_HOST_CONFIG';

    public const ALIAS = 'ORRERY_TEST_HOST_ALIAS';

    public const SECOND = 'ORRERY_TEST_HOST_SECOND';

    public const TABLES = 'ORRERY_TEST_HOST_TABLES';

    public const SPY = 'ORRERY_TEST_HOST_SPY';

    public const SPY_NAMES = 'ORRERY_TEST_HOST_SPY_NAMES';

    public const FAILED = 'ORRERY_TEST_HOST_FAILED';

    public function boot(): void
    {
        $file = self::variable(self::FILE);

        if ($file !== null) {
            $config = $this->app['config'];
            $alias = self::variable(self::ALIAS);
            HostFileFixture::apply($config, $file, self::json(self::variable(self::CONFIG)), self::variable(self::SECOND), $alias === null ? null : self::json($alias));

            if (self::variable(self::TABLES) === '1') {
                $failed = self::json(self::variable(self::FAILED));
                $app = $this->app;
                $this->app['events']->listen(CommandStarting::class, static function () use ($app, $config, $failed): void {
                    HostFileFixture::useHostTables($config);

                    foreach ((array) ($failed['connections'] ?? []) as $name => $connection) {
                        $config->set('database.connections.'.$name, $connection);
                    }

                    if (is_string($failed['failed_connection'] ?? null)) {
                        $config->set('queue.failed.database', $failed['failed_connection']);
                    }

                    if (is_array($failed['config'] ?? null)) {
                        foreach ($failed['config'] as $key => $value) {
                            $config->set((string) $key, $value);
                        }
                    }

                    // The schedule and its mutexes are built with the console kernel: build them again with this cache.
                    $app->forgetInstance(Schedule::class);
                });
            }
        }

        $spy = self::variable(self::SPY);

        if ($spy !== null) {
            $this->spy($spy);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([HostBatchCommand::class, HostReaderCommand::class, HostNativeCommand::class, HostScheduleCommand::class]);
        }
    }

    private function spy(string $log): void
    {
        $command = implode(' ', array_slice((array) ($_SERVER['argv'] ?? []), 1, 1));
        $write = static function (array $entry) use ($log, $command): void {
            file_put_contents($log, json_encode(['pid' => getmypid(), 'command' => $command] + $entry, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        };
        $extra = array_values(array_filter(explode(',', (string) self::variable(self::SPY_NAMES))));
        $connections = [HostFileFixture::CONNECTION, HostFileFixture::ALIAS, ...$extra];
        $names = array_map(static fn (string $name): string => PackageSession::NAME_PREFIX.$name, $connections);

        PackageSession::recordForTesting(static function (string $kind, string $sql, ?string $session, ?Connection $connection = null) use ($write, $names): void {
            if ($kind === PackageSession::STATEMENT || ! in_array($session, $names, true)) {
                return;
            }

            $mode = null;

            foreach (PackageSession::sessionsForTesting() as $entry) {
                if ($entry['connection'] === $connection) {
                    $mode = $entry['mode'];
                }
            }

            $write(['source' => 'package', 'kind' => $kind, 'mode' => $mode, 'sql' => $sql]);
        });

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($write, $connections): void {
            if (in_array($event->connectionName, $connections, true)) {
                $write(['source' => 'laravel', 'kind' => 'executed', 'mode' => null, 'sql' => $event->sql]);
            }
        });

        $this->app['events']->listen(MessageLogged::class, static function (MessageLogged $event) use ($write): void {
            if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                $write(['source' => 'log', 'kind' => $event->level, 'mode' => null, 'sql' => mb_substr($event->message, 0, 300)]);
            }
        });
    }

    private static function variable(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(?string $value): array
    {
        $decoded = $value === null ? [] : json_decode($value, true, 8, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
