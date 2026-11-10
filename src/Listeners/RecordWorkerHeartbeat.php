<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Justrau\Orrery\Control\WorkerControl;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\WatchesItself;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Workers\Heartbeats;

/**
 * Worker heartbeats, the heartbeat flush point of the
 * degradation record, and the swap-off control check of the worker
 * loop. It watches its own work (WatchesItself): the flush runs
 * also while the breaker is open (it skips its write while a breaker of a
 * lost mirror is open, Watcher::skipsFlush()), and the heartbeat write and
 * the signal reads run inside the watcher.
 *
 * - WorkerStarting: the daemon's queue string and options (no write).
 * - Looping: the queue string, a throttled heartbeat, and, with the swap
 *   off, false when the control plane stops the whole loop (every
 *   subscribed queue paused, or a terminate).
 * - JobProcessing: the queue of the reservation and a throttled heartbeat
 *   (this also covers the swap-off mode, where pop() is Laravel's).
 *
 * Heartbeats::write() writes only in worker processes and only for
 * database-driver connections.
 */
class RecordWorkerHeartbeat implements WatchesItself
{
    /**
     * The default interval between two heartbeat writes of one process, in
     * seconds (`orrery.workers.heartbeat_seconds`, from
     * ORRERY_HEARTBEAT_SECONDS).
     */
    public const DEFAULT_INTERVAL = 15.0;

    /**
     * The throttle: the time of this process's last heartbeat write.
     */
    protected float $lastWrittenAt = 0.0;

    public function handle(Looping|JobProcessing|WorkerStarting $event): ?bool
    {
        $connection = DatabaseDriverFilter::connectionOf($event);
        $connection = is_string($connection) ? $connection : '';

        if ($event instanceof WorkerStarting) {
            Heartbeats::loop(Observation::queueName($event->queue), $event->workerOptions);

            return null;
        }

        if ($event instanceof JobProcessing) {
            Heartbeats::reserved($connection, Observation::queueName($event->job->getQueue()));
            self::write($connection, null);

            return null;
        }

        $queues = Observation::queueName($event->queue);
        // Laravel 13 adds the worker options to Looping; Laravel 12 has none.
        Heartbeats::loop($queues, get_object_vars($event)['workerOptions'] ?? null);
        self::write($connection, $queues);

        return WorkerControl::loopShouldStop($connection, $queues) ? false : null;
    }

    /**
     * Idempotent, throttled heartbeat write. Called by this listener and by
     * DatabaseQueue::pop() on every poll, whether or not a job was returned
     * (queue:listen children run queue:work --once and raise no Looping).
     *
     * Each write is a flush point of the degradation record.
     * The flush runs first, so a window that this process reports carries
     * the state from before the heartbeat's own write. The flush runs in
     * every process; the row is written only by a worker process on a
     * database-driver connection (Heartbeats::write()).
     */
    public static function write(string $connection, ?string $queues = null): void
    {
        $now = Clock::now();

        if ($now - self::bound()->lastWrittenAt < self::interval()) {
            return;
        }

        self::bound()->lastWrittenAt = $now;

        Degradation::flush();

        Heartbeats::write($connection, $queues);
    }

    /**
     * Resets the throttle (tests). The throttle is process state: the
     * listener is a container singleton.
     */
    public static function resetThrottle(): void
    {
        self::bound()->lastWrittenAt = 0.0;
    }

    /**
     * The least seconds between two heartbeat writes of one worker
     * (`orrery.workers.heartbeat_seconds`); LiveWorkers derives its fresh
     * window from it.
     */
    public static function interval(): float
    {
        $seconds = Container::getInstance()->make('config')->get('orrery.workers.heartbeat_seconds', self::DEFAULT_INTERVAL);

        return is_numeric($seconds) ? max(0.0, (float) $seconds) : self::DEFAULT_INTERVAL;
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
