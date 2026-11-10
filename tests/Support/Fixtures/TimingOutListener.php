<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures;

use Illuminate\Queue\TimeoutExceededException;
use Justrau\Orrery\Runtime\WatchesItself;

/**
 * A listener that watches itself and meets the worker's timeout (Laravel
 * throws TimeoutExceededException into the running code when
 * Worker::$killOnTimeout is false).
 */
final class TimingOutListener implements WatchesItself
{
    public function handle(object $event): never
    {
        throw new TimeoutExceededException('The job has timed out.');
    }
}
