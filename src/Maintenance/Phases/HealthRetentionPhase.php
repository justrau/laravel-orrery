<?php

namespace Justrau\Orrery\Maintenance\Phases;

use Illuminate\Contracts\Config\Repository;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Runtime\HealthEvents;
use Justrau\Orrery\Storage\Clock;

/**
 * Health-event retention: a closed window that
 * ended before now minus `health.retention_days` days (default 7) is
 * deleted, and an open window whose start and last error are both before
 * that time (HealthEvents::pruneBefore()). Before orrery_samples the
 * cutoff followed the oldest metric snapshot, which is gone.
 */
final class HealthRetentionPhase implements TickPhase
{
    public const DEFAULT_RETENTION_DAYS = 7;

    public function __construct(private readonly Repository $config) {}

    public function name(): string
    {
        return 'health_retention';
    }

    public function priority(): int
    {
        return 900;
    }

    public function run(TickBudget $budget): void
    {
        HealthEvents::pruneBefore($this->cutoff());
    }

    public function cutoff(): float
    {
        $days = $this->config->get('orrery.health.retention_days', self::DEFAULT_RETENTION_DAYS);

        return Clock::now() - (is_numeric($days) && $days >= 0 ? (float) $days : self::DEFAULT_RETENTION_DAYS) * 86400;
    }
}
