<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Runtime\Hooks;

/**
 * An attempt exception (the rule exception). Its evidence is not a
 * queue statement, so it runs at once. The job id is the id of the attempt,
 * so a release-then-throw exception matches the row's previous id.
 */
final class ObserveJobException
{
    /** The hold point at the end of the listener. */
    public const AFTER = 'observer.after_exception';

    public function __construct(private readonly JobStore $store) {}

    public function handle(JobExceptionOccurred $event): void
    {
        $observation = ObservesAttempts::observation(Observation::EXCEPTION, DatabaseDriverFilter::connectionOf($event), $event->job, ['exception' => $event->exception]);

        if ($observation !== null) {
            $this->store->observe($observation);
        }

        Hooks::reach(self::AFTER);
    }
}
