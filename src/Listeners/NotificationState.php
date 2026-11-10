<?php

namespace Justrau\Orrery\Listeners;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Connection;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Throwable;

/**
 * The lock, the cooldowns and the missing-channel markers of the long-wait
 * notification (SendNotification). They live in the default cache store
 * when it is shared and lasting; else in the mirror's orrery_settings, each
 * key with its expiry (epoch seconds) as its value:
 *
 * - a store that writes to a host SQLite file (D17:
 *   HostFiles::cacheIsSafe());
 * - a store of one process (`array`; `null` keeps nothing): each tick is a
 *   new process, so a cooldown there would never hold;
 * - a `failover` store: when its first store is down, it answers from the
 *   next one without a throw, which may be an `array` store;
 * - a store that throws (a Redis or Memcached outage): the error is
 *   reported (`notification:state`), and this instance uses the mirror
 *   from then on. A cooldown that the store holds is not seen then, so a
 *   channel can send once more.
 *
 * One instance serves one long-wait event or one stuck-notification step.
 */
final class NotificationState
{
    private const TABLE = 'orrery_settings';

    /** The result of fromCache() when the mirror holds the state. */
    private const MIRROR = 'mirror';

    /** The reported operation of a cache store that threw. */
    public const OPERATION = 'notification:state';

    private function __construct(private ?Cache $cache)
    {
    }

    public static function current(): self
    {
        $container = Container::getInstance();

        if (! $container->make(HostFiles::class)->cacheIsSafe(null)) {
            return new self(null);
        }

        /** @var Cache $cache */
        $cache = $container->make('cache.store');
        $store = $cache->getStore();

        // A failover store answers from its next store without a throw (an `array` store
        // after a Redis outage), so its entries may last one process only.
        return new self($store instanceof ArrayStore || $store instanceof NullStore || $store instanceof FailoverStore ? null : $cache);
    }

    public function usesMirror(): bool
    {
        return $this->cache === null;
    }

    /**
     * Takes the lock of $key for $seconds. Null when another process holds
     * it; else the release. A cache store without locks gives a release that
     * does nothing.
     *
     * @return (Closure(): void)|null
     */
    public function lock(string $key, int $seconds): ?Closure
    {
        $lock = $this->fromCache(static function (Cache $cache) use ($key, $seconds): Lock|false|null {
            $store = $cache->getStore();
            $lock = $store instanceof LockProvider ? $store->lock($key, $seconds) : null;

            return $lock === null ? null : ($lock->get() ? $lock : false);
        });

        if ($lock !== self::MIRROR) {
            if ($lock === false) {
                return null;
            }

            return static function () use ($lock): void {
                try {
                    $lock?->release();
                } catch (Throwable) {
                    // The lock expires after its seconds.
                }
            };
        }

        if (! $this->add($key, $seconds)) {
            return null;
        }

        return static function () use ($key): void {
            try {
                Container::getInstance()->make(PackageSession::class)->mirror()->table(self::TABLE)->where('key', $key)->delete();
            } catch (Throwable $error) {
                // The lock expires after its seconds.
                Watcher::instance()->report(self::OPERATION, $error);
            }
        };
    }

    public function has(string $key): bool
    {
        $cached = $this->fromCache(static fn (Cache $cache): bool => $cache->has($key));

        if ($cached !== self::MIRROR) {
            return (bool) $cached;
        }

        $expires = Container::getInstance()->make(PackageSession::class)->mirror()->table(self::TABLE)->where('key', $key)->value('value');

        return is_numeric($expires) && (float) $expires > Clock::now();
    }

    public function put(string $key, int $seconds): void
    {
        if ($this->fromCache(static fn (Cache $cache): bool => $cache->put($key, Clock::toDecimal(Clock::now()), $seconds)) !== self::MIRROR) {
            return;
        }

        Container::getInstance()->make(PackageSession::class)->mirror()->table(self::TABLE)->upsert([['key' => $key, 'value' => Clock::toDecimal(Clock::now() + $seconds)]], ['key'], ['value']);
    }

    public function forget(string $key): void
    {
        if ($this->fromCache(static fn (Cache $cache): bool => $cache->forget($key)) !== self::MIRROR) {
            return;
        }

        Container::getInstance()->make(PackageSession::class)->mirror()->table(self::TABLE)->where('key', $key)->delete();
    }

    /**
     * Stores $key for $seconds when it is absent or expired; true when this
     * call stored it.
     */
    public function add(string $key, int $seconds): bool
    {
        $cached = $this->fromCache(static fn (Cache $cache): bool => $cache->add($key, Clock::toDecimal(Clock::now()), $seconds));

        if ($cached !== self::MIRROR) {
            return (bool) $cached;
        }

        $now = Clock::now();
        $mirror = Container::getInstance()->make(PackageSession::class)->mirror();
        // The row first, expired: on Postgres a FOR UPDATE of a
        // row that does not exist locks nothing, so two ticks at once would
        // both store the key and both send.
        $mirror->table(self::TABLE)->insertOrIgnore([['key' => $key, 'value' => '0']]);

        return Container::getInstance()->make(PackageTransaction::class)->run($mirror, static function (Connection $tx) use ($key, $seconds, $now): bool {
            $expires = $tx->table(self::TABLE)->where('key', $key)->lockForUpdate()->value('value');

            if (is_numeric($expires) && (float) $expires > $now) {
                return false;
            }

            $tx->table(self::TABLE)->upsert([['key' => $key, 'value' => Clock::toDecimal($now + $seconds)]], ['key'], ['value']);

            return true;
        });
    }

    /**
     * $work on the cache store, or MIRROR when this instance uses the
     * mirror: none to begin with, or the store threw (reported, and the
     * mirror from then on).
     *
     * @template T
     *
     * @param  Closure(Cache): T  $work
     * @return T|self::MIRROR
     */
    private function fromCache(Closure $work): mixed
    {
        if ($this->cache === null) {
            return self::MIRROR;
        }

        try {
            return $work($this->cache);
        } catch (Throwable $error) {
            Watcher::instance()->report(self::OPERATION, $error);
            $this->cache = null;

            return self::MIRROR;
        }
    }
}
