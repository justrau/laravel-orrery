<?php

namespace Justrau\Orrery\Maintenance;

use Justrau\Orrery\Storage\Clock;

/**
 * The cooperative deadline of one tick, on the monotonic
 * clock, so a change of the wall clock never moves it.
 *
 * atLeast() gives a phase that runs past the tick's deadline
 * (RunsPastDeadline) a budget of its own share; that budget keeps the run
 * of its parent (run()), so the run-scoped measurement stays shared.
 */
final class TickBudget
{
    public function __construct(
        private readonly float $deadlineMonotonic,
        private readonly ?TickBudget $run = null,
    ) {
    }

    /**
     * @phpstan-impure
     */
    public function exhausted(): bool
    {
        return Clock::monotonic() >= $this->deadlineMonotonic;
    }

    /**
     * Seconds until the deadline; 0 after it.
     */
    public function remaining(): float
    {
        return max(0.0, $this->deadlineMonotonic - Clock::monotonic());
    }

    /**
     * A budget of the same run that ends at this deadline, or $seconds from
     * now when that is later.
     */
    public function atLeast(float $seconds): self
    {
        return new self(max($this->deadlineMonotonic, Clock::monotonic() + $seconds), $this->run());
    }

    /**
     * The budget that identifies the tick run: the first budget of the run.
     */
    public function run(): self
    {
        return $this->run ?? $this;
    }
}
