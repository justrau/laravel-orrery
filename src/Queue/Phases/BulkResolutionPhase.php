<?php

namespace Justrau\Orrery\Queue\Phases;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Queue\BulkResolver;

/**
 * The tick retries of unresolved bulk rows:
 * at most 3 attempts per group of rows, 5,000 jobs rows per attempt, from
 * their floor, with the match by uuid and generation of the first resolution.
 * After that, the rows stay job_id_unresolved until a reservation resolves
 * them (reserve).
 */
final class BulkResolutionPhase implements TickPhase
{
    public function __construct(private readonly BulkResolver $resolver) {}

    public function name(): string
    {
        return 'bulk_resolution';
    }

    public function priority(): int
    {
        return 250;
    }

    public function run(TickBudget $budget): void
    {
        $this->resolver->resolveUnresolved($budget);
    }
}
