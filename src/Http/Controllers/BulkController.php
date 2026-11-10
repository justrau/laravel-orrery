<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Http\Bulk\BulkRefusal;
use Justrau\Orrery\Http\Bulk\BulkRun;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

/**
 * The bulk runs (Http\Bulk\BulkRun).
 *
 * - POST /api/bulk/freeze `{action, source}`: the signed freeze, the held
 *   count and a preview. It writes nothing, but it is a POST under the
 *   operate ability: only operators prepare an operation.
 * - POST /api/bulk/run `{token, after, confirmed_total}`: the next page. The
 *   token goes back exactly as the freeze gave it.
 * - GET /api/bulk: the RECENT most recent runs. GET /api/bulk/{ref}: one run
 *   with its counters and the ledger states of its operations. Both are
 *   viewer reads; they show the actor and the source.
 *
 * The bodies are read from the raw request content, so a byte-exact queue
 * ("high " with its space) reaches the source as sent, whatever the
 * application's TrimStrings middleware does to the parsed input.
 *
 * Errors: 422 or 503 `{error, message, …}` (BulkRefusal: `invalid` with
 * `field`, `frozen_invalid`, `frozen_expired`, `confirmation_mismatch`,
 * `frozen_mismatch`, `total_not_exact`, `too_many` with `maximum`,
 * `config_invalid`, `app_key_missing`, `schema_outdated`); 404
 * `not_found`; 500 `redaction_config_invalid` for a freeze while the
 * redaction configuration is invalid (its preview is job data; a run never
 * reads job text); 503 `read_timeout` or `read_failed` for a failed read,
 * reported through the watcher without a job value.
 */
class BulkController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function freeze(Request $request, BulkRun $runs): JsonResponse
    {
        return $this->answer('http:bulk_freeze', function () use ($request, $runs): array {
            $body = self::body($request);

            return $runs->freeze($body['action'] ?? null, $body['source'] ?? null);
        });
    }

    public function run(Request $request, BulkRun $runs): JsonResponse
    {
        return $this->answer('http:bulk_run', function () use ($request, $runs): array {
            $body = self::body($request);

            return $runs->run($body['token'] ?? null, $body['after'] ?? null, $body['confirmed_total'] ?? null, Orrery::actor($request));
        });
    }

    public function index(BulkRun $runs): JsonResponse
    {
        return $this->answer('http:bulk_runs', static fn (): array => ['runs' => $runs->recent()]);
    }

    public function show(string $ref, BulkRun $runs): JsonResponse
    {
        return $this->answer('http:bulk_run_show', static function () use ($ref, $runs): array|JsonResponse {
            $run = Operations::validRef($ref) ? $runs->show($ref) : null;

            return $run ?? new JsonResponse(['error' => 'not_found', 'message' => 'Not found.'], 404);
        });
    }

    /**
     * @param  \Closure(): (array<string, mixed>|JsonResponse)  $work
     */
    private function answer(string $operation, \Closure $work): JsonResponse
    {
        try {
            $answer = $work();

            return $answer instanceof JsonResponse ? $answer : new JsonResponse($answer, 200, [], self::JSON);
        } catch (BulkRefusal $refusal) {
            return new JsonResponse($refusal->body(), $refusal->status, [], self::JSON);
        } catch (RedactionConfigInvalid $invalid) {
            return new JsonResponse($invalid->body(), 500, [], self::JSON);
        } catch (Throwable $error) {
            Watcher::instance()->report($operation, $error);

            if (JobList::isTimeout($error)) {
                return new JsonResponse(['error' => 'read_timeout', 'message' => 'A read took too long. Narrow the selection.'], 503, [], self::JSON);
            }

            return new JsonResponse(['error' => 'read_failed', 'message' => "Orrery's storage could not be read. Try again."], 503, [], self::JSON);
        }
    }

    /**
     * The JSON object of the raw request content.
     *
     * @return array<array-key, mixed>
     *
     * @throws BulkRefusal
     */
    private static function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true, 64);

        if (! is_array($body) || (array_is_list($body) && $body !== [])) {
            throw BulkRefusal::invalid('body', 'The body is a JSON object.');
        }

        return $body;
    }
}
