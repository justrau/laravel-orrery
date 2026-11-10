<?php

namespace Justrau\Orrery\Listeners;

use Justrau\Orrery\Events\JobFailed;
use Justrau\Orrery\Failed\OccurrenceSlot;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Observation\QueueObjects;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Throwable;

/**
 * A failure (the rules fail, failWithoutRow and
 * adoptRetryFailure), from the package JobFailed. A failure in a worker
 * process writes that worker's evidence for the attempt; a failure outside a
 * worker clears it.
 *
 * The occurrence that the failure created goes into the OccurrenceSlot of the
 * id binding: CapturingUuidFailedJobProvider::log() takes
 * it once, after its insert. A write that is rejected, only accounted or
 * dropped puts nothing there.
 */
final class ObserveJobFailed
{
    /** The hold point at the end of the listener. */
    public const AFTER = 'observer.after_job_failed';

    public function __construct(
        private readonly JobStore $store,
        private readonly OccurrenceSlot $slot,
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
    ) {}

    public function handle(JobFailed $event): void
    {
        $this->leaveInterruptedTransaction();
        $connection = $event->connectionName;
        $queue = QueueObjects::queueObject($connection);

        if ($connection !== null && $queue !== null) {
            $context = ProcessContext::current();
            $observation = Observation::fromPayload(Observation::FAIL, $event->payload->value, $connection, (string) $event->queue, [
                'jobId' => $event->jobId,
                'attempt' => $event->attempts,
                'exception' => $event->exception,
                'inWorker' => $context->isWorker(),
                'worker' => $context->workerEvidence(),
                'at' => Clock::now(),
                'instrumented' => QueueObjects::isSwapped($queue),
            ]);

            if ($observation !== null) {
                $occurrence = $this->store->observe($observation)->occurrenceId;

                if ($occurrence !== null) {
                    // The slot of the id binding: read by CapturingUuidFailedJobProvider::log().
                    $this->slot->put($observation->uuid, $occurrence);
                }
            }
        }

        Hooks::reach(self::AFTER);
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
