<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A long job for `composer workbench:worker`: a worker holds it reserved, so the dashboard
 * shows a running job.
 *
 * The runtime stays below $timeout, $timeout stays below the worker's --max-time (600 s), and
 * both stay below the retry_after of the `database` connection (900 s).
 *
 * Laravel's worker answers SIGINT and SIGTERM by quitting after the current job. Without help,
 * Ctrl-C would wait for this job to end. So, while it runs, the job also stops on those signals,
 * and it passes each signal on to the worker's own handler.
 */
class SlowJob implements ShouldQueue
{
    use Queueable;

    public const DEFAULT_SECONDS = 570;

    public int $timeout = 590;

    public function __construct(public int $seconds = self::DEFAULT_SECONDS) {}

    public function handle(): void
    {
        $stopped = false;
        $previous = $this->stopOnSignals($stopped);

        try {
            $deadline = microtime(true) + max(0, min($this->seconds, $this->timeout - 10));

            while (! $stopped && microtime(true) < $deadline) {
                usleep(250_000);
            }
        } finally {
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
        }
    }

    /**
     * @return array<int, callable|int> The handlers to restore, keyed by signal number.
     */
    private function stopOnSignals(bool &$stopped): array
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_get_handler')) {
            return [];
        }

        $previous = [];

        foreach ([SIGINT, SIGTERM] as $signal) {
            $handler = pcntl_signal_get_handler($signal);
            $previous[$signal] = $handler;

            pcntl_signal($signal, static function (int $number, mixed $info = null) use (&$stopped, $handler): void {
                $stopped = true;

                if (is_callable($handler)) {
                    self::forward($handler, $number, $info);
                }
            });
        }

        return $previous;
    }

    /**
     * Call a handler that pcntl_signal_get_handler() returned, the way PHP calls a signal
     * handler: with the signal number and the signal information.
     *
     * @param  callable(int, mixed): mixed  $handler
     */
    private static function forward(callable $handler, int $number, mixed $info): void
    {
        $handler($number, $info);
    }
}
