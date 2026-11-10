<?php

namespace Justrau\Orrery\Registration\Modules;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\ProcessState;
use Justrau\Orrery\Runtime\SharedBreaker;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The runtime: the watcher and its
 * breaker, the degradation record and the process context. Every later
 * listener and tick phase runs inside the watcher.
 *
 * Every binding is a singleton (process state): the breaker, the counters
 * and the degradation record live for the process, and a new application
 * starts clean. boot() resolves them on the base application, so the
 * requests of an Octane worker (sandbox clones) share them; a singleton
 * that a sandbox resolved first would live for one request only.
 *
 * The SharedBreaker carries an open breaker to the next requests of a web
 * SAPI, where every request builds a new application.
 *
 * boot() registers:
 * - the terminating callback: the unit of work ends, so no idle package
 *   reader stays on a host SQLite file (D18). The terminating flush of the
 *   degradation record is ObservationModule's, after the end of its
 *   unit of work: one flush per terminating, so a failing write is tried
 *   once;
 * - WorkerStarting: the process context confirms the worker's options;
 * - Looping: each loop of a worker starts a new unit of work (D18).
 */
final class RuntimeModule implements Module
{
    /** The process state of this module, resolved in boot() on the base application (Modules::resolveAtBoot()). */
    public const PROCESS_STATE = [
        Watcher::class,
        Degradation::class,
        ProcessState::class,
    ];

    /**
     * Whether the shared breaker file is on: `orrery.observer.shared_breaker`
     * when it is a boolean, else only when PHP does not run as `cli` or
     * `phpdbg` (PHP-FPM, mod_php, the built-in server, FrankenPHP: its SAPI
     * is `frankenphp`, also in worker mode, whose several worker threads
     * each hold their own application; Octane on Swoole or RoadRunner runs
     * as `cli`).
     */
    public static function sharedBreakerEnabled(mixed $configured, string $sapi): bool
    {
        return is_bool($configured) ? $configured : $sapi !== 'cli' && $sapi !== 'phpdbg';
    }

    public function register(Application $app): void
    {
        $app->singleton(Watcher::class, static function (Application $app): Watcher {
            $seconds = $app->make('config')->get('orrery.observer.breaker_seconds', 30);

            return new Watcher(is_numeric($seconds) ? (float) $seconds : null, $app->make(SharedBreaker::class));
        });

        $app->singleton(SharedBreaker::class, static function (Application $app): SharedBreaker {
            $config = $app->make('config');
            $enabled = self::sharedBreakerEnabled($config->get('orrery.observer.shared_breaker'), PHP_SAPI);
            $path = $config->get('orrery.observer.shared_breaker_path');
            $directory = null;

            if ($enabled) {
                $directory = is_string($path) && $path !== '' ? $path : $app->storagePath('framework');
            }

            return new SharedBreaker(
                $directory,
                static fn (): string => $app->make(PackageSession::class)->mirrorKey(),
            );
        });

        $app->singleton(Degradation::class);
        $app->singleton(ProcessState::class);
    }

    public function boot(Application $app): void
    {
        Modules::resolveAtBoot($app, self::PROCESS_STATE);

        $app->terminating(static function (): void {
            PackageSession::releaseAutomatic();
        });

        $events = $app->make(Dispatcher::class);
        $events->listen(WorkerStarting::class, static function (WorkerStarting $event): void {
            Watcher::instance()->run('process_context:confirm', static fn () => ProcessContext::current()->confirm($event));
        });
        $events->listen(Looping::class, static function (): void {
            PackageSession::releaseAutomatic();
        });
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
