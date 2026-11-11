<?php

namespace Justrau\Orrery\Registration\Modules;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Control\Phases\SignalExpiryPhase;
use Justrau\Orrery\Control\SignalCache;
use Justrau\Orrery\Control\StopLatch;
use Justrau\Orrery\Listeners\ForgetStoppedWorker;
use Justrau\Orrery\Listeners\RecordWorkerHeartbeat;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Workers\Heartbeats;
use Justrau\Orrery\Workers\LiveWorkers;
use Justrau\Orrery\Workers\Phases\HeartbeatSweepPhase;

/**
 * Workers and the control plane.
 *
 * - The heartbeat listener on WorkerStarting, Looping and JobProcessing. On
 *   Looping it also returns false when the worker loop must not reserve (a
 *   global or connection pause of every subscribed queue, or a terminate).
 * - ForgetStoppedWorker on WorkerStopping, JobTimedOut, CommandStarting and
 *   CommandFinished: a worker that stops normally deletes its heartbeat row.
 * - The tick phases heartbeat_sweep (200) and signal_expiry (300).
 * - Every binding is a singleton (process state): the stop latch, the
 *   signal cache, the heartbeat state, the heartbeat listener (its
 *   throttle) and the stop listener (the command depth), so a long-lived worker keeps them and a new application
 *   starts without them.
 */
final class ControlModule implements Module
{
    /** The process state of this module, resolved in boot() on the base application (Modules::resolveAtBoot()). */
    public const PROCESS_STATE = [
        LiveWorkers::class,
        StopLatch::class,
        SignalCache::class,
        Heartbeats::class,
        RecordWorkerHeartbeat::class,
        ForgetStoppedWorker::class,
    ];

    public function register(Application $app): void
    {
        $app->singleton(LiveWorkers::class);
        $app->singleton(StopLatch::class);
        $app->singleton(SignalCache::class);
        $app->singleton(Heartbeats::class);
        $app->singleton(RecordWorkerHeartbeat::class);
        $app->singleton(ForgetStoppedWorker::class);

        // The precise start of a process without LARAVEL_START (for terminate).
        ProcessContext::markBooted();

        $app->tag([
            HeartbeatSweepPhase::class,
            SignalExpiryPhase::class,
        ], TickCommand::PHASES_TAG);
    }

    public function boot(Application $app): void
    {
        Modules::resolveAtBoot($app, self::PROCESS_STATE);
    }

    public function listeners(): array
    {
        return [
            WorkerStarting::class => [RecordWorkerHeartbeat::class],
            Looping::class => [RecordWorkerHeartbeat::class],
            JobProcessing::class => [RecordWorkerHeartbeat::class],
            WorkerStopping::class => [ForgetStoppedWorker::class],
            JobTimedOut::class => [ForgetStoppedWorker::class],
            CommandStarting::class => [ForgetStoppedWorker::class],
            CommandFinished::class => [ForgetStoppedWorker::class],
        ];
    }

    public static function resetForTesting(): void
    {
        // Every state is a container binding: a new application has new ones.
    }
}
