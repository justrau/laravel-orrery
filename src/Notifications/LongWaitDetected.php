<?php

namespace Justrau\Orrery\Notifications;

use Justrau\Orrery\Contracts\LongWaitDetectedNotification;

class LongWaitDetected extends RoutedNotification implements LongWaitDetectedNotification
{
    public string $longWaitConnection;

    public string $longWaitQueue;

    public function __construct(string $connection, string $queue, public int $seconds)
    {
        $this->longWaitConnection = $connection;
        $this->longWaitQueue = $queue;
    }

    /**
     * The SMS: GSM characters only (StuckText::gsm()), at most 160, with the
     * prefix "<name>:" as the stuck-job SMS has it (the brackets of the
     * summary are not GSM basic-set characters). The queue name is cut to
     * 40 characters and the connection name to 20, so the wait always fits.
     */
    public function smsText(): string
    {
        return StuckJobDetected::gsm(sprintf(
            '%s: the "%s" queue on the "%s" connection has a wait time of %s seconds.',
            mb_substr(StuckJobDetected::cut((string) config('orrery.name')), 0, 20),
            mb_substr(StuckJobDetected::cut($this->longWaitQueue), 0, 40),
            mb_substr(StuckJobDetected::cut($this->longWaitConnection), 0, 20),
            $this->seconds,
        ));
    }

    public function signature(): string
    {
        return md5($this->longWaitConnection.$this->longWaitQueue);
    }

    protected function mailSubject(): string
    {
        return 'Long Queue Wait Detected';
    }

    protected function title(): string
    {
        return 'Long Wait Detected';
    }

    protected function summary(): string
    {
        return sprintf(
            '[%s] The "%s" queue on the "%s" connection has a wait time of %s seconds.',
            StuckJobDetected::cut((string) config('orrery.name')),
            StuckJobDetected::cut($this->longWaitQueue),
            StuckJobDetected::cut($this->longWaitConnection),
            $this->seconds,
        );
    }
}
