<?php

namespace Justrau\Orrery\Queue;

use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\Runtime\Watcher;

/**
 * The database queue connections whose queue object is not the package's
 * DatabaseQueue. For these connections the package
 * mirrors only the outcomes: no `pending` or `reserved` row, so a mirror
 * count of waiting or running jobs is unknown, never 0.
 *
 * Each entry is `{connection, reason}`. The reason is `swap_disabled` (the
 * connector swap is off), `foreign_queue_class` (another connector won the
 * `database` binding), or `unknown` (the queue object could not be
 * resolved, or the breaker is open). Resolving a queue object runs inside
 * the watcher (operation OPERATION), so an error is recorded and never
 * reaches the caller.
 *
 * A caller resolves once per request: `GET /api/health` and
 * `GET /api/workload` each call uninstrumented() once.
 */
final class Instrumentation
{
    public const OPERATION = 'health:queue_object';

    public const SWAP_DISABLED = 'swap_disabled';

    public const FOREIGN_QUEUE_CLASS = 'foreign_queue_class';

    public const UNKNOWN = 'unknown';

    /**
     * @return list<array{connection: string, reason: string}>
     */
    public function uninstrumented(): array
    {
        $entries = [];

        foreach ((array) config('queue.connections', []) as $name => $queue) {
            if (! is_array($queue) || ($queue['driver'] ?? null) !== 'database') {
                continue;
            }

            $connection = (string) $name;
            $resolved = Watcher::instance()->attempt(self::OPERATION, static fn (): bool => app('queue')->connection($connection) instanceof DatabaseQueue);

            if (! $resolved->succeeded()) {
                $entries[] = ['connection' => $connection, 'reason' => self::UNKNOWN];

                continue;
            }

            if ($resolved->value() === true) {
                continue;
            }

            $entries[] = ['connection' => $connection, 'reason' => config('orrery.swap_connector', true) ? self::FOREIGN_QUEUE_CLASS : self::SWAP_DISABLED];
        }

        return $entries;
    }
}
