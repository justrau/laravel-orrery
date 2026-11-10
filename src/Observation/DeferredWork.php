<?php

namespace Justrau\Orrery\Observation;

use Closure;

/**
 * The work that a hook defers (CommitDeferral::around() and after()): the
 * run, its byte estimate, the jobs that it observes, and what it frees
 * when the deferral holds it.
 *
 * A held item keeps only what its run needs later: $compact frees the rest
 * (the decoded payload copies) at the moment the deferral holds the item.
 * An item that runs at once is never compacted, so its run reads the copy
 * that the build decoded.
 */
final class DeferredWork
{
    /**
     * @param  Closure(): mixed  $run
     * @param  int  $bytes  the byte estimate of the held item (CommitDeferral::estimate(), bulkEstimate())
     * @param  int  $jobs  the jobs that the run observes (a bulk insert: each of its jobs)
     * @param  (Closure(): void)|null  $compact
     */
    public function __construct(
        public readonly Closure $run,
        public readonly int $bytes = 0,
        public readonly int $jobs = 1,
        public readonly ?Closure $compact = null,
    ) {}
}
