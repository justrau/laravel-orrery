<?php

namespace Justrau\Orrery\Http;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Http\Lists\CappedCount;
use Justrau\Orrery\Http\Lists\ListRange;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Observation\TagNormalizer;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The tags API: the monitored tags with capped
 * counts, the monitor state of one tag, and monitor and unmonitor. A tag's
 * jobs are the Jobs list with `tag=` (Http\Lists\JobList), not this class.
 *
 * - At most MAX_TAGS monitored tags, in the order of the tag index
 *   (bytes on SQLite and MySQL, whose tag columns have a binary collation;
 *   the database collation on Postgres), and `truncated` when more exist.
 * - Each tag's `count` (orrery_job_tags, the retained jobs with the tag,
 *   failed ones included) and `failed` (orrery_failed_tags) are capped
 *   counts on the index of the tag's list view (`tag_pushed`,
 *   `tag_finished`), with the same predicate as that list's total, so they
 *   read at most COUNT_CAP + 1 index entries each. A count whose read
 *   fails is `{count: null, at_least: false}` and is reported: unknown is
 *   never zero. After one failed count, the remaining counts of the
 *   request are unknown without a read.
 * - Tags are compared byte for byte. SQL compares with the collated,
 *   sargable equality; on MySQL and MariaDB a binary comparison is added as
 *   a residual predicate, because their binary collation pads trailing
 *   spaces; PHP compares a read tag with `===`.
 * - Every read runs on the mirror's package session, bounded by the
 *   package's statement timeout (PackageSession::bounded()).
 */
final class Tags
{
    /** The largest count of one tag; a larger count says `at_least`. */
    public const COUNT_CAP = 1_000;

    /** The most monitored tags in one response; more give `truncated`. */
    public const MAX_TAGS = 100;

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly MonitoringVersion $monitoring,
    ) {}

    /**
     * The stored form of a requested tag, or null when it is not a usable
     * tag (not a string, empty after trimming, not valid UTF-8, or with a
     * NUL byte anywhere: Postgres cuts text at NUL, so `a\0b` would reach
     * the row `a`; TagNormalizer drops such a tag).
     */
    public static function normalize(mixed $tag): ?string
    {
        return is_string($tag) ? TagNormalizer::normalize($tag) : null;
    }

    /**
     * The monitored tags, at most MAX_TAGS, with their capped counts. A
     * failed read of the tag list throws; a failed count is null.
     *
     * @return array{tags: list<array{tag: string, count: array{count: int|null, at_least: bool}, failed: array{count: int|null, at_least: bool}}>, truncated: bool}
     */
    public function monitored(): array
    {
        $mirror = $this->sessions->mirror();
        $tags = $this->sessions->bounded($mirror, static fn (Connection $session): array => array_map(
            'strval',
            $session->table(MonitoringVersion::TABLE)->orderBy('tag')->limit(self::MAX_TAGS + 1)->pluck('tag')->all(),
        ));

        $rows = [];
        // After the first count that fails, the others are unknown without a read: a failing
        // mirror then costs one statement timeout per request, not one per count.
        $failed = false;
        $count = function (string $table, string $index, string $order, string $tag) use ($mirror, &$failed): array {
            $value = $failed ? null : $this->count($mirror, $table, $index, $order, $tag);
            $failed = $value === null;

            return $value ?? ['count' => null, 'at_least' => false];
        };

        foreach (array_slice($tags, 0, self::MAX_TAGS) as $tag) {
            $rows[] = [
                'tag' => $tag,
                'count' => $count('orrery_job_tags', 'tag_pushed', 'pushed_at', $tag),
                'failed' => $count('orrery_failed_tags', 'tag_finished', 'finished_at', $tag),
            ];
        }

        return ['tags' => $rows, 'truncated' => count($tags) > self::MAX_TAGS];
    }

    /**
     * The number of monitored tags (`monitored_tags` of GET /api/counts):
     * one count on the mirror's package session, bounded by the package's
     * statement timeout. A failed read throws.
     */
    public function monitoredCount(): int
    {
        return $this->sessions->bounded($this->sessions->mirror(), static fn (Connection $session): int => $session->table(MonitoringVersion::TABLE)->count());
    }

    /**
     * Whether the stored tag is monitored: one primary-key read, then a
     * byte comparison. Null when the read failed (reported).
     */
    public function isMonitored(string $tag): ?bool
    {
        $mirror = $this->sessions->mirror();
        $result = Watcher::instance()->attempt('http:tags_lookup', fn (): bool => $this->sessions->bounded($mirror, static function (Connection $session) use ($tag): bool {
            $query = self::exactly($session, $session->table(MonitoringVersion::TABLE), $tag);

            foreach ($query->limit(2)->pluck('tag')->all() as $stored) {
                if ((string) $stored === $tag) {
                    return true;
                }
            }

            return false;
        }));

        return $result->succeeded() && is_bool($result->value()) ? $result->value() : null;
    }

    /**
     * Starts monitoring the stored tag, committed before it returns. True
     * when the set of monitored tags changed.
     */
    public function monitor(string $tag): bool
    {
        return $this->monitoring->monitor($tag);
    }

    /**
     * Stops monitoring the stored tag, committed before it returns. True
     * when the set of monitored tags changed.
     */
    public function unmonitor(string $tag): bool
    {
        return $this->monitoring->stopMonitoring($tag);
    }

    /**
     * The capped count of one tag in a tag table, on the index of its list
     * view, with the predicate of that list's total; null when the read
     * failed (reported).
     *
     * @return array{count: int, at_least: bool}|null
     */
    private function count(Connection $mirror, string $table, string $index, string $order, string $tag): ?array
    {
        $result = Watcher::instance()->attempt('http:tags_count', fn (): array => $this->sessions->bounded($mirror, static function (Connection $session) use ($table, $index, $order, $tag): array {
            $query = static function () use ($session, $table, $index, $order, $tag): Builder {
                $query = $session->table($table);

                // As the list engine hints its access paths: MySQL and SQLite otherwise may prefer the primary key.
                IndexName::force($query, $session, $table, $index);

                return self::exactly($session, $query, $tag)->whereNotNull($order);
            };

            return CappedCount::of([new ListRange($query, $order, 'job_uuid')], self::COUNT_CAP);
        }));

        $value = $result->succeeded() ? $result->value() : null;

        if (is_array($value) && is_int($value['count'] ?? null) && is_bool($value['at_least'] ?? null)) {
            return ['count' => $value['count'], 'at_least' => $value['at_least']];
        }

        return null;
    }

    /**
     * `tag = ?`, and on MySQL and MariaDB also the bytes: utf8mb4_bin is a
     * PAD SPACE collation, so `orders ` equals `orders` there. The one tag
     * predicate of the tag counts and of the list engine's tag reads
     * (Http\Lists\JobList), so a tag's count equals its list total.
     */
    public static function exactly(Connection $session, Builder $query, string $tag): Builder
    {
        $query->where('tag', $tag);

        if (in_array($session->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereRaw('CAST(`tag` AS BINARY) = CAST(? AS BINARY)', [$tag]);
        }

        return $query;
    }
}
