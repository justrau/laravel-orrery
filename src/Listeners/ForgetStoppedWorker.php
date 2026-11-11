<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\WatchesItself;
use Justrau\Orrery\Workers\Heartbeats;

/**
 * A worker that stops normally deletes its own heartbeat row, so it leaves
 * the Workers list at once instead of turning quiet (Heartbeats::forget():
 * one statement, inside the watcher, none when the process wrote no row).
 *
 * - WorkerStopping: a daemon that stops (queue:restart, --max-jobs,
 *   --max-time, --stop-when-empty, the memory limit, SIGTERM, a
 *   terminate signal of Orrery).
 * - CommandFinished of the outermost command: a --once worker, which raises
 *   no WorkerStopping. A command that a job calls inside the worker
 *   (Artisan::call()) is nested, so its CommandFinished deletes nothing.
 * - Not a queue:listen child: its parent starts the next child at once,
 *   and without the row the parent would leave the list between two
 *   children. LiveWorkers shows only the newest child of a parent.
 * - Not after JobTimedOut: Laravel kills the worker, and the job can stay
 *   reserved; the row then stays for the stuck-job rules (WorkerLiveness)
 *   until the heartbeat sweep.
 *
 * It watches itself (WatchesItself), so the command depth is counted also
 * while the breaker is open; the delete runs inside the watcher. None of
 * these events is on the job's hot path.
 */
class ForgetStoppedWorker implements WatchesItself
{
    /** Reached before the delete (a test hold point). */
    public const BEFORE_FORGET = 'heartbeat.before_forget';

    // Process state: a container singleton.

    /** The depth of the running console commands. */
    protected int $depth = 0;

    /** A job of this process timed out: Laravel kills the worker next. */
    protected bool $timedOut = false;

    public function handle(WorkerStopping|JobTimedOut|CommandStarting|CommandFinished $event): void
    {
        if ($event instanceof CommandStarting) {
            $this->depth++;

            return;
        }

        if ($event instanceof JobTimedOut) {
            $this->timedOut = true;

            return;
        }

        if ($event instanceof CommandFinished) {
            $this->depth = max(0, $this->depth - 1);

            if ($this->depth > 0) {
                return;
            }
        }

        if ($this->timedOut) {
            return;
        }

        $context = ProcessContext::current();

        if (! $context->isWorker() || $context->mode() === ProcessContext::LISTEN_CHILD) {
            return;
        }

        Hooks::reach(self::BEFORE_FORGET);
        Heartbeats::forget();
    }
}
