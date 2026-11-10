<?php

namespace Justrau\Orrery\Database\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * The explicit name of a package index or key: <table prefix><table>_<suffix>.
 *
 * Laravel's default name joins every column name, so the composite keys of the package tables
 * exceed the MySQL limit of 64 characters. With a table prefix of 16 characters, every explicit
 * name stays below that limit (the longest has 50 characters).
 *
 * A name longer than MAX_LENGTH bytes (a longer prefix) keeps its first 50 bytes, cut on a UTF-8
 * character boundary, and gets "_" and the first 12 hex characters of the SHA-256 of the full
 * name. So the name stays unique,
 * deterministic, and inside the 63-character limit of Postgres, which cuts longer names
 * silently. Readers of an index name call for() too, so they get the same name.
 */
final class IndexName
{
    public const MAX_LENGTH = 63;

    private const HASH_LENGTH = 12;

    public static function for(Connection $connection, string $table, string $suffix): string
    {
        return self::compose($connection->getTablePrefix(), $table, $suffix);
    }

    /**
     * Forces the package index <table>_<suffix> on $query where the engine
     * takes a hint (MySQL and MariaDB: FORCE INDEX, SQLite: INDEXED BY).
     * Postgres has no hint. A name that Laravel's grammars refuse in a hint
     * (a table prefix outside [A-Za-z0-9_$], such as `my-app_`) gets no hint:
     * the query then runs on the planner's own choice instead
     * of failing.
     */
    public static function force(Builder $query, Connection $session, string $table, string $suffix): Builder
    {
        if (! in_array($session->getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            return $query;
        }

        $name = self::for($session, $table, $suffix);

        if (preg_match('/^[A-Za-z0-9_$]+$/D', $name) === 1) {
            $query->forceIndex($name);
        }

        return $query;
    }

    public static function compose(string $prefix, string $table, string $suffix): string
    {
        $name = $prefix.$table.'_'.$suffix;

        if (strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        // mb_strcut() keeps at most that many bytes and never cuts inside a UTF-8 character, so a
        // table name with multibyte characters gives a valid identifier (at most 50 bytes).
        return mb_strcut($name, 0, self::MAX_LENGTH - self::HASH_LENGTH - 1, 'UTF-8').'_'.substr(hash('sha256', $name), 0, self::HASH_LENGTH);
    }
}
