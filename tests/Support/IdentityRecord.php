<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/**
 * What a process really resolved: the PHP version, the queue, package,
 * failed-job and batch configuration, and for the primary and the mirror
 * connection the driver, the database that the server (or SQLite) reports,
 * the table prefix, the server version and the identity evidence.
 *
 * Evidence:
 * - endpoint-token (MySQL, Postgres): the token of the orrery_marker database,
 *   read through the resolved connection, plus the host, port and session id.
 * - sqlite-file: the canonical file that SQLite reports, in its owned
 *   orrery-sqlite-* directory, plus the process id.
 * - sqlite-memory: an in-memory database of this process.
 *
 * capture() reads the resolved state only. assertMatches() compares it with a
 * TestEnvironment, and line() prints it for a parent process to check.
 */
final class IdentityRecord
{
    public const LINE_PREFIX = 'ORRERY-IDENTITY ';

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private readonly array $data) {}

    public static function capture(Application $app): self
    {
        $config = $app['config'];
        $db = $app['db'];

        $default = $config->get('database.default');
        $queueDefault = $config->get('queue.default');
        $queueConnection = $config->get("queue.connections.{$queueDefault}.connection") ?? $default;
        $packageSetting = $config->get('orrery.database');
        $packageConnection = $packageSetting ?? $default;
        $primary = self::side($db, $queueConnection);

        return new self([
            'php' => PHP_VERSION,
            'default' => $default,
            'queue' => [
                'default' => $queueDefault,
                'driver' => $config->get("queue.connections.{$queueDefault}.driver"),
                'connection' => $queueConnection,
                'table' => $config->get("queue.connections.{$queueDefault}.table"),
            ],
            'package' => ['setting' => $packageSetting, 'connection' => $packageConnection],
            'primary' => $primary,
            // The same name resolves to the same connection of the manager,
            // so its record is the same; another name is read on its own.
            'mirror' => $packageConnection === $queueConnection ? $primary : self::side($db, $packageConnection),
            'failed' => [
                'driver' => $config->get('queue.failed.driver'),
                'database' => $config->get('queue.failed.database'),
                'table' => $config->get('queue.failed.table'),
            ],
            'batching' => [
                'database' => $config->get('queue.batching.database'),
                'table' => $config->get('queue.batching.table'),
            ],
            'cache' => $config->get('cache.default'),
            // The directory of the default store when it is a file store (the shared cache), else null.
            'cache_path' => is_string($config->get('cache.default')) ? $config->get('cache.stores.'.$config->get('cache.default').'.path') : null,
            'session' => $config->get('session.driver'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromLine(string $line): self
    {
        $line = trim($line);

        if (! str_starts_with($line, self::LINE_PREFIX)) {
            throw new HarnessRefusal("Not an identity line: {$line}");
        }

        $data = json_decode(substr($line, strlen(self::LINE_PREFIX)), true);

        if (! is_array($data)) {
            throw new HarnessRefusal("The identity line does not hold a JSON object: {$line}");
        }

        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $path): mixed
    {
        $value = $this->data;

        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    public function line(): string
    {
        return self::LINE_PREFIX.json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @throws HarnessRefusal with every difference, when the record does not match
     */
    public function assertMatches(TestEnvironment $env): void
    {
        $differences = [];
        $expect = function (string $path, mixed $expected) use (&$differences): void {
            $actual = $this->get($path);

            if ($actual !== $expected) {
                $differences[] = sprintf('%s: expected %s, got %s', $path, self::show($expected), self::show($actual));
            }
        };

        $php = (string) $this->get('php');

        if ($env->expectPhp !== null && $php !== $env->expectPhp && ! str_starts_with($php, $env->expectPhp.'.')) {
            $differences[] = sprintf('php: expected %s (ORRERY_EXPECT_PHP), got %s', $env->expectPhp, self::show($php));
        }

        if ($env->expectDriver !== null && $env->expectDriver !== $env->driver) {
            $differences[] = sprintf('driver: expected %s (ORRERY_EXPECT_DRIVER), but the harness is configured for %s', $env->expectDriver, $env->driver);
        }

        $expect('default', TestEnvironment::PRIMARY);
        $expect('queue.default', 'database');
        $expect('queue.driver', 'database');
        $expect('queue.connection', TestEnvironment::PRIMARY);
        $expect('queue.table', 'jobs');
        $expect('package.setting', $env->packageConnection());
        $expect('package.connection', $env->mirrorConnectionName());
        $expect('failed.driver', 'database-uuids');
        $expect('failed.database', TestEnvironment::PRIMARY);
        $expect('failed.table', 'failed_jobs');
        $expect('batching.database', TestEnvironment::PRIMARY);
        $expect('batching.table', 'job_batches');

        $sides = [
            'primary' => [TestEnvironment::PRIMARY, $env->database, $env->primaryPrefix()],
            'mirror' => [$env->mirrorConnectionName(), $env->mirrorDatabase(), $env->mirrorPrefix()],
        ];

        // The harness pins the array store; a test that opted into the
        // shared file store (SharedCache) expects that store and its directory.
        $expect('cache', $env->sharedCache === null ? 'array' : SharedCache::STORE);
        $expect('cache_path', $env->sharedCache);
        $expect('session', 'array');

        foreach ($sides as $side => [$connection, $database, $prefix]) {
            $expect("{$side}.error", null);
            $expect("{$side}.connection", $connection);
            $expect("{$side}.driver", $env->driver);
            $expect("{$side}.database", $database);
            $expect("{$side}.prefix", $prefix);

            $version = (string) $this->get("{$side}.server_version");

            if ($env->isServer()) {
                $wanted = TestEnvironment::SERVER_VERSIONS[$env->driver];

                if (! str_starts_with($version, $wanted)) {
                    $differences[] = sprintf('%s.server_version: expected %s*, got %s', $side, $wanted, self::show($version));
                }

                $expect("{$side}.evidence.kind", 'endpoint-token');
                $expect("{$side}.evidence.marker", $env->token);
                $expect("{$side}.evidence.host", TestEnvironment::HOST);
                $expect("{$side}.evidence.port", $env->port);
            } else {
                if ($version === '') {
                    $differences[] = "{$side}.server_version: expected the SQLite version, got nothing";
                }

                $expect("{$side}.evidence.kind", $env->inMemory() ? 'sqlite-memory' : 'sqlite-file');
            }
        }

        $samePdo = $this->get('primary.pdo') === $this->get('mirror.pdo');

        if ($env->separate() && $samePdo) {
            $differences[] = 'mirror.pdo: expected a PDO of its own in separate mode, got the PDO of the primary connection';
        }

        if (! $env->separate() && ! $samePdo) {
            $differences[] = 'mirror.pdo: expected the PDO of the primary connection in same mode, got another PDO';
        }

        if ($differences !== []) {
            throw new HarnessRefusal(
                "The resolved database identity does not match the test environment ({$env->describe()}):\n  - "
                .implode("\n  - ", $differences)."\n".$this->line()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function side(DatabaseManager $db, mixed $name): array
    {
        try {
            $connection = $db->connection(is_string($name) ? $name : null);
        } catch (Throwable $throwable) {
            return ['connection' => $name, 'error' => get_class($throwable).': '.$throwable->getMessage()];
        }

        return self::connection($connection);
    }

    /**
     * @return array<string, mixed>
     */
    private static function connection(Connection $connection): array
    {
        $driver = $connection->getDriverName();
        $record = [
            'connection' => $connection->getName(),
            'driver' => $driver,
            'database' => null,
            'prefix' => $connection->getTablePrefix(),
            'server_version' => null,
            'pdo' => null,
            'evidence' => null,
        ];

        try {
            return self::resolved($connection, $driver, $record);
        } catch (Throwable $throwable) {
            // A connection that cannot be used still shows what it was
            // configured to reach, so the difference names the cause.
            $record['configured'] = [
                'host' => $connection->getConfig('host'),
                'port' => $connection->getConfig('port'),
                'database' => $connection->getConfig('database'),
            ];
            $record['error'] = get_class($throwable).': '.$throwable->getMessage();

            return $record;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function resolved(Connection $connection, string $driver, array $record): array
    {
        $pdo = $connection->getPdo();
        $record['server_version'] = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $record['pdo'] = spl_object_id($pdo);

        if ($driver === 'sqlite') {
            $file = '';

            foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? null) === 'main') {
                    $file = (string) ($row['file'] ?? '');
                }
            }

            $record['database'] = $file === '' ? TestEnvironment::MEMORY : $file;
            $record['evidence'] = $file === ''
                ? ['kind' => 'sqlite-memory', 'pid' => getmypid()]
                : ['kind' => 'sqlite-file', 'directory' => dirname($file), 'pid' => getmypid()];

            return $record;
        }

        if ($driver === 'mysql') {
            [$record['database'], $session, $marker] = self::serverIdentity(
                $pdo,
                'SELECT DATABASE(), CONNECTION_ID(), (SELECT COUNT(*) FROM orrery_marker.marker), (SELECT MIN(token) FROM orrery_marker.marker)',
                ['SELECT DATABASE()', 'SELECT CONNECTION_ID()', 'SELECT token FROM orrery_marker.marker'],
            );
        } elseif ($driver === 'pgsql') {
            $marker = "FROM pg_database WHERE datname = 'orrery_marker'";
            [$record['database'], $session, $marker] = self::serverIdentity(
                $pdo,
                "SELECT current_database(), pg_backend_pid(), (SELECT COUNT(*) {$marker}), (SELECT MIN(shobj_description(oid, 'pg_database')) {$marker})",
                ['SELECT current_database()', 'SELECT pg_backend_pid()', "SELECT shobj_description(oid, 'pg_database') {$marker}"],
            );
        } else {
            return $record;
        }

        $record['evidence'] = [
            'kind' => 'endpoint-token',
            'marker' => $marker,
            'host' => $connection->getConfig('host'),
            'port' => $connection->getConfig('port'),
            'session' => $session,
        ];

        return $record;
    }

    /**
     * The database, the session id and the one marker token of a server
     * connection, in one statement. When that statement fails (for example,
     * no marker table), the three reads run on their own, and a marker that
     * cannot be read is null.
     *
     * @param  array{0: string, 1: string, 2: string}  $separate
     * @return array{0: mixed, 1: int, 2: ?string}
     */
    private static function serverIdentity(PDO $pdo, string $combined, array $separate): array
    {
        try {
            $row = $pdo->query($combined)->fetch(PDO::FETCH_NUM);
        } catch (Throwable) {
            $row = false;
        }

        if (is_array($row)) {
            return [$row[0], (int) $row[1], (int) $row[2] === 1 && is_string($row[3]) ? $row[3] : null];
        }

        return [$pdo->query($separate[0])->fetchColumn(), (int) $pdo->query($separate[1])->fetchColumn(), self::marker($pdo, $separate[2])];
    }

    /**
     * The one marker token, or null when there is none or more than one.
     */
    private static function marker(PDO $pdo, string $sql): ?string
    {
        try {
            $values = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return null;
        }

        return count($values) === 1 && is_string($values[0]) ? $values[0] : null;
    }

    private static function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
