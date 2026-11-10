<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The monitored-tag set and the monitoring version V, cached for 10 seconds
 * per process: process state, a container singleton.
 *
 * Read order: V first, then the tag set, on the mirror session at the
 * selected committed-read level. Each read runs in
 * autocommit, so each one sees the latest committed state. A monitoring
 * change that commits between the two reads pairs an older V with the newer
 * tag set, and repair then recomputes the rows. The reverse order is
 * never used: it can pair a newer V with an older tag set, which repair
 * would skip.
 *
 * The caller takes the snapshot outside a package transaction (the store
 * takes it before it begins an insert transaction). The hook point
 * `monitoring_cache.between_reads` lies between the two reads.
 */
final class MonitoredTagCache
{
    public const BETWEEN_READS = 'monitoring_cache.between_reads';

    public const SETTING = 'monitoring_version';

    public const TTL_SECONDS = 10.0;

    /** @var array{tags: array<string, true>, version: int}|null */
    private ?array $snapshot = null;

    private float $readAt = 0.0;

    /**
     * @return array{tags: array<string, true>, version: int} cached for 10 s per process; reads V first, then the tag set
     */
    public function snapshot(): array
    {
        $now = Watcher::monotonic();

        if ($this->snapshot !== null && $now - $this->readAt < self::TTL_SECONDS) {
            return $this->snapshot;
        }

        $connection = Container::getInstance()->make(PackageSession::class)->mirror();

        $value = $connection->table('orrery_settings')->where('key', self::SETTING)->value('value');
        $version = is_numeric($value) ? (int) $value : 0;

        Hooks::reach(self::BETWEEN_READS);

        $tags = [];

        foreach ($connection->table('orrery_monitored_tags')->orderBy('tag')->pluck('tag') as $tag) {
            $tags[(string) $tag] = true;
        }

        $this->snapshot = ['tags' => $tags, 'version' => $version];
        $this->readAt = $now;

        return $this->snapshot;
    }

    /**
     * Forgets the cached snapshot of the application's cache (a container
     * singleton): the next snapshot() reads again.
     */
    public static function flush(): void
    {
        $cache = Container::getInstance()->make(self::class);
        $cache->snapshot = null;
        $cache->readAt = 0.0;
    }
}
