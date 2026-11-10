<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Queue\QueueManager;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;

/**
 * Laravel's own queue pause (`queue:pause`),
 * read only through the host's public QueueManager API: isPaused() and,
 * where the manager has it, getPausedQueues(). The package never reads a
 * cache key itself and never changes a pause flag to inspect it.
 *
 * read() gives one state per queue, `array{queue: ?bool, global: ?bool,
 * unknown: ?string}`:
 * - `global`: Laravel 13's global pause (`queue:pause --all`, pauseAll()):
 *   true, false, or null when unknown. The manager has no getter for it, so
 *   it is read through the public API for PROBE_QUEUE, a queue name that no
 *   worker uses, which is paused only through the global key: inside the
 *   first connection's getPausedQueues() call. One probe per read(), and
 *   only when the manager has pauseAll(); without it (Laravel 12) it is
 *   false.
 * - `queue`: the queue's own flag, true or false, or null when unknown.
 *   The flag of a failover connection whose first connection is the
 *   queue's connection counts as the queue's own: a worker on
 *   that connection asks under the failover name.
 * - `unknown`: why `queue` is unknown: null (known), MASKED (the global
 *   pause is on, and Laravel 13 then answers the global value for every
 *   queue, so the queue's own flag cannot be read), UNSAFE (the default
 *   cache store is a `database` store on a host SQLite file, D17: a
 *   DatabaseStore read deletes expired keys, so no automatic read runs; an
 *   operator action reads it), or FAILED (the read threw, or the watcher
 *   skipped it).
 *
 * The workload (one state per row, for the primary alias, inside the
 * MeasurementCache entry) and the `signal_expiry` phase read it
 * through read(), which bounds the store round trips: one getPausedQueues()
 * per connection where the manager has it (the probe included), else one
 * isPaused() per queue (Laravel 12), and after the first failed read every
 * remaining queue is FAILED without a further call.
 *
 * @phpstan-type State array{queue: ?bool, global: ?bool, unknown: ?string}
 */
final class NativePause
{
    /** A queue name that no worker uses: isPaused() is true for it only through Laravel's global pause. */
    public const PROBE_QUEUE = '__orrery_global_pause_probe__';

    public const MASKED = 'masked';

    public const UNSAFE = 'unsafe';

    public const FAILED = 'failed';

    /** The hint of UNSAFE (D17). */
    public const UNSAFE_HINT = "native pause state not read: the cache store is the app's SQLite database";

    /**
     * The queue's own flag: true, false, or null when unknown (masked by the
     * global pause, not read, or failed).
     */
    public function isPaused(string $connection, string $queue): ?bool
    {
        return $this->read([[$connection, $queue]])[self::key($connection, $queue)]['queue'];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $queues  [connection, queue] pairs
     * @return array<string, State> keyed by key()
     */
    public function read(array $queues): array
    {
        // A worker on a failover connection asks for the pause under its own name: each
        // queue's flag is read under its failover connections too, and a flag set there pauses it.
        $failovers = [];
        $names = $queues;

        foreach ($queues as [$connection, $queue]) {
            foreach (DatabaseDriverFilter::failoversOf($connection) as $failover) {
                $failovers[self::key($connection, $queue)][] = self::key($failover, $queue);
                $names[] = [$failover, $queue];
            }
        }

        $states = $this->readNames($names);

        if ($failovers === []) {
            return $states;
        }

        $result = [];

        foreach ($queues as [$connection, $queue]) {
            $key = self::key($connection, $queue);
            $state = $states[$key];

            foreach ($failovers[$key] ?? [] as $other) {
                $state = self::either($state, $states[$other]);
            }

            $result[$key] = $state;
        }

        return $result;
    }

    /**
     * The state of two names of one queue: paused when either flag is set,
     * else unknown when either is unknown.
     *
     * @param  State  $a
     * @param  State  $b
     * @return State
     */
    private static function either(array $a, array $b): array
    {
        if ($a['queue'] === true || $b['queue'] === true) {
            return $a['queue'] === true ? $a : $b;
        }

        return $a['queue'] === null ? $a : $b;
    }

    /**
     * read() for exactly the given names.
     *
     * @param  list<array{0: string, 1: string}>  $queues
     * @return array<string, State>
     */
    private function readNames(array $queues): array
    {
        $byConnection = [];

        foreach ($queues as [$connection, $queue]) {
            $byConnection[$connection][$queue] = true;
        }

        $container = Container::getInstance();

        // D17: Laravel's pause state lives in the default cache store. A read
        // through a `database` store on a host SQLite file can delete expired
        // keys, so an automatic read does not run: each queue is unknown. An
        // operator action may read it.
        if ($container->make(PackageSession::class)->operatorContext() === null && ! $container->make(HostFiles::class)->cacheIsSafe(null)) {
            return self::fill($queues, ['queue' => null, 'global' => null, 'unknown' => self::UNSAFE]);
        }

        if ($queues === []) {
            return [];
        }

        /** @var object $bound the bound manager, untyped: the version checks below must not be narrowed away */
        $bound = $container->make('queue');
        // getPausedQueues() reads a connection's flags in one store round
        // trip. Every supported Laravel 13 has it and pauseAll() (the floor
        // 13.30), and Laravel 12 has neither, so the global probe always
        // rides on a getPausedQueues() call.
        $global = self::laravelHasGlobalPause() ? null : false;

        $result = self::fill($queues, ['queue' => null, 'global' => $global, 'unknown' => self::FAILED]);

        foreach ($byConnection as $connection => $names) {
            $connection = (string) $connection;
            $names = array_map(static fn (int|string $name): string => (string) $name, array_keys($names));
            // The global probe rides on the first connection's read.
            $probe = $global === null;

            /** @var array<string, bool>|null $paused */
            $paused = Watcher::instance()->run('native_pause', static function () use ($bound, $connection, $names, $probe): array {
                $values = [];

                // The check narrows the untyped $bound here, on every Laravel line and PHPStan version.
                if (method_exists($bound, 'getPausedQueues')) {
                    $asked = $probe ? array_values(array_unique([...$names, self::PROBE_QUEUE])) : $names;
                    $list = (array) $bound->getPausedQueues($connection, $asked);

                    foreach ($asked as $name) {
                        $values[$name] = in_array($name, $list, true);
                    }

                    return $values;
                }

                foreach ($names as $name) {
                    $values[$name] = (bool) self::manager()->isPaused($connection, $name);
                }

                return $values;
            }, null);

            if ($paused === null) {
                // The store failed: the remaining queues stay unknown, without another round trip.
                break;
            }

            if ($probe) {
                $global = $paused[self::PROBE_QUEUE] ?? false;

                // The probe's own entry stays when a caller asked for it (a global or connection continue).
                if (! in_array(self::PROBE_QUEUE, $names, true)) {
                    unset($paused[self::PROBE_QUEUE]);
                }

                if ($global) {
                    return self::fill($queues, ['queue' => null, 'global' => true, 'unknown' => self::MASKED]);
                }

                // Laravel's global pause is off: the queues still unknown keep that.
                foreach ($result as $key => $state) {
                    $result[$key]['global'] = false;
                }
            }

            foreach ($paused as $name => $value) {
                $result[self::key($connection, (string) $name)] = ['queue' => $value, 'global' => false, 'unknown' => null];
            }
        }

        return $result;
    }

    /**
     * Whether the bound manager has Laravel's global pause (pauseAll(), on
     * every supported Laravel 13; not on Laravel 12). Without it no global
     * pause exists, and nothing needs a probe read.
     */
    public static function laravelHasGlobalPause(): bool
    {
        /** @var object $bound the bound manager, untyped: the version check must not be narrowed away */
        $bound = Container::getInstance()->make('queue');

        return method_exists($bound, 'pauseAll');
    }

    private static function manager(): QueueManager
    {
        return Container::getInstance()->make(QueueManager::class);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $queues
     * @param  State  $state
     * @return array<string, State>
     */
    private static function fill(array $queues, array $state): array
    {
        $result = [];

        foreach ($queues as [$connection, $queue]) {
            $result[self::key($connection, $queue)] = $state;
        }

        return $result;
    }

    public static function key(string $connection, string $queue): string
    {
        return $connection."\0".$queue;
    }
}
