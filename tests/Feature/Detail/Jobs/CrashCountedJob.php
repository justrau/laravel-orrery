<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Detail\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job whose crashes count as exceptions (#[CountCrashesAsExceptions]; 13.40
 * has it, 13.30 does not): with tries 10 and maxExceptions 2, a worker
 * that finds the marker of a crashed attempt counts it, and fails the job
 * with MaxAttemptsExceededException once two are counted, far below its
 * tries value. handle() completes.
 */
#[CountCrashesAsExceptions]
class CrashCountedJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 2;

    public function handle(): void {}
}
