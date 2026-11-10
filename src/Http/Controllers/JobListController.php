<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Http\Lists\InvalidListRequest;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Lists\ListRequest;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

/**
 * GET /api/jobs: the Jobs list, through the list engine
 * (Http\Lists\JobList).
 *
 * The parameters come from the raw query string, so a byte-exact filter
 * ("emails " with its space) reaches the engine as sent, whatever the
 * application's TrimStrings middleware does to $request->query().
 *
 * - 422 `{error, message, field}` for an invalid parameter (`invalid`) or a
 *   cursor of another list (`cursor_mismatch`).
 * - 500 `redaction_config_invalid` while the redaction configuration is
 *   invalid: no job data is returned.
 * - 503 `read_timeout` when a read passes the package's statement timeout,
 *   503 `read_failed` for another read error (reported through the
 *   watcher, without a job value).
 */
class JobListController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public function index(Request $request, JobList $jobs): JsonResponse
    {
        try {
            // The raw query string: the byte-exact filters must not pass through the
            // application's TrimStrings or ConvertEmptyStringsToNull middleware.
            $list = ListRequest::from(RawQuery::of($request));
        } catch (InvalidListRequest $invalid) {
            return new JsonResponse($invalid->body(), 422, [], self::JSON);
        } catch (QueryTooLong $tooLong) {
            return $tooLong->response();
        }

        try {
            return new JsonResponse($jobs->page($list), 200, [], self::JSON);
        } catch (RedactionConfigInvalid $invalid) {
            return new JsonResponse($invalid->body(), 500, [], self::JSON);
        } catch (Throwable $error) {
            Watcher::instance()->report('http:jobs_list', $error);

            if (JobList::isTimeout($error)) {
                return new JsonResponse(['error' => 'read_timeout', 'message' => 'The list read took too long. Narrow the list with a time range or a queue.'], 503, [], self::JSON);
            }

            return new JsonResponse(['error' => 'read_failed', 'message' => 'The list could not be read.'], 503, [], self::JSON);
        }
    }
}
