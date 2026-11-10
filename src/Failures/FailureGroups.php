<?php

namespace Justrau\Orrery\Failures;

/**
 * The page size and the buckets of the failure groups.
 * GroupReads builds the groups.
 *
 * - The buckets have BUCKET_MINUTE seconds when to - from is at most one
 *   hour, else BUCKET_HOUR seconds. A boundary is floor(epoch / size) *
 *   size. The buckets run from the bucket of `from` to the last bucket that
 *   starts before `to` and not after as_of. A bucket that ends at or before
 *   the retention horizon, or (when the scan is truncated) at or before
 *   scanned_from, is absent (unknown), never zero. A bucket is `partial`
 *   when the window [max(from, retention_from), min(to, as_of)) does not
 *   cover it completely (the current bucket, a bucket cut by `from`, `to` or
 *   the retention horizon), or when it holds scanned_from.
 * - The counts are those that the caller passes (bucketsOf()), or of the
 *   given rows (counts()).
 */
final class FailureGroups
{
    public const PAGE_SIZE = 50;

    public const BUCKET_MINUTE = 60;

    public const BUCKET_HOUR = 3600;

    public static function bucketSeconds(FailureScope $scope): int
    {
        return $scope->seconds() <= self::BUCKET_HOUR ? self::BUCKET_MINUTE : self::BUCKET_HOUR;
    }

    public static function bucketLabel(int $seconds): string
    {
        return $seconds === self::BUCKET_MINUTE ? 'per minute' : 'per hour';
    }

    /**
     * The counts of the rows per bucket start.
     *
     * @param  list<FailedRow>  $rows
     * @return array<int, int>
     */
    public static function counts(array $rows, int $size): array
    {
        $counts = [];

        foreach ($rows as $row) {
            if ($row->failedAt !== null) {
                $at = (int) (floor($row->failedAt / $size) * $size);
                $counts[$at] = ($counts[$at] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The buckets of one group from its counts per bucket start. $scannedFrom
     * is the time before which the counts are unknown (null: none).
     *
     * @param  array<int, int>  $counts
     * @return list<array{at: int, count: int, partial: bool}>
     */
    public static function bucketsOf(array $counts, FailureScope $scope, int $size, float $asOf, ?float $retentionFrom, ?float $scannedFrom = null): array
    {
        $known = max($scope->from, $retentionFrom ?? $scope->from);
        $until = min($scope->to, $asOf);
        $buckets = [];

        for ($at = (int) (floor($scope->from / $size) * $size); $at < $scope->to && $at <= $asOf; $at += $size) {
            $end = $at + $size;

            // A bucket that ends at or before a horizon is unknown: absent, never zero.
            if (($retentionFrom !== null && $end <= $retentionFrom) || ($scannedFrom !== null && $end <= $scannedFrom)) {
                continue;
            }

            $buckets[] = [
                'at' => $at,
                'count' => $counts[$at] ?? 0,
                // The oldest scanned row's bucket can hold unscanned rows of the same time.
                'partial' => $at < $known || $end > $until || ($scannedFrom !== null && $at <= $scannedFrom),
            ];
        }

        return $buckets;
    }
}
