<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures;

use Justrau\Orrery\DatabaseQueue;

/**
 * The package queue with a recording seam:
 * it records the arguments that pop() forwards to the parent, then calls
 * the parent implementation.
 */
class IndexRecordingQueue extends DatabaseQueue
{
    /** @var list<array<int, mixed>> */
    public static array $calls = [];

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function popFromParent(array $arguments): mixed
    {
        self::$calls[] = $arguments;

        return parent::popFromParent($arguments);
    }
}
