<?php

namespace Justrau\Orrery\Runtime;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;

/**
 * The off switch `orrery.enabled` (ORRERY_ENABLED), decided once per
 * process.
 *
 * The provider binds this class as a singleton and resolves it in boot()
 * on the base application, so an Octane sandbox shares the decision. Every
 * lazy callback asks enabled(), which reads that instance: the connector
 * swap, the `queue.failer` extender, the route middleware, the commands
 * and the ping. A config change after the boot never gives a half-on
 * state; a running process keeps its decision until it restarts.
 *
 * Only an explicit false turns the package off: false, 0, or a word such as
 * "0", "false", "off" or "no". Null, a blank value and any other word keep
 * it on, as for `orrery.schedule` and `orrery.load_migrations`.
 *
 * While it is off, the provider registers the bindings only: no connector
 * swap, no failed-job provider extender, no listener, no terminating
 * callback, no payload callback and no schedule entry (OrreryServiceProvider).
 * The dashboard routes answer 503 (Http\Middleware\RefuseWhileDisabled), and
 * the operator commands that act refuse (Console\RefusesWhileDisabled).
 */
final class OffSwitch
{
    /** The text of every refusal while the package is off. */
    public const MESSAGE = 'Orrery is disabled (ORRERY_ENABLED=false).';

    /** The error code of the dashboard's JSON 503 while the package is off. */
    public const ERROR = 'orrery_disabled';

    public function __construct(public readonly bool $on) {}

    public static function fromConfig(Repository $config): self
    {
        return new self(self::parse($config->get('orrery.enabled', true)));
    }

    /**
     * The decision of this process: the bound instance (made at the
     * provider's boot), else the configuration (before the provider
     * registered, which only a test outside the package's provider meets).
     */
    public static function enabled(): bool
    {
        $container = Container::getInstance();

        if ($container->bound(self::class)) {
            return $container->make(self::class)->on;
        }

        return self::fromConfig($container->make('config'))->on;
    }

    public static function parse(mixed $value): bool
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '' || $value === true) {
            return true;
        }

        if ($value === false) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }
}
