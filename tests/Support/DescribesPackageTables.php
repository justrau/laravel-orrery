<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Database\Connection;
use Justrau\Orrery\Database\Schema\PackageSchema;

/**
 * The catalog fingerprint of the package tables (SchemaTest, SchemaBaselineTest).
 */
trait DescribesPackageTables
{
    /**
     * Everything that the engine reports of the package tables (and of $extra, tables that a
     * test adds): on MySQL and MariaDB the text of SHOW CREATE TABLE and the catalog rows of the
     * tables, columns and keys; on Postgres the columns, the index definitions and the
     * constraint definitions; on SQLite the PRAGMA structure of the tables and indexes. Without
     * times, sizes and counters.
     *
     * @param  list<string>  $extra
     * @return array<string, mixed>
     */
    private function describeTables(Connection $connection, array $extra = []): array
    {
        $prefix = $connection->getTablePrefix();
        $tables = array_map(static fn (string $table): string => $prefix.$table, [...PackageSchema::TABLES, ...$extra]);
        sort($tables);
        $in = implode(', ', array_map(static fn (string $table): string => $connection->getPdo()->quote($table), $tables));
        $rows = static fn (string $sql): array => array_map(static fn (object $row): array => (array) $row, $connection->select($sql));

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => [
                'tables' => array_map(static fn (string $table): array => (array) $connection->selectOne('SHOW CREATE TABLE `'.str_replace('`', '``', $table).'`'), array_combine($tables, $tables)),
                'options' => $rows("SELECT TABLE_NAME, TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in}) ORDER BY TABLE_NAME"),
                'columns' => $rows("SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_KEY, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in}) ORDER BY TABLE_NAME, ORDINAL_POSITION"),
                'keys' => $rows("SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, NON_UNIQUE, COLUMN_NAME, SUB_PART, COLLATION, NULLABLE, INDEX_TYPE, PACKED, INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in}) ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"),
                'constraints' => $rows("SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in}) ORDER BY TABLE_NAME, CONSTRAINT_NAME"),
            ],
            'pgsql' => [
                'tables' => $rows("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename IN ({$in}) ORDER BY tablename"),
                'columns' => $rows("SELECT table_name, ordinal_position, column_name, data_type, character_maximum_length, numeric_precision, numeric_scale, is_nullable, column_default, collation_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN ({$in}) ORDER BY table_name, ordinal_position"),
                'indexes' => $rows("SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename IN ({$in}) ORDER BY tablename, indexname"),
                'constraints' => $rows("SELECT c.conrelid::regclass::text AS relation, c.conname, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE t.relnamespace = current_schema()::text::regnamespace AND t.relname IN ({$in}) ORDER BY relation, c.conname"),
            ],
            default => ['tables' => self::describeSqliteTables($connection, $tables)],
        };
    }

    /**
     * The structure of SQLite tables, from the PRAGMAs where they report it, not from the stored
     * DDL text (another Laravel release can change that text without a change of structure): per
     * table its columns (table_xinfo), whether its integer key is AUTOINCREMENT, its foreign keys
     * (foreign_key_list) and its indexes (index_list without the creation order, and index_xinfo
     * of each). What no PRAGMA reports comes from the DDL text, normalised (lower case, no
     * identifier quotes, one space): the CHECK constraints and the column collations of the
     * table, and the whole definition of a partial or an expression index (its predicate and its
     * expressions).
     *
     * @param  list<string>  $tables
     * @return array<string, mixed>
     */
    private static function describeSqliteTables(Connection $connection, array $tables): array
    {
        $pdo = $connection->getPdo();
        $quote = static fn (string $name): string => $pdo->quote($name);
        $rows = static fn (string $sql): array => $pdo->query($sql)?->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $described = [];

        foreach ($tables as $table) {
            $sql = $rows('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = '.$quote($table))[0]['sql'] ?? null;

            if ($sql === null) {
                continue;
            }

            $indexes = [];

            foreach ($rows('PRAGMA index_list('.$quote($table).')') as $index) {
                unset($index['seq']);
                $index['columns'] = $rows('PRAGMA index_xinfo('.$quote((string) $index['name']).')');

                // index_xinfo names no expression (cid -2) and index_list no predicate: the text does.
                if ((int) $index['partial'] === 1 || in_array(-2, array_map(static fn (array $column): int => (int) $column['cid'], $index['columns']), true)) {
                    $index['definition'] = self::normalizedSql((string) ($rows('SELECT sql FROM sqlite_master WHERE type = \'index\' AND name = '.$quote((string) $index['name']))[0]['sql'] ?? ''));
                }

                $indexes[(string) $index['name']] = $index;
            }

            ksort($indexes);
            [$checks, $collations] = self::sqliteClauses((string) $sql);
            $described[$table] = [
                'columns' => $rows('PRAGMA table_xinfo('.$quote($table).')'),
                'autoincrement' => preg_match('/\bautoincrement\b/i', (string) $sql) === 1,
                'foreign_keys' => $rows('PRAGMA foreign_key_list('.$quote($table).')'),
                'checks' => $checks,
                'collations' => $collations,
                'indexes' => $indexes,
            ];
        }

        return $described;
    }

    /**
     * The CHECK constraints (sorted, each with its column, or `table` for a table constraint)
     * and the collation of each column that names one, from a CREATE TABLE text.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private static function sqliteClauses(string $sql): array
    {
        $sql = self::normalizedSql($sql);
        $open = strpos($sql, '(');
        $checks = [];
        $collations = [];

        if ($open === false) {
            return [[], []];
        }

        foreach (self::topLevelParts(substr($sql, $open + 1, self::closing($sql, $open) - $open - 1), ',') as $part) {
            $part = trim((string) preg_replace('/^constraint \S+ /', '', trim($part)));
            $table = preg_match('/^(check|primary key|unique|foreign key)\b/', $part) === 1;
            $owner = $table ? 'table' : (string) strtok($part, ' ');

            $offset = 0;
            while (preg_match('/\bcheck ?\(/', $part, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $start = $match[0][1] + strlen($match[0][0]) - 1;
                $end = self::closing($part, $start);
                $checks[] = $owner.': '.substr($part, $start + 1, $end - $start - 1);
                $offset = $end;
            }

            if (! $table && preg_match('/\bcollate (\S+)/', $part, $collation) === 1) {
                $collations[$owner] = trim($collation[1], "'");
            }
        }

        sort($checks);
        ksort($collations);

        return [$checks, $collations];
    }

    /** Lower case, no identifier quotes, one space, no space around a parenthesis or a comma (string literals kept as they are). */
    private static function normalizedSql(string $sql): string
    {
        $parts = preg_split("/('(?:[^']|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $part = strtolower(str_replace(['"', '`', '[', ']'], '', $part));
                $part = (string) preg_replace(['/\s+/', '/ ?\( ?/', '/ \)/', '/ ?, ?/'], [' ', '(', ')', ','], $part);
            }

            $parts[$i] = $part;
        }

        return trim(implode('', $parts));
    }

    /** The offset of the parenthesis that closes the one at $open (outside string literals). */
    private static function closing(string $sql, int $open): int
    {
        $depth = 0;
        $quoted = false;

        for ($i = $open, $length = strlen($sql); $i < $length; $i++) {
            $character = $sql[$i];

            if ($character === "'") {
                $quoted = ! $quoted;
            } elseif (! $quoted && $character === '(') {
                $depth++;
            } elseif (! $quoted && $character === ')' && --$depth === 0) {
                return $i;
            }
        }

        return strlen($sql);
    }

    /**
     * $sql split at each $separator outside parentheses and string literals.
     *
     * @return list<string>
     */
    private static function topLevelParts(string $sql, string $separator): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quoted = false;

        for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
            $character = $sql[$i];

            if ($character === "'") {
                $quoted = ! $quoted;
            } elseif (! $quoted && $character === '(') {
                $depth++;
            } elseif (! $quoted && $character === ')') {
                $depth--;
            } elseif (! $quoted && $depth === 0 && $character === $separator) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        return [...$parts, $current];
    }
}
