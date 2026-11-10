<?php

namespace Justrau\Orrery;

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

        \Illuminate\Queue\Events\JobFailed::class => [
            Listeners\MarshalFailedEvent::class,
        ],
    ];
}
