<?php

namespace Justrau\Orrery\Monitoring\Phases;

use Illuminate\Container\Container;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Illuminate\Contracts\Events\Dispatcher;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\RunsPastDeadline;
use Justrau\Orrery\Monitoring\MeasurementIncomplete;
use Justrau\Orrery\Monitoring\TickMeasurement;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\Runtime\Watcher;

/**
 * The long-wait check of tick. It dispatches LongWaitDetected
 * for each queue whose oldest executable age or estimated time to clear
 * reaches its threshold (WaitTime::alerts()). The event's seconds are the
 * larger of the two. Each dispatch runs inside its own watched operation
 * (DISPATCH_OPERATION), so an application listener that throws ends only
 * its own alert; SendNotification sends each channel inside its own
 * watched operation too, so a notification error never stops tick.
 *
 * The rows come from the run-scoped measurement (TickMeasurement),
 * which the queue-sample phase of the same run shares.
 *
 * It is the last phase of the tick: the sends of the notification (mail,
 * Slack, SMS) run inside it, and a channel that does not answer holds the
 * phase for its client's timeout. So a slow channel can delay only this
 * phase. It runs also after the tick's deadline, with SHARE_SECONDS of its
 * own (RunsPastDeadline): a slow tick never ends the alerts.
 *
 * While it dispatches, its budget is the container instance BUDGET, so
 * SendNotification sends no channel after the budget ended.
 *
 * Unknown rows never alert. After the readable rows, the
 * phase throws MeasurementIncomplete with the connections of the unknown
 * rows, so the tick records the phase as failed; also when the budget ran
 * out before every alert was sent, so the unknown targets are still named.
 */
final class LongWaitPhase implements RunsPastDeadline
{
    /** The watched operation of one dispatch of LongWaitDetected. */
    public const DISPATCH_OPERATION = 'long_wait:dispatch';

    /**
     * The container key of the phase's budget while it dispatches: the
     * listener SendNotification checks it before each send.
     */
    public const BUDGET = 'orrery.long_wait.budget';

    /** The least seconds of the phase, also after the tick's deadline. */
    public const SHARE_SECONDS = 10.0;

    public function __construct(
        private readonly TickMeasurement $measurement,
        private readonly Dispatcher $events,
    ) {
    }

    public function name(): string
    {
        return 'long_wait';
    }

    public function priority(): int
    {
        // Last, after stuck_notifications: a send that hangs (the event's listeners run here) delays no other phase.
        return 960;
    }

    public function shareSeconds(): float
    {
        return self::SHARE_SECONDS;
    }

    public function run(TickBudget $budget): void
    {
        $rows = $this->measurement->forRun($budget)['rows'];
        $unknown = [];
        $container = Container::getInstance();
        $sessions = $container->make(PackageSession::class);
        $files = $container->make(HostFiles::class);

        foreach ($rows as $row) {
            // D18: a queue on an application SQLite file without automatic
            // reads is unknown by design, not an incomplete measurement.
            if ($row['backlog_unknown'] && $files->allowsReads($sessions->databaseConnectionFor($row['connection']))) {
                $unknown[$row['connection']] = true;
            }
        }

        $container->instance(self::BUDGET, $budget);

        try {
            foreach (WaitTime::alerting($rows) as $row) {
                if ($budget->exhausted()) {
                    break;
                }

                $seconds = max($row['wait'] ?? 0, (int) floor($row['oldest_age'] ?? 0.0));
                // Watched: an application listener that throws ends only its own alert.
                Watcher::instance()->attempt(self::DISPATCH_OPERATION, fn () => $this->events->dispatch(new LongWaitDetected($row['connection'], $row['queue'], $seconds)));
            }
        } finally {
            $container->forgetInstance(self::BUDGET);
        }

        if ($unknown !== []) {
            $targets = array_keys($unknown);
            sort($targets);

            throw new MeasurementIncomplete($targets);
        }
    }
}
