<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use Justrau\Orrery\Http\Tags;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

/**
 * The tags API (Http\Tags):
 *
 * - `GET /api/tags`: `{tags, truncated, lookup}`. `tags` holds at most 100
 *   monitored tags, each `{tag, count: {count, at_least}, failed: {count,
 *   at_least}}` with counts capped at 1,000. With `?tag=`, `lookup` is
 *   `{tag, monitored}` for the stored form of that tag (the tag view's
 *   monitor state, also for a tag beyond the first 100; `monitored` is
 *   null when that read failed); else null.
 * - `POST /api/tags/monitored` `{tag}` (read from the raw JSON body),
 *   `DELETE /api/tags/monitored/{tag}` and `DELETE
 *   /api/tags/monitored?tag=` (for `.`, `..` and `/`): synchronous; the change is committed when
 *   the response returns. 200 `{tag, monitored, changed}` with the stored
 *   form of the tag. The three write routes need the operate ability
 *   (Authenticate, by method).
 *
 * Errors, each `{error, message}`: 422 `invalid` with `field: tag` for an
 * empty, blank, non-string or invalid UTF-8 tag, or one with a NUL byte; 503 `read_timeout` or
 * `read_failed` when the tag list or the lookup cannot be read; 503
 * `write_failed` when a monitor or unmonitor fails. The error is reported
 * through the watcher, and no response repeats a value.
 */
class TagsController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function index(Request $request, Tags $tags): JsonResponse
    {
        // The raw query string, as the Jobs list reads it: an array value (`tag[]=x`) stays an array.
        try {
            $query = RawQuery::of($request);
        } catch (QueryTooLong $tooLong) {
            return $tooLong->response();
        }

        $lookup = null;

        if (array_key_exists('tag', $query)) {
            $lookup = Tags::normalize($query['tag']);

            if ($lookup === null) {
                return self::invalid();
            }
        }

        try {
            $response = $tags->monitored();
            $response['lookup'] = $lookup === null ? null : ['tag' => $lookup, 'monitored' => $tags->isMonitored($lookup)];

            return new JsonResponse($response, 200, [], self::JSON);
        } catch (Throwable $error) {
            Watcher::instance()->report('http:tags', $error);

            if (JobList::isTimeout($error)) {
                return new JsonResponse(['error' => 'read_timeout', 'message' => 'The monitored tags took too long to read.'], 503, [], self::JSON);
            }

            return new JsonResponse(['error' => 'read_failed', 'message' => 'The monitored tags could not be read.'], 503, [], self::JSON);
        }
    }

    public function store(Request $request, Tags $tags): JsonResponse
    {
        // The raw JSON body: the application's TrimStrings middleware strips non-ASCII spaces
        // (NBSP, U+3000) that the TagNormalizer keeps, so $request->input() could name another tag.
        $body = json_decode((string) $request->getContent(), true);
        $tag = Tags::normalize(is_array($body) && array_key_exists('tag', $body) ? $body['tag'] : null);

        return $tag === null ? self::invalid() : self::change($tag, true, static fn (): bool => $tags->monitor($tag));
    }

    public function destroy(string $tag, Tags $tags): JsonResponse
    {
        return self::unmonitor(Tags::normalize($tag), $tags);
    }

    /**
     * `DELETE /api/tags/monitored?tag=`: the same for a tag that no path can
     * carry (`.` and `..`, which clients resolve, and `/` where the server
     * refuses `%2F`), read from the raw query string.
     */
    public function destroyByQuery(Request $request, Tags $tags): JsonResponse
    {
        try {
            $query = RawQuery::of($request);
        } catch (QueryTooLong $tooLong) {
            return $tooLong->response();
        }

        return self::unmonitor(Tags::normalize($query['tag'] ?? null), $tags);
    }

    private static function unmonitor(?string $stored, Tags $tags): JsonResponse
    {
        return $stored === null ? self::invalid() : self::change($stored, false, static fn (): bool => $tags->unmonitor($stored));
    }

    /**
     * @param  callable(): bool  $change
     */
    private static function change(string $tag, bool $monitored, callable $change): JsonResponse
    {
        try {
            $changed = $change();
        } catch (Throwable $error) {
            Watcher::instance()->report($monitored ? 'http:tags_monitor' : 'http:tags_unmonitor', $error);

            return new JsonResponse(['error' => 'write_failed', 'message' => 'The change may not have been saved. Reload to see the current state; trying again is safe.'], 503, [], self::JSON);
        }

        return new JsonResponse(['tag' => $tag, 'monitored' => $monitored, 'changed' => $changed], 200, [], self::JSON);
    }

    private static function invalid(): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid', 'message' => 'The tag must be a non-empty text.', 'field' => 'tag'], 422, [], self::JSON);
    }
}
