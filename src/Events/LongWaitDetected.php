<?php

namespace Justrau\Orrery\Events;

use Illuminate\Container\Container;
use Justrau\Orrery\Contracts\LongWaitDetectedNotification;

class LongWaitDetected
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $seconds,
    ) {
    }

    public function toNotification(): LongWaitDetectedNotification
    {
        /** @var LongWaitDetectedNotification $notification */
        $notification = Container::getInstance()->make(LongWaitDetectedNotification::class, [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'seconds' => $this->seconds,
        ]);

        return $notification;
    }
}
