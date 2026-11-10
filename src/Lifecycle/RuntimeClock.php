<?php

namespace Justrau\Orrery\Lifecycle;

use Illuminate\Container\Container;
use Justrau\Orrery\Storage\Clock;

/**
 * The runtime map. JobProcessing starts a job's clock and
 * JobAttempted forgets it (the listeners wire both). The map keeps at most
 * 1,000 jobs, so a missed JobAttempted cannot grow it without bound.
 *
 * The map is unit-of-work state: a scoped binding. A worker
 * flushes the scoped instances at the start of each loop, before pop(), so
 * a job's JobProcessing and its completion share one map, and the next job
 * starts with a new one. Octane flushes it per request.
 */
final class RuntimeClock
{
    public const LIMIT = 1000;

    /** @var array<string, float> uuid => monotonic start */
    private array $started = [];

    public static function start(string $uuid): void
    {
        $clock = self::bound();
        unset($clock->started[$uuid]);
        $clock->started[$uuid] = Clock::monotonic();

        while (count($clock->started) > self::LIMIT) {
            unset($clock->started[array_key_first($clock->started)]);
        }
    }

    public static function elapsedMs(string $uuid): ?float
    {
        $clock = self::bound();

        if (! isset($clock->started[$uuid])) {
            return null;
        }

        return max(0.0, (Clock::monotonic() - $clock->started[$uuid]) * 1000);
    }

    public static function forget(string $uuid): void
    {
        unset(self::bound()->started[$uuid]);
    }

    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
