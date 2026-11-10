<?php

namespace Justrau\Orrery\Failed;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider as LaravelDatabaseUuidFailedJobProvider;

/**
 * The verifying extender of `queue.failer`.
 *
 * Laravel's QueueServiceProvider is deferred and binds `queue.failer` when it
 * loads, so an extender is the only binding that holds on every resolution,
 * also after an application's bind() or singleton() and after
 * app()->forgetInstance('queue.failer').
 *
 * verify() replaces the resolved failer with CapturingUuidFailedJobProvider
 * only when `queue.failed.driver` is `database-uuids`, the failer is exactly
 * Laravel's DatabaseUuidFailedJobProvider (not a subclass), and its (array)
 * cast equals the cast of a public construction from `db`,
 * `queue.failed.database` and `queue.failed.table` (the same manager
 * instance, the same strings: '01' is not '1'). The replacement has the same
 * arguments, so the host's failures still reach the same table. Any other
 * failer (another table or connection, an inherited factory, a subclass, a
 * wrapper) comes back unchanged, and retry and forget are read-only for it.
 */
final class ProviderRegistrar
{
    public const DATABASE_UUIDS = 'database-uuids';

    public static function register(Application $app): void
    {
        $app->extend('queue.failer', static fn (mixed $failer, $app): mixed => self::verify($failer, $app));
    }

    public static function verify(mixed $failer, Application $app): mixed
    {
        $failed = $app->make('config')->get('queue.failed');

        if (! is_object($failer) || ! is_array($failed) || ($failed['driver'] ?? null) !== self::DATABASE_UUIDS
            || $failer::class !== LaravelDatabaseUuidFailedJobProvider::class || ! is_string($failed['table'] ?? null)) {
            return $failer;
        }

        $database = $failed['database'] ?? null;

        if ((array) $failer !== (array) new LaravelDatabaseUuidFailedJobProvider($app->make('db'), $database, $failed['table'])) {
            return $failer;
        }

        return new CapturingUuidFailedJobProvider($app->make('db'), $database, $failed['table']);
    }
}
