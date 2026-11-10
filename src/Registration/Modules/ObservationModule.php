<?php

namespace Justrau\Orrery\Registration\Modules;

use ArrayObject;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Observation\DegradationDropCounter;
use Justrau\Orrery\Observation\PayloadClassifier;
use Justrau\Orrery\Observation\WatchRegistry;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;
use WeakMap;

/**
 * The observation of the queue: the commit deferral,
 * its units of work, and the payload classification.
 *
 * - WatchRegistry is process state (a singleton): one entry per watched
 *   connection instance, so each instance gets its connection callbacks
 *   once. It is resolved in boot(), so a long-lived process (Octane) keeps
 *   it.
 * - CommitDeferral, the item store, is process state too: a singleton,
 *   resolved in boot() on the base application. This is by design:
 *   a scoped store loses counts. An
 *   Octane sandbox resolves its own scoped store, which the base
 *   application's units never end, and a transaction that a worker leaks
 *   across loops leaves its items in stores that nobody counts, with a
 *   10,000-item cap per store instead of per process. The units of work
 *   (tokens) give the unit-of-work semantics: each unit ends what it began.
 *   Its drops go to the degradation record (DegradationDropCounter).
 * - The units of work: each worker or sync job (JobProcessing to
 *   JobAttempted, by the job object), each console command (CommandStarting
 *   to CommandFinished, by the command's input), and the process or request (boot to the terminating
 *   callback, then a new unit for the next request of a long-lived
 *   process). Every begin has its end: a unit ends by the token that its
 *   begin returned (CommitDeferral::endUnit() also ends the units begun
 *   inside it).
 *
 * The listeners are registered in boot(), not through listeners(): the
 * driver filter of the wrapped module listeners would skip a sync job's
 * events, and a command event has no queue connection. Each one catches its
 * own errors. The terminating callback ends the process unit, and then
 * flushes the degradation record, because the watcher's own terminating
 * flush ran before it.
 *
 * After the units, boot() registers the one payload classification
 * callback of the package. It runs PayloadClassifier
 * inside Watcher::run('payload.classify', …, []), so an error of the
 * package (for example a tags() method that throws) gives a payload without
 * package keys, and the job is still queued. LifecycleModule's bulk
 * callback comes after it, so it sees the generation.
 */
final class ObservationModule implements Module
{
    public function register(Application $app): void
    {
        $app->singleton(WatchRegistry::class, static fn (): WatchRegistry => new WatchRegistry);

        $app->singleton(CommitDeferral::class, static fn (Application $app): CommitDeferral => new CommitDeferral(
            new DegradationDropCounter,
            $app->make(Watcher::class),
            $app->make(WatchRegistry::class),
        ));
    }

    public function boot(Application $app): void
    {
        $guarded = static function (\Closure $work): void {
            try {
                $work();
            } catch (Throwable $error) {
                try {
                    Watcher::instance()->report('deferral.unit', $error);
                } catch (Throwable) {
                    // Nothing is left to report to.
                }
            }
        };

        $guarded(static fn () => $app->make(WatchRegistry::class));
        $guarded(static fn () => $app->make(CommitDeferral::class));

        /** @var WeakMap<Job, array{CommitDeferral, int}> $jobs the unit of each running job */
        $jobs = new WeakMap;
        /** @var WeakMap<object, array{CommitDeferral, int}> $commands the unit of each running command, by its input */
        $commands = new WeakMap;
        /** @var ArrayObject<string, array{CommitDeferral, int}> $process the unit of the process or request */
        $process = new ArrayObject;

        $begin = static function () use ($app): array {
            $deferral = $app->make(CommitDeferral::class);

            return [$deferral, $deferral->beginUnit()];
        };

        $guarded(static function () use ($begin, $process): void {
            $process['unit'] = $begin();
        });

        $events = $app->make(Dispatcher::class);

        $events->listen(JobProcessing::class, static function (JobProcessing $event) use ($guarded, $begin, $jobs): void {
            $guarded(static function () use ($event, $begin, $jobs): void {
                $jobs[$event->job] = $begin();
            });
        });

        $events->listen(JobAttempted::class, static function (JobAttempted $event) use ($guarded, $jobs): void {
            $guarded(static function () use ($event, $jobs): void {
                $unit = $jobs[$event->job] ?? null;

                if ($unit === null) {
                    return;
                }

                unset($jobs[$event->job]);
                $unit[0]->endUnit($unit[1]);
            });
        });

        // Keyed by the command's input, as jobs are keyed by the job: a
        // CommandFinished whose CommandStarting began no unit (an earlier
        // listener threw) ends nothing.
        $events->listen(CommandStarting::class, static function (CommandStarting $event) use ($guarded, $begin, $commands): void {
            $guarded(static function () use ($event, $begin, $commands): void {
                $commands[$event->input] = $begin();
            });
        });

        $events->listen(CommandFinished::class, static function (CommandFinished $event) use ($guarded, $commands): void {
            $guarded(static function () use ($event, $commands): void {
                $unit = $commands[$event->input] ?? null;

                if ($unit === null) {
                    return;
                }

                unset($commands[$event->input]);
                $unit[0]->endUnit($unit[1]);
            });
        });

        $app->terminating(static function () use ($guarded, $begin, $process): void {
            $guarded(static function () use ($begin, $process): void {
                $unit = $process['unit'] ?? null;
                unset($process['unit']);

                if ($unit !== null) {
                    $unit[0]->endUnit($unit[1]);
                }

                // The next request of a long-lived process.
                $process['unit'] = $begin();
            });

            // The one terminating flush of the degradation record
            // runs after the end of the unit, so its counts are in it, and
            // once, so a failing write costs one attempt.
            $guarded(static fn () => Degradation::flush(terminating: true));
        });

        self::classifyPayloads();
    }

    /**
     * One registration per application boot. Laravel's own test teardown
     * (and the test reset hook) clears the static list of Queue, and the
     * next application boot registers the callback again.
     */
    private static function classifyPayloads(): void
    {
        Queue::createPayloadUsing(static function (mixed $connection, mixed $queue, mixed $payload): array {
            if (! is_array($payload)) {
                return [];
            }

            $connection = is_string($connection) ? $connection : null;

            // The driver check comes before the watcher: Watcher::run() opens
            // the package storage session, and a redis or sync dispatch must
            // never wait for the mirror (as in the listener wrapper of the
            // provider). A configuration error here gives no package keys.
            try {
                if (! DatabaseDriverFilter::isDatabase($connection)) {
                    return [];
                }

                $watcher = Watcher::instance();
            } catch (Throwable) {
                return [];
            }

            return $watcher->run(
                'payload.classify',
                static fn (): array => (new PayloadClassifier)(
                    $connection,
                    is_string($queue) ? $queue : null,
                    $payload,
                ),
                [],
            );
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public static function resetForTesting(): void
    {
        // The registry and the item store are container bindings: a new
        // application has new ones. The test reset hook removes the payload
        // callbacks (Laravel's own static list).
    }
}
