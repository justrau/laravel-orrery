<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Markers\Markers;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\Throughput;
use Justrau\Orrery\Storage\Clock;

/**
 * GET /api/throughput?minutes=60.
 *
 * - `minutes` is an integer in 5..1440 (default 60). Any other value gives
 *   422 JSON. An empty value counts as absent.
 * - The window is half-open [from, to): `to` is the request time (`as_of`)
 *   and `from` = to − minutes × 60. Every time is epoch seconds.
 * - `buckets`: `{at, completed, failed, partial}` for each 60-second bucket
 *   (`at` = floor(epoch / 60) × 60) from the bucket that contains `from` to
 *   the bucket that contains the last instant before `to`, oldest first (an
 *   unaligned window touches minutes + 1 buckets).
 *   - `completed`: completed rows by `finished_at`, silenced included.
 *   - `failed`: every row by `failed_at`, whatever its current status: the
 *     latest failure of each job.
 *   - Each value comes from the read rule of the samples
 *     (Monitoring\SampleCoverage): the stored counts before the frontier M,
 *     a scan of the retained minutes from M on (so the last complete minute
 *     is not a gap while the tick has not counted it), and null for every
 *     minute that is invalid, retired, before M without a `total` row, or no
 *     longer retained. A covered quiet minute is 0.
 *   - `partial` is true for the current bucket and for the bucket that
 *     `from` cuts.
 * - `retention_from` per series is a retention horizon, not a completeness
 *   proof: the earlier of the oldest `total` row and the time from which the
 *   minutes are scanned (the later of M, else the current minute, and the
 *   series' trim horizon), never earlier than now − `samples.retention_hours`. The trim horizon
 *   takes the shortest value of each key over `orrery.trim` and every
 *   `orrery.trim_overrides` entry, so a minute whose rows a
 *   short override trimmed is absent, never an undercount.
 * - `markers`: the restart and deploy markers with `at` in [from, to),
 *   newest first, at most 200 (`markers_truncated`), with Markers::since()'s
 *   fields `id`, `kind`, `at`, `label` and `source`.
 */
class ThroughputController extends Controller
{
    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 1440;

    public const DEFAULT_MINUTES = 60;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function index(Request $request, Throughput $throughput, Markers $markers, SampleCounts $samples): JsonResponse
    {
        $minutes = $this->minutes($request->query('minutes'));

        if ($minutes === null) {
            return new JsonResponse([
                'error' => 'invalid_parameter',
                'message' => sprintf('minutes is an integer from %d to %d.', self::MIN_MINUTES, self::MAX_MINUTES),
            ], 422, [], self::JSON);
        }

        $now = Clock::now();
        $size = Throughput::BUCKET_SECONDS;
        $from = $now - $minutes * 60;
        $first = (int) (floor($from / $size) * $size);
        $last = (int) (ceil($now / $size) * $size) - $size;
        $read = self::watchedRead('http:throughput', static fn (): array => [
            $throughput->minutes($first, $last + $size),
            $throughput->oldestCovered(),
            $markers->since($from),
        ], 'The throughput');

        if ($read instanceof JsonResponse) {
            return $read;
        }

        [$counts, $oldest, [$marks, $truncated]] = $read;
        $buckets = [];

        for ($at = $first; $at <= $last; $at += $size) {
            $buckets[] = [
                'at' => $at,
                'completed' => $counts['minutes'][$at]['completed'] ?? null,
                'failed' => $counts['minutes'][$at]['failed'] ?? null,
                'partial' => $at + $size > $now || $at < $from,
            ];
        }

        $retention = [];

        foreach ($counts['scanned_from'] as $series => $scanned) {
            $retention[$series] = max($now - $samples->retentionHours() * 3600, min($oldest === null ? INF : (float) $oldest, $scanned));
        }

        return new JsonResponse([
            'as_of' => $now,
            'from' => $from,
            'to' => $now,
            'bucket_seconds' => $size,
            'retention_from' => $retention,
            'buckets' => $buckets,
            'markers' => array_values(array_filter($marks, static fn (array $marker): bool => $marker['at'] < $now)),
            'markers_truncated' => $truncated,
        ], 200, [], self::JSON);
    }

    private function minutes(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_MINUTES;
        }

        if (! is_string($value) || preg_match('/^\d{1,4}$/D', $value) !== 1) {
            return null;
        }

        $minutes = (int) $value;

        return $minutes >= self::MIN_MINUTES && $minutes <= self::MAX_MINUTES ? $minutes : null;
    }
}
