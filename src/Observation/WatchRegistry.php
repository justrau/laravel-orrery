<?php

namespace Justrau\Orrery\Observation;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;
use WeakMap;

/**
 * The process state of the commit deferral: one
 * WatchState per watched connection instance, and the two connection
 * callbacks, registered once per instance for the life of the process.
 *
 * The item store (CommitDeferral) is a container singleton too, but the
 * callbacks still never capture a store:
 * they run their cheap checks first, and only then resolve the current
 * store ($store, by default the container's CommitDeferral):
 *
 * - beforeStartingTransaction: one counter increment; it returns when no
 *   store holds a tracked root, or when the instance does not begin at
 *   level 0.
 * - beforeExecuting (registered at the instance's first pop() or
 *   deleteAndRelease()): one array read; it returns unless the top capture
 *   of the instance still looks for the record of the parent's own
 *   transaction.
 *
 * Neither callback sends SQL or throws.
 */
final class WatchRegistry
{
    /** @var WeakMap<Connection, WatchState> */
    private WeakMap $watched;

    /** @var WeakMap<CommitDeferral, true> the stores that hold tracked roots */
    private WeakMap $holding;

    /**
     * @param  (Closure(): CommitDeferral)|null  $store  resolves the current item store
     */
    public function __construct(private readonly ?Closure $store = null)
    {
        $this->watched = new WeakMap;
        $this->holding = new WeakMap;
    }

    /**
     * The state of $q, with its callbacks registered once.
     */
    public function watch(Connection $q, int $ownBegins): WatchState
    {
        $state = $this->watched[$q] ?? null;

        if ($state === null) {
            $state = $this->watched[$q] = new WatchState;

            $q->beforeStartingTransaction(function (Connection $connection) use ($state): void {
                $state->begins++;

                if (count($this->holding) === 0 || $connection->transactionLevel() !== 0) {
                    return;
                }

                $this->store()?->levelZeroBegin($connection);
            });
        }

        if ($ownBegins > 0 && ! $state->witness) {
            $state->witness = true;

            $q->beforeExecuting(function (mixed $query, mixed $bindings, Connection $connection) use ($state): void {
                $top = $state->active === [] ? null : $state->active[array_key_last($state->active)];

                if ($top === null || ! $top->awaitsOwnRecord()) {
                    return;
                }

                $this->store()?->statementOfOwnTransaction($connection, $top);
            });
        }

        return $state;
    }

    public function state(Connection $q): ?WatchState
    {
        return $this->watched[$q] ?? null;
    }

    /**
     * A store tells whether it holds tracked roots.
     */
    public function holds(CommitDeferral $store, bool $holds): void
    {
        if ($holds) {
            $this->holding[$store] = true;
        } else {
            unset($this->holding[$store]);
        }
    }

    /**
     * The watched connection instances (tests).
     */
    public function instances(): int
    {
        return count($this->watched);
    }

    private function store(): ?CommitDeferral
    {
        try {
            return $this->store !== null ? ($this->store)() : Container::getInstance()->make(CommitDeferral::class);
        } catch (Throwable $error) {
            try {
                Watcher::instance()->report('deferral.store', $error);
            } catch (Throwable) {
                // Nothing is left to report to.
            }

            return null;
        }
    }
}
