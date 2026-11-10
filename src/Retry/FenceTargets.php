<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Justrau\Orrery\Storage\ConnectionName;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Throwable;

/**
 * The database connections that hold a fence table: the
 * database connection of every `database` queue connection, and the
 * failed-record connection of `database-uuids` (mode before). Each name
 * once, as its base name, in configuration order.
 *
 * withoutMirror() leaves out the mirror's own connection when the main
 * migration creates the fence there (the mirror shares that database); a
 * sibling SQLite mirror is another database, so the queue's own file
 * keeps its entry.
 */
final class FenceTargets
{
    public function __construct(
        private readonly PackageSession $sessions,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return list<string>
     */
    public function connections(): array
    {
        $names = [];

        foreach ((array) $this->config()->get('queue.connections', []) as $name => $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'database') {
                try {
                    $names[$this->sessions->databaseConnectionFor((string) $name)] = true;
                } catch (Throwable) {
                    // A queue connection whose database is not configured has no fence.
                }
            }
        }

        $failed = $this->config()->get('queue.failed');

        if (is_array($failed) && ($failed['driver'] ?? null) === 'database-uuids') {
            $database = is_string($failed['database'] ?? null) && $failed['database'] !== '' ? $failed['database'] : (string) $this->config()->get('database.default');

            try {
                $names[ConnectionName::base($this->db, $database)] = true;
            } catch (Throwable) {
                // The same.
            }
        }

        return array_map('strval', array_keys($names));
    }

    /**
     * The fence report of /api/health and orrery:install.
     * Both lists name `database` queue
     * connections, one kind of name:
     * - `missing`: the queue connections whose retries are refused for a
     *   missing fence table (or one that could not be checked): their own
     *   database has none, or the failed records of `database-uuids` are on
     *   another database (a retry in mode `before`) and that database has
     *   none;
     * - `unknown`: the queue connections whose fence could not be checked,
     *   because its database is a host SQLite file that allows no automatic
     *   reads (D18) outside an operator action: never reported as missing.
     * Fence::available() keeps each answer for 60 s.
     *
     * @return array{missing: list<string>, unknown: list<string>}
     */
    public function report(Fence $fence, HostFiles $files): array
    {
        $missing = [];
        $unknown = [];
        /** @var array<string, string> $queues the database connection of each queue connection */
        $queues = [];
        $operator = $this->sessions->operatorContext() !== null;

        // The state of one database connection's fence table: present, missing or unknown.
        $state = static function (string $database) use ($fence, $files, $operator): string {
            if (! $operator && ! $files->allowsReads($database)) {
                return 'unknown';
            }

            try {
                return $fence->available($database) ? 'present' : 'missing';
            } catch (HostFileAccessNotAllowed) {
                // Refused after the check above (the journal mode changed): unknown.
                return 'unknown';
            } catch (Throwable) {
                // A table that could not be checked cannot be relied on.
                return 'missing';
            }
        };
        $add = static function (string $name, string $found) use (&$missing, &$unknown): void {
            if ($found === 'missing') {
                $missing[] = $name;
            } elseif ($found === 'unknown') {
                $unknown[] = $name;
            }
        };

        foreach ((array) $this->config()->get('queue.connections', []) as $name => $connection) {
            if (! is_array($connection) || ($connection['driver'] ?? null) !== 'database') {
                continue;
            }

            try {
                $database = $this->sessions->databaseConnectionFor((string) $name);
            } catch (Throwable) {
                $missing[] = (string) $name;

                continue;
            }

            $queues[(string) $name] = $database;
            $add((string) $name, $state($database));
        }

        $failed = $this->config()->get('queue.failed');

        if (is_array($failed) && ($failed['driver'] ?? null) === 'database-uuids') {
            $database = is_string($failed['database'] ?? null) && $failed['database'] !== '' ? $failed['database'] : (string) $this->config()->get('database.default');

            try {
                $database = ConnectionName::base($this->db, $database);
            } catch (Throwable) {
                // An unknown name: no retry reaches it.
                $database = null;
            }

            // Mode before: the queue connections on another database connection than the failed records.
            $before = $database === null ? [] : array_keys(array_filter($queues, static fn (string $queueDatabase): bool => $queueDatabase !== $database));

            if ($before !== []) {
                $found = $state((string) $database);

                foreach ($before as $name) {
                    $add((string) $name, $found);
                }
            }
        }

        $missing = array_values(array_unique($missing));

        // A connection that is missing on one database is not also unknown.
        return ['missing' => $missing, 'unknown' => array_values(array_diff(array_unique($unknown), $missing))];
    }

    /**
     * @return list<string>
     */
    public function withoutMirror(): array
    {
        $configured = $this->config()->get('orrery.database');
        $mirror = is_string($configured) && $configured !== '' ? $configured : (string) $this->config()->get('database.default');

        try {
            $mirror = ConnectionName::base($this->db, $mirror);
        } catch (Throwable) {
            // An unknown name matches no fence connection.
        }

        $sibling = $this->sessions->mirrorIsSibling();

        return array_values(array_filter($this->connections(), static fn (string $connection): bool => $sibling || $connection !== $mirror));
    }

    /**
     * The configuration of the running request, read when it is used: this
     * object is resolved at boot, and an Octane request has its
     * own configuration sandbox.
     */
    private function config(): Repository
    {
        return Container::getInstance()->make('config');
    }
}
