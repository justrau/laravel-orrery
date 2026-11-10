<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Http\Lists\IndexMissing;
use Justrau\Orrery\Http\Lists\InvalidListRequest;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Lists\StuckList;
use Justrau\Orrery\Http\Lists\StuckRequest;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

/**
 * GET /api/jobs/stuck?signal=&connection=&queue=&class=&cursor=&per_page=
 * The rows of one stuck signal, through StuckList, with the
 * list contract of 4.3 (StuckRequest has the parameters).
 *
 * The parameters come from the raw query string, so a byte-exact filter
 * reaches the list as sent, whatever the application's TrimStrings
 * middleware does to $request->query().
 *
 * - 422 `{error, message, field}` for an invalid parameter (`invalid`) or a
 *   cursor of another signal or filter (`cursor_mismatch`).
 * - 503 `{error: index_missing, message, index, hint}` while the signal's
 *   deferred index is missing; the hint is the build command.
 * - 500 `redaction_config_invalid` while the redaction configuration is
 *   invalid: no job data is returned.
 * - 503 `read_timeout` when a read passes the package's statement timeout,
 *   503 `read_failed` for another read error (reported through the
 *   watcher, without a job value).
 */
class StuckJobsController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function index(Request $request, StuckList $stuck): JsonResponse
    {
        try {
            $list = StuckRequest::from(RawQuery::of($request));
        } catch (InvalidListRequest $invalid) {
            return new JsonResponse($invalid->body(), 422, [], self::JSON);
        } catch (QueryTooLong $tooLong) {
            return $tooLong->response();
        }

        try {
            return new JsonResponse($stuck->page($list), 200, [], self::JSON);
        } catch (IndexMissing $missing) {
            return new JsonResponse(['error' => 'index_missing', 'message' => $missing->getMessage(), 'index' => $missing->index, 'hint' => DeferredIndexes::COMMAND], 503, [], self::JSON);
        } catch (RedactionConfigInvalid $invalid) {
            return new JsonResponse($invalid->body(), 500, [], self::JSON);
        } catch (Throwable $error) {
            Watcher::instance()->report('http:jobs_stuck', $error);

            if (JobList::isTimeout($error)) {
                return new JsonResponse(['error' => 'read_timeout', 'message' => 'The list read took too long. Narrow the list with a queue.'], 503, [], self::JSON);
            }

            return new JsonResponse(['error' => 'read_failed', 'message' => 'The list could not be read.'], 503, [], self::JSON);
        }
    }
}
