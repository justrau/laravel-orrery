<?php

namespace Justrau\Orrery\Database\Schema;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;

/**
 * Creates a package table on MySQL or MariaDB with its keys inside the one
 * `create table` statement (task S-F).
 *
 * Laravel's MySQL grammar puts the primary key into `create table`, but adds
 * every other key with its own `alter table … add index`. Each such statement
 * is a DDL statement of its own: on MySQL 8.4 the 15 tables with 38 separate
 * key statements took a median of 918 ms, and with the keys inside `create
 * table` 94 ms (5 rounds each, load average about 13, 2026-11-08).
 *
 * The statement is Laravel's own `create table` (columns, primary key,
 * charset, collation, engine) with the key clauses put before the parenthesis
 * that closes the column list. Each clause is the grammar's own statement of
 * the key without its "alter table <table> add"; only a plain clause
 * (`index` or `unique`, a quoted name and a column list, nothing after it)
 * goes inside, and any other key stays a statement of its own. When the
 * `create table` statement does not have the expected form (another Laravel
 * release, a temporary table), the table is created with Laravel's separate
 * statements, as before.
 */
final class InlineKeys
{
    /**
     * Creates the table with the definition's plain keys inside `create
     * table`, or with Laravel's statements when the statement has an
     * unknown form.
     *
     * @param  Closure(Blueprint): void  $definition
     */
    public static function create(Builder $schema, string $table, Closure $definition): void
    {
        $connection = $schema->getConnection();
        $statements = self::statements($schema, $table, $definition);

        if ($statements === null) {
            $schema->create($table, $definition);

            return;
        }

        foreach ($statements as $statement) {
            $connection->statement($statement);
        }
    }

    /**
     * The statements of the table with its plain keys inside `create table`,
     * or null when Laravel's first statement does not have the expected form.
     *
     * @param  Closure(Blueprint): void  $definition
     * @return list<string>|null
     */
    private static function statements(Builder $schema, string $table, Closure $definition): ?array
    {
        $connection = $schema->getConnection();
        $grammar = $connection->getSchemaGrammar();

        // The MySQL grammar (MariaDB's extends it) writes the key statements read below.
        if (! $grammar instanceof MySqlGrammar) {
            return null;
        }

        // As Builder::create() does, so a host's binding of Blueprint applies here too.
        $blueprint = Container::getInstance()->make(Blueprint::class, ['connection' => $connection, 'table' => $table, 'callback' => null]);
        $blueprint->create();
        $definition($blueprint);

        $head = 'alter table '.$grammar->wrapTable($blueprint).' add ';
        $keys = [];

        foreach ($blueprint->getCommands() as $command) {
            $name = $command->get('name');

            if (! in_array($name, ['index', 'unique'], true) || $command->get('shouldBeSkipped')) {
                continue;
            }

            // The grammar's own statement of the key; only "alter table <table> add <key>" with a
            // plain key clause goes inside. Any other form stays a statement of its own.
            $compiled = $name === 'unique' ? $grammar->compileUnique($blueprint, $command) : $grammar->compileIndex($blueprint, $command);

            if (! str_starts_with($compiled, $head)) {
                continue;
            }

            $clause = substr($compiled, strlen($head));

            if (preg_match('/^(index|unique) `.+`\(.+\)$/s', $clause) !== 1) {
                continue;
            }

            $keys[] = $clause;
            $command->offsetSet('shouldBeSkipped', true);
        }

        /** @var list<string> $statements */
        $statements = array_values($blueprint->toSql());

        if ($statements === []) {
            return null;
        }

        $create = self::splice($statements[0], 'create table '.$grammar->wrapTable($blueprint).' (', $keys);

        if ($create === null) {
            return null;
        }

        $statements[0] = $create;

        return $statements;
    }

    /**
     * Puts the key clauses into a `create table` statement that starts with
     * $head (`create table <table> (`), before the parenthesis that closes
     * its column list. It skips the parentheses inside quoted strings
     * (with backslash escapes) and quoted identifiers. Null when the
     * statement does not start with $head or the list is not closed.
     *
     * @param  list<string>  $keys
     */
    public static function splice(string $create, string $head, array $keys): ?string
    {
        if (! str_starts_with($create, $head)) {
            return null;
        }

        $depth = 1;
        $quote = null;
        $length = strlen($create);

        for ($at = strlen($head); $at < $length; $at++) {
            $char = $create[$at];

            if ($quote !== null) {
                if ($char === '\\' && $quote !== '`') {
                    $at++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $keys === [] ? $create : substr($create, 0, $at).', '.implode(', ', $keys).substr($create, $at);
            }
        }

        return null;
    }
}
