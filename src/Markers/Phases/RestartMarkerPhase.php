<?php

namespace Justrau\Orrery\Markers\Phases;

use Illuminate\Container\Container;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Markers\Markers;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\HostFiles;
use Throwable;

/**
 * The restart markers.
 *
 * - It reads `illuminate:queue:restart` from the `cache.store` binding, the
 *   default cache repository, as Laravel's RestartCommand and Worker do. In
 *   a cluster, that store must be shared; `queue:restart` needs that anyway.
 * - A cache error is recorded in the degradation record and reported
 *   through the watcher (`tick:restart_markers:cache`), and the phase
 *   skips. It never opens the mirror breaker: the cache store is not the
 *   package storage, so a lost database cache connection must not stop the
 *   later phases.
 * - Markers::observeRestart() applies the value.
 */
final class RestartMarkerPhase implements TickPhase
{
    public const CACHE_OPERATION = 'tick:restart_markers:cache';

    public function __construct(private readonly Markers $markers) {}

    public function name(): string
    {
        return 'restart_markers';
    }

    public function priority(): int
    {
        return 420;
    }

    public function run(TickBudget $budget): void
    {
        // D17: no automatic read of a cache store that writes to a host
        // SQLite file (a read can delete expired keys). No marker is
        // observed: the restarts of this time are unknown.
        if (! Container::getInstance()->make(HostFiles::class)->cacheIsSafe(null)) {
            return;
        }

        try {
            $value = Container::getInstance()->make('cache.store')->get(Markers::CACHE_KEY);
        } catch (Throwable $error) {
            Degradation::error(self::CACHE_OPERATION, $error);
            Watcher::instance()->report(self::CACHE_OPERATION, $error);

            return;
        }

        $this->markers->observeRestart($value);
    }
}
