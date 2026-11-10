<?php

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\Support\WorkbenchCliTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Workbench\App\Providers\WorkbenchServiceProvider;
use Workbench\App\Support\WorkbenchBootstrapCache;

/**
 * The Workbench reaches only the owned SQLite file and local files, whatever the environment says.
 *
 * The test runs the real Workbench CLI (`migrate --force`, then `workbench:ownership`) with a
 * poisoned environment. Every consumer gets a hostile value: DB_*, DB_URL, SESSION_*, CACHE_*,
 * QUEUE_*, APP_ENV, APP_MAINTENANCE_*, REDIS_*, MEMCACHED_*, LOG_*, MAIL_*, BROADCAST_* and
 * FILESYSTEM_*. Each hostile host and port points at two sentinel listeners of this test (TCP and
 * UDP). The sentinel accepts and closes every TCP connection and records it, so a MySQL, Redis,
 * SMTP or S3 client fails at once instead of hanging, and the test sees the attempt.
 *
 * The probe sends two HTTP requests through the HTTP kernel (the maintenance check, the session
 * and a compiled Blade layout), and uses the cache, a lock, every queue connection, the
 * failed-job and batch stores, the package tables, the log, the mailer, the broadcaster and the
 * default disk. The test then requires no sentinel traffic, only the owned connection opened,
 * the pinned configuration, and the owned bootstrap cache paths (WorkbenchBootstrapCacheTest
 * covers hostile APP_*_CACHE values).
 *
 * LARAVEL_STORAGE_PATH and VIEW_COMPILED_PATH point at a foreign storage directory with a
 * maintenance file in it. The test requires that directory unchanged, no maintenance mode, and
 * every storage root (storage path, maintenance file, compiled views, disk root, session files)
 * in the owned workbench/storage directory.
 */
#[Group('workbench')]
final class WorkbenchOwnershipTest extends WorkbenchCliTestCase
{
    public function test_a_poisoned_environment_reaches_only_the_owned_database_and_local_files(): void
    {
        $database = $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';

        $tcp = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($tcp, 'Cannot start the TCP sentinel: '.$errorMessage);
        stream_set_blocking($tcp, false);

        $udp = stream_socket_server('udp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND);
        $this->assertIsResource($udp, 'Cannot start the UDP sentinel: '.$errorMessage);
        stream_set_blocking($udp, false);

        $tcpPort = self::port($tcp);
        $udpPort = self::port($udp);
        $attempts = [];

        // A foreign storage directory, the target of LARAVEL_STORAGE_PATH and VIEW_COMPILED_PATH.
        // Its maintenance file would put the dashboard in maintenance mode (HTTP 503) if the
        // Workbench read it. Nothing in it may change.
        $foreignStorage = $this->outsideDirectory.DIRECTORY_SEPARATOR.'storage';
        foreach (['framework/views', 'framework/sessions', 'app/private', 'logs', 'compiled-views'] as $directory) {
            mkdir($foreignStorage.DIRECTORY_SEPARATOR.$directory, 0700, true);
        }
        file_put_contents($foreignStorage.'/framework/down', json_encode(['except' => [], 'redirect' => null, 'retry' => null, 'refresh' => null, 'secret' => null, 'status' => 503, 'template' => null]));
        $foreignBefore = self::tree($foreignStorage);

        $watch = static function () use ($tcp, $udp, &$attempts): void {
            while (($client = @stream_socket_accept($tcp, 0)) !== false) {
                $attempts[] = 'TCP connection to the sentinel';
                fclose($client);
            }

            while (is_string($datagram = @stream_socket_recvfrom($udp, 65535)) && $datagram !== '') {
                $attempts[] = 'UDP datagram to the sentinel: '.substr($datagram, 0, 120);
            }
        };

        $environment = self::poisonedEnvironment($database, '127.0.0.1', $tcpPort, $udpPort) + [
            'LARAVEL_STORAGE_PATH' => $foreignStorage,
            'VIEW_COMPILED_PATH' => $foreignStorage.DIRECTORY_SEPARATOR.'compiled-views',
        ];

        $migrate = $this->testbench(['migrate', '--force'], $environment, $watch);
        $probe = $this->testbench(['workbench:ownership'], $environment, $watch);

        fclose($tcp);
        fclose($udp);

        $this->assertSame([], $attempts, 'The Workbench contacted a service it does not own.');
        $this->assertSame($foreignBefore, self::tree($foreignStorage), 'The Workbench changed the foreign storage directory.');
        $this->assertSucceeded($migrate, 'migrate --force with a poisoned environment');
        $this->assertSucceeded($probe, 'workbench:ownership with a poisoned environment');

        $report = self::probeReport($probe);
        $canonical = (string) realpath($database);

        $this->assertSame(WorkbenchServiceProvider::ENVIRONMENT, $report['environment']);
        // The package reads and writes its tables on its own session of the sibling mirror file
        // next to the owned file: inside the owned directory. The wait times
        // of /api/workload read the jobs table on its read-only session of the owned file (D18).
        $this->assertSame([
            'sqlite' => ['driver' => 'sqlite', 'database' => $canonical],
            'orrery.session.sqlite#sibling (sibling)' => ['driver' => 'sqlite', 'database' => dirname($canonical).'/database.orrery.sqlite'],
            'orrery.session.sqlite (automatic)' => ['driver' => 'sqlite', 'database' => $canonical],
        ], $report['opened_connections']);
        $this->assertSame([200, 200], array_values($report['http']));
        $this->assertSame(['driver' => 'Illuminate\Foundation\FileBasedMaintenanceMode', 'active' => false], $report['maintenance']);
        $this->assertSame('Illuminate\Session\DatabaseSessionHandler', $report['session']['handler']);
        $this->assertSame(['store' => 'Illuminate\Cache\FileStore', 'roundtrip' => true, 'lock' => true], $report['cache']);
        $this->assertSame('Justrau\Orrery\DatabaseQueue', $report['queue']['default']);
        // The package's verifying extender of queue.failer.
        $this->assertSame('Justrau\Orrery\Failed\CapturingUuidFailedJobProvider', $report['failed']['class']);
        $this->assertSame('Illuminate\Mail\Transport\LogTransport', $report['mail']);
        $this->assertSame('Illuminate\Broadcasting\Broadcasters\NullBroadcaster', $report['broadcast']);
        $storage = WorkbenchServiceProvider::storagePath();
        $this->assertSame([
            'disk' => 'local',
            'roundtrip' => true,
            'written_path' => $storage.'/app/private/workbench-ownership-'.$report['token'].'.txt',
        ], $report['filesystem']);
        $this->assertSame([
            'path' => $storage,
            'maintenance_file' => $storage.'/framework/down',
            'view_compiled' => $storage.'/framework/views',
            'compiled_layout' => $report['storage']['compiled_layout'],
            'compiled_layout_exists' => true,
            'local_root' => $storage.'/app/private',
            'session_files' => $storage.'/framework/sessions',
            'cache_files' => $storage.'/framework/cache/data',
            'cache_locks' => $storage.'/framework/cache/data',
            'links' => [],
        ], $report['storage']);
        $this->assertStringStartsWith($storage.'/framework/views/', $report['storage']['compiled_layout']);
        $this->assertSame(['channel' => 'workbench', 'path' => WorkbenchServiceProvider::logFile()], $report['log']);
        $this->assertSame(self::pinnedConfiguration(), $report['config']);
        $this->assertSame(WorkbenchBootstrapCache::paths(), $report['bootstrap_caches']);
        $this->assertSame(WorkbenchBootstrapCache::paths()['APP_PACKAGES_CACHE'], $report['package_manifest']);

        // The requests stored their session in the owned file. Both requests run in one
        // application, and the session handler inserts once and then updates, so one row is enough.
        $pdo = new PDO('sqlite:'.$canonical, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sessions = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $sessions);
        $this->assertSame($sessions, $report['session']['rows']);
        $pdo = null;

        // The log line and the logged mail went to the owned log file.
        $log = (string) file_get_contents(WorkbenchServiceProvider::logFile());
        $this->assertStringContainsString('Workbench ownership probe '.$report['token'], $log);
    }

    /**
     * @return array<string, string>
     */
    private static function poisonedEnvironment(string $database, string $host, int $tcpPort, int $udpPort): array
    {
        return [
            // The guard input names a test-owned file. Every other value is hostile.
            'DB_DATABASE' => $database,
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => (string) $tcpPort,
            'DB_USERNAME' => 'forbidden',
            'DB_PASSWORD' => 'forbidden',
            'DB_URL' => "mysql://forbidden:forbidden@{$host}:{$tcpPort}/forbidden",
            'DB_CACHE_CONNECTION' => 'mysql',
            'DB_CACHE_LOCK_CONNECTION' => 'mysql',
            'DB_CACHE_TABLE' => 'forbidden_cache',
            'DB_QUEUE_CONNECTION' => 'mysql',
            'DB_QUEUE_TABLE' => 'forbidden_jobs',
            'DB_QUEUE' => 'forbidden',
            'ORRERY_CONNECTION' => 'mysql',
            'SESSION_DRIVER' => 'database',
            'SESSION_CONNECTION' => 'mysql',
            'SESSION_STORE' => 'redis',
            'SESSION_TABLE' => 'forbidden_sessions',
            'CACHE_STORE' => 'redis',
            'CACHE_PREFIX' => 'forbidden',
            'QUEUE_CONNECTION' => 'redis',
            'QUEUE_FAILED_DRIVER' => 'database-uuids',
            'REDIS_QUEUE_CONNECTION' => 'default',
            'APP_MAINTENANCE_DRIVER' => 'cache',
            'APP_MAINTENANCE_STORE' => 'redis',
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_URL' => "tcp://{$host}:{$tcpPort}",
            'REDIS_HOST' => $host,
            'REDIS_PORT' => (string) $tcpPort,
            'REDIS_PASSWORD' => 'forbidden',
            'MEMCACHED_HOST' => $host,
            'MEMCACHED_PORT' => (string) $tcpPort,
            'LOG_CHANNEL' => 'papertrail',
            'LOG_STACK' => 'papertrail',
            'LOG_DEPRECATIONS_CHANNEL' => 'papertrail',
            'LOG_LEVEL' => 'debug',
            'PAPERTRAIL_URL' => $host,
            'PAPERTRAIL_PORT' => (string) $udpPort,
            'MAIL_MAILER' => 'smtp',
            'MAIL_URL' => "smtp://{$host}:{$tcpPort}",
            'MAIL_HOST' => $host,
            'MAIL_PORT' => (string) $tcpPort,
            'BROADCAST_CONNECTION' => 'redis',
            'FILESYSTEM_DISK' => 's3',
            'AWS_ENDPOINT' => "http://{$host}:{$tcpPort}",
            'AWS_BUCKET' => 'forbidden',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pinnedConfiguration(): array
    {
        return [
            'database.default' => 'sqlite',
            'database.connections' => ['sqlite'],
            'database.redis' => ['client', 'options'],
            'orrery.database' => null,
            'queue.default' => 'database',
            'queue.connections' => ['sync' => null, 'database' => 'sqlite', 'database-secondary' => 'sqlite'],
            'queue.failed.database' => 'sqlite',
            'queue.batching.database' => 'sqlite',
            'cache.default' => 'file',
            'cache.stores' => ['file', 'array'],
            'cache.stores.file.driver' => 'file',
            'session.driver' => 'database',
            'session.connection' => 'sqlite',
            'session.store' => null,
            'app.maintenance.driver' => 'file',
            'app.maintenance.store' => null,
            'logging.channels' => ['workbench', 'null'],
            'mail.default' => 'log',
            'mail.mailers' => ['log'],
            'broadcasting.default' => 'null',
            'broadcasting.connections' => ['null'],
            'filesystems.default' => 'local',
            'filesystems.disks' => ['local'],
        ];
    }

    /**
     * @param  resource  $socket
     */
    private static function port($socket): int
    {
        $name = (string) stream_socket_get_name($socket, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
