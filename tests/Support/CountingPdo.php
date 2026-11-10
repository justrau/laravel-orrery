<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Closure;
use PDO;
use PDOStatement;

/**
 * A PDO that counts its wire calls: each exec(),
 * query(), statement execute(), beginTransaction(), commit() and
 * rollBack() calls $count once. prepare() is not counted: with emulated
 * prepares (every pooled Postgres session) it sends nothing, and the
 * execute() of its statement is the round trip.
 *
 * HotPathBudgetTest uses it in the pooled Postgres run, so the budget counts
 * wire calls and not only the recorder's logical round trips.
 */
final class CountingPdo extends PDO
{
    /**
     * @param  array<int, mixed>  $options
     * @param  Closure(): void  $count
     */
    public function __construct(string $dsn, ?string $username, ?string $password, array $options, private readonly Closure $count)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, [$count]]);
    }

    /**
     * The PDO of a Postgres session configuration.
     *
     * @param  array<string, mixed>  $config
     * @param  Closure(): void  $count
     */
    public static function postgres(array $config, Closure $count): self
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', (string) $config['host'], (string) $config['port'], (string) $config['database']);
        /** @var array<int, mixed> $options */
        $options = (array) ($config['options'] ?? []);

        return new self($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), $options, $count);
    }

    public function exec(string $statement): int|false
    {
        ($this->count)();

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        ($this->count)();

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function beginTransaction(): bool
    {
        ($this->count)();

        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        ($this->count)();

        return parent::commit();
    }

    public function rollBack(): bool
    {
        ($this->count)();

        return parent::rollBack();
    }
}
