<?php

namespace Justrau\Orrery\Failed;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Storage\EndpointIdentity;
use Justrau\Orrery\Storage\PackageSession;
use RuntimeException;
use stdClass;

/**
 * The failed-record table of the package's provider:
 * `queue.failed.database` (normalised: null is database.default) and
 * `queue.failed.table`.
 *
 * - The identity rule: a record belongs to an occurrence when its `id` and
 *   `uuid` are the row's `provider_id` and uuid, and the row's
 *   `provider_target` is target() now. read() is `SELECT * … WHERE id = ?
 *   AND uuid = ?` on the package session of the failed connection; every
 *   delete is `DELETE … WHERE id = ? AND uuid = ?`.
 * - The mode of a retry: in_tx when the normalised failed connection is the
 *   normalised database connection name of the queue connection, else
 *   before (also when two names reach one database).
 * - Ids that are never reused: SQLite only with
 *   `INTEGER PRIMARY KEY AUTOINCREMENT` (read once per process from
 *   sqlite_master on the package session); MySQL 8.0+ and MariaDB 10.2.4+
 *   (a persistent auto-increment counter); Postgres (an identity or serial
 *   column; manual resets are documented, not checked).
 * - saved_record keeps every column losslessly: a string that is not valid
 *   UTF-8 is stored as {"b64": …}.
 */
final class SqlFailedRecords extends FailedRecords
{
    public function __construct(
        private readonly PackageSession $sessions,
        private readonly string $connection,
        private readonly string $table,
    ) {}

    /**
     * The target of a failed-record table: sha256 of the endpoint identity
     * of the connection's writer configuration (with its prefix and
     * schema) and the table name. The provider builds it from the
     * configuration of the connection object that performed the insert (its
     * own writer), so a cached connection and a later configuration change
     * cannot agree unnoticed.
     *
     * With a `write` list of several writers, Laravel picks one at random per
     * connection object, so a record logged through another writer than the
     * current configuration's first one has another target: its retry and
     * forget are refused with `target_changed`. The refusal is conservative:
     * a process on another writer is refused, never misdirected.
     *
     * @param  array<string, mixed>  $writer
     */
    public static function targetOf(array $writer, string $table): string
    {
        return hash('sha256', EndpointIdentity::of($writer)->hash()."\0".$table);
    }

    public function kind(): string
    {
        return self::SQL;
    }

    /** The normalised database connection name of the failed-record table. */
    public function connection(): string
    {
        return $this->connection;
    }

    public function table(): string
    {
        return $this->table;
    }

    /** The current target, from the configuration. */
    public function target(): string
    {
        return self::targetOf($this->sessions->writerConfig($this->connection), $this->table);
    }

    public function mode(string $queueConnection): string
    {
        return $this->sessions->databaseConnectionFor($queueConnection) === $this->connection ? Operations::IN_TX : Operations::BEFORE;
    }

    /**
     * The record with exactly this id and uuid, or null when it is gone.
     */
    public function read(int $id, string $uuid): ?FailedRecord
    {
        $row = $this->session()->table($this->table)->where('id', $id)->where('uuid', $uuid)->first();

        return $row instanceof stdClass ? new FailedRecord($id, $uuid, (array) $row) : null;
    }

    /**
     * Which of the (id, uuid) pairs exist, in one read on the package session
     * (reconcile_failed): the keys of pairKey() of the records whose id and
     * uuid both match. $target is the target that the caller checked the
     * rows against: the read throws when the configuration names another
     * target now, so it never reads another table than the rows name.
     *
     * @param  list<array{id: int, uuid: string}>  $pairs
     * @return array<string, true>
     */
    public function present(array $pairs, string $target): array
    {
        if ($pairs === []) {
            return [];
        }

        if (! hash_equals($target, $this->target())) {
            throw new RuntimeException('orrery: the failed-job storage changed during the check.');
        }

        $ids = array_values(array_unique(array_map(static fn (array $pair): int => $pair['id'], $pairs)));
        $wanted = [];

        foreach ($pairs as $pair) {
            $wanted[self::pairKey($pair['id'], $pair['uuid'])] = true;
        }

        $found = [];

        foreach ($this->session()->table($this->table)->whereIn('id', $ids)->get(['id', 'uuid']) as $record) {
            $key = self::pairKey((int) $record->id, is_string($record->uuid) ? $record->uuid : '');

            if (isset($wanted[$key])) {
                $found[$key] = true;
            }
        }

        return $found;
    }

    /** The key of one exact record: its id and uuid, as present() returns them. */
    public static function pairKey(int $id, string $uuid): string
    {
        return $id."\0".$uuid;
    }

    /**
     * The exact delete on a session of the caller's transaction; the
     * number of deleted rows.
     */
    public function delete(Connection $session, int $id, string $uuid): int
    {
        return $session->table($this->table)->where('id', $id)->where('uuid', $uuid)->delete();
    }

    /**
     * Inserts the saved columns without `id` on a session of the caller's
     * transaction; the new record's id.
     *
     * @param  array<string, mixed>  $columns
     */
    public function restore(Connection $session, array $columns): int
    {
        unset($columns['id']);
        $id = CapturingUuidFailedJobProvider::receipt($session->table($this->table)->insertGetId($columns));

        if ($id === null) {
            throw new RuntimeException('orrery: the restored failed record has no id.');
        }

        return $id;
    }

    /**
     * Every column of a record as JSON, lossless.
     */
    public static function encode(FailedRecord $record): string
    {
        $columns = [];

        foreach ($record->columns as $name => $value) {
            $columns[$name] = is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? ['b64' => base64_encode($value)] : $value;
        }

        return json_encode($columns, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $saved): array
    {
        $columns = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($columns)) {
            throw new RuntimeException('orrery: a saved record is not a JSON object.');
        }

        $decoded = [];

        foreach ($columns as $name => $value) {
            $decoded[(string) $name] = is_array($value) && array_keys($value) === ['b64'] && is_string($value['b64']) ? base64_decode($value['b64'], true) : $value;
        }

        return $decoded;
    }

    /**
     * Whether the table's ids are never handed out again (see the class
     * comment). The answer is kept per target for the process.
     */
    public function idsNeverReused(): bool
    {
        $target = $this->target();

        $proofs = Container::getInstance()->make(IdReuseProofs::class);

        return $proofs->answers[$target] ??= $this->proveIds();
    }

    /**
     * Whether a SQLite table definition gives the `id` column itself
     * `INTEGER PRIMARY KEY AUTOINCREMENT`: comments are removed first,
     * and AUTOINCREMENT on another column or in a comment does not count.
     */
    public static function idAutoincrements(string $definition): bool
    {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '/--[^\n]*/'], ' ', $definition);

        return preg_match('/(?:\(|,)\s*(?:"id"|`id`|\[id\]|\bid\b)\s+integer\s+primary\s+key\s+autoincrement\b/i', $code) === 1;
    }

    private function proveIds(): bool
    {
        $session = $this->session();

        switch ($session->getDriverName()) {
            case 'sqlite':
                $sql = $session->selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$session->getTablePrefix().$this->table]);
                $definition = $sql instanceof stdClass && is_string($sql->sql ?? null) ? $sql->sql : '';

                return self::idAutoincrements($definition);
            case 'mysql':
            case 'mariadb':
                // Laravel's own reads of PDO::ATTR_SERVER_VERSION (no statement); MariaDB without its prefix.
                $maria = $session instanceof MySqlConnection && $session->isMaria();

                return version_compare($session->getServerVersion(), $maria ? '10.2.4' : '8.0.0', '>=');
            case 'pgsql':
                return true;
            default:
                return false;
        }
    }

    private function session(): Connection
    {
        return $this->sessions->forAction($this->connection);
    }
}
