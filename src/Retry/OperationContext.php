<?php

namespace Justrau\Orrery\Retry;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The context of one OperationRunner run: the mirror database's time at the
 * start of the run, the tick budget, and each database's clock, read at most
 * once per run (on its automatic package session).
 */
final class OperationContext
{
    /** @var array<string, float> */
    private array $clocks = [];

    public function __construct(
        public readonly float $mirrorNow,
        public readonly TickBudget $budget,
        private readonly PackageSession $sessions,
    ) {}

    /**
     * The clock of a database connection (null: database.default), read
     * once per run. A failed read throws and keeps nothing.
     */
    public function clock(?string $connection): float
    {
        $key = $connection ?? "\0default";

        return $this->clocks[$key] ??= DatabaseClock::now($this->sessions->for($connection));
    }
}
