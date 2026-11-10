<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures;

use Illuminate\Contracts\Queue\Job;

/**
 * The handler of a raw payload from another producer (`job` =
 * `RawHandler@handle`). It counts its runs and deletes the job,
 * as a handler without Laravel's CallQueuedHandler must do.
 */
class RawHandler
{
    public static int $runs = 0;

    public function handle(Job $job, mixed $data = null): void
    {
        self::$runs++;
        $job->delete();
    }
}
