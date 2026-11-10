<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations\Fixtures;

use Justrau\Orrery\Tests\Support\AutocommitBounds;
use Justrau\Orrery\Tests\Support\PdoFromConfig;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A mirror PDO whose updates of orrery_operations fail.
 * Every other statement runs.
 */
final class ThrowingOperationsPdo extends PDO
{
    use PdoFromConfig;

    /** @param  array<string, mixed>  $config */
    public static function forConfig(array $config): self
    {
        [$dsn, $username, $password, $options] = self::pdoArguments($config);

        return new self($dsn, $username, $password, $options);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // A pooled session qualifies the table and sends an autocommit update after its bounds.
        if (preg_match('/^\s*update\s+(?:["`]?\w+["`]?\.)?["`]?\w*orrery_operations\b/i', AutocommitBounds::strip($query)) === 1) {
            throw new PDOException('SQLSTATE[HY000]: General error: the test mirror refuses this update');
        }

        return parent::prepare($query, $options);
    }
}
