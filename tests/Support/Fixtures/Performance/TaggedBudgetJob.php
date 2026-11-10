<?php

namespace Justrau\Orrery\Tests\Support\Fixtures\Performance;

use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;

/**
 * A job with three tags, for the tagged budget of HotPathBudgetTest:
 * its push is one package transaction with the job insert and one
 * multi-row tag insert.
 */
class TaggedBudgetJob extends BasicJob
{
    public const TAGS = ['budget:a', 'budget:b', 'budget:c'];

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return self::TAGS;
    }
}
