<?php

namespace Justrau\Orrery\Runtime;

use Closure;

/**
 * The mirror breaker of one process. A dependency error opens
 * it for `orrery.observer.breaker_seconds`. It measures elapsed time on the
 * monotonic clock that it gets (Watcher::monotonic()), so a change of the
 * wall clock never shortens or extends it.
 */
final class Breaker
{
    /** Monotonic seconds until which the breaker is open; null when it is closed. */
    private ?float $openUntil = null;

    /**
     * @param  Closure(): float  $monotonic
     */
    public function __construct(private readonly float $seconds, private readonly Closure $monotonic) {}

    /**
     * Opens the breaker. Returns true when it changed from closed to open.
     * A breaker that is open already keeps its end.
     */
    public function open(): bool
    {
        if ($this->isOpen()) {
            return false;
        }

        $this->openUntil = ($this->monotonic)() + $this->seconds;

        return true;
    }

    /**
     * Opens the breaker for the $seconds that remain of a breaker that
     * another process opened (SharedBreaker). Returns true when it changed
     * from closed to open; an open breaker keeps its end.
     */
    public function openFor(float $seconds): bool
    {
        if ($this->isOpen() || $seconds <= 0.0) {
            return false;
        }

        $this->openUntil = ($this->monotonic)() + min($seconds, $this->seconds);

        return true;
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    /**
     * Whether the breaker is open now. It never changes the state: the
     * watcher closes an expired breaker with closeIfExpired(), because a
     * close is a flush point.
     */
    public function isOpen(): bool
    {
        return $this->openUntil !== null && ($this->monotonic)() < $this->openUntil;
    }

    /**
     * Closes an expired breaker. Returns true when this call closed it.
     */
    public function closeIfExpired(): bool
    {
        if ($this->openUntil === null || ($this->monotonic)() < $this->openUntil) {
            return false;
        }

        $this->openUntil = null;

        return true;
    }
}
