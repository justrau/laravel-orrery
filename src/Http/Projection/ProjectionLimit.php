<?php

namespace Justrau\Orrery\Http\Projection;

use RuntimeException;

/**
 * A projection budget ended (ProjectionBudget). The message names only the
 * limit, never an input byte.
 */
final class ProjectionLimit extends RuntimeException
{
    public const INPUT = 'input';

    public const DEPTH = 'depth';

    public const NODES = 'nodes';

    public const WORK = 'work';

    public function __construct(public readonly string $limit)
    {
        parent::__construct("The projection reached its {$limit} limit.");
    }

    /**
     * @return array{unavailable: string, limit: string}
     */
    public function marker(): array
    {
        return ['unavailable' => 'limit', 'limit' => $this->limit];
    }
}
