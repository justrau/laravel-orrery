<?php

namespace Justrau\Orrery;

use Illuminate\Queue\Events\JobFailed;

trait EventMap
{
    /**
     * Event => listeners mapping. The lifecycle listeners are in
     * Registration\Modules\LifecycleModule.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected array $events = [
        Events\LongWaitDetected::class => [
            Listeners\SendNotification::class,
        ],

        Events\LongWaitRecovered::class => [
            Listeners\SendRecoveredNotification::class,
        ],

        JobFailed::class => [
            Listeners\MarshalFailedEvent::class,
        ],
    ];
}
