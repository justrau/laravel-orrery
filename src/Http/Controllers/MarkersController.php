<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Markers\Markers;
use Justrau\Orrery\Storage\Clock;

/**
 * GET /api/markers?since=.
 *
 * - `since` is epoch seconds (decimals allowed). A bound earlier than
 *   now − 7 days (the marker retention) is raised to it, and the response's
 *   `since` is the bound actually used. Without it (absent or empty), the
 *   range starts 7 days before the request.
 * - `markers`: the markers with `at` >= since, newest first, at most 200.
 *   Each has `id`, `kind` (`restart` or `deploy`), `at` (epoch seconds),
 *   `label` and `source`. `truncated` is true when more markers match.
 * - `as_of` is the request time.
 * - A `since` that is not epoch seconds gives 422 JSON.
 */
class MarkersController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private const EPOCH = '/^\d{1,12}(\.\d{1,6})?$/D';

    public function index(Request $request, Markers $markers): JsonResponse
    {
        $now = Clock::now();
        $earliest = $now - Markers::RETENTION_SECONDS;
        $value = self::rawQuery($request, 'since');

        if ($value === null || $value === '') {
            $since = $earliest;
        } elseif (! is_string($value) || preg_match(self::EPOCH, $value) !== 1) {
            return $this->invalid('since is epoch seconds, for example 1790000000 or 1790000000.25.');
        } else {
            // The floor moves with the clock, so an earlier bound is raised to it, never refused.
            $since = max((float) $value, $earliest);
        }

        $read = self::watchedRead('http:markers', static fn (): array => $markers->since($since), 'The markers');

        if ($read instanceof JsonResponse) {
            return $read;
        }

        [$rows, $truncated] = $read;

        return new JsonResponse([
            'as_of' => $now,
            'since' => $since,
            'markers' => $rows,
            'truncated' => $truncated,
        ], 200, [], self::JSON);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_parameter', 'message' => $message], 422, [], self::JSON);
    }
}
