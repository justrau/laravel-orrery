<?php

namespace Justrau\Orrery\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Justrau\Orrery\Events\StuckJobDetected as StuckJobEvent;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Monitoring\StuckSignals;
use Justrau\Orrery\Queue\BulkResolver;

/**
 * The notification of one stuck incident,
 * sent by Monitoring\StuckNotifications to the routes
 * of the long-wait notification (StuckText).
 *
 * The text holds only what the event carries: the job's display name, its
 * uuid, queue and connection (each through cut()) and the signal with its
 * numeric facts; `more` adds the stuck jobs of the same queue and signal
 * that the cooldown held back since the last message. It holds no payload,
 * exception text or context, and it claims no process death, crash or
 * eventual pickup.
 */
class StuckJobDetected extends Notification
{
    use Queueable;
    use StuckText;

    public function __construct(public StuckJobEvent $event, public int $more = 0) {}

    public function title(): string
    {
        return 'Stuck Job Detected';
    }

    /**
     * One sentence: the job, what its signal says, and the held-back count.
     */
    public function summary(): string
    {
        return sprintf(
            '[%s] The job "%s" (%s) on the "%s" queue of the "%s" connection looks stuck: %s.%s',
            self::cut((string) config('orrery.name')),
            self::cut($this->event->name),
            $this->event->uuid,
            self::cut($this->event->queue),
            self::cut($this->event->connection),
            $this->reason(),
            $this->more > 0 ? sprintf(' And %d more stuck jobs on this queue with the signal %s.', $this->more, $this->event->signal) : '',
        );
    }

    /**
     * The SMS: the signal, the queue and the held-back count, at most 160
     * GSM characters.
     */
    public function smsText(): string
    {
        return self::gsm(sprintf(
            '%s: stuck job (%s) on %s%s',
            mb_substr(self::cut((string) config('orrery.name')), 0, 20),
            $this->event->signal,
            mb_substr(self::cut($this->event->queue), 0, 80),
            $this->more > 0 ? sprintf(', +%d more', $this->more) : '',
        ));
    }

    /**
     * The signal's sentence, filled with its facts. A fact that is missing
     * or not a number gives the sentence without it.
     */
    private function reason(): string
    {
        $evidence = $this->event->evidence;
        $number = static fn (string $key): ?int => is_numeric($evidence[$key] ?? null) ? (int) $evidence[$key] : null;

        return match ($this->event->signal) {
            StuckSignals::RESERVATION_EXPIRED => $number('retry_after') === null
                ? 'its reservation is older than its lease, and no outcome was observed'
                : sprintf('its reservation is older than its lease of %d seconds, and no outcome was observed', $number('retry_after')),
            StuckSignals::OUTCOME_NOT_OBSERVED => $number('gap') === null
                ? 'attempts of it ended without an observed outcome'
                : sprintf('%d of its attempts ended without an observed outcome', $number('gap')),
            StuckSignals::REPEATED_RELEASES => $number('releases') === null
                ? 'it was released many times in its current cycle'
                : sprintf('it was released %d times in its current cycle', $number('releases')),
            StuckSignals::ATTEMPTS_NOT_CAPPED => sprintf(
                '%s, and %s',
                $number('attempts') === null ? 'it made many attempts' : sprintf('it made %d attempts', $number('attempts')),
                ($evidence['rule'] ?? null) === 'deadline' ? 'no attempt limit applies before its retryUntil deadline' : 'no attempt limit applies',
            ),
            StuckIncidents::UNKNOWN => match ($evidence['reason'] ?? null) {
                Reconciliation::UNKNOWN_REASON => 'its state is unknown, because the job left the jobs table without an observed outcome',
                BulkResolver::UNRESOLVED_REASON => 'its state is unknown, because its job id in the jobs table was never resolved',
                default => 'its state is unknown',
            },
            default => 'it shows the stuck signal '.$this->event->signal,
        };
    }
}
