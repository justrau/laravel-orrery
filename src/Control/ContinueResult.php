<?php

namespace Justrau\Orrery\Control;

/**
 * The result of SignalState::continue().
 *
 * - `removed`: 1 when the continue lifted a pause of exactly its scope (the
 *   pause row, or for a queue its annotation or Laravel's own flag), else 0.
 * - `still_paused_by`: every other cause that still pauses the target, each
 *   `{scope, value, source}`: Orrery's global or connection pause (source
 *   `orrery`), Laravel's global pause (`queue:pause --all`) and, for a queue,
 *   Laravel's queue pause (`queue:pause`). A continue never lifts a broader
 *   pause, so the target runs only when the list is empty.
 * - `still_paused_unknown`: Laravel's pause state could not be read, so the
 *   list may miss a Laravel pause (unknown is never "running").
 * - `lift_unknown`: a queue continue deleted no annotation, and Laravel's own
 *   flag of the queue was hidden (by Laravel's global pause) or unreadable
 *   before resume() ran: resume() may have lifted it, so "nothing changed"
 *   would be false.
 */
final class ContinueResult
{
    /**
     * @param  list<array{scope: string, value: string, source: string}>  $stillPausedBy
     */
    public function __construct(
        public readonly int $removed,
        public readonly array $stillPausedBy,
        public readonly bool $stillPausedUnknown = false,
        public readonly bool $liftUnknown = false,
    ) {
    }

    /** Whether Laravel's global pause holds the target (it hides the queue's own flag). */
    public function underLaravelGlobalPause(): bool
    {
        foreach ($this->stillPausedBy as $cause) {
            if ($cause['source'] === PauseCauses::QUEUE_PAUSE_ALL) {
                return true;
            }
        }

        return false;
    }

    /**
     * The causes as the command output names them, for example "Laravel's
     * global pause (queue:continue --all)".
     *
     * @return list<string>
     */
    public function describeCauses(): array
    {
        return array_map(static fn (array $cause): string => match ($cause['source']) {
            PauseCauses::QUEUE_PAUSE_ALL => "Laravel's global pause (queue:continue --all)",
            PauseCauses::QUEUE_PAUSE => "Laravel's queue pause (queue:continue {$cause['value']})",
            default => $cause['scope'] === 'global'
                ? "Orrery's global pause (orrery:continue)"
                : "Orrery's pause of connection \"{$cause['value']}\" (orrery:continue --connection={$cause['value']})",
        }, $this->stillPausedBy);
    }

    /**
     * @return array{removed: int, still_paused_by: list<array{scope: string, value: string, source: string}>, still_paused_unknown: bool, lift_unknown: bool}
     */
    public function toArray(): array
    {
        return [
            'removed' => $this->removed,
            'still_paused_by' => $this->stillPausedBy,
            'still_paused_unknown' => $this->stillPausedUnknown,
            'lift_unknown' => $this->liftUnknown,
        ];
    }
}
