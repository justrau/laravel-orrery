<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\SampleCoverage;
use Justrau\Orrery\Monitoring\SampleWindow;
use Justrau\Orrery\Queue\Instrumentation;
use Justrau\Orrery\Runtime\SafeError;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Throwable;

/**
 * The job classes: the `job` scope of
 * orrery_samples, keyed by the job name across queues and connections,
 * read through SampleCoverage (the read rule of the samples: a minute is
 * stored, scanned or unknown, never a zero-filled gap).
 *
 * The window of both endpoints is half-open [from, to): `to` is the request
 * time (`as_of`), and `from` is the minute boundary that gives `minutes`
 * minutes, the current (partial) one included, as in the queue history.
 *
 * GET /api/classes?minutes=60 (5..60, default 60):
 * - `classes`: every class with a `job` row (stored or scanned) in a known
 *   minute of the window, each `{name, completed, failed, pushed,
 *   runtime_avg_ms, complete}`. `completed` and `failed` sum the known
 *   minutes; `complete` is false when a minute of the window is unknown for
 *   the completions or the failures, and then each sum is a lower bound
 *   ("at least"). `runtime_avg_ms` is Σ runtime_ms_sum ÷ Σ runtime_count
 *   over the known minutes, null when the count is 0. `pushed` is the sum of
 *   the arrivals, null when any minute's arrivals are unknown (a
 *   minute counted before schema step 7 included), and null in both
 *   endpoints while a database queue connection is uninstrumented
 *   (arrivalsKnown()).
 * - At most CLASS_LIMIT classes, by completed + failed desc, then the name
 *   in byte order; `truncated` is true when more had rows.
 * - `unknown_minutes`: `{count, first, last}` of the minutes whose
 *   completions or failures are unknown (P-5), or null when none is.
 *
 * GET /api/classes/series?name=<class>&minutes=60 (5..1440, default 60):
 * - `name` is required, byte-exact (it holds backslashes; read from the
 *   raw query string, so a host's TrimStrings or ConvertEmptyStringsToNull
 *   middleware does not change it), valid UTF-8 without NUL, at most
 *   NAME_MAX_CHARACTERS characters (the `name` column of orrery_samples).
 * - `buckets`: per minute `{at, completed, failed, pushed, runtime_avg_ms,
 *   partial}`, each count null when its kind is unknown; a minute whose
 *   kinds are all unknown is absent. `partial` is true for the current
 *   minute.
 *
 * Both:
 * - `retention_from` is a retention horizon per kind (`completed`,
 *   `failed`, `pushed`, as /api/throughput gives one per series), not a
 *   completeness proof: the earlier of the oldest `total` row and the time
 *   from which that kind is scanned (SampleWindow::$scannedFrom: the later of M and the trim
 *   horizon, which takes RetentionPolicy::shortest() of each key over
 *   `orrery.trim` and every override), never earlier than now −
 *   `samples.retention_hours`.
 * - An invalid parameter gives 422 `{error: "invalid_parameter", message,
 *   field}`.
 * - The reads run in the watcher (operation READ_OPERATION) on a bounded
 *   mirror session (PackageSession::bounded()). A statement timeout gives
 *   503 `{error: "read_timeout"}`; another failure (an open breaker
 *   included) gives 503 `{error: "read_failed"}`. Both are reported by the
 *   watcher, never passed to the host's exception handler.
 */
class JobClassesController extends Controller
{
    public const MIN_MINUTES = 5;

    public const MAX_CLASS_MINUTES = 60;

    public const MAX_SERIES_MINUTES = 1440;

    public const DEFAULT_MINUTES = 60;

    public const CLASS_LIMIT = 500;

    public const NAME_MAX_CHARACTERS = 512;

    public const READ_OPERATION = 'http:classes';

    /** Statement timeouts: Postgres SQLSTATE 57014; MySQL 3024 and MariaDB 1969 by driver code. */
    private const TIMEOUT_STATE = '57014';

    private const TIMEOUT_CODES = [3024, 1969];

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public function index(Request $request, SampleCoverage $coverage, SampleCounts $counts, PackageSession $sessions, Instrumentation $instrumentation): JsonResponse
    {
        if (($tooLong = self::tooLong($request)) !== null) {
            return $tooLong;
        }

        $minutes = self::minutes($request->query('minutes'), self::MAX_CLASS_MINUTES);

        if ($minutes === null) {
            return self::invalid('minutes', sprintf('minutes is an integer from %d to %d.', self::MIN_MINUTES, self::MAX_CLASS_MINUTES));
        }

        [$now, $from, $current] = self::window($minutes);
        $read = $this->read($coverage, $sessions, null, $from, $current + SampleCounts::MINUTE);

        if ($read instanceof JsonResponse) {
            return $read;
        }

        [$window, $oldest] = $read;
        $arrivalsKnown = self::arrivalsKnown($instrumentation);
        $unknown = [];

        foreach ($window->minutes() as $minute) {
            if (! $window->known($minute, 'completed') || ! $window->known($minute, 'failed')) {
                $unknown[] = $minute;
            }
        }

        $classes = [];

        foreach ($window->series() as $series) {
            $name = $window->labels[$series]['name'] ?? null;

            if ($name === null) {
                continue;
            }

            $sums = ['completed' => 0, 'failed' => 0, 'runtime_ms_sum' => 0, 'runtime_count' => 0];

            foreach ($window->minutes() as $minute) {
                foreach ($sums as $field => $sum) {
                    $sums[$field] = $sum + ($window->value($minute, $series, $field) ?? 0);
                }
            }

            $classes[] = [
                'name' => $name,
                'completed' => $sums['completed'],
                'failed' => $sums['failed'],
                'pushed' => $arrivalsKnown ? $window->sum($series, 'pushed') : null,
                'runtime_avg_ms' => $sums['runtime_count'] === 0 ? null : (float) $sums['runtime_ms_sum'] / $sums['runtime_count'],
                'complete' => $unknown === [],
            ];
        }

        // strcmp(), not <=>: two numeric-string names would compare as numbers.
        usort($classes, static fn (array $a, array $b): int => ($b['completed'] + $b['failed']) <=> ($a['completed'] + $a['failed']) ?: strcmp($a['name'], $b['name']));

        return new JsonResponse([
            'as_of' => $now,
            'from' => $from,
            'to' => $now,
            'retention_from' => $this->retentionFrom($window, $oldest, $counts, $now),
            'classes' => array_slice($classes, 0, self::CLASS_LIMIT),
            'truncated' => count($classes) > self::CLASS_LIMIT,
            'unknown_minutes' => $unknown === [] ? null : ['count' => count($unknown), 'first' => $unknown[0], 'last' => $unknown[count($unknown) - 1]],
        ], 200, [], self::JSON);
    }

    public function series(Request $request, SampleCoverage $coverage, SampleCounts $counts, PackageSession $sessions, Instrumentation $instrumentation): JsonResponse
    {
        if (($tooLong = self::tooLong($request)) !== null) {
            return $tooLong;
        }

        $minutes = self::minutes($request->query('minutes'), self::MAX_SERIES_MINUTES);

        if ($minutes === null) {
            return self::invalid('minutes', sprintf('minutes is an integer from %d to %d.', self::MIN_MINUTES, self::MAX_SERIES_MINUTES));
        }

        $name = self::name($request);

        if ($name === null) {
            return self::invalid('name', sprintf('name is the job class: valid UTF-8 without NUL, at most %d characters.', self::NAME_MAX_CHARACTERS));
        }

        [$now, $from, $current] = self::window($minutes);
        $read = $this->read($coverage, $sessions, $name, $from, $current + SampleCounts::MINUTE);

        if ($read instanceof JsonResponse) {
            return $read;
        }

        [$window, $oldest] = $read;
        $arrivalsKnown = self::arrivalsKnown($instrumentation);
        $series = SampleCounts::series('job', $name);
        $buckets = [];

        foreach ($window->minutes() as $at) {
            $completed = $window->value($at, $series, 'completed');
            $failed = $window->value($at, $series, 'failed');
            $pushed = $arrivalsKnown ? $window->value($at, $series, 'pushed') : null;

            if ($completed === null && $failed === null && $pushed === null) {
                continue;
            }

            $runtimeCount = $window->value($at, $series, 'runtime_count');

            $buckets[] = [
                'at' => $at,
                'completed' => $completed,
                'failed' => $failed,
                'pushed' => $pushed,
                'runtime_avg_ms' => $runtimeCount === null || $runtimeCount === 0 ? null : (float) $window->value($at, $series, 'runtime_ms_sum') / $runtimeCount,
                'partial' => $at === $current,
            ];
        }

        return new JsonResponse([
            'name' => $name,
            'as_of' => $now,
            'from' => $from,
            'to' => $now,
            'bucket_seconds' => SampleCounts::MINUTE,
            'retention_from' => $this->retentionFrom($window, $oldest, $counts, $now),
            'buckets' => $buckets,
        ], 200, [], self::JSON);
    }

    /**
     * The window and the oldest `total` row, read in the watcher on a
     * bounded mirror session; a 503 response when the read failed.
     *
     * @return array{0: SampleWindow, 1: ?int}|JsonResponse
     */
    private function read(SampleCoverage $coverage, PackageSession $sessions, ?string $name, int $from, int $to): array|JsonResponse
    {
        $result = Watcher::instance()->attempt(self::READ_OPERATION, static fn (): array => $sessions->bounded(
            $sessions->mirror(),
            static fn (): array => [
                $coverage->of('job', $name === null ? null : ['name' => $name], $from, $to, SampleWindow::KINDS),
                $coverage->oldestTotal(),
            ],
        ));

        if ($result->succeeded()) {
            /** @var array{0: SampleWindow, 1: ?int} */
            return $result->value();
        }

        $timeout = self::isTimeout($result->error());

        return new JsonResponse([
            'error' => $timeout ? 'read_timeout' : 'read_failed',
            'message' => $timeout ? 'The job classes could not be read in time.' : 'The job classes could not be read.',
        ], 503, [], self::JSON);
    }

    /**
     * Whether the arrivals of the job classes can be known: a
     * `job` row sums every connection, and an uninstrumented connection
     * mirrors only outcomes, so a job that waited longer than the recount
     * window there is never counted as an arrival. With such a connection
     * (or one whose state is unknown), `pushed` is null, as `in_per_min` is
     * in /api/workload.
     */
    private static function arrivalsKnown(Instrumentation $instrumentation): bool
    {
        return $instrumentation->uninstrumented() === [];
    }

    private static function isTimeout(?Throwable $error): bool
    {
        if ($error === null) {
            return false;
        }

        $cause = SafeError::cause($error);

        return $cause['sqlState'] === self::TIMEOUT_STATE || in_array($cause['driverCode'], self::TIMEOUT_CODES, true);
    }

    /**
     * Per kind: the earlier of the oldest `total` row and the time from
     * which that kind is scanned, never before the samples' retention.
     *
     * @return array{completed: float, failed: float, pushed: float}
     */
    private function retentionFrom(SampleWindow $window, ?int $oldest, SampleCounts $counts, float $now): array
    {
        $floor = $now - $counts->retentionHours() * 3600;
        $stored = $oldest === null ? INF : (float) $oldest;
        $retention = [];

        foreach (SampleWindow::KINDS as $kind) {
            $retention[$kind] = (float) max($floor, min($stored, $window->scannedFrom[$kind] ?? INF));
        }

        return $retention;
    }

    /**
     * The request time, the first minute of the window and the current minute.
     *
     * @return array{0: float, 1: int, 2: int}
     */
    private static function window(int $minutes): array
    {
        $now = Clock::now();
        $current = SampleCounts::minuteOf($now);

        return [$now, $current - ($minutes - 1) * SampleCounts::MINUTE, $current];
    }

    private static function minutes(mixed $value, int $max): ?int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_MINUTES;
        }

        if (! is_string($value) || preg_match('/^\d{1,4}$/D', $value) !== 1) {
            return null;
        }

        $minutes = (int) $value;

        return $minutes >= self::MIN_MINUTES && $minutes <= $max ? $minutes : null;
    }

    /**
     * The 422 of a query that PHP would cut at max_input_vars, or null. The
     * actions read `minutes` from the parsed query (a bounded integer, the
     * same after TrimStrings), so only the raw parse sees the cut.
     */
    private static function tooLong(Request $request): ?JsonResponse
    {
        try {
            RawQuery::of($request);
        } catch (QueryTooLong $tooLong) {
            return $tooLong->response();
        }

        return null;
    }

    /**
     * The `name` of the raw query string (a host middleware may trim or null
     * the parsed query), or null when it is absent, not a string, not valid
     * UTF-8, holds a NUL byte or is too long.
     */
    private static function name(Request $request): ?string
    {
        // RawQuery: PHP's query parser warns past max_input_vars, and Laravel turns the warning into an error.
        // The action refused a query past the limit before (tooLong()).
        $name = RawQuery::of($request)['name'] ?? null;

        if (! is_string($name) || ! mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")) {
            return null;
        }

        return mb_strlen($name, 'UTF-8') <= self::NAME_MAX_CHARACTERS ? $name : null;
    }

    private static function invalid(string $field, string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_parameter', 'message' => $message, 'field' => $field], 422, [], self::JSON);
    }
}
