<?php

namespace Justrau\Orrery\Storage;

use LogicException;

/**
 * An operator action (D17 and D18): a retry, forget or resolve route or
 * command, a group or batch operation, orrery:clear, orrery:forget --all,
 * orrery:reconcile, or an operator's native pause and continue.
 *
 * Only PackageSession::operator() makes one. Passed to PackageSession::for(),
 * it gives the read-write operator session of a host SQLite file. The
 * context is a lease: it is re-entrant (nested operator() calls share it and
 * count their depth), and the outermost call ends it in `finally`, which
 * releases the operator sessions. An ended context never opens a session
 * again, so an automatic caller cannot inherit a writable lease.
 */
final class OperatorContext
{
    private int $depth = 0;

    private bool $ended = false;

    /**
     * @internal PackageSession::operator() makes the contexts.
     */
    public function __construct()
    {
    }

    public function isActive(): bool
    {
        return ! $this->ended && $this->depth > 0;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    /**
     * @throws LogicException when the context has ended
     */
    public function assertActive(): void
    {
        if (! $this->isActive()) {
            throw new LogicException('This operator context has ended, so it cannot open an operator session. Start a new one with PackageSession::operator().');
        }
    }

    /**
     * @internal
     */
    public function enter(): void
    {
        $this->assertNotEnded();
        $this->depth++;
    }

    /**
     * @internal
     *
     * @return int the depth after the call: 0 ends the context
     */
    public function leave(): int
    {
        $this->depth = max(0, $this->depth - 1);

        if ($this->depth === 0) {
            $this->ended = true;
        }

        return $this->depth;
    }

    private function assertNotEnded(): void
    {
        if ($this->ended) {
            throw new LogicException('This operator context has ended.');
        }
    }
}
