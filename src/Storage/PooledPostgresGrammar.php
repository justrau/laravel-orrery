<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Database\Query\Grammars\PostgresGrammar;
use LogicException;

/**
 * The query grammar of a pooled Postgres session: a table that the
 * statement names is qualified with its schema under the server's default
 * search path (PostgresSession's map), so the statement reaches the
 * intended relation whatever search path a pooled backend carries.
 *
 * Unchanged: an Expression, a name that already names its schema, a table
 * alias (only the table before `as` is qualified), and the qualifier of a
 * column (`orrery_jobs.id` and `t.id` stay as they are: Postgres matches the
 * qualifier with the exposed name of the FROM item, which is the table's own
 * name or its alias). A name that is not a relation of the package (an
 * alias of a subquery, for example) stays unqualified.
 */
final class PooledPostgresGrammar extends PostgresGrammar
{
    /**
     * @param  \Illuminate\Contracts\Database\Query\Expression|string  $table
     * @param  string|null  $prefix
     * @return string
     */
    public function wrapTable($table, $prefix = null)
    {
        if (! is_string($table) || stripos($table, ' as ') !== false || str_contains($table, '.')) {
            return parent::wrapTable($table, $prefix);
        }

        $prefix ??= $this->connection->getTablePrefix();
        $schema = $this->session()->schemaFor($prefix.$table);

        if ($schema === null) {
            return parent::wrapTable($table, $prefix);
        }

        return $this->wrapValue($schema).'.'.$this->wrapValue($prefix.$table);
    }

    /**
     * The qualifier of a column is never qualified with a schema.
     *
     * @param  list<string>  $segments
     * @return string
     */
    protected function wrapSegments($segments)
    {
        $wrapped = [];

        foreach ($segments as $key => $segment) {
            $wrapped[] = $key === 0 && count($segments) > 1
                ? parent::wrapTable($segment)
                : $this->wrapValue($segment);
        }

        return implode('.', $wrapped);
    }

    private function session(): PostgresSession
    {
        $connection = $this->connection;

        if (! $connection instanceof PostgresSession) {
            throw new LogicException('The pooled grammar belongs to a PostgresSession.');
        }

        return $connection;
    }
}
