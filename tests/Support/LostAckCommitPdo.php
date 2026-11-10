<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use PDO;
use PDOException;

/**
 * commit() sends the COMMIT, then throws a lost-connection PDOException
 * ("server has gone away"): the transaction committed on the server, and its
 * acknowledgement is lost.
 *
 * The raw form of a commit (exec('COMMIT'), which the package transaction
 * uses on SQLite) behaves in the same way. The fault fires once while the PDO
 * is armed, and then the PDO disarms itself. A new PDO is armed; disarm()
 * gives one that commits normally until arm().
 */
final class LostAckCommitPdo extends PDO
{
    use PdoFromConfig;

    /** The commits whose acknowledgement was lost. */
    public int $lost = 0;

    private bool $armed = true;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function forConfig(array $config): self
    {
        [$dsn, $username, $password, $options] = self::pdoArguments($config);

        return new self($dsn, $username, $password, $options);
    }

    public function arm(): self
    {
        $this->armed = true;

        return $this;
    }

    public function disarm(): self
    {
        $this->armed = false;

        return $this;
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    public function commit(): bool
    {
        $result = parent::commit();

        if ($this->armed) {
            $this->lose();
        }

        return $result;
    }

    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);

        if ($this->armed && preg_match('/^\s*(COMMIT|END)\b/i', $statement) === 1) {
            $this->lose();
        }

        return $result;
    }

    private function lose(): never
    {
        $this->armed = false;
        $this->lost++;

        throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away (the test lost the acknowledgement of the COMMIT)');
    }
}
