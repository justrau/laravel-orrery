<?php

namespace Justrau\Orrery\Control;

use RuntimeException;
use Throwable;

/**
 * A queue pause or continue whose Laravel part happened, but whose Orrery
 * record failed after it: the queue IS paused (or resumed)
 * through Laravel's queue:pause state, and only the package's annotation
 * (the actor, the display row) or the read of the remaining causes is
 * missing. The message says so; the previous exception is the failure.
 */
final class QueuePauseRecordFailed extends RuntimeException
{
    public function __construct(public readonly bool $paused, string $label, Throwable $previous)
    {
        parent::__construct($paused
            ? "Laravel's queue pause of {$label} is set, so the queue is paused, but Orrery could not write its record of the pause (the actor). Run the command again to write it."
            : "Laravel's queue pause of {$label} was lifted, but Orrery could not update its record or read the other pauses. Check the dashboard, or run the command again.", 0, $previous);
    }
}
