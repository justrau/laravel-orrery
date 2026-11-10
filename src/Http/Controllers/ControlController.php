<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Justrau\Orrery\Control\ControlTarget;
use Justrau\Orrery\Control\QueuePauseRecordFailed;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Monitoring\NativePause;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

class ControlController extends Controller
{
    public function __construct(protected SignalState $signals)
    {
        parent::__construct();
    }

    /**
     * Every signal row. A queue annotation (a pause row of scope `queue`)
     * is display-only: `effective` is Laravel's own flag of
     * that queue (NativePause, the rule of the workload): true, false (a
     * native queue:continue ended it), or null when unknown. Other rows
     * have no `effective`. A failed read of the signals answers a JSON 503.
     *
     * @return array<int, array<string, mixed>>|JsonResponse
     */
    public function index(NativePause $native): array|JsonResponse
    {
        $signals = $this->signals;
        $rows = self::watchedRead('http:control', static fn (): array => $signals->all(), 'The control signals');

        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        $queues = [];

        foreach ($rows as $row) {
            if ($row['signal'] === SignalState::PAUSE && $row['scope'] === 'queue') {
                $parts = explode(':', (string) $row['scope_value'], 2);
                $queues[] = [$parts[0], $parts[1] ?? ''];
            }
        }

        $states = $queues === [] ? [] : $native->read($queues);

        foreach ($rows as $index => $row) {
            if ($row['signal'] === SignalState::PAUSE && $row['scope'] === 'queue') {
                $parts = explode(':', (string) $row['scope_value'], 2);
                $rows[$index]['effective'] = $states[NativePause::key($parts[0], $parts[1] ?? '')]['queue'] ?? null;
            }
        }

        return $rows;
    }

    /**
     * Body: {action: 'pause'|'continue'|'terminate', scope?: 'global'|'connection'|'queue', connection?, queue?}
     * Default scope is 'global', and a body without a scope may name no
     * connection and no queue (else 422). The JSON body is read raw, so the
     * host's TrimStrings never changes a name. The target must be a `database` queue
     * connection, and its scope value must fit 191 characters
     * (ControlTarget); else 422. A continue adds `removed`,
     * `still_paused_by` and `still_paused_unknown` (ContinueResult). A
     * failed write is reported through the watcher and answers 503
     * `write_failed`; a queue pause or continue whose Laravel part happened
     * before the package record failed answers 503 `record_failed` with
     * `paused` (the queue's state now) and a message for the dashboard that
     * says so (not the command line's message of QueuePauseRecordFailed).
     *
     * @return array<string, mixed>|JsonResponse
     */
    public function store(Request $request): array|JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true, 8);
        $validated = Validator::make(is_array($body) ? $body : $request->all(), [
            'action' => 'required|in:pause,continue,terminate',
            'scope' => 'nullable|in:global,connection,queue',
            'connection' => 'nullable|string|max:64',
            'queue' => 'nullable|string|max:191',
        ])->validate();

        if (! isset($validated['scope']) && (isset($validated['connection']) || isset($validated['queue']))) {
            throw ValidationException::withMessages(['scope' => 'A connection or a queue needs its scope.']);
        }

        $action = (string) $validated['action'];
        $scope = (string) ($validated['scope'] ?? 'global');
        $connection = isset($validated['connection']) ? (string) $validated['connection'] : null;
        $queue = isset($validated['queue']) ? (string) $validated['queue'] : null;

        try {
            $target = ControlTarget::resolve($scope, $connection, $queue);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages([$error->getCode() === ControlTarget::REFUSED_CONNECTION ? 'connection' : 'queue' => $error->getMessage()]);
        }

        $value = $target->value;

        // The actor: resolved here, never by a worker's signal read.
        $actor = Orrery::actor($request);

        $result = [];

        try {
            if ($action === 'pause') {
                $this->signals->pause($scope, $value, $actor);
            } elseif ($action === 'continue') {
                // The lifted pause and every cause that still pauses the target.
                $result = $this->signals->continue($scope, $value)->toArray();
            } elseif ($action === 'terminate') {
                $this->signals->terminate($scope, $value, issuedBy: $actor);
            }
        } catch (QueuePauseRecordFailed $error) {
            // Laravel's part happened: the message says what holds now.
            Watcher::instance()->report('http:control_write', $error->getPrevious() ?? $error);

            // The exception's message is the command line's ("Run the command again"); the dashboard has its own.
            $message = $error->paused
                ? "The queue is paused through Laravel's queue pause, but Orrery could not save its record of the pause (who paused it). Reload to see the current state."
                : "Laravel's queue pause was lifted, but Orrery could not update its record or read the other pauses, so another pause may still hold the queue. Reload to see the current state.";

            return new JsonResponse(['error' => 'record_failed', 'paused' => $error->paused, 'message' => $message], 503, [], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            // Reported without its SQL and values (SafeError), never rethrown as a 500.
            Watcher::instance()->report('http:control_write', $error);

            return new JsonResponse(['error' => 'write_failed', 'message' => 'The change may not have been saved. Reload to see the current state; trying again is safe.'], 503, [], JSON_UNESCAPED_UNICODE);
        }

        return [
            'action' => $action,
            'scope' => $scope,
            'value' => $value,
            'ok' => true,
        ] + $result;
    }
}
