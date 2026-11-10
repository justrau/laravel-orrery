<?php

namespace Justrau\Orrery\Registration\Modules;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Markers\Markers;
use Justrau\Orrery\Markers\Phases\MarkerRetentionPhase;
use Justrau\Orrery\Markers\Phases\RestartMarkerPhase;
use Justrau\Orrery\Monitoring\MonitoringRepair;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Monitoring\Phases\LongWaitPhase;
use Justrau\Orrery\Monitoring\Phases\MonitoringRepairPhase;
use Justrau\Orrery\Monitoring\Phases\SampleCountsPhase;
use Justrau\Orrery\Monitoring\Phases\SampleRetentionPhase;
use Justrau\Orrery\Monitoring\Phases\SamplesPhase;
use Justrau\Orrery\Monitoring\Phases\StuckEventsPhase;
use Justrau\Orrery\Monitoring\Phases\StuckIncidentsPhase;
use Justrau\Orrery\Monitoring\Phases\StuckNotificationsPhase;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\Samples;
use Justrau\Orrery\Monitoring\TickMeasurement;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\OrreryServiceProvider;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Registration\Modules;

/**
 * Monitoring: wait times, the
 * per-minute samples, the chart markers, the tag monitoring version and its
 * repair, and the optional index of the application's `jobs` table.
 *
 * - Tick phases: `monitoring_repair` (90), `sample_counts` (97),
 *   `samples` (405, the queue state; it shares its TickMeasurement with
 *   `long_wait`), `stuck_incidents` (410, the stuck incidents),
 *   `restart_markers` (420), `sample_retention` (870), `marker_retention`
 *   (875), `stuck_events` (940, StuckJobDetected), `stuck_notifications`
 *   (950, the notifications of the stuck incidents) and `long_wait` (960,
 *   LongWaitDetected and its notifications). The phases that run the
 *   application's listeners and channels are last, so that a slow one
 *   delays no other phase.
 * - Every binding is a singleton (process state). TickMeasurement keeps one
 *   measurement per tick run. Samples, SampleCounts and MonitoringRepair
 *   keep their test seams on the instance.
 * - The jobs index: boot() offers
 *   database/optional/2026_09_23_000100_add_orrery_jobs_index.php under the
 *   publish tag `orrery-jobs-index`. The package never loads or runs that
 *   migration: `php artisan vendor:publish --tag=orrery-jobs-index` copies
 *   it into the application's migrations under the time of the publish
 *   (`<Y_m_d_His>_add_orrery_jobs_index.php`, as Laravel names published
 *   package migrations), and the next `migrate` runs it.
 *   The entry goes into Laravel's publish lists as ServiceProvider::
 *   publishes() writes them, under the package provider, so
 *   `vendor:publish --provider=...OrreryServiceProvider` offers it too.
 */
final class MonitoringModule implements Module
{
    public const PUBLISH_TAG = 'orrery-jobs-index';

    public const MIGRATION = '2026_09_23_000100_add_orrery_jobs_index.php';

    /**
     * The migration file of the jobs index in the package.
     */
    public static function source(): string
    {
        return dirname(__DIR__, 3).'/database/optional/'.self::MIGRATION;
    }

    /** The process state of this module, resolved in boot() on the base application (Modules::resolveAtBoot()). */
    public const PROCESS_STATE = [
        WaitTime::class,
        TickMeasurement::class,
        Samples::class,
        SampleCounts::class,
        Markers::class,
        MonitoringVersion::class,
        MonitoringRepair::class,
    ];

    public function register(Application $app): void
    {
        $app->singleton(WaitTime::class);
        $app->singleton(TickMeasurement::class);
        $app->singleton(Samples::class);
        $app->singleton(SampleCounts::class);
        $app->singleton(Markers::class);
        $app->singleton(MonitoringVersion::class);
        $app->singleton(MonitoringRepair::class);

        $app->tag([
            MonitoringRepairPhase::class,
            SampleCountsPhase::class,
            LongWaitPhase::class,
            SamplesPhase::class,
            StuckIncidentsPhase::class,
            StuckEventsPhase::class,
            RestartMarkerPhase::class,
            SampleRetentionPhase::class,
            MarkerRetentionPhase::class,
            StuckNotificationsPhase::class,
        ], TickCommand::PHASES_TAG);
    }

    public function boot(Application $app): void
    {
        Modules::resolveAtBoot($app, self::PROCESS_STATE);

        $paths = [self::source() => $app->databasePath('migrations/'.self::MIGRATION)];

        ServiceProvider::$publishes[OrreryServiceProvider::class] = array_merge(ServiceProvider::$publishes[OrreryServiceProvider::class] ?? [], $paths);
        ServiceProvider::$publishGroups[self::PUBLISH_TAG] = array_merge(ServiceProvider::$publishGroups[self::PUBLISH_TAG] ?? [], $paths);
        // The copy is named by the time of the publish: under its fixed 2026-09-23 name, a fresh
        // `migrate` ran it before the application's jobs table existed, and it built nothing.
        OrreryServiceProvider::publishWithCurrentDate(self::source());
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
