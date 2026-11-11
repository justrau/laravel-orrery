<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use Justrau\Orrery\Http\Suggestions;

/**
 * GET /api/suggestions?field=class|tag&q=… (the suggestions of the Jobs
 * filter bar's Class and Tag fields; Http\Suggestions documents the reads).
 *
 * - `field` is `class` or `tag`; `q` is optional (empty: the newest
 *   values), a text of valid UTF-8 without NUL, at most
 *   Suggestions::MAX_QUERY characters. Both are read from the raw query
 *   string, so no host middleware trims them. Any other value gives 422
 *   `{error: "invalid_parameter", message, field}`.
 * - 200 `{field, q, values: [{value, monitored}], truncated, limited}`:
 *   at most Suggestions::MAX_VALUES values (`monitored` is true for a
 *   monitored tag, else false); `truncated` when more matched; `limited`
 *   when a read hit its bound, so older values may be missing. The list is
 *   a hint for typing, never a complete list.
 * - `Cache-Control: private, max-age=30`: the browser reuses an answer
 *   while the user types the same text again.
 * - The read runs in the watcher on a bounded mirror session: a failure
 *   gives 503 `read_timeout` or `read_failed`.
 */
class SuggestionsController extends Controller
{
    public const FIELDS = ['class', 'tag'];

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public function index(Request $request, Suggestions $suggestions): JsonResponse
    {
        try {
            $query = RawQuery::of($request);
        } catch (QueryTooLong $tooLong) {
            return $tooLong->response();
        }

        $field = $query['field'] ?? null;

        if (! is_string($field) || ! in_array($field, self::FIELDS, true)) {
            return self::invalid('field', 'field is class or tag.');
        }

        $text = $query['q'] ?? '';

        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || mb_strlen($text) > Suggestions::MAX_QUERY) {
            return self::invalid('q', sprintf('q is a text of at most %d characters.', Suggestions::MAX_QUERY));
        }

        $read = self::watchedRead('http:suggestions', static fn (): array => $suggestions->find($field, $text), 'The suggestions');

        if ($read instanceof JsonResponse) {
            return $read;
        }

        return new JsonResponse(['field' => $field, 'q' => $text] + $read, 200, ['Cache-Control' => 'private, max-age=30'], self::JSON);
    }

    private static function invalid(string $field, string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_parameter', 'message' => $message, 'field' => $field], 422, [], self::JSON);
    }
}
