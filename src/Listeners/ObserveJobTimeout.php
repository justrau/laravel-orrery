<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Queue\Events\JobTimedOut;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Throwable;

/**
 * An attempt timeout (the rule exception, PCNTL only). It runs at
 * once.
 */
final class ObserveJobTimeout
{
    public function __construct(
        private readonly JobStore $store,
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
    ) {}

    public function handle(JobTimedOut $event): void
    {
        $this->leaveInterruptedTransaction();
        $observation = ObservesAttempts::observation(Observation::TIMEOUT, DatabaseDriverFilter::connectionOf($event), $event->job);

        if ($observation !== null) {
            $this->store->observe($observation);
        }
    }

    /**
     * Ends the mirror's package transaction that the timeout alarm
     * interrupted (PackageTransaction::abandon()), so this
     * listener's writes are not discarded with it.
     */
    private function leaveInterruptedTransaction(): void
    {
        try {
            $this->transactions->abandon($this->sessions->mirror());
        } catch (Throwable) {
            // No mirror session: the write meets the same error and is counted.
        }
    }
}
