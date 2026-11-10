<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Actions\ActionRefused;
use Justrau\Orrery\Actions\DelayedJobs;
use Justrau\Orrery\Actions\OperatorAudit;
use Justrau\Orrery\Actions\WaitingJobs;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Throwable;

/**
 * **POST /api/jobs/{uuid}/run-now**: a delayed job becomes available now
 * (Actions\DelayedJobs::runNow(), inside one operator action, D17), and the
 * action is recorded (Actions\OperatorAudit, with the request's actor).
 *
 * 200 `{id, result}`, where `result` is `available`, `not_delayed`,
 * `not_pending`, `gone` or `job_id_unknown` (an unresolved bulk row: try
 * again in a minute). 404 `not_found` without a mirror row; 409
 * `not_database_queue` when the job's connection is no longer a database
 * queue; 503 `write_failed` when the mirror row or the queue connection
 * fails. No answer carries job text.
 */
class DelayedJobsController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly DelayedJobs $jobs,
        private readonly OperatorAudit $audit,
    ) {
        parent::__construct();
    }

    public function runNow(Request $request, string $uuid): JsonResponse
    {
        $uuid = strtolower($uuid);
        $actor = Orrery::actor($request);

        try {
            $outcome = PackageSession::asOperator(fn (): array => $this->jobs->runNow($uuid));
        } catch (ActionRefused $refused) {
            return new JsonResponse(['error' => $refused->reason, 'message' => $refused->getMessage()], 409, [], self::JSON);
        } catch (Throwable $error) {
            Watcher::instance()->report('http:job_run_now', $error);

            return new JsonResponse(['error' => 'write_failed', 'message' => 'The change may not have been made. Reload to see the current state.'], 503, [], self::JSON);
        }

        if ($outcome['result'] === WaitingJobs::NOT_FOUND) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Not found.'], 404, [], self::JSON);
        }

        $this->audit->record('run_now', ['uuid' => $uuid, 'connection' => $outcome['connection'], 'queue' => $outcome['queue']], $actor, $outcome['result']);

        return new JsonResponse(['id' => $uuid, 'result' => $outcome['result']], 200, [], self::JSON);
    }
}
