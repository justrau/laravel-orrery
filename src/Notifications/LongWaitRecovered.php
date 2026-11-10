<?php

namespace Justrau\Orrery\Notifications;

use Justrau\Orrery\Contracts\LongWaitRecoveredNotification;

/**
 * The end of a long-wait incident (Events\LongWaitRecovered). The tick
 * sends it only to the channels that got the incident's start message.
 */
class LongWaitRecovered extends RoutedNotification implements LongWaitRecoveredNotification
{
    public string $recoveredConnection;

    public string $recoveredQueue;

    public function __construct(string $connection, string $queue, public int $seconds)
    {
        // Queueable owns $connection and $queue (the queue of a queued notification).
        $this->recoveredConnection = $connection;
        $this->recoveredQueue = $queue;
    }

    /**
     * The SMS: GSM characters only, at most 160; the names are cut as in
     * the long-wait SMS, so the duration always fits.
     */
    public function smsText(): string
    {
        return StuckJobDetected::gsm(sprintf(
            '%s: the "%s" queue on the "%s" connection waits within its limit again, after %s seconds.',
            mb_substr(StuckJobDetected::cut((string) config('orrery.name')), 0, 20),
            mb_substr(StuckJobDetected::cut($this->recoveredQueue), 0, 40),
            mb_substr(StuckJobDetected::cut($this->recoveredConnection), 0, 20),
            $this->seconds,
        ));
    }

    protected function good(): bool
    {
        return true;
    }

    protected function title(): string
    {
        return 'Long Wait Recovered';
    }

    protected function summary(): string
    {
        return sprintf(
            '[%s] The "%s" queue on the "%s" connection waits within its limit again, after a long wait of %s seconds.',
            StuckJobDetected::cut((string) config('orrery.name')),
            StuckJobDetected::cut($this->recoveredQueue),
            StuckJobDetected::cut($this->recoveredConnection),
            $this->seconds,
        );
    }
}
