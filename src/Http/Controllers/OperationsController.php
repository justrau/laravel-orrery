<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Justrau\Orrery\Retry\OperationResolver;
use Justrau\Orrery\Retry\OperationStep;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

/**
 * POST /api/operations/{id}/resolve: the operator's resolve
 * of one operation. It runs the operation's pending step inside an operator
 * action, so a step that writes a host SQLite file runs (D17). A write
 * route, so it needs the operate ability.
 *
 * - 200 with the operation's state after the step;
 * - 404 for an unknown id;
 * - 409 when the step refuses because a fingerprint differs
 *   (target_changed): restore the configuration first;
 * - 503 when the step itself fails (the operation keeps its state).
 *
 * Every error carries its code: `not_found`, `target_changed`,
 * `resolve_failed` (the step failed, with the operation, or the resolve
 * itself threw).
 */
class OperationsController extends Controller
{
    public function resolve(string $id, OperationResolver $resolver): JsonResponse
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) {
            return response()->json(['error' => 'not_found', 'message' => 'Not found.'], 404);
        }

        try {
            $result = $resolver->resolve($id);
        } catch (Throwable $error) {
            Watcher::instance()->report('operations:resolve', $error);

            return response()->json(['error' => 'resolve_failed', 'message' => 'The operation could not be resolved.'], 503);
        }

        if ($result === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Not found.'], 404);
        }

        if ($result['code'] === RetryHandler::TARGET_CHANGED) {
            return response()->json(['error' => 'target_changed', 'message' => 'The target of the operation changed since its claim; restore the configuration, then resolve it.', 'operation' => $result], 409);
        }

        $status = in_array($result['step'], [OperationStep::UNDECIDED, OperationStep::BLOCKED], true) ? 503 : 200;

        return response()->json($status === 503 ? ['error' => 'resolve_failed', 'operation' => $result] : ['operation' => $result], $status);
    }
}
