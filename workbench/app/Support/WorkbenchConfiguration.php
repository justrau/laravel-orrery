<?php

namespace Workbench\App\Support;

use Illuminate\Contracts\Config\Repository;
use Monolog\Handler\NullHandler;
use RuntimeException;

/**
 * The complete storage and service configuration of the Workbench app.
 *
 * WorkbenchServiceProvider applies it with the guarded SQLite file. WorkbenchSeederTest applies
 * the same configuration to a temporary file, so the test seeds exactly what `composer build` seeds.
 *
 * Every consumer that could reach a database, a network service or another process's files is
 * pinned to an owned, local implementation. The connection and store lists are replaced, not
 * merged, so a name such as `mysql` or `redis` cannot resolve, whatever the environment says.
 * An inherited DB_*, DB_URL, SESSION_*, CACHE_*, QUEUE_*, APP_MAINTENANCE_*, REDIS_*, LOG_*,
 * MAIL_*, BROADCAST_*, FILESYSTEM_*, VIEW_COMPILED_PATH or LARAVEL_STORAGE_PATH value therefore
 * has no effect. WorkbenchOwnershipTest proves this with a poisoned environment and sentinels.
 *
 * Every file path is derived from the owned storage directory ($storage). Laravel computes the
 * storage paths in its config files when the config loads, from LARAVEL_STORAGE_PATH if that is
 * set. The provider pins the storage path later, so each precomputed path is set again here:
 * the compiled views, the session files, the disk roots and links, and the log file.
 */
final class WorkbenchConfiguration
{
    /**
     * The queue that the second queue connection uses by default.
     */
    public const SECONDARY_QUEUE = 'reports';

    /**
     * The name of the only log channel.
     */
    public const LOG_CHANNEL = 'workbench';

    /**
     * The rollback-mode switch: `delete` serves the dashboard with the SQLite
     * file in rollback-journal mode, so the package makes no automatic access to it (D18)
     * and shows its unknown states.
     */
    public const JOURNAL_VARIABLE = 'ORRERY_TEST_SQLITE_JOURNAL';

    /**
     * The display zone of the served dashboard (`orrery.display_timezone`). The
     * package reads it with env(), which the web server child of `serve` does not see, so the
     * provider passes it through ProcessEnvironment.
     */
    public const DISPLAY_TIMEZONE_VARIABLE = 'ORRERY_DISPLAY_TIMEZONE';

    public const WAL = 'wal';

    public const ROLLBACK = 'delete';

    /**
     * The journal mode that the switch selects: unset, empty or `wal` gives WAL; `delete` gives the
     * rollback mode; every other value throws.
     */
    public static function journalMode(?string $value): string
    {
        if ($value === null || $value === '' || $value === self::WAL) {
            return self::WAL;
        }

        if ($value === self::ROLLBACK) {
            return self::ROLLBACK;
        }

        throw new RuntimeException(sprintf(
            'The Workbench refuses %s=[%s]: leave it unset (or `wal`) for WAL, or set it to `%s` for the rollback mode.',
            self::JOURNAL_VARIABLE,
            $value,
            self::ROLLBACK,
        ));
    }

    /**
     * Sets `orrery.display_timezone` from the process environment (DISPLAY_TIMEZONE_VARIABLE),
     * when it is set and not empty. The package checks the zone itself (Orrery::displayTimezone()).
     */
    public static function displayTimezone(Repository $config, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $config->set('orrery.display_timezone', $value);
        }
    }

    public static function apply(Repository $config, string $connection, string $databaseFile, string $storage, string $journal = self::WAL): void
    {
        self::pinDatabase($config, $connection, $databaseFile, $journal);
        self::pinRedis($config);
        self::pinPackage($config);
        self::pinQueue($config, $connection);
        self::pinFailedJobs($config, $connection);
        self::pinBatches($config, $connection);
        self::pinCache($config, $storage);
        self::pinSession($config, $connection);
        self::pinSessionFiles($config, $storage);
        self::pinMaintenance($config);
        self::pinViews($config, $storage);
        self::pinLogging($config, self::logFile($storage));
        self::pinMail($config);
        self::pinBroadcasting($config);
        self::pinFilesystems($config, $storage);
        self::pinStorageLinks($config);
    }

    /**
     * The only log file: <storage>/logs/workbench.log.
     */
    public static function logFile(string $storage): string
    {
        return $storage.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'workbench.log';
    }

    /**
     * The owned SQLite connection is the default and the only connection.
     */
    private static function pinDatabase(Repository $config, string $connection, string $databaseFile, string $journal): void
    {
        $config->set('database.default', $connection);
        $config->set('database.connections', [
            $connection => [
                'driver' => 'sqlite',
                'url' => null,
                'database' => $databaseFile,
                'prefix' => '',
                'foreign_key_constraints' => true,
                // `composer serve` and `composer workbench:worker` use the file at the same time.
                // WAL lets the dashboard read while the worker writes. It also avoids a sync per
                // statement, which makes the seeder about 35% faster.
                'busy_timeout' => 5000,
                // The rollback-mode switch changes this for the web server only (see
                // WorkbenchServiceProvider::register()).
                'journal_mode' => $journal,
                'synchronous' => 'normal',
            ],
        ]);
    }

    /**
     * No Redis connection is defined, so no Redis client can be created.
     */
    private static function pinRedis(Repository $config): void
    {
        $config->set('database.redis', [
            'client' => 'phpredis',
            'options' => [],
        ]);
    }

    /**
     * The package tables live on the default connection, like in most applications.
     */
    private static function pinPackage(Repository $config): void
    {
        $config->set('orrery.database', null);
    }

    private static function pinQueue(Repository $config, string $connection): void
    {
        $config->set('queue.default', 'database');
        $config->set('queue.connections', [
            // dispatch_sync() runs through the in-process sync connection.
            'sync' => [
                'driver' => 'sync',
            ],
            'database' => [
                'driver' => 'database',
                'connection' => $connection,
                'table' => 'jobs',
                'queue' => 'default',
                // Longer than the slow job of `composer workbench:worker`, so no worker reserves it twice.
                'retry_after' => 900,
                'after_commit' => false,
            ],
            'database-secondary' => [
                'driver' => 'database',
                'connection' => $connection,
                'table' => 'jobs',
                'queue' => self::SECONDARY_QUEUE,
                'retry_after' => 90,
                'after_commit' => false,
            ],
        ]);
    }

    private static function pinFailedJobs(Repository $config, string $connection): void
    {
        $config->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => $connection,
            'table' => 'failed_jobs',
        ]);
    }

    private static function pinBatches(Repository $config, string $connection): void
    {
        $config->set('queue.batching', [
            'database' => $connection,
            'table' => 'job_batches',
        ]);
    }

    /**
     * The retried job reads a cache flag, and the tick's restart markers and the dashboard's
     * native pause state (Laravel's `queue:pause`) read the default store.
     *
     * The store is the `file` driver in the owned storage directory, not a `database` store on
     * the Workbench's SQLite file. A `database` store there is a host file for the package (D17):
     * a DatabaseStore read deletes expired keys, so the package never reads it automatically, and
     * every queue's pause state would show as unknown on the dashboard.
     */
    private static function pinCache(Repository $config, string $storage): void
    {
        $directory = implode(DIRECTORY_SEPARATOR, [$storage, 'framework', 'cache', 'data']);

        $config->set('cache.default', 'file');
        $config->set('cache.stores', [
            'file' => [
                'driver' => 'file',
                'path' => $directory,
                'lock_path' => $directory,
            ],
            'array' => [
                'driver' => 'array',
                'serialize' => false,
            ],
        ]);
    }

    /**
     * Sessions of the dashboard live in the sessions table of the owned file.
     */
    private static function pinSession(Repository $config, string $connection): void
    {
        $config->set('session.driver', 'database');
        $config->set('session.connection', $connection);
        $config->set('session.table', 'sessions');
        $config->set('session.store', null);
    }

    /**
     * The file session driver is not used, but its directory is precomputed from the storage path.
     */
    private static function pinSessionFiles(Repository $config, string $storage): void
    {
        $config->set('session.files', implode(DIRECTORY_SEPARATOR, [$storage, 'framework', 'sessions']));
    }

    /**
     * Every HTTP request checks maintenance mode. The file driver reads storage_path('framework/down')
     * at run time, so the provider's pinned storage path decides where it reads.
     */
    private static function pinMaintenance(Repository $config): void
    {
        $config->set('app.maintenance', [
            'driver' => 'file',
            'store' => null,
        ]);
    }

    /**
     * The dashboard renders a Blade layout. Its compiled copy goes to the owned storage directory,
     * whatever VIEW_COMPILED_PATH or LARAVEL_STORAGE_PATH say.
     */
    private static function pinViews(Repository $config, string $storage): void
    {
        $config->set('view.compiled', implode(DIRECTORY_SEPARATOR, [$storage, 'framework', 'views']));
    }

    /**
     * The failing sample jobs report their exceptions, so logs must stay local.
     */
    private static function pinLogging(Repository $config, string $logFile): void
    {
        $config->set('logging.default', self::LOG_CHANNEL);
        $config->set('logging.deprecations', [
            'channel' => 'null',
            'trace' => false,
        ]);
        $config->set('logging.channels', [
            self::LOG_CHANNEL => [
                'driver' => 'single',
                'path' => $logFile,
                'level' => 'debug',
                'replace_placeholders' => true,
            ],
            'null' => [
                'driver' => 'monolog',
                'handler' => NullHandler::class,
            ],
        ]);
    }

    /**
     * Mail goes to the log. The package sends long-wait notifications by mail.
     */
    private static function pinMail(Repository $config): void
    {
        $config->set('mail.default', 'log');
        $config->set('mail.mailers', [
            'log' => [
                'transport' => 'log',
                'channel' => self::LOG_CHANNEL,
            ],
        ]);
    }

    private static function pinBroadcasting(Repository $config): void
    {
        $config->set('broadcasting.default', 'null');
        $config->set('broadcasting.connections', [
            'null' => [
                'driver' => 'null',
            ],
        ]);
    }

    /**
     * Only one local disk exists, with its root in the owned storage directory. The application's
     * own local disk is not kept, because its root was precomputed from the inherited storage path.
     */
    private static function pinFilesystems(Repository $config, string $storage): void
    {
        $config->set('filesystems.default', 'local');
        $config->set('filesystems.cloud', null);
        $config->set('filesystems.disks', [
            'local' => [
                'driver' => 'local',
                'root' => implode(DIRECTORY_SEPARATOR, [$storage, 'app', 'private']),
                'throw' => false,
                'report' => false,
            ],
        ]);
    }

    /**
     * `storage:link` would link public/storage to a precomputed storage path. The Workbench has none.
     */
    private static function pinStorageLinks(Repository $config): void
    {
        $config->set('filesystems.links', []);
    }
}
