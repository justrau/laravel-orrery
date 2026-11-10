<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Observation\TagNormalizer;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;

/**
 * The monitored-tag set and the monitoring version V.
 *
 * - monitor() and stopMonitoring() normalise the tag (TagNormalizer), and
 *   change orrery_monitored_tags and V in one package transaction. They
 *   update no job row, so their cost does not grow with the number of
 *   tagged jobs. A call that changes nothing (the tag is monitored already,
 *   or not monitored) keeps V.
 * - The settings row of V is created (value 0) before the first
 *   transaction that locks it (ensureRow()), outside the lock.
 * - The change locks the settings row of V first (FOR UPDATE on MySQL and
 *   Postgres; SQLite runs under BEGIN IMMEDIATE), then changes the tag
 *   table, then writes V + 1. Retention and repair read V with a shared
 *   lock in their own transactions (locked()), so a monitoring change and
 *   a retention decision never interleave.
 * - After the change, this process's monitoring cache is flushed. Other
 *   processes read the change within the 10 s of their cache; the older V
 *   of their rows marks them for repair.
 * - The hook point BEFORE_VERSION lies between the tag change and the
 *   version write.
 */
final class MonitoringVersion
{
    public const SETTING = MonitoredTagCache::SETTING;

    public const TABLE = 'orrery_monitored_tags';

    public const BEFORE_VERSION = 'monitoring_version.before_version';

    public function __construct(private readonly PackageTransaction $transactions) {}

    /**
     * Starts monitoring the normalised tag. True when the tag set changed.
     */
    public function monitor(string $tag): bool
    {
        return $this->change($tag, true);
    }

    /**
     * Stops monitoring the normalised tag. True when the tag set changed.
     * The tag rows of the jobs stay until their jobs are trimmed.
     */
    public function stopMonitoring(string $tag): bool
    {
        return $this->change($tag, false);
    }

    /**
     * The committed V, read in autocommit (absent: 0).
     */
    public function current(?Connection $connection = null): int
    {
        return self::parse(($connection ?? Container::getInstance()->make(PackageSession::class)->mirror())->table('orrery_settings')->where('key', self::SETTING)->value('value'));
    }

    /**
     * Creates the settings row of V (value 0) when it is absent: a plain
     * read, then an insertOrIgnore, in autocommit and outside any lock. A
     * concurrent creation is ignored. Every caller of locked() and every
     * monitoring change calls it before its transaction, because a locking
     * read of a missing row locks nothing on MySQL and Postgres at READ
     * COMMITTED.
     */
    public function ensureRow(?Connection $session = null): void
    {
        $session ??= Container::getInstance()->make(PackageSession::class)->mirror();

        if (! $session->table('orrery_settings')->where('key', self::SETTING)->exists()) {
            $session->table('orrery_settings')->insertOrIgnore(['key' => self::SETTING, 'value' => '0']);
        }
    }

    /**
     * V inside the caller's package transaction, with a shared lock on its
     * row (MySQL and Postgres), so a monitoring change waits until the
     * transaction ends. SQLite runs under BEGIN IMMEDIATE. The caller calls
     * ensureRow() before its transaction, so the row exists and the lock
     * holds.
     */
    public function locked(Connection $tx): int
    {
        return self::parse($tx->table('orrery_settings')->where('key', self::SETTING)->sharedLock()->value('value'));
    }

    private function change(string $tag, bool $monitor): bool
    {
        $normalized = TagNormalizer::normalize($tag);

        if ($normalized === null) {
            return false;
        }

        try {
            $this->ensureRow();

            return $this->transactions->run(Container::getInstance()->make(PackageSession::class)->mirror(), function (Connection $tx) use ($normalized, $monitor): bool {
                $version = $this->lockForChange($tx);

                $changed = $monitor
                    ? $tx->table(self::TABLE)->insertOrIgnore(['tag' => $normalized, 'created_at' => Clock::toDecimal(Clock::now())])
                    : $tx->table(self::TABLE)->where('tag', $normalized)->delete();

                if ($changed === 0) {
                    return false;
                }

                Hooks::reach(self::BEFORE_VERSION);

                $tx->table('orrery_settings')->where('key', self::SETTING)->update(['value' => (string) ($version + 1)]);

                return true;
            });
        } finally {
            MonitoredTagCache::flush();
        }
    }

    /**
     * V with an exclusive lock on its row, which ensureRow() created before
     * the transaction.
     */
    private function lockForChange(Connection $tx): int
    {
        return self::parse($tx->table('orrery_settings')->where('key', self::SETTING)->lockForUpdate()->value('value'));
    }

    private static function parse(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
