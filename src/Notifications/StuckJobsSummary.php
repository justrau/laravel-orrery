<?php

namespace Justrau\Orrery\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The summary of the stuck jobs that the cooldown of one (connection,
 * queue, signal, channel) held back (Monitoring\StuckNotifications), sent
 * when the cooldown ends and no later stuck job of that key carried the
 * count. The routes and the text rules are StuckText's. The queue and its
 * connection are queueName and queueConnection: Queueable has `queue` and
 * `connection` for a queued notification.
 */
class StuckJobsSummary extends Notification
{
    use Queueable;
    use StuckText;

    public function __construct(
        public string $queueConnection,
        public string $queueName,
        public string $signal,
        public int $count,
    ) {}

    public function title(): string
    {
        return 'Stuck Jobs Detected';
    }

    public function summary(): string
    {
        return sprintf(
            '[%s] %d more jobs on the "%s" queue of the "%s" connection showed the stuck signal %s in the last minutes.',
            self::cut((string) config('orrery.name')),
            $this->count,
            self::cut($this->queueName),
            self::cut($this->queueConnection),
            $this->signal,
        );
    }

    public function smsText(): string
    {
        return self::gsm(sprintf(
            '%s: %d more stuck jobs (%s) on %s',
            mb_substr(self::cut((string) config('orrery.name')), 0, 20),
            $this->count,
            $this->signal,
            mb_substr(self::cut($this->queueName), 0, 80),
        ));
    }
}
