<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Throwable;

/**
 * The per-job writes.
 *
 * - POST /api/jobs/{uuid}/retry `{"occurrence_id": n}`: RetryService::retry()
 *   of exactly that occurrence (source `dashboard`, the request's actor). It
 *   never substitutes the current occurrence: a stale page whose occurrence
 *   was replaced gets `not_eligible` / `occurrence_mismatch`.
 * - POST /api/jobs/{uuid}/forget `{"occurrence_id": n}`:
 *   ForgetService::forget() of that occurrence.
 *
 * Both run inside one operator action and answer 200 with the service's
 * result item `{id, occurrence_id, result, code, message, operation_id,
 * cycle, job_id, awaiting_reason}`: ids, the result, its code and the
 * service's operator text, never job text. The SPA reads `result`, not the
 * status code.
 *
 * - 422 `invalid` (with `field`) for a body without a whole occurrence_id
 *   of 1 or more.
 * - A uuid without a mirror row: a forget whose `done` forget operation of
 *   that occurrence is in the ledger answers 200 `already_done` (a replay
 *   after a lost response); anything else answers 404 `not_found`. When the
 *   mirror cannot be read, the service runs and answers its own refusal.
 * - Neither inserts a bulk_ref, so both work on a schema before step 8.
 */
class JobWriteController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function retry(Request $request, string $uuid, RetryService $retries): JsonResponse
    {
        $uuid = strtolower($uuid);
        $occurrence = self::occurrence($request);

        if ($occurrence === null) {
            return self::invalidOccurrence();
        }

        if ($this->exists($uuid) === false) {
            return self::missing();
        }

        $actor = Orrery::actor($request);
        $result = PackageSession::asOperator(static fn (): RetryResult => $retries->retry($uuid, $occurrence, 'dashboard', $actor));

        return self::item($uuid, $occurrence, $result->result, $result->code, $result->message, $result->operationId, $result->cycle, $result->jobId, $result->awaitingReason);
    }

    public function forget(Request $request, string $uuid, ForgetService $forgets): JsonResponse
    {
        $uuid = strtolower($uuid);
        $occurrence = self::occurrence($request);

        if ($occurrence === null) {
            return self::invalidOccurrence();
        }

        if ($this->exists($uuid) === false) {
            try {
                $forgotten = $forgets->forgotten($uuid, $occurrence);
            } catch (Throwable $error) {
                Watcher::instance()->report('http:job_forget', $error);
                $forgotten = false;
            }

            // A replay after a lost response: the done forget of this occurrence is the proof.
            return $forgotten ? self::item($uuid, $occurrence, 'already_done', null, null, null, null, null, null) : self::missing();
        }

        $actor = Orrery::actor($request);
        $result = PackageSession::asOperator(static fn (): ForgetResult => $forgets->forget($uuid, $occurrence, $actor, 'dashboard'));

        return self::item($uuid, $occurrence, $result->result, $result->code, $result->message, $result->operationId, null, null, null);
    }

    /**
     * Whether the mirror has a row of the uuid; null when it cannot be read
     * (the service then answers its own refusal).
     */
    private function exists(string $uuid): ?bool
    {
        try {
            return app(PackageSession::class)->mirror()->table(JobStore::TABLE)->where('uuid', $uuid)->exists();
        } catch (Throwable $error) {
            Watcher::instance()->report('http:job_write', $error);

            return null;
        }
    }

    /**
     * The body's occurrence_id: a whole number of 1 or more, else null.
     */
    private static function occurrence(Request $request): ?int
    {
        $body = json_decode((string) $request->getContent(), true, 8);
        $occurrence = is_array($body) ? ($body['occurrence_id'] ?? null) : null;

        return is_int($occurrence) && $occurrence >= 1 ? $occurrence : null;
    }

    private static function item(string $uuid, int $occurrence, string $result, ?string $code, ?string $message, ?string $operation, ?int $cycle, ?int $jobId, ?string $awaiting): JsonResponse
    {
        return new JsonResponse([
            'id' => $uuid,
            'occurrence_id' => $occurrence,
            'result' => $result,
            'code' => $code,
            'message' => $message,
            'operation_id' => $operation,
            'cycle' => $cycle,
            'job_id' => $jobId,
            'awaiting_reason' => $awaiting,
        ], 200, [], self::JSON);
    }

    private static function invalidOccurrence(): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid', 'message' => 'occurrence_id is a whole number of 1 or more.', 'field' => 'occurrence_id'], 422, [], self::JSON);
    }

    private static function missing(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found', 'message' => 'Not found.'], 404, [], self::JSON);
    }
}
