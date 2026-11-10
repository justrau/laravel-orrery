<?php

namespace Justrau\Orrery\Control;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Queue\Worker;
use Throwable;

/**
 * The process-wide stop latch of a terminate.
 *
 * stop() sets the latch, so no later pop() of this process reserves on any
 * queue. It sets `shouldQuit` on the bound `queue.worker` instance, so the
 * worker loop ends after the current iteration. Only when no Worker
 * instance is bound does it send SIGTERM to this process with posix_kill.
 *
 * The latch is process state: a container singleton, so a
 * new application starts without it.
 */
final class StopLatch
{
    private bool $set = false;

    /** @var (Closure(int, int): void)|null */
    private ?Closure $killer = null;

    public static function stop(): void
    {
        $latch = self::bound();
        $latch->set = true;

        $worker = self::boundWorker();

        if ($worker !== null) {
            $worker->shouldQuit = true;

            return;
        }

        $signal = defined('SIGTERM') ? SIGTERM : 15;
        $pid = (int) getmypid();

        if ($latch->killer !== null) {
            ($latch->killer)($pid, $signal);

            return;
        }

        if (function_exists('posix_kill')) {
            posix_kill($pid, $signal);
        }
    }

    public static function isSet(): bool
    {
        return self::bound()->set;
    }

    /**
     * Replaces posix_kill() in tests: a test process must never send itself SIGTERM.
     *
     * @param  (Closure(int, int): void)|null  $killer
     */
    public static function useKillerForTesting(?Closure $killer): void
    {
        self::bound()->killer = $killer;
    }

    public static function reset(): void
    {
        self::bound()->set = false;
    }

    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }

    /**
     * The Worker instance that the container already holds, or null. It
     * never builds one.
     */
    private static function boundWorker(): ?Worker
    {
        try {
            $container = Container::getInstance();

            if (! $container->resolved('queue.worker')) {
                return null;
            }

            // The host or a test can bind another object.
            /** @var mixed $worker */
            $worker = $container->make('queue.worker');

            return $worker instanceof Worker ? $worker : null;
        } catch (Throwable) {
            return null;
        }
    }
}
