<?php

namespace Justrau\Orrery\Observation;

/**
 * The process state of one watched queue connection instance
 * (CommitDeferral): its begin counter, whether its beforeExecuting witness
 * is registered, and the stack of the captures whose parent calls run.
 *
 * @internal
 */
final class WatchState
{
    public int $begins = 0;

    public bool $witness = false;

    /** @var list<Capture> */
    public array $active = [];
}
