<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\SampleCoverage;
use Justrau\Orrery\Monitoring\Samples;
use Justrau\Orrery\Storage\Clock;

/**
 * GET /api/queues/{connection}/{queue}/history?minutes=60.
 *
 * - `connection` is a queue connection; an alias of a shared jobs table
 *   reads the series of its primary alias (the first in sorted order), as
 *   the samples store it. The series is byte-exact (SampleCounts::series()).
 * - `minutes` is an integer in 5..1440 (default 60). Any other value gives
 *   422 JSON.
 * - The window is half-open [from, to): `to` is the request time (`as_of`),
 *   and `from` is the minute boundary that gives `minutes` buckets, the
 *   current one included.
 * - `bucket_seconds` is 60. Each bucket is one minute: `at` (the minute
 *   boundary, epoch seconds); the queue state `ready`, `oldest_age` and
 *   `over_limit` of the minute's sample, null when the minute was not
 *   sampled; `completed` and `failed` of the queue by the read rule of the
 *   samples (SampleCoverage), null when unknown, 0 for a covered quiet
 *   minute; and `partial` (true for the current minute).
 * - A minute without a sample and without known counts is absent, never
 *   zero-filled.
 * - `retention_from` is the retention horizon of the samples: now −
 *   `samples.retention_hours`. No sample is kept before it. It is not a
 *   proof that every minute after it was sampled.
 * - `minutes` absent, or empty (a host's ConvertEmptyStringsToNull makes it
 *   absent), gives the default.
 */
class QueueHistoryController extends Controller
{
    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 1440;

    public const DEFAULT_MINUTES = 60;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public function show(Request $request, Samples $samples, SampleCoverage $coverage, SampleCounts $counts, string $connection, string $queue): JsonResponse
    {
        $minutes = $this->minutes($request->query('minutes'));

        if ($minutes === null) {
            return new JsonResponse([
                'error' => 'invalid_parameter',
                'message' => sprintf('minutes is an integer from %d to %d.', self::MIN_MINUTES, self::MAX_MINUTES),
            ], 422, [], self::JSON);
        }

        $now = Clock::now();
        $bucket = Samples::BUCKET_SECONDS;
        $current = SampleCounts::minuteOf($now);
        $from = $current - ($minutes - 1) * $bucket;

        $read = self::watchedRead('http:queue_history', static fn (): array => [
            $samples->history($connection, $queue, $from, $now),
            $coverage->of('queue', ['connection' => $connection, 'queue' => $queue], $from, $current + $bucket),
        ], 'The queue history');

        if ($read instanceof JsonResponse) {
            return $read;
        }

        [$history, $window] = $read;
        $states = [];

        foreach ($history as $row) {
            $states[$row['at']] = $row;
        }

        $series = SampleCounts::series('queue', $coverage->primary($connection), $queue);
        $buckets = [];

        foreach ($window->minutes() as $at) {
            $state = $states[$at] ?? null;
            $completed = $window->value($at, $series, 'completed');
            $failed = $window->value($at, $series, 'failed');

            if ($state === null && $completed === null && $failed === null) {
                continue;
            }

            $buckets[] = [
                'at' => $at,
                'ready' => $state['ready'] ?? null,
                'oldest_age' => $state['oldest_age'] ?? null,
                'over_limit' => $state['over_limit'] ?? null,
                'completed' => $completed,
                'failed' => $failed,
                'partial' => $at === $current,
            ];
        }

        return new JsonResponse([
            'connection' => $connection,
            'queue' => $queue,
            'as_of' => $now,
            'from' => $from,
            'to' => $now,
            'bucket_seconds' => $bucket,
            'retention_from' => $now - $counts->retentionHours() * 3600,
            'buckets' => $buckets,
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
