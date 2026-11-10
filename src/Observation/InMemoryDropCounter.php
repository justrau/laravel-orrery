<?php

namespace Justrau\Orrery\Observation;

/**
 * The in-memory DropCounter: it keeps the two counts in this object. The
 * tests of the deferral use it; the adapter on the degradation
 * record.
 */
final class InMemoryDropCounter implements DropCounter
{
    private int $unverified = 0;

    private int $rollbackUnverified = 0;

    public function unverified(int $items = 1): void
    {
        $this->unverified += $items;
    }

    public function rollbackUnverified(int $items = 1): void
    {
        $this->rollbackUnverified += $items;
    }

    public function unverifiedCount(): int
    {
        return $this->unverified;
    }

    public function rollbackUnverifiedCount(): int
    {
        return $this->rollbackUnverified;
    }
}
