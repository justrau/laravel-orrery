<?php

namespace Justrau\Orrery\Registration\Modules;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\SchemaLoaded;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Registration\BootsWhileDisabled;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;

/**
 * The package storage: the package sessions, the host
 * SQLite file policy (D17, D18), the package transaction and the schema of
 * the package tables. Listed first: every later module reads and writes
 * through them. Its boot() also listens to Laravel's SchemaLoaded, so a
 * package schema loaded from a schema dump gets its marker. It boots also
 * while the package is off (BootsWhileDisabled): `migrate` still works.
 *
 * Every binding is a singleton (process state): the open sessions, the
 * journal-mode checks and the SQLite counters live for the process, and a
 * new application starts without them.
 *
 * The reset removes the two test clock seams, which stay static (the
 * allow-list of StaticStateInventoryTest).
 */
final class StorageModule implements BootsWhileDisabled
{
    /** The process state of this module, resolved in boot() on the base application (Modules::resolveAtBoot()). */
    public const PROCESS_STATE = [
        PackageSession::class,
        HostFiles::class,
        PackageTransaction::class,
        PackageSchema::class,
        DeferredIndexes::class,
    ];

    public function register(Application $app): void
    {
        $app->singleton(PackageSession::class);
        $app->singleton(HostFiles::class);
        $app->singleton(PackageTransaction::class);
        $app->singleton(PackageSchema::class);
        // The 60 s cache of the deferred index reads.
        $app->singleton(DeferredIndexes::class);
    }

    public function boot(Application $app): void
    {
        Modules::resolveAtBoot($app, self::PROCESS_STATE);

        // A `migrate` from a schema:dump file loads the package tables without the marker row.
        // afterSchemaLoad() reports its own errors: it never stops the host's migrate.
        $events = $app->make('events');
        $events->listen(SchemaLoaded::class, static function (SchemaLoaded $event): void {
            PackageSchema::afterSchemaLoad($event->connection);
        });

        // The application's default before `migrate --database=<name>` swaps it (PackageSchema::install()).
        $events->listen(CommandStarting::class, static function (): void {
            PackageSchema::commandStarted();
        });
        $events->listen(CommandFinished::class, static function (): void {
            PackageSchema::commandFinished();
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public static function resetForTesting(): void
    {
        Clock::resetForTesting();
        DatabaseClock::$offsetForTesting = null;
    }
}
