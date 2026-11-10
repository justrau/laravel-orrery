<?php

namespace Justrau\Orrery\Monitoring;

/**
 * The counts of a window of minutes as SampleCoverage::of() decided them
 * (the read rule).
 *
 * - known(): whether the completions (`completed`, and with them
 *   `runtime_ms_sum` and `runtime_count`), the failures (`failed`) or the
 *   arrivals (`pushed`) of a minute are known. An unknown minute is
 *   null for every series, never 0. A kind that the reader did not request
 *   (SampleCoverage::of()) is unknown in every minute.
 * - value(): the count of one series in one minute: null when that kind is
 *   unknown, else the stored or scanned count, 0 for a series without a
 *   row in a known minute.
 * - labels: the display values of every series that has a row in the
 *   window (`connection`, `queue` for the queue scope, `name` for the job
 *   scope), keyed by the series digest.
 * - scannedFrom: per kind, the earliest time from which the minutes were
 *   decided by a scan of the retained rows: the later of M (without M: the
 *   current minute) and the trim horizon at the end of the reads. A minute
 *   that starts before it was decided by the stored counts, or is unknown.
 *   An outcome kind that was not requested has INF; `pushed` is present
 *   only when it was requested, so the outcome readers' shapes stay.
 *
 * @phpstan-type Counts array{completed: int, failed: int, runtime_ms_sum: int, runtime_count: int, pushed: int}
 * @phpstan-type Label array{connection: ?string, queue: ?string, name: ?string}
 */
final class SampleWindow
{
    /** Every kind of count: completions, failures and arrivals. */
    public const KINDS = ['completed', 'failed', 'pushed'];

    /** The kinds of the outcome readers (the throughput and the queue history); the job classes and the workload rates read KINDS. */
    public const OUTCOMES = ['completed', 'failed'];

    /**
     * @param  array<int, array<'completed'|'failed'|'pushed', bool>>  $known  per minute
     * @param  array<int, array<string, Counts>>  $rows  per minute and series
     * @param  array<string, Label>  $labels
     * @param  array{completed: float, failed: float, pushed?: float}  $scannedFrom  per kind (INF for an outcome kind that was not requested; `pushed` only when requested)
     */
    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly float $asOf,
        private readonly array $known,
        private readonly array $rows,
        public readonly array $labels,
        public readonly array $scannedFrom = ['completed' => INF, 'failed' => INF],
    ) {}

    /**
     * The minute starts of the window, oldest first.
     *
     * @return list<int>
     */
    public function minutes(): array
    {
        return $this->from < $this->to ? range($this->from, $this->to - SampleCounts::MINUTE, SampleCounts::MINUTE) : [];
    }

    /**
     * @param  'completed'|'failed'|'pushed'  $kind
     */
    public function known(int $minute, string $kind): bool
    {
        return $this->known[$minute][$kind] ?? false;
    }

    /**
     * @param  'completed'|'failed'|'runtime_ms_sum'|'runtime_count'|'pushed'  $field
     */
    public function value(int $minute, string $series, string $field): ?int
    {
        if (! $this->known($minute, self::kindOf($field))) {
            return null;
        }

        return $this->rows[$minute][$series][$field] ?? 0;
    }

    /**
     * The sum of $field over the window for one series: null when a minute
     * of the window is unknown for that field.
     *
     * @param  'completed'|'failed'|'runtime_ms_sum'|'runtime_count'|'pushed'  $field
     */
    public function sum(string $series, string $field): ?int
    {
        $sum = 0;

        foreach ($this->minutes() as $minute) {
            $value = $this->value($minute, $series, $field);

            if ($value === null) {
                return null;
            }

            $sum += $value;
        }

        return $sum;
    }

    /**
     * The series with a row in a known minute of the window, in digest order.
     *
     * @return list<string>
     */
    public function series(): array
    {
        $series = [];

        foreach ($this->rows as $minute => $rows) {
            if ($this->known($minute, 'completed') || $this->known($minute, 'failed') || $this->known($minute, 'pushed')) {
                foreach (array_keys($rows) as $digest) {
                    $series[(string) $digest] = true;
                }
            }
        }

        $series = array_keys($series);
        sort($series, SORT_STRING);

        return array_map('strval', $series);
    }

    /**
     * The kind that decides whether a field is known.
     *
     * @param  'completed'|'failed'|'runtime_ms_sum'|'runtime_count'|'pushed'  $field
     * @return 'completed'|'failed'|'pushed'
     */
    private static function kindOf(string $field): string
    {
        return match ($field) {
            'failed' => 'failed',
            'pushed' => 'pushed',
            default => 'completed',
        };
    }

    /**
     * The earliest minute of the window whose $kind is known, or null.
     *
     * @param  'completed'|'failed'|'pushed'  $kind
     */
    public function earliestKnown(string $kind): ?int
    {
        foreach ($this->minutes() as $minute) {
            if ($this->known($minute, $kind)) {
                return $minute;
            }
        }

        return null;
    }
}
