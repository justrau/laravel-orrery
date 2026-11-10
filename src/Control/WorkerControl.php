<?php

namespace Justrau\Orrery\Control;

use Illuminate\Container\Container;
use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Workers\Heartbeats;
use Throwable;

/**
 * The worker side of the control plane. It
 * applies only in worker processes (queue:work in argv,
 * ProcessContext::isWorker()).
 *
 * A queue pause is Laravel's own (QueueManager::pause()): Laravel's
 * Worker::getNextJob() skips a natively paused queue before it calls pop()
 * (on every supported Laravel), so neither check here reads a cache key for it. The
 * checks read only the global and connection pause rows and terminate.
 *
 * - The package's queue: DatabaseQueue::pop() asks popShouldSkip() for each
 *   queue. A set stop latch skips every queue; a terminate sets the latch;
 *   a global or connection pause skips the queue.
 * - The Looping listener asks loopShouldStop() with the loop's queue
 *   string for every queue object. A terminate of any subscribed queue sets
 *   the latch, also for the package's queue: Laravel never calls pop() for
 *   a natively paused queue. For a queue object that is not the package's
 *   (another connector won), pop() is Laravel's, so the loop
 *   also stops the whole worker under a global or connection pause, which
 *   covers every subscribed queue, so the stop is exact.
 *
 * The signal reads run inside the watcher (SignalState), so a mirror error
 * never reaches the worker.
 */
final class WorkerControl
{
    /**
     * True when pop() must not reserve on this queue.
     */
    public static function popShouldSkip(string $connection, string $queue): bool
    {
        $context = ProcessContext::current();

        if (! $context->isWorker()) {
            return false;
        }

        if (StopLatch::isSet()) {
            return true;
        }

        $signals = new SignalState;

        if ($signals->terminateIssuedAfter($connection, $queue, $context->controlStartedAt()) !== null) {
            StopLatch::stop();

            return true;
        }

        return $signals->connectionPaused($connection);
    }

    /**
     * True when the worker loop must not reserve in this iteration (the
     * Looping listener then returns false): a set latch or an applicable
     * terminate for every queue object, and, for a queue object that is not
     * the package's only, a global or connection pause. For the package's
     * queue, pop() decides the pause.
     */
    public static function loopShouldStop(string $connection, string $queues): bool
    {
        $context = ProcessContext::current();

        if (! $context->isWorker()) {
            return false;
        }

        if (StopLatch::isSet()) {
            return true;
        }

        $subscribed = $queues === '' ? Heartbeats::subscribed($connection) : array_values(array_filter(array_map('trim', explode(',', $queues)), static fn (string $queue): bool => $queue !== ''));
        $signals = new SignalState;

        foreach ($subscribed as $queue) {
            if ($signals->terminateIssuedAfter($connection, $queue, $context->controlStartedAt()) !== null) {
                StopLatch::stop();

                return true;
            }
        }

        // The package's pop() decides the pause per queue.
        if (self::instrumented($connection)) {
            return false;
        }

        return $subscribed !== [] && $signals->connectionPaused($connection);
    }

    /**
     * Whether the queue object of the connection is the package's DatabaseQueue.
     */
    private static function instrumented(string $connection): bool
    {
        try {
            return Container::getInstance()->make('queue')->connection($connection) instanceof DatabaseQueue;
        } catch (Throwable $error) {
            Watcher::instance()->report('control:swap_mode', $error);

            // Unknown: the loop never stops for a connection that cannot be resolved.
            return true;
        }
    }
}
