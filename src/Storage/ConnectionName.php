<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Database\DatabaseManager;
use ReflectionMethod;

/**
 * The database connection name of a connection value, for example the
 * `connection` key of a `database` queue connection.
 *
 * Laravel accepts a PDO alias after the name: `x::read` and `x::write`, and
 * on Laravel versions that support it also `x::direct`. Each alias uses the
 * configuration of `x`. The running framework decides which suffixes it
 * accepts: this class asks the DatabaseManager's own parser
 * (`parseConnectionName()`), and does not guess from a version number. So
 * where Laravel has no direct alias, `x::direct` stays a literal connection
 * name. From Laravel 13.31, Laravel also names a direct connection instance
 * `x::direct` (13.30 names it `x`).
 *
 * The package tables and the fence live on the writer of that configuration,
 * so the package uses the base name and never the alias's PDO.
 *
 * Compatibility limit: the call uses reflection on a protected framework
 * method, with no fallback. If a future framework version renames or removes
 * `parseConnectionName()`, this method throws a ReflectionException.
 */
final class ConnectionName
{
    public static function base(DatabaseManager $db, string $connection): string
    {
        // The supported Laravel versions define this protected method (see the compatibility limit).
        $parsed = (new ReflectionMethod($db, 'parseConnectionName'))->invoke($db, $connection);

        return is_array($parsed) && is_string($parsed[0] ?? null) && $parsed[0] !== '' ? $parsed[0] : $connection;
    }
}
