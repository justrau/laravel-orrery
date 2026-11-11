<?php

namespace Justrau\Orrery\Runtime;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;

/**
 * The off switch `orrery.enabled` (ORRERY_ENABLED).
 *
 * Only an explicit false turns the package off: false, 0, or a word such as
 * "0", "false", "off" or "no". Null, a blank value and any other word keep
 * it on, as for `orrery.schedule` and `orrery.load_migrations`.
 *
 * While it is off, the provider registers the bindings only: no connector
 * swap, no failed-job provider extender, no listener, no terminating
 * callback, no payload callback and no schedule entry (OrreryServiceProvider).
 * The dashboard routes answer 503 (Http\Middleware\RefuseWhileDisabled), and
 * the operator commands that act refuse (Console\RefusesWhileDisabled). A
 * running process keeps the configuration that it loaded.
 */
final class OffSwitch
{
    /** The text of every refusal while the package is off. */
    public const MESSAGE = 'Orrery is disabled (ORRERY_ENABLED=false).';

    /** The error code of the dashboard's JSON 503 while the package is off. */
    public const ERROR = 'orrery_disabled';

    public static function enabled(?Repository $config = null): bool
    {
        $config ??= Container::getInstance()->make('config');
        $value = $config->get('orrery.enabled', true);

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
