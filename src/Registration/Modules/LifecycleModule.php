<?php

namespace Justrau\Orrery\Registration\Modules;

use ArrayObject;
use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Queue;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Events\JobFailed;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\History;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\RuntimeClock;
use Justrau\Orrery\Listeners\ObserveJobAttempted;
use Justrau\Orrery\Listeners\ObserveJobException;
use Justrau\Orrery\Listeners\ObserveJobFailed;
use Justrau\Orrery\Listeners\ObserveJobProcessed;
use Justrau\Orrery\Listeners\ObserveJobProcessing;
use Justrau\Orrery\Listeners\ObserveJobTimeout;
use Justrau\Orrery\Listeners\ObserveRetryRequested;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Queue\BulkCapture;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Queue\OpenBulkCaptures;
use Justrau\Orrery\Queue\PendingBulkCaptures;
use Justrau\Orrery\Queue\Phases\BulkResolutionPhase;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;
use WeakMap;

/**
 * The lifecycle: the lifecycle store,
 * its history, cycle summaries and incident cleanup, the monitoring cache,
 * the outcome listeners and the bulk capture.
 *
 * Lifetimes: JobStore, History, CycleSummaries, IncidentCleanup (its table
 * cache; a singleton, so a subclass can be bound),
 * MonitoredTagCache (the 10-second cache), BulkResolver and the pending bulk
 * captures are singletons (process state). The runtime map (RuntimeClock)
 * and the open bulk captures are scoped (unit-of-work state).
 *
 * The swapped hooks of DatabaseQueue observe push, later, pushRaw(), pop()
 * and releases, each from its parent call's return value.
 * The listeners observe the rest: the outcomes. A queue object that is not
 * the package's (the swap is off, or another connector won) gets only its
 * outcomes mirrored. Every listener is wrapped: the driver
 * filter, then the watcher. A completion writes no metric counter:
 * the tick counts the minutes into orrery_samples.
 *
 * boot() registers, after the outcome listeners (so the listener order of
 * the earlier HooksModule and BulkModule stays):
 * - a payload callback after ObservationModule's classification, so it sees
 *   the generation: it records the identity of every payload that a bulk()
 *   call classifies (BulkCapture::classified());
 * - the units of work of the pending bulk captures, as ObservationModule
 *   defines them: each worker or sync job (JobProcessing to JobAttempted),
 *   each console command (CommandStarting to CommandFinished), and the
 *   process or request (boot to the terminating callback). A pending
 *   capture whose flush never ran is dropped and counted at the end of its
 *   unit (BulkCapture::endUnit()).
 * Each of these listeners catches its own errors. The tick phase
 * `bulk_resolution` (250) retries unresolved rows.
 */
final class LifecycleModule implements Module
{
    /** The process state of this module, resolved in boot() on the base application (Modules::resolveAtBoot()). */
    public const PROCESS_STATE = [
        JobStore::class,
        MonitoredTagCache::class,
        IncidentCleanup::class,
        History::class,
        CycleSummaries::class,
        BulkResolver::class,
        PendingBulkCaptures::class,
    ];

    public function register(Application $app): void
    {
        $app->singleton(JobStore::class);
        $app->singleton(MonitoredTagCache::class);
        $app->scoped(RuntimeClock::class);

        $app->singleton(IncidentCleanup::class);
        $app->singleton(History::class);
        $app->singleton(CycleSummaries::class);

        $app->singleton(BulkResolver::class);
        $app->singleton(PendingBulkCaptures::class);
        $app->scoped(OpenBulkCaptures::class);

        $app->tag([BulkResolutionPhase::class], TickCommand::PHASES_TAG);
    }

    public function boot(Application $app): void
    {
        Modules::resolveAtBoot($app, self::PROCESS_STATE);

        Queue::createPayloadUsing(static function (mixed $connection, mixed $queue, mixed $payload): array {
            try {
                if (is_array($payload) && DatabaseDriverFilter::isDatabase(is_string($connection) ? $connection : null)) {
                    BulkCapture::classified($payload);
                }
            } catch (Throwable $error) {
                self::report($error);
            }

            return [];
        });

        $guarded = static function (Closure $work): void {
            try {
                $work();
            } catch (Throwable $error) {
                self::report($error);
            }
        };

        /** @var WeakMap<Job, int> $jobs */
        $jobs = new WeakMap;
        /** @var WeakMap<object, int> $commands */
        $commands = new WeakMap;
        /** @var ArrayObject<string, int> $process */
        $process = new ArrayObject;

        $guarded(static function () use ($process): void {
            $process['unit'] = BulkCapture::beginUnit();
        });

        $events = $app->make(Dispatcher::class);

        $events->listen(JobProcessing::class, static function (JobProcessing $event) use ($guarded, $jobs): void {
            $guarded(static function () use ($event, $jobs): void {
                $jobs[$event->job] = BulkCapture::beginUnit();
            });
        });

        $events->listen(JobAttempted::class, static function (JobAttempted $event) use ($guarded, $jobs): void {
            $guarded(static function () use ($event, $jobs): void {
                $unit = $jobs[$event->job] ?? null;

                if ($unit !== null) {
                    unset($jobs[$event->job]);
                    self::endUnit($unit);
                }
            });
        });

        $events->listen(CommandStarting::class, static function (CommandStarting $event) use ($guarded, $commands): void {
            $guarded(static function () use ($event, $commands): void {
                $commands[$event->input] = BulkCapture::beginUnit();
            });
        });

        $events->listen(CommandFinished::class, static function (CommandFinished $event) use ($guarded, $commands): void {
            $guarded(static function () use ($event, $commands): void {
                $unit = $commands[$event->input] ?? null;

                if ($unit !== null) {
                    unset($commands[$event->input]);
                    self::endUnit($unit);
                }
            });
        });

        $app->terminating(static function () use ($guarded, $process): void {
            $guarded(static function () use ($process): void {
                $unit = $process['unit'] ?? null;
                unset($process['unit']);

                if ($unit !== null) {
                    // ObservationModule's terminating callback flushed the
                    // degradation record before this one.
                    self::endUnit($unit, true);
                }

                // The next request of a long-lived process.
                $process['unit'] = BulkCapture::beginUnit();
            });
        });
    }

    public function listeners(): array
    {
        return [
            JobProcessing::class => [ObserveJobProcessing::class],
            JobExceptionOccurred::class => [ObserveJobException::class],
            JobTimedOut::class => [ObserveJobTimeout::class],
            JobProcessed::class => [ObserveJobProcessed::class],
            JobFailed::class => [ObserveJobFailed::class],
            JobAttempted::class => [ObserveJobAttempted::class],
            JobRetryRequested::class => [ObserveRetryRequested::class],
        ];
    }

    public static function resetForTesting(): void
    {
        // Every state is a container binding: a new application has new ones.
    }

    private static function endUnit(int $unit, bool $flush = false): void
    {
        $before = Degradation::record()['unverified'] ?? 0;
        BulkCapture::endUnit($unit);

        if ($flush && (Degradation::record()['unverified'] ?? 0) !== $before) {
            Degradation::flush();
        }
    }

    private static function report(Throwable $error): void
    {
        try {
            Watcher::instance()->report('hook:bulk.unit', $error);
        } catch (Throwable) {
            // Nothing is left to report to.
        }
    }
}
