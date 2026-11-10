<?php

namespace Justrau\Orrery\Failed;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Failed\NullFailedJobProvider;

/**
 * The second guard of the failed-record adapter: the bound
 * failer must target the configured `queue.failed`.
 *
 * - CapturingUuidFailedJobProvider: exactly that class (only the verifying
 *   extender makes it), and its (array) cast equals the cast of a public
 *   construction from `db`, `queue.failed.database` and
 *   `queue.failed.table`, key by key with === (the same `db` manager
 *   instance, the same strings: '01' is not '1');
 * - NullFailedJobProvider: exactly that class, and `queue.failed.driver`
 *   is the string 'null' or null;
 * - anything else does not (read-only).
 *
 * An (array) cast lists the properties and calls no method of the failer.
 */
final class FailerProvenance
{
    public static function targetsConfiguration(Container $app, object $failer, ?Repository $config = null): bool
    {
        $failed = ($config ?? $app->make('config'))->get('queue.failed');
        $failed = is_array($failed) ? $failed : [];

        if ($failer::class === NullFailedJobProvider::class) {
            return in_array($failed['driver'] ?? null, ['null', null], true);
        }

        if ($failer::class !== CapturingUuidFailedJobProvider::class || ! is_string($failed['table'] ?? null)) {
            return false;
        }

        $expected = new CapturingUuidFailedJobProvider($app->make('db'), $failed['database'] ?? null, $failed['table']);

        return (array) $failer === (array) $expected;
    }
}
