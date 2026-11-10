<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\TimeoutExceededException;
use Justrau\Orrery\Events\JobFailed;
use Justrau\Orrery\JobPayload;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Runtime\WatchesItself;
use Throwable;

/**
 * Dispatches the package's JobFailed event, which the application listens
 * to (Horizon's event). It sends no package statement, so it runs also
 * while the mirror breaker is open (WatchesItself). An
 * exception of an application listener goes to the application's exception
 * handler as it is: it is no package error, and it never reaches the
 * worker.
 */
class MarshalFailedEvent implements WatchesItself
{
    public function __construct(public Dispatcher $events) {}

    public function handle(LaravelJobFailed $event): void
    {
        if (! $event->job instanceof DatabaseJob) {
            return;
        }

        $payload = new JobPayload($event->job->getRawBody());

        $failed = (new JobFailed($event->exception, $payload))
            ->connection($event->connectionName)
            ->queue(Observation::queueName($event->job->getQueue()))
            ->attempt(is_numeric($event->job->getJobId()) ? (int) $event->job->getJobId() : null, $event->job->attempts());

        try {
            $this->events->dispatch($failed);
        } catch (TimeoutExceededException $timeout) {
            // The worker's own timeout (Worker::$killOnTimeout false) belongs to the job (Watcher).
            throw $timeout;
        } catch (Throwable $error) {
            report($error);
        }
    }
}
