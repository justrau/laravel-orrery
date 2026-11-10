<?php

namespace Justrau\Orrery\Maintenance;

/**
 * A tick phase that runs also after the tick's deadline passed:
 * `stuck_events` and the two notification phases, which are
 * last, so they delay no other phase. TickRunner gives it the rest of the
 * tick's budget, or
 * shareSeconds() from its start when that is later. A phase that ends
 * after its own budget records `tick_overrun:<name>`.
 */
interface RunsPastDeadline extends TickPhase
{
    /**
     * The least seconds that the phase gets, also after the deadline.
     */
    public function shareSeconds(): float;
}
