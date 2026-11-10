<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection\Jobs;

use Illuminate\Bus\Batchable;

/**
 * CanaryJob as a batch member: the same canaries in every position.
 */
class BatchableCanaryJob extends CanaryJob
{
    use Batchable;
}
