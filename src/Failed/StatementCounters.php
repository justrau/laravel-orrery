<?php

namespace Justrau\Orrery\Failed;

use Closure;
use Illuminate\Database\Connection;
use stdClass;
use WeakMap;

/**
 * The statement counters of the package's failed-job provider:
 * process state, a container singleton. Each
 * connection instance gets one beforeExecuting callback for the life of the
 * process, so a worker that logs 1,000 failures adds one callback, not
 * 1,000.
 */
final class StatementCounters
{
    /** @var WeakMap<Connection, true> the connections whose statement counter is registered */
    private WeakMap $counted;

    /** @var WeakMap<Connection, array<int, stdClass>> the running counters of each connection, one per running call */
    private WeakMap $counts;

    public function __construct()
    {
        $this->counted = new WeakMap;
        $this->counts = new WeakMap;
    }

    /**
     * Runs $work with a counter of the statements that $host runs meanwhile.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return array{0: T, 1: int} the work's value and its statements
     */
    public function count(Connection $host, Closure $work): array
    {
        if (! isset($this->counted[$host])) {
            $this->counted[$host] = true;
            $host->beforeExecuting(function (string $sql, array $bindings, Connection $connection): void {
                // Every running call of the connection counts the statement, so a nested
                // log() from a host listener never hides its statements from the outer one.
                foreach ($this->counts[$connection] ?? [] as $counter) {
                    $counter->statements++;
                }
            });
        }

        // A counter of this call only: a nested call adds and removes its own.
        $counter = new stdClass;
        $counter->statements = 0;
        $running = $this->running($host);
        $running[spl_object_id($counter)] = $counter;
        $this->counts[$host] = $running;

        try {
            $value = $work();
        } finally {
            // Never an Error for a key that another call removed.
            $running = $this->running($host);
            unset($running[spl_object_id($counter)]);

            if ($running === []) {
                unset($this->counts[$host]);
            } else {
                $this->counts[$host] = $running;
            }
        }

        return [$value, $counter->statements];
    }

    /**
     * The connection instances that have a counter callback (tests).
     */
    public function connections(): int
    {
        return count($this->counted);
    }

    /**
     * The running counters of $host; none when the key is unset.
     *
     * @return array<int, stdClass>
     */
    private function running(Connection $host): array
    {
        return $this->counts[$host] ?? [];
    }
}
