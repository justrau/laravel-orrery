<?php

namespace Justrau\Orrery\Registration\Modules;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Justrau\Orrery\Actions\OperatorAudit;
use Justrau\Orrery\Actions\WaitingJobs;
use Justrau\Orrery\Console\ClearCommand;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Maintenance\Phases\HealthRetentionPhase;
use Justrau\Orrery\Maintenance\Phases\ReconcileActivePhase;
use Justrau\Orrery\Maintenance\Phases\ReconcileFailedPhase;
use Justrau\Orrery\Maintenance\Phases\RetentionPhase;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Maintenance\RetentionPolicy;
use Justrau\Orrery\Maintenance\SiblingFileMutex;
use Justrau\Orrery\Maintenance\SiblingLocks;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;

/**
 * Maintenance: the tick runner, retention,
 * reconciliation, orrery:clear and its service (Actions\WaitingJobs), the
 * operator audit (Actions\OperatorAudit) and the schedule.
 *
 * - Tick phases: `retention` (100), `reconcile_active` (260),
 *   `reconcile_failed` (460) and `health_retention` (900).
 * - Schedule: orrery:tick every minute, onOneServer() (it needs a cache
 *   store that all servers share) and withoutOverlapping() with a lock that
 *   expires after 10 minutes. `orrery.schedule` false schedules nothing.
 *   When the effective schedule store writes to a host SQLite file (D17), the
 *   event uses SiblingFileMutex and no onOneServer(). The tick counts every
 *   minute into orrery_samples.
 *
 * Every binding is a singleton (process state): the tick runner, the
 * services with their test seams, WaitingJobs (its batch seam) and the
 * schedule locks of this process (SiblingLocks).
 */
final class MaintenanceModule implements Module
{
    public const TICK_OVERLAP_MINUTES = 10;

    /** The process state of this module, resolved in boot() on the base application (Modules::resolveAtBoot()). */
    public const PROCESS_STATE = [
        TickRunner::class,
        RetentionPolicy::class,
        Retention::class,
        Reconciliation::class,
        WaitingJobs::class,
        SiblingLocks::class,
    ];

    public function register(Application $app): void
    {
        $app->singleton(TickRunner::class);
        $app->singleton(RetentionPolicy::class);
        $app->singleton(Retention::class);
        $app->singleton(Reconciliation::class);
        $app->singleton(ClearCommand::class);
        $app->singleton(WaitingJobs::class);
        $app->singleton(OperatorAudit::class);
        $app->singleton(SiblingLocks::class);

        $app->tag([
            RetentionPhase::class,
            ReconcileActivePhase::class,
            ReconcileFailedPhase::class,
            HealthRetentionPhase::class,
        ], TickCommand::PHASES_TAG);
    }

    public function boot(Application $app): void
    {
        Modules::resolveAtBoot($app, self::PROCESS_STATE);

        // As ServiceProvider::callAfterResolving(): also when the schedule is resolved already.
        $app->afterResolving(Schedule::class, self::schedule(...));

        if ($app->resolved(Schedule::class)) {
            self::schedule($app->make(Schedule::class));
        }
    }

    public static function schedule(Schedule $schedule): void
    {
        // `orrery.schedule` false: the app schedules orrery:tick itself (docs/installation.md, "The scheduler").
        $enabled = Container::getInstance()->make('config')->get('orrery.schedule', true);

        // An empty value (ORRERY_SCHEDULE=) means the default.
        if ($enabled !== null && $enabled !== '' && filter_var($enabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false) {
            return;
        }

        $lockFile = self::lockFile();
        $tick = $schedule->command('orrery:tick')->everyMinute();

        if ($lockFile === null) {
            $tick->onOneServer()->withoutOverlapping(self::TICK_OVERLAP_MINUTES);

            return;
        }

        // D17: the scheduler's store writes to a host SQLite file, so the
        // event takes a file lock and no onOneServer() (single-server setup).
        $tick->preventOverlapsUsing(new SiblingFileMutex(SiblingFileMutex::pathFor($lockFile, 'tick')))->withoutOverlapping(self::TICK_OVERLAP_MINUTES);
    }

    /**
     * The SQLite file next to which the schedule locks live when the
     * effective schedule store is unsafe (HostFiles::cacheIsSafe()): the
     * mirror's file when the mirror is SQLite (the sibling, or the file of
     * orrery.database), else the host file that made the store unsafe. Null
     * when the store is safe.
     */
    public static function lockFile(): ?string
    {
        $container = Container::getInstance();
        $files = $container->make(HostFiles::class);
        $store = $files->scheduleStore();
        $unsafe = $files->unsafeCacheFile($store);

        if ($unsafe === null) {
            return null;
        }

        $sessions = $container->make(PackageSession::class);
        $sibling = $sessions->siblingPath();

        if ($sibling !== null) {
            return $sibling;
        }

        $configured = $container->make('config')->get('orrery.database');
        $mirrorFile = is_string($configured) && $configured !== '' ? $files->realFile($configured) : null;

        return $mirrorFile ?? $unsafe;
    }

    public function listeners(): array
    {
        return [];
    }

    public static function resetForTesting(): void
    {
        // Every state is a container binding: a new application has new ones.
    }
}
