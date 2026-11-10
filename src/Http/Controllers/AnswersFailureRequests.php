<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Justrau\Orrery\Failures\FailureScope;
use Justrau\Orrery\Failures\InvalidFailureRequest;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Maintenance\RetentionPolicy;

/**
 * The shared parts of the failure-group controllers: the scope, the
 * retention horizon, the 422 body, the 500 body of an invalid redaction
 * configuration and the scrubbed JSON.
 */
trait AnswersFailureRequests
{
    /**
     * @throws InvalidFailureRequest
     */
    protected static function scopeFrom(mixed $queue, mixed $from, mixed $to): FailureScope
    {
        if ($from === null || $to === null) {
            throw new InvalidFailureRequest('from and to are required.');
        }

        return FailureScope::from($queue, $from, $to);
    }

    /**
     * as_of - the shortest `failed` retention over `orrery.trim.failed` and
     * every `orrery.trim_overrides` entry (RetentionPolicy::shortest()):
     * failed rows older than this may be gone in some queue.
     * Null when no value is valid by RetentionPolicy's rule (a whole number
     * of minutes, 0 or more): then the retention deletes nothing.
     */
    protected static function retentionFrom(float $asOf): ?float
    {
        $minutes = RetentionPolicy::shortest('failed');

        return $minutes === null ? null : $asOf - $minutes * 60;
    }

    protected static function invalid(InvalidFailureRequest $error): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_request', 'message' => $error->getMessage()], 422);
    }

    /**
     * An invalid `orrery.redact` rule: no job data is returned.
     * The body names the rule's position, never its text.
     */
    protected static function redactionInvalid(RedactionConfigInvalid $error): JsonResponse
    {
        return new JsonResponse($error->body(), 500);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected static function json(array $body): JsonResponse
    {
        return new JsonResponse($body, 200, [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
