<?php

namespace Justrau\Orrery\Lifecycle;

use Justrau\Orrery\Storage\Clock;

/**
 * The columns that every transition which starts a cycle writes
 * (the cycle start metadata and the reset at a new cycle): the retry
 * finalisation, adoption and nativeRetry.
 *
 * The caller adds retried_count + 1 and retried_at (the lifetime retry
 * count"), because they depend on the row, and the columns of the
 * transition that follows (for nativeRetry, the reservation of reserve).
 */
final class CycleStart
{
    /** The per-cycle counters and high-water columns, reset to 0. */
    public const COUNTERS = [
        'attempts', 'releases', 'exceptions', 'timeouts', 'reclaims', 'observed_attempts', 'late_outcomes',
        'last_outcome_attempt', 'last_exception_attempt', 'last_timeout_attempt', 'observed_gap',
    ];

    /**
     * The columns of the cycle reset plus the cycle metadata.
     *
     * $generation is null only for a native retry of a payload
     * without a generation.
     *
     * @return array<string, mixed>
     */
    public static function columns(int $newCycle, ?string $generation, ?int $firstJobId, string $source, float $at): array
    {
        return [
            'cycle' => $newCycle,
            'cycle_gen' => $generation,
            'cycle_first_job_id' => $firstJobId,
            'cycle_started_at' => Clock::toDecimal($at),
            'cycle_source' => $source,
            'prev_job_id' => null,
            'job_id_unresolved' => false,
            'bulk_floor_id' => null,
            'absent_since' => null,
        ] + array_fill_keys(self::COUNTERS, 0);
    }
}
