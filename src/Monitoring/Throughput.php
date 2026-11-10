<?php

namespace Justrau\Orrery\Monitoring;

/**
 * The per-minute outcome counts of the throughput chart, by the
 * read rule of the samples (SampleCoverage): the stored
 * `total` rows before the frontier M, a scan of the retained mirror rows
 * from M on, and null for every invalid, retired or unretained minute.
 *
 * - completed: rows with `status` completed, by `finished_at` (silenced
 *   included);
 * - failed: every row by `failed_at`, whatever its current status:
 *   a finalised retry keeps `failed_at`, so a burst stays
 *   in the band after its retries complete. A row that failed twice holds
 *   only its latest `failed_at`, so it counts its latest failure only.
 */
final class Throughput
{
    public const BUCKET_SECONDS = 60;

    /** Every status of an orrery_jobs row: the failed series counts each of them. */
    public const STATUSES = ['pending', 'reserved', 'completed', 'failed', 'retrying', 'unknown'];

    public function __construct(private readonly SampleCoverage $coverage)
    {
    }

    /**
     * The counts of every minute in [from, to) (minute boundaries), keyed by
     * the minute: `completed` and `failed`, each null when unknown; and per
     * series the time from which the minutes were scanned
     * (SampleWindow::$scannedFrom).
     *
     * @return array{minutes: array<int, array{completed: ?int, failed: ?int}>, scanned_from: array{completed: float, failed: float}}
     */
    public function minutes(int $from, int $to): array
    {
        $window = $this->coverage->of('total', null, $from, $to);
        $series = SampleCounts::series('total');
        $minutes = [];

        foreach ($window->minutes() as $minute) {
            $minutes[$minute] = [
                'completed' => $window->value($minute, $series, 'completed'),
                'failed' => $window->value($minute, $series, 'failed'),
            ];
        }

        return ['minutes' => $minutes, 'scanned_from' => $window->scannedFrom];
    }

    /**
     * The minute of the oldest `total` row, or null.
     */
    public function oldestCovered(): ?int
    {
        return $this->coverage->oldestTotal();
    }
}
