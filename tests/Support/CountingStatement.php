<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Closure;
use PDOStatement;

/**
 * The statement class of CountingPdo: each execute() is one wire call.
 */
final class CountingStatement extends PDOStatement
{
    /**
     * @param  Closure(): void  $count
     */
    protected function __construct(private readonly Closure $count) {}

    public function execute(?array $params = null): bool
    {
        ($this->count)();

        return parent::execute($params);
    }
}
