<?php

namespace Justrau\Orrery;

trait ServiceBindings
{
    /**
     * Container bindings registered as singletons. Keys are contracts (or, when keyless, a concrete class).
     *
     * @var array<class-string|int, class-string>
     */
    protected array $serviceBindings = [
        Contracts\WorkloadRepository::class => Database\Repositories\DatabaseWorkloadRepository::class,
    ];

    protected function registerServices(): void
    {
        foreach ($this->serviceBindings as $contract => $impl) {
            if (is_int($contract)) {
                $this->app->singleton($impl);
            } else {
                $this->app->singleton($contract, $impl);
            }
        }

        // Not a singleton: each event instantiates a new notification with constructor params.
        $this->app->bind(
            Contracts\LongWaitDetectedNotification::class,
            Notifications\LongWaitDetected::class,
        );
    }
}
