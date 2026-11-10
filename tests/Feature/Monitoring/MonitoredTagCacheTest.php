<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Tests\Support\PackageSessionHelpers;
use Justrau\Orrery\Tests\TestCase;

/**
 * The monitoring cache read order.
 */
final class MonitoredTagCacheTest extends TestCase
{
    use PackageSessionHelpers;

    /**
     * Rejects: the tag set read before V, or the two reads in one transaction.
     */
    public function test_version_is_read_before_the_tag_set(): void
    {
        $this->assertReadOrder('the selected level');

        if ($this->testEnvironment()->driver === 'mysql') {
            // The server default of a MySQL package session: REPEATABLE
            // READ. Each autocommit read still sees the latest committed state.
            PackageSession::releaseAll();

            self::assertSame('REPEATABLE-READ', $this->reportedLevel($this->sessions()->mirror()));

            $this->assertReadOrder('REPEATABLE READ');
        }
    }

    /**
     * Rejects: a cache without a lifetime, or one that reads the tables at every call.
     */
    public function test_snapshot_is_cached_for_ten_seconds(): void
    {
        $this->setMonitoring(['alpha'], 1);
        $cache = $this->app->make(MonitoredTagCache::class);

        self::assertSame(['tags' => ['alpha' => true], 'version' => 1], $cache->snapshot());

        $this->setMonitoring(['alpha', 'beta'], 2);
        Watcher::advanceMonotonicForTesting(9.0);
        self::assertSame(['tags' => ['alpha' => true], 'version' => 1], $cache->snapshot(), 'inside 10 s the cached snapshot stays');

        Watcher::advanceMonotonicForTesting(1.5);
        self::assertSame(['tags' => ['alpha' => true, 'beta' => true], 'version' => 2], $cache->snapshot(), 'after 10 s the cache reads again');

        $this->mirror()->table('orrery_settings')->where('key', 'monitoring_version')->delete();
        MonitoredTagCache::flush();
        self::assertSame(0, $cache->snapshot()['version'], 'an absent version is 0');
    }

    private function assertReadOrder(string $variant): void
    {
        $this->setMonitoring(['old'], 3);
        MonitoredTagCache::flush();

        $mirror = $this->sessions()->mirrorName();
        $reads = [];
        $recording = true;
        $inHook = false;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$reads, &$recording, &$inHook, $mirror): void {
            if ($recording && ! $inHook && $event->connectionName === $mirror) {
                $reads[] = ['sql' => $event->sql, 'in_transaction' => PackageTransaction::isOpen($event->connection)];
            }
        });

        $hooked = 0;
        Hooks::$callback = function (string $point) use (&$hooked, &$inHook): void {
            if ($point === MonitoredTagCache::BETWEEN_READS) {
                $hooked++;
                $inHook = true;
                // Another session commits a monitored tag and a new V between the two reads.
                $this->mirror()->transaction(function (Connection $other): void {
                    $other->table('orrery_monitored_tags')->insert(['tag' => 'new', 'created_at' => Clock::toDecimal(Clock::now())]);
                    $other->table('orrery_settings')->where('key', 'monitoring_version')->update(['value' => '4']);
                });
                $inHook = false;
            }
        };

        try {
            $snapshot = $this->app->make(MonitoredTagCache::class)->snapshot();
        } finally {
            Hooks::$callback = null;
            $recording = false;
        }

        self::assertSame(1, $hooked, "{$variant}: the hook point between the reads was reached");
        self::assertSame(3, $snapshot['version'], "{$variant}: the snapshot keeps the old V");
        self::assertSame(['new' => true, 'old' => true], $this->sorted($snapshot['tags']), "{$variant}: the snapshot has the new tag set, so repair recomputes its rows");

        self::assertCount(2, $reads, "{$variant}: two reads: ".json_encode($reads));
        self::assertStringContainsString('orrery_settings', $reads[0]['sql'], "{$variant}: V is read first");
        self::assertStringContainsString('orrery_monitored_tags', $reads[1]['sql'], "{$variant}: the tag set is read second");
        self::assertFalse($reads[0]['in_transaction'], "{$variant}: the V read runs in autocommit");
        self::assertFalse($reads[1]['in_transaction'], "{$variant}: the tag read runs in autocommit");
    }

    /**
     * @param  array<string, true>  $tags
     * @return array<string, true>
     */
    private function sorted(array $tags): array
    {
        ksort($tags);

        return $tags;
    }

    /**
     * @param  list<string>  $tags
     */
    private function setMonitoring(array $tags, int $version): void
    {
        $this->mirror()->table('orrery_monitored_tags')->delete();
        $this->mirror()->table('orrery_monitored_tags')->insert(array_map(static fn (string $tag): array => ['tag' => $tag, 'created_at' => Clock::toDecimal(Clock::now())], $tags));
        $this->mirror()->table('orrery_settings')->updateOrInsert(['key' => 'monitoring_version'], ['value' => (string) $version]);
    }
}
