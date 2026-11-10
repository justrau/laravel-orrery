<?php

namespace Justrau\Orrery\Storage;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use RuntimeException;

/**
 * The clock of a database server: the time
 * domain of the terminate signals (the mirror's clock) and of the operation
 * deadlines (each database's own clock). The callers read it on the package
 * session of that database, so clocks of different hosts are never
 * compared.
 *
 * This is the one exception to the rule of Storage\Clock that SQL never
 * calls the server clock.
 *
 * - SQLite: julianday('now') in epoch seconds. SQLite runs in the caller's
 *   process, so all its processes share one host clock: the supported
 *   SQLite topology. The resolution is one millisecond.
 * - MySQL and MariaDB: UNIX_TIMESTAMP() plus the microseconds of NOW(6).
 *   Both use the start time of the statement. The product (not a division)
 *   keeps six decimals whatever div_precision_increment is. This form avoids the DST
 *   ambiguity of UNIX_TIMESTAMP(NOW(6)) in a session time zone with DST.
 * - Postgres: EXTRACT(EPOCH FROM clock_timestamp()).
 *
 * expression() gives the SQL of the clock with its bindings, so a statement
 * can compare with the clock in the same statement (the conditional fence
 * insert). now() selects the same expression.
 *
 * The test seam: $offsetForTesting, set
 * only by test code and cleared by the module reset, gives the offset in
 * seconds of a connection's clock. expression() then adds it as a bound
 * number, so now() and every statement that uses expression() see the same
 * shifted clock. This class reads no environment variable and no file.
 *
 * now() throws when the read fails or gives no finite number.
 */
final class DatabaseClock
{
    /** The alias of the selected value; a test can find the statement by it. */
    public const ALIAS = 'orrery_db_now';

    /** @var (Closure(Connection): float)|null the offset of a connection's clock in seconds (tests only) */
    public static ?Closure $offsetForTesting = null;

    public static function now(Connection $connection): float
    {
        [$clock, $bindings] = self::expression($connection);
        $row = $connection->selectOne('select '.$clock->getValue($connection->getQueryGrammar()).' as '.self::ALIAS, $bindings);
        $value = is_object($row) ? ($row->{self::ALIAS} ?? null) : null;

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new RuntimeException('orrery: the database clock of the package storage gave no time.');
        }

        return (float) $value;
    }

    /**
     * The SQL of the clock of $connection's server in epoch seconds, and its
     * bindings: none, or the test offset as one bound number.
     *
     * @return array{0: Expression<literal-string>, 1: list<float>}
     */
    public static function expression(Connection $connection): array
    {
        $offset = self::$offsetForTesting === null ? null : (float) (self::$offsetForTesting)($connection);

        if ($offset === null) {
            return [match ($connection->getDriverName()) {
                'sqlite' => TrustedSql::expression("((julianday('now') - 2440587.5) * 86400.0)"),
                'mysql', 'mariadb' => TrustedSql::expression('(UNIX_TIMESTAMP() + MICROSECOND(NOW(6)) * 0.000001)'),
                'pgsql' => TrustedSql::expression('EXTRACT(EPOCH FROM clock_timestamp())'),
                default => throw self::unsupported($connection),
            }, []];
        }

        return [match ($connection->getDriverName()) {
            'sqlite' => TrustedSql::expression("(((julianday('now') - 2440587.5) * 86400.0) + ?)"),
            'mysql', 'mariadb' => TrustedSql::expression('(UNIX_TIMESTAMP() + MICROSECOND(NOW(6)) * 0.000001 + ?)'),
            'pgsql' => TrustedSql::expression('(EXTRACT(EPOCH FROM clock_timestamp()) + CAST(? AS numeric))'),
            default => throw self::unsupported($connection),
        }, [$offset]];
    }

    private static function unsupported(Connection $connection): RuntimeException
    {
        return new RuntimeException("orrery: the database clock does not support the driver [{$connection->getDriverName()}].");
    }
}
