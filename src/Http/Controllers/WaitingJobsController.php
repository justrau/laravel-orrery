<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Actions\ActionRefused;
use Justrau\Orrery\Actions\ClearResult;
use Justrau\Orrery\Actions\OperatorAudit;
use Justrau\Orrery\Actions\WaitingJobs;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Throwable;

/**
 * The writes on waiting jobs (Actions\WaitingJobs, the service of
 * orrery:clear). Each runs inside one operator action (D17), and each
 * write is recorded (Actions\OperatorAudit: the event
 * OperatorActionPerformed and one info log line, with the request's actor).
 *
 * - **POST /api/jobs/{uuid}/delete**: deletes one waiting job (the
 *   service's deleteOne()). 200 `{id, result}`, where `result` is
 *   `deleted`, `not_pending`, `gone`, `job_id_unknown` (an unresolved bulk
 *   row: try again in a minute) or `mirror_failed` (the job is deleted, its
 *   row here stays until reconciliation). 404 `not_found` without a mirror
 *   row; 409 `not_database_queue` when the job's connection is no longer a
 *   database queue; 503 `write_failed` when the mirror row or the queue
 *   connection fails (the outcome is then not known).
 * - **POST /api/queues/{connection}/{queue}/waiting**: a read-only POST
 *   (operators only, as it reads the host table in an operator action): the
 *   count of the clear's sheet, `{waiting, ready, delayed, in_batches}`;
 *   `in_batches` is null when the mirror cannot be read. 409
 *   `not_database_queue`; 503 `read_failed`.
 * - **POST /api/queues/{connection}/{queue}/clear**: clears the waiting
 *   jobs of the queue (the service's clear() with BUDGET_SECONDS) and
 *   answers 200 `{deleted, mirror_rows, unresolved_rows, undecodable,
 *   count_mismatches, mirror_failed, complete, reason}` (reason null,
 *   `probe_failed` or `time_budget`). 409 `in_transaction` when the queue
 *   connection is inside a transaction, 409 `not_database_queue`; 503
 *   `write_failed` on an error of the queue connection.
 *
 * `{connection}` is the queue connection (any alias: the mirror rows of
 * every alias go), `{queue}` the byte-exact queue name, "/" included.
 * No answer carries job text.
 */
class WaitingJobsController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly WaitingJobs $jobs,
        private readonly OperatorAudit $audit,
    ) {
        parent::__construct();
    }

    public function delete(Request $request, string $uuid): JsonResponse
    {
        $uuid = strtolower($uuid);
        $actor = Orrery::actor($request);

        try {
            $outcome = PackageSession::asOperator(fn (): array => $this->jobs->deleteOne($uuid));
        } catch (ActionRefused $refused) {
            return self::refused($refused);
        } catch (Throwable $error) {
            return self::failed('http:job_delete', $error);
        }

        if ($outcome['result'] === WaitingJobs::NOT_FOUND) {
            return self::json(['error' => 'not_found', 'message' => 'Not found.'], 404);
        }

        $this->audit->record('delete', ['uuid' => $uuid, 'connection' => $outcome['connection'], 'queue' => $outcome['queue'], 'batch_id' => $outcome['batch_id']], $actor, $outcome['result']);

        return self::json(['id' => $uuid, 'result' => $outcome['result']]);
    }

    public function waiting(string $connection, string $queue): JsonResponse
    {
        try {
            $count = PackageSession::asOperator(fn (): array => $this->jobs->count($connection, $queue));
        } catch (ActionRefused $refused) {
            return self::refused($refused);
        } catch (Throwable $error) {
            Watcher::instance()->report('http:queue_waiting', $error);

            return self::json(['error' => 'read_failed', 'message' => 'The waiting jobs could not be counted.'], 503);
        }

        return self::json(['waiting' => $count['ready'] + $count['delayed']] + $count);
    }

    public function clear(Request $request, string $connection, string $queue): JsonResponse
    {
        $actor = Orrery::actor($request);

        try {
            $result = PackageSession::asOperator(fn (): ClearResult => $this->jobs->clear($connection, $queue, WaitingJobs::BUDGET_SECONDS));
        } catch (ActionRefused $refused) {
            return self::refused($refused);
        } catch (Throwable $error) {
            return self::failed('http:queue_clear', $error);
        }

        $this->audit->record('clear', ['connection' => $connection, 'queue' => $queue], $actor, $result->complete ? 'done' : (string) $result->reason, $result->toArray());

        return self::json($result->toArray());
    }

    private static function refused(ActionRefused $refused): JsonResponse
    {
        return self::json(['error' => $refused->reason, 'message' => $refused->getMessage()], 409);
    }

    /**
     * A write whose outcome is not known: the error is reported without a
     * job value, and the answer is 503 `write_failed`.
     */
    private static function failed(string $operation, Throwable $error): JsonResponse
    {
        Watcher::instance()->report($operation, $error);

        return self::json(['error' => 'write_failed', 'message' => 'The change may not have been made. Reload to see the current state.'], 503);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, [], self::JSON);
    }
}
