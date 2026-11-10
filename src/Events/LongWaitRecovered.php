<?php

namespace Justrau\Orrery\Events;

use Illuminate\Container\Container;
use Justrau\Orrery\Contracts\LongWaitRecoveredNotification;

/**
 * A long-wait incident ended: the queue stayed below its threshold for
 * LongWaitIncidents::QUIET_TICKS consecutive known ticks. The tick
 * dispatches it once per incident, also when no channel got the start
 * message.
 *
 * - `seconds`: the duration of the incident, from its first alerting tick
 *   to its end, whole seconds;
 * - `startedAt`: the epoch of the first alerting tick;
 * - `channels`: the channels whose start message was delivered; the
 *   package notification goes only to these.
 */
class LongWaitRecovered
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public int $seconds,
        public float $startedAt,
        public array $channels,
    ) {
    }

    public function toNotification(): LongWaitRecoveredNotification
    {
        /** @var LongWaitRecoveredNotification $notification */
        $notification = Container::getInstance()->make(LongWaitRecoveredNotification::class, [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'seconds' => $this->seconds,
        ]);

        return $notification;
    }
}
