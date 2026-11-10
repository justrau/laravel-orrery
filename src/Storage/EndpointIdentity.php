<?php

namespace Justrau\Orrery\Storage;

/**
 * The endpoint that a package session reaches. PackageSession keys its
 * cache by the connection name and this identity together, so a name that is
 * retargeted at runtime gets a new session, and the target fingerprint is
 * the hash of the same identity, so the two can never disagree.
 *
 * It is computed once from the resolved writer configuration:
 * - the driver;
 * - the effective host or socket (MySQL: `unix_socket` before `host`);
 * - the effective port: `connect_via_port` before `port`, as
 *   PostgresConnector chooses;
 * - the effective database (`connect_via_database` before `database`) and
 *   the configured `database`; a SQLite file by its realpath() (a relative
 *   path also against base_path(), as Laravel's connector), so a symlinked
 *   release path does not change it;
 * - the username (the role), the table prefix, and `search_path`/`schema`;
 * - the `server_options` map (startup options that PostgresConnector
 *   appends to the DSN), with sorted keys, whenever the key is present.
 * The access mode of a host SQLite file is never part of it.
 */
final class EndpointIdentity
{
    /**
     * @param  array<string, mixed>  $fields
     */
    private function __construct(public readonly array $fields) {}

    /**
     * @param  array<string, mixed>  $writer  a writer configuration (PackageSession::writerConfig())
     */
    public static function of(array $writer): self
    {
        $driver = is_string($writer['driver'] ?? null) ? $writer['driver'] : '';

        if ($driver === 'sqlite') {
            $database = (string) ($writer['database'] ?? '');

            return new self([
                'driver' => 'sqlite',
                'database' => self::realFile($database),
                'prefix' => (string) ($writer['prefix'] ?? ''),
            ]);
        }

        $socket = in_array($driver, ['mysql', 'mariadb'], true) ? (string) ($writer['unix_socket'] ?? '') : '';
        $fields = [
            'driver' => $driver,
            'endpoint' => $socket !== '' ? ['socket' => $socket] : ['host' => self::hosts($writer['host'] ?? null)],
            'port' => self::scalar($writer['connect_via_port'] ?? $writer['port'] ?? null),
            'database' => self::scalar($writer['connect_via_database'] ?? $writer['database'] ?? null),
            'configured_database' => self::scalar($writer['database'] ?? null),
            'username' => self::scalar($writer['username'] ?? null),
            'prefix' => (string) ($writer['prefix'] ?? ''),
            'search_path' => self::normalised($writer['search_path'] ?? $writer['schema'] ?? null),
        ];

        if ($driver === 'pgsql' && array_key_exists('server_options', $writer)) {
            $options = is_array($writer['server_options']) ? $writer['server_options'] : [];
            ksort($options);
            $fields['server_options'] = array_map(self::scalar(...), $options);
        }

        return new self($fields);
    }

    /**
     * The hash of the identity: the cache key part of PackageSession and
     * the target fingerprint.
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function equals(self $other): bool
    {
        return $this->hash() === $other->hash();
    }

    /**
     * The real path of a SQLite database file. An in-memory database, a
     * `file:` URI and a missing file keep their configured value.
     */
    public static function realFile(string $database): string
    {
        if ($database === ':memory:' || str_contains($database, 'mode=memory') || str_starts_with($database, 'file:')) {
            return $database;
        }

        // As Laravel's SQLiteConnector resolves it: the path itself, else
        // relative to the application's base path.
        $real = realpath($database);

        if ($real === false && function_exists('base_path')) {
            $real = realpath(base_path($database));
        }

        return $real === false ? $database : $real;
    }

    /**
     * Laravel shuffles a list of hosts at connect, so their order is not
     * part of the endpoint.
     */
    private static function hosts(mixed $value): mixed
    {
        $hosts = self::normalised($value);

        if (is_array($hosts)) {
            sort($hosts);
        }

        return $hosts;
    }

    private static function normalised(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_values(array_map(self::scalar(...), $value));
        }

        return self::scalar($value);
    }

    private static function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }
}
