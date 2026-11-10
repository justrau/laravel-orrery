<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry\Jobs;

use Illuminate\Bus\Batchable;

/**
 * RetryableJob as a batch member: Laravel records its
 * successes and failures in its batch by the job uuid.
 */
class BatchableRetryableJob extends RetryableJob
{
    use Batchable;
}
