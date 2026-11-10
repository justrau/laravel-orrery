<?php

namespace Justrau\Orrery\Queue;

/**
 * The open captures of the running bulk() calls (BulkCapture): unit-of-work
 * state, a scoped binding. A capture is open only during its
 * bulk() call, so no scoped flush (a worker loop, an Octane request) falls
 * inside it.
 */
final class OpenBulkCaptures
{
    /** @var list<BulkCapture|null> the open captures, innermost last; null is a suspension (pushToDatabase()) */
    public array $entries = [];
}
