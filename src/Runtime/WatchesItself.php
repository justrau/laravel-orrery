<?php

namespace Justrau\Orrery\Runtime;

/**
 * A package listener that runs its own work inside the watcher.
 * The provider's wrapper applies the driver filter and calls it
 * without the outer Watcher::run(), so a part of the listener that must run
 * while the breaker is open still runs: the heartbeat's flush of the
 * degradation record. The wrapper still catches and reports every error of
 * such a listener, so the listener never changes the host outcome.
 */
interface WatchesItself
{
}
