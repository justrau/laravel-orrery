<?php

namespace Justrau\Orrery\Maintenance;

/**
 * One phase of orrery:tick. A module tags its phases
 * `orrery.tick-phases` in its register(). The runner sorts them by
 * priority(), lowest first, and runs each one inside the watcher. A phase
 * checks $budget between its batches and stops when it is exhausted.
 */
interface TickPhase
{
    public function name(): string;

    /**
     * Lower runs first.
     */
    public function priority(): int;

    public function run(TickBudget $budget): void;
}
