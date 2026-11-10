<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Database\Connection;

/**
 * A value that a database connection escaped for raw SQL
 * (Connection::escape()). Only of() makes one, so TrustedSql can accept it as
 * a safe part of an expression.
 */
final class EscapedValue
{
    private function __construct(public readonly string $sql)
    {
    }

    public static function of(Connection $connection, string|int|null $value): self
    {
        return new self($connection->escape($value));
    }
}
