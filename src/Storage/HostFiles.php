<?php

namespace Justrau\Orrery\Storage;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Env;
use PDO;
use Throwable;

/**
 * The host SQLite files (D17 and D18): the real files (realpath()) of the
 * database connections that the package uses and that hold the
 * application's data:
 * - every `database` queue connection, the failed-record connection and the
 *   batch connection (queue.batching.database);
 * - the mirror's connection when orrery.database is not set (the mirror is
 *   then the sibling file; the default file is the application's);
 * - the data and lock connections of the cache stores that the package
 *   touches: the default store and the effective schedule store (computed
 *   as Laravel's Kernel::scheduleCache() does), failover stores included.
 * A file is compared by its real path, so two names or a symlinked path that
 * reach one file are one host file.
 *
 * D17: the package never writes automatically to a host file. D18: it reads
 * a host file automatically only when allowsReads() holds:
 * orrery.sqlite_automatic_access is true; every configured connection name
 * of the real file sets journal_mode WAL (the key, else pragmas.journal_mode)
 * and no EXCLUSIVE locking mode (the key or a pragma, also schema-qualified
 * such as main.locking_mode); the effective busy_timeout of every such name
 * is above 0. The configuration's WAL is confirmed once per unit of work by
 * `PRAGMA journal_mode` on a read-only SQLite PDO with busy_timeout 0; an
 * answer other than `wal`, or an error, means no access for that unit, and a
 * non-`wal` answer is kept for about 60 s.
 *
 * The package never opens a host database file, or its -wal, -shm or
 * -journal file, other than through SQLite: this class uses realpath() and
 * PDO SQLite only.
 */
final class HostFiles
{
    /** How long a confirmed non-WAL answer is kept, in seconds. */
    public const NOT_WAL_SECONDS = 60.0;

    /** The PDO SQLite default busy timeout, in milliseconds. */
    public const DEFAULT_BUSY_TIMEOUT_MS = 60000;

    public const READS_WARNING = 'SQLite: enable WAL (journal_mode wal) and a busy_timeout so Orrery can observe the queue without affecting it.';

    /** @var (Closure(string $connection): ?bool)|null */
    private ?Closure $isHostForTesting = null;

    /** @var (Closure(string $connection): ?bool)|null */
    private ?Closure $allowsReadsForTesting = null;

    /** @var array<string, true> the files whose WAL mode this unit of work confirmed */
    private array $confirmed = [];

    /** @var array<string, float> the files that answered another journal mode, with the monotonic time */
    private array $notWal = [];

    /** @var array<string, string> the hints of this process by file */
    private array $hints = [];

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Whether a database connection name is on a host SQLite file.
     */
    public function isHost(string $connection): bool
    {
        $seam = $this->isHostForTesting;
        $answer = $seam === null ? null : $seam($connection);

        if ($answer !== null) {
            return $answer;
        }

        $file = $this->realFile($connection);

        return $file !== null && in_array($file, $this->files(), true);
    }

    /**
     * Whether the package may read a host file automatically (D18). A
     * connection that is not on a host file always allows reads.
     */
    public function allowsReads(string $connection): bool
    {
        $seam = $this->allowsReadsForTesting;
        $answer = $seam === null ? null : $seam($connection);

        if ($answer !== null) {
            return $answer;
        }

        if (! $this->isHost($connection)) {
            return true;
        }

        $file = $this->realFile($connection);

        return $file !== null && $this->configurationAllowsReads($file) && $this->confirmWal($file);
    }

    /**
     * Whether the configuration of a host file admits automatic reads, without
     * the confirmation read: the switch, a regular file, WAL and NORMAL
     * locking on every configured name, and a positive effective busy timeout.
     */
    public function configurationAllowsReads(string $file): bool
    {
        $switch = $this->app->make('config')->get('orrery.sqlite_automatic_access', true);

        if ($switch !== true && filter_var($switch, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
            return false;
        }

        if (str_starts_with($file, 'file:')) {
            return false;
        }

        $names = $this->namesOfFile($file);

        if ($names === []) {
            return false;
        }

        foreach ($names as $config) {
            if (! self::declaresWal($config) || self::declaresExclusiveLocking($config) || self::effectiveBusyTimeout($config) <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The real host files, each once.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->usedConnections() as $connection) {
            $file = $this->realFile($connection);

            if ($file !== null) {
                $files[$file] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * The host files that allow no automatic reads, by their configuration.
     *
     * @return list<string>
     */
    public function filesWithoutReads(): array
    {
        return array_values(array_filter($this->files(), fn (string $file): bool => ! $this->configurationAllowsReads($file)));
    }

    /**
     * The first used connection name whose real file is $file (by its path,
     * or by device and inode), or null. A used connection given as a `file:`
     * URI is compared by the file that its URI names (uriFile()), so the
     * refusal of a shared mirror works whichever side uses a URI (D17).
     */
    public function connectionOfFile(string $file): ?string
    {
        $identity = self::fileIdentity($file);

        foreach ($this->usedConnections() as $connection) {
            $other = $this->realFile($connection);

            if ($other !== null && str_starts_with($other, 'file:')) {
                $other = self::uriFile($other);
            }

            // Also by device and inode: another letter case on a
            // case-insensitive file system, or a hard link, is the same file.
            if ($other === $file || ($identity !== null && $other !== null && self::fileIdentity($other) === $identity)) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * The real path of the file that a SQLite `file:` URI names: without the
     * scheme, the authority (`file:///…`, `file://localhost/…`), the query
     * and the fragment, percent-decoded, and resolved as a plain path is
     * (EndpointIdentity::realFile()). Null for an in-memory URI.
     */
    private static function uriFile(string $uri): ?string
    {
        $path = preg_split('/[?#]/', substr($uri, strlen('file:')), 2)[0] ?? '';

        if (str_starts_with($path, '//')) {
            $slash = strpos($path, '/', 2);
            $path = $slash === false ? '' : substr($path, $slash);
        }

        $path = rawurldecode($path);

        if ($path === '' || $path === ':memory:') {
            return null;
        }

        return EndpointIdentity::realFile($path);
    }

    /**
     * The device and inode of an existing file, or null.
     */
    private static function fileIdentity(string $file): ?string
    {
        if (str_starts_with($file, 'file:') || $file === ':memory:') {
            return null;
        }

        $stat = @stat($file);

        return $stat === false ? null : $stat['dev'].':'.$stat['ino'];
    }

    /**
     * The real path of a SQLite file connection; null for another driver, an
     * in-memory database or a connection that is not configured. A `file:`
     * URI keeps its configured value, so it is a host file with no
     * automatic access when a used connection names it.
     */
    public function realFile(string $connection): ?string
    {
        $config = $this->app->make('config')->get('database.connections.'.$this->base($connection));

        return is_array($config) ? self::realFileOf($config) : null;
    }

    /**
     * Whether a cache store never writes to a host SQLite file: false for a
     * `database` store whose data connection or lock_connection is on a
     * SQLite file, and for a failover store any of whose stores is unsafe.
     * Null is the default store.
     */
    public function cacheIsSafe(?string $store): bool
    {
        return $this->unsafeCacheFile($store) === null;
    }

    /**
     * The host file that makes a cache store unsafe, or null.
     */
    public function unsafeCacheFile(?string $store, int $depth = 0): ?string
    {
        $config = $this->app->make('config');
        $store ??= $this->defaultStore();

        if ($store === null || $depth > 5) {
            return null;
        }

        $definition = $config->get("cache.stores.{$store}");

        if (! is_array($definition)) {
            return null;
        }

        if (($definition['driver'] ?? null) === 'failover') {
            foreach ((array) ($definition['stores'] ?? []) as $inner) {
                $file = is_string($inner) ? $this->unsafeCacheFile($inner, $depth + 1) : null;

                if ($file !== null) {
                    return $file;
                }
            }

            return null;
        }

        if (($definition['driver'] ?? null) !== 'database') {
            return null;
        }

        foreach ($this->storeConnections($definition) as $connection) {
            $file = $this->realFile($connection);

            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The effective store of the scheduler's mutexes, as Laravel's
     * Kernel::scheduleCache() computes it: cache.schedule_store (a present
     * null means the default store), else SCHEDULE_CACHE_DRIVER or
     * SCHEDULE_CACHE_STORE, else the default store.
     */
    public function scheduleStore(): ?string
    {
        $store = $this->app->make('config')->get('cache.schedule_store', Env::get('SCHEDULE_CACHE_DRIVER', static fn () => Env::get('SCHEDULE_CACHE_STORE')));

        return is_string($store) && $store !== '' ? $store : $this->defaultStore();
    }

    public function defaultStore(): ?string
    {
        $store = $this->app->make('config')->get('cache.default');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * The hints of this process (a host file that answered another journal
     * mode than its configuration), by file.
     *
     * @return array<string, string>
     */
    public static function hints(): array
    {
        return self::bound()->hints;
    }

    /**
     * Ends a unit of work: the next unit confirms the journal mode again.
     */
    public static function endUnit(): void
    {
        self::bound()->confirmed = [];
    }

    /**
     * Replaces the answers in tests. A seam that returns null leaves the
     * answer to the policy above.
     *
     * @param  (Closure(string $connection): ?bool)|null  $isHost
     * @param  (Closure(string $connection): ?bool)|null  $allowsReads
     */
    public static function useForTesting(?Closure $isHost, ?Closure $allowsReads = null): void
    {
        $state = self::bound();

        $state->isHostForTesting = $isHost;
        $state->allowsReadsForTesting = $allowsReads;
    }

    public static function resetForTesting(): void
    {
        $state = self::bound();

        $state->isHostForTesting = null;
        $state->allowsReadsForTesting = null;
        $state->confirmed = [];
        $state->notWal = [];
        $state->hints = [];
    }

    /**
     * The real path of a SQLite connection configuration, or null.
     *
     * @param  array<mixed>  $config
     */
    public static function realFileOf(array $config): ?string
    {
        $writer = PackageSession::writerConfigOf($config);

        if (($writer['driver'] ?? null) !== 'sqlite' || PackageSession::isInMemory($writer)) {
            return null;
        }

        return EndpointIdentity::realFile((string) ($writer['database'] ?? ''));
    }

    /**
     * The journal mode that a configuration declares is WAL: the
     * journal_mode key, else pragmas.journal_mode, compared without case.
     *
     * @param  array<mixed>  $config
     */
    public static function declaresWal(array $config): bool
    {
        $mode = $config['journal_mode'] ?? null;

        if ($mode === null) {
            $pragmas = is_array($config['pragmas'] ?? null) ? $config['pragmas'] : [];
            $mode = $pragmas['journal_mode'] ?? null;
        }

        return is_string($mode) && strtolower(trim($mode)) === 'wal';
    }

    /**
     * The configuration sets an EXCLUSIVE locking mode: the locking_mode key,
     * or a pragma whose key is locking_mode, also schema-qualified (for
     * example main.locking_mode), in any case.
     *
     * @param  array<mixed>  $config
     */
    public static function declaresExclusiveLocking(array $config): bool
    {
        $values = [$config['locking_mode'] ?? null];
        $pragmas = is_array($config['pragmas'] ?? null) ? $config['pragmas'] : [];

        foreach ($pragmas as $key => $value) {
            if (preg_match('/(^|\.)locking_mode$/i', trim((string) $key)) === 1) {
                $values[] = $value;
            }
        }

        foreach ($values as $value) {
            if (is_string($value) && strtolower(trim($value)) === 'exclusive') {
                return true;
            }
        }

        return false;
    }

    /**
     * The effective busy timeout of a configuration in milliseconds: the
     * busy_timeout key, else pragmas.busy_timeout, else the PDO timeout
     * option × 1000, else PDO SQLite's 60 s.
     *
     * @param  array<mixed>  $config
     */
    public static function effectiveBusyTimeout(array $config): int
    {
        if (isset($config['busy_timeout']) && is_numeric($config['busy_timeout'])) {
            return (int) $config['busy_timeout'];
        }

        $pragmas = is_array($config['pragmas'] ?? null) ? $config['pragmas'] : [];

        if (isset($pragmas['busy_timeout']) && is_numeric($pragmas['busy_timeout'])) {
            return (int) $pragmas['busy_timeout'];
        }

        $options = is_array($config['options'] ?? null) ? $config['options'] : [];

        if (isset($options[PDO::ATTR_TIMEOUT]) && is_numeric($options[PDO::ATTR_TIMEOUT])) {
            return (int) $options[PDO::ATTR_TIMEOUT] * 1000;
        }

        return self::DEFAULT_BUSY_TIMEOUT_MS;
    }

    /**
     * The confirmation read of a file's journal mode for this unit of work.
     */
    private function confirmWal(string $file): bool
    {
        if (isset($this->confirmed[$file])) {
            return true;
        }

        $refused = $this->notWal[$file] ?? null;

        if ($refused !== null && Clock::monotonic() - $refused < self::NOT_WAL_SECONDS) {
            return false;
        }

        try {
            $mode = self::readJournalMode($file);
        } catch (Throwable) {
            // For example while another connection holds an EXCLUSIVE lock: no access for this unit.
            return false;
        }

        if ($mode === 'wal') {
            unset($this->notWal[$file]);
            $this->confirmed[$file] = true;

            return true;
        }

        $this->notWal[$file] = Clock::monotonic();
        $this->hints[$file] = "The SQLite file {$file} is configured for WAL but uses the journal mode {$mode}, so Orrery reads nothing from it automatically.";

        return false;
    }

    /**
     * PRAGMA journal_mode on a read-only SQLite PDO with busy_timeout 0. It
     * changes nothing.
     */
    private static function readJournalMode(string $file): string
    {
        $flags = defined('Pdo\Sqlite::ATTR_OPEN_FLAGS') ? (int) constant('Pdo\Sqlite::ATTR_OPEN_FLAGS') : (int) constant('PDO::SQLITE_ATTR_OPEN_FLAGS');
        $readOnly = defined('Pdo\Sqlite::OPEN_READONLY') ? (int) constant('Pdo\Sqlite::OPEN_READONLY') : (int) constant('PDO::SQLITE_OPEN_READONLY');
        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false, $flags => $readOnly]);

        try {
            $pdo->exec('PRAGMA busy_timeout = 0');
            $statement = $pdo->query('PRAGMA journal_mode');

            return strtolower((string) ($statement === false ? '' : $statement->fetchColumn()));
        } finally {
            $statement = null;
            $pdo = null;
        }
    }

    /**
     * The configurations of every configured connection name of a real file.
     *
     * @return list<array<mixed>>
     */
    private function namesOfFile(string $file): array
    {
        $names = [];

        foreach ((array) $this->app->make('config')->get('database.connections', []) as $config) {
            if (is_array($config) && self::realFileOf($config) === $file) {
                $names[] = PackageSession::writerConfigOf($config);
            }
        }

        return $names;
    }

    /**
     * The database connection names whose files hold the application's data.
     *
     * @return list<string>
     */
    private function usedConnections(): array
    {
        $config = $this->app->make('config');
        $default = (string) $config->get('database.default');
        $names = [];

        foreach ((array) $config->get('queue.connections', []) as $queue) {
            if (is_array($queue) && ($queue['driver'] ?? null) === 'database') {
                $connection = $queue['connection'] ?? null;
                $names[] = is_string($connection) && $connection !== '' ? $connection : $default;
            }
        }

        if (in_array($config->get('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $failed = $config->get('queue.failed.database');
            $names[] = is_string($failed) && $failed !== '' ? $failed : $default;
        }

        // Laravel's batch repository uses the default connection when the key is empty.
        $batching = $config->get('queue.batching.database');
        $names[] = is_string($batching) && $batching !== '' ? $batching : $default;

        $mirror = $config->get('orrery.database');

        if (! is_string($mirror) || $mirror === '') {
            $names[] = $default;
        }

        foreach (array_unique(array_filter([$this->defaultStore(), $this->scheduleStore()])) as $store) {
            array_push($names, ...$this->cacheConnections($store));
        }

        return array_values(array_unique(array_map($this->base(...), array_filter($names, static fn (string $name): bool => $name !== ''))));
    }

    /**
     * The database connections of a cache store, failover stores included.
     *
     * @return list<string>
     */
    private function cacheConnections(string $store, int $depth = 0): array
    {
        $definition = $this->app->make('config')->get("cache.stores.{$store}");

        if (! is_array($definition) || $depth > 5) {
            return [];
        }

        if (($definition['driver'] ?? null) === 'failover') {
            $names = [];

            foreach ((array) ($definition['stores'] ?? []) as $inner) {
                if (is_string($inner)) {
                    array_push($names, ...$this->cacheConnections($inner, $depth + 1));
                }
            }

            return $names;
        }

        return ($definition['driver'] ?? null) === 'database' ? $this->storeConnections($definition) : [];
    }

    /**
     * The data connection and the lock connection of a `database` store.
     *
     * @param  array<mixed>  $definition
     * @return list<string>
     */
    private function storeConnections(array $definition): array
    {
        $default = (string) $this->app->make('config')->get('database.default');
        $data = is_string($definition['connection'] ?? null) && $definition['connection'] !== '' ? $definition['connection'] : $default;
        $lock = is_string($definition['lock_connection'] ?? null) && $definition['lock_connection'] !== '' ? $definition['lock_connection'] : $data;

        return array_values(array_unique([$data, $lock]));
    }

    private function base(string $connection): string
    {
        /** @var DatabaseManager $db */
        $db = $this->app->make('db');

        return ConnectionName::base($db, $connection);
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
