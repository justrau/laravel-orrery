<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\PostgresBuilder;
use LogicException;

/**
 * The schema builder of a pooled Postgres session: hasTable() and the
 * other existence checks take the schema of an unqualified table from
 * PostgresSession's map (the server's default search path), never from
 * current_schema(), which a pooled backend's leftover search path changes.
 * hasTable() asks to_regclass() for the qualified name.
 *
 * A pooled session runs no DDL: its schema grammar does not qualify names,
 * so a CREATE or DROP would follow the backend's leftover search path. The
 * package's migrations run on the application's own connection (on pooled
 * Postgres through a `direct` endpoint or a direct connection), so every
 * schema change here is refused.
 */
final class PooledPostgresBuilder extends PostgresBuilder
{
    /**
     * @param  string  $reference
     * @param  string|bool|null  $withDefaultSchema
     * @return array{0: string|null, 1: string}
     */
    public function parseSchemaAndTable($reference, $withDefaultSchema = null)
    {
        $segments = explode('.', $reference);

        if (count($segments) > 1) {
            return parent::parseSchemaAndTable($reference, $withDefaultSchema);
        }

        $schema = $this->session()->lookUpSchema($this->connection->getTablePrefix().$reference);

        if ($schema === null && $withDefaultSchema !== null && $withDefaultSchema !== false) {
            $schema = is_string($withDefaultSchema) ? $withDefaultSchema : $this->session()->defaultSchema();
        }

        return [$schema, $reference];
    }

    /**
     * @return string|null
     */
    public function getCurrentSchemaName()
    {
        return $this->session()->defaultSchema();
    }

    /**
     * @param  string  $table
     * @return bool
     */
    public function hasTable($table)
    {
        [$schema, $name] = $this->parseSchemaAndTable($table);

        if ($schema === null) {
            return false;
        }

        $qualified = self::quoted($schema).'.'.self::quoted($this->connection->getTablePrefix().$name);

        return (bool) $this->connection->scalar('select to_regclass(?) is not null', [$qualified]);
    }

    /**
     * @return void
     */
    protected function build(Blueprint $blueprint)
    {
        throw self::noDdl();
    }

    /**
     * @return void
     */
    public function dropAllTables()
    {
        throw self::noDdl();
    }

    /**
     * @return void
     */
    public function dropAllViews()
    {
        throw self::noDdl();
    }

    /**
     * @return void
     */
    public function dropAllTypes()
    {
        throw self::noDdl();
    }

    /**
     * @param  string  $name
     * @return bool
     */
    public function createDatabase($name)
    {
        throw self::noDdl();
    }

    /**
     * @param  string  $name
     * @return bool
     */
    public function dropDatabaseIfExists($name)
    {
        throw self::noDdl();
    }

    private static function noDdl(): LogicException
    {
        return new LogicException('A pooled package session runs no DDL: run schema changes on a direct connection.');
    }

    private static function quoted(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function session(): PostgresSession
    {
        $connection = $this->connection;

        if (! $connection instanceof PostgresSession) {
            throw new LogicException('The pooled schema builder belongs to a PostgresSession.');
        }

        return $connection;
    }
}
