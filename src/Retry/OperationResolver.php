<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Contracts\Foundation\Application;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use stdClass;
use Throwable;

/**
 * The operator's resolve of one operation:
 * `POST /api/operations/{id}/resolve`, `orrery:resolve
 * {id}` and `--all`, and a later retry or forget of an occurrence that an
 * operation still holds.
 *
 * resolve() runs the pending step of the operation inside an operator
 * action (PackageSession::operator()), so a step that writes a host SQLite
 * file runs (D17), whatever next_check_at and the awaiting fields say. It
 * applies the step as the runner does: later reschedules without a check,
 * an undecided or blocked step counts a check with the backoff and keeps its
 * code, an awaiting step marks the operation. A step whose fingerprint check
 * fails changes nothing (target_changed), so the resolve refuses while the
 * targets differ. A claimed retry before its deadline is still in progress.
 */
final class OperationResolver
{
    public function __construct(
        private readonly Application $app,
        private readonly PackageSession $sessions,
        private readonly Operations $operations,
    ) {}

    /**
     * The operation after the step, with the step's kind and code; null when
     * no operation has the id.
     *
     * @return array{id: string, action: string, state: string, step: string, code: string|null, error_code: string|null, awaiting_reason: string|null}|null
     */
    public function resolve(string $id): ?array
    {
        return $this->sessions->operator(function () use ($id): ?array {
            $mirror = $this->sessions->mirror();
            $operation = $this->operations->find($mirror, $id);

            if ($operation === null) {
                return null;
            }

            if (in_array($operation->state, Operations::TERMINAL, true)) {
                return self::snapshot($operation, 'terminal', null);
            }

            $handler = $this->handlers()[(string) $operation->action] ?? null;

            if ($handler === null) {
                return self::snapshot($operation, OperationStep::UNDECIDED, 'handler_missing');
            }

            $now = DatabaseClock::now($mirror);
            $step = $handler->step($operation, new OperationContext($now, new TickBudget(Clock::monotonic() + OperationRunner::CAP_SECONDS), $this->sessions));
            $mirror = $this->sessions->mirror();

            switch ($step->kind) {
                case OperationStep::LATER:
                    $this->operations->reschedule($mirror, $id, (float) $step->nextCheckAt, false);

                    break;
                case OperationStep::AWAITING:
                    $this->operations->await($mirror, $id, (string) $step->reason, $now);

                    break;
                case OperationStep::UNDECIDED:
                case OperationStep::BLOCKED:
                    $this->operations->reschedule($mirror, $id, $now + OperationRunner::backoff((int) $operation->checks + 1), true, $step->errorCode, $step->error);

                    break;
            }

            $after = $this->operations->find($mirror, $id) ?? $operation;

            return self::snapshot($after, $step->kind, $step->errorCode ?? $step->reason);
        });
    }

    /**
     * The pending step of the operation that holds a row, before a later
     * retry or forget of the same occurrence ("a later retry or
     * forget of the same occurrence, which first runs the pending step of the
     * operation that holds the row ... and then continues when the row is
     * free"). It runs resolve(), so the step has the same target and
     * authorisation checks; an error of the step is reported, never thrown
     * into the caller, which then reads the row again.
     */
    public function runPending(string $id): void
    {
        try {
            $this->resolve($id);
        } catch (Throwable $error) {
            Watcher::instance()->report('operations:check', $error);
        }
    }

    /**
     * The ids of the operations that wait for an operator, oldest first, at
     * most $limit.
     *
     * @return list<string>
     */
    public function awaiting(int $limit = 1000): array
    {
        $ids = $this->sessions->mirror()->table(Operations::TABLE)
            ->whereIn('state', Operations::ACTIVE)
            ->whereNotNull('awaiting_since')
            ->orderBy('awaiting_since')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }

    /**
     * @return array{id: string, action: string, state: string, step: string, code: string|null, error_code: string|null, awaiting_reason: string|null}
     */
    private static function snapshot(stdClass $operation, string $step, ?string $code): array
    {
        return [
            'id' => (string) $operation->id,
            'action' => (string) $operation->action,
            'state' => (string) $operation->state,
            'step' => $step,
            'code' => $code,
            'error_code' => $operation->error_code === null ? null : (string) $operation->error_code,
            'awaiting_reason' => $operation->awaiting_reason === null ? null : (string) $operation->awaiting_reason,
        ];
    }

    /**
     * @return array<string, OperationHandler>
     */
    private function handlers(): array
    {
        $handlers = [];

        foreach ($this->app->tagged(OperationRunner::HANDLERS) as $handler) {
            if ($handler instanceof OperationHandler) {
                $handlers[$handler->action()] = $handler;
            }
        }

        return $handlers;
    }
}
