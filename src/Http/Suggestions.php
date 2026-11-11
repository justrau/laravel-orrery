<?php

declare(strict_types=1);

namespace Justrau\Orrery\Http;

use Illuminate\Database\Connection;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The suggestions of the Jobs filter bar's Class and Tag fields
 * (`GET /api/suggestions`): values the package has seen, read with fixed
 * bounds on indexes, and matched in PHP.
 *
 * - Class: the job names of the newest JOB_SCAN jobs (their ids in the order
 *   of the index `pushed`, pushed_at desc, id desc, which covers them; then
 *   the rows by primary key), then the job names of the per-class
 *   samples of the last SAMPLE_MINUTES minutes (`orrery_samples`, a range
 *   on the index `minute`), at most SAMPLE_CLASSES of them, grouped by the
 *   byte-exact `series` digest (so `App\Sync` and `App\SYNC` stay two
 *   classes also under MySQL's case-insensitive collation), the most
 *   recently seen first. The samples keep the classes of jobs whose rows
 *   were trimmed.
 * - Tag: the monitored tags (at most Tags::MAX_TAGS + 1, in the order of the
 *   tag index), then the tags of the newest TAG_JOB_SCAN jobs (read as for
 *   the classes, then `orrery_job_tags` by the index `job`),
 *   newest job first.
 * - Each bound is read as bound + 1, so one extra row says that it was hit:
 *   `limited` is then true and older values may be missing. The list is a
 *   suggestion, never a complete list.
 * - The match is a case-insensitive substring of the value, in PHP, so `%`
 *   and `_` are only themselves (no LIKE). A value that starts with the
 *   query (or whose short class name does) ranks before one that only
 *   contains it; within a rank the order of the sources is kept (monitored
 *   tags first, then the newest). At most MAX_VALUES values; `truncated`
 *   says more matched.
 * - No server cache: every read is bounded, the SPA waits 200 ms after
 *   typing, and the browser keeps each answer 30 s (the controller's
 *   Cache-Control). A cache store would also hold job names and tags
 *   outside the package storage.
 * - Every read runs on the mirror's package session, bounded by the
 *   package's statement timeout (PackageSession::bounded()).
 */
final class Suggestions
{
    /** The most values in one answer. */
    public const MAX_VALUES = 20;

    /** The longest query, in characters (the tag and job name columns hold 255). */
    public const MAX_QUERY = 255;

    /** The newest jobs whose names are read. */
    public const JOB_SCAN = 1_000;

    /** The minutes of per-class samples whose names are read (the current one included). */
    public const SAMPLE_MINUTES = 60;

    /** The most classes read from the samples. */
    public const SAMPLE_CLASSES = 500;

    /** The newest jobs whose tags are read. */
    public const TAG_JOB_SCAN = 1_000;

    public function __construct(private readonly PackageSession $sessions) {}

    /**
     * @param  'class'|'tag'  $field
     * @return array{values: list<array{value: string, monitored: bool}>, truncated: bool, limited: bool}
     */
    public function find(string $field, string $query): array
    {
        [$candidates, $limited] = $this->sessions->bounded(
            $this->sessions->mirror(),
            fn (Connection $session): array => $field === 'class' ? self::classes($session) : self::tags($session),
        );

        $needle = mb_strtolower(trim($query));
        $ranked = [[], []];

        foreach ($candidates as $value => $monitored) {
            $rank = self::rank((string) $value, $needle, $field === 'class');

            if ($rank !== null) {
                $ranked[$rank][] = ['value' => (string) $value, 'monitored' => $monitored];
            }
        }

        $matches = array_merge($ranked[0], $ranked[1]);

        return [
            'values' => array_slice($matches, 0, self::MAX_VALUES),
            'truncated' => count($matches) > self::MAX_VALUES,
            'limited' => $limited,
        ];
    }

    /**
     * 0 when the value (or its short class name) starts with the needle, 1
     * when it contains it, null when it does not match. An empty needle
     * matches every value at rank 0.
     */
    private static function rank(string $value, string $needle, bool $class): ?int
    {
        if ($needle === '') {
            return 0;
        }

        $lower = mb_strtolower($value);
        $position = mb_strpos($lower, $needle);

        if ($position === false) {
            return null;
        }

        if ($position === 0) {
            return 0;
        }

        if ($class) {
            $slash = strrpos($lower, '\\');

            if ($slash !== false && str_starts_with(substr($lower, $slash + 1), $needle)) {
                return 0;
            }
        }

        return 1;
    }

    /**
     * The distinct job names, newest first, each => false (never monitored),
     * and whether a bound was hit.
     *
     * @return array{array<string, bool>, bool}
     */
    private static function classes(Connection $session): array
    {
        [$jobs, $jobsLimited] = self::newestJobs($session, self::JOB_SCAN);
        $names = array_column($jobs, 'name');

        $current = SampleCounts::minuteOf(Clock::now());
        $from = $current - (self::SAMPLE_MINUTES - 1) * SampleCounts::MINUTE;
        $samples = $session->table(SampleCounts::TABLE)
            ->select('series')
            ->selectRaw('MAX(name) AS name')
            ->selectRaw('MAX(minute) AS seen')
            ->whereBetween('minute', [$from, $current])
            ->where('scope', 'job')
            ->groupBy('series')
            ->orderByDesc('seen')->orderBy('series')
            ->limit(self::SAMPLE_CLASSES + 1)
            ->get()->all();

        $values = [];

        foreach ([...$names, ...array_map(static fn (object $row): mixed => $row->name ?? null, array_slice($samples, 0, self::SAMPLE_CLASSES))] as $name) {
            if (is_string($name) && $name !== '') {
                $values[$name] ??= false;
            }
        }

        return [$values, $jobsLimited || count($samples) > self::SAMPLE_CLASSES];
    }

    /**
     * The newest `$limit` jobs, newest first, each `{uuid, name}`, and
     * whether more exist. The order reads only `id` from the index `pushed`
     * (pushed_at, id), which covers it, so no engine sorts the table; the
     * rows are then read by primary key.
     *
     * @return array{list<array{uuid: string, name: string}>, bool}
     */
    private static function newestJobs(Connection $session, int $limit): array
    {
        $ids = array_map('intval', $session->table('orrery_jobs')
            ->orderByDesc('pushed_at')->orderByDesc('id')
            ->limit($limit + 1)
            ->pluck('id')->all());
        $rank = array_flip(array_slice($ids, 0, $limit));
        $jobs = [];

        foreach (array_chunk(array_keys($rank), 500) as $chunk) {
            foreach ($session->table('orrery_jobs')->whereIn('id', $chunk)->get(['id', 'uuid', 'name']) as $row) {
                $jobs[$rank[(int) $row->id]] = ['uuid' => (string) $row->uuid, 'name' => (string) $row->name];
            }
        }

        ksort($jobs);

        return [array_values($jobs), count($ids) > $limit];
    }

    /**
     * The monitored tags (=> true), then the distinct tags of the newest
     * jobs, newest first (=> false).
     *
     * @return array{array<string, bool>, bool}
     */
    private static function tags(Connection $session): array
    {
        $monitored = $session->table(MonitoringVersion::TABLE)->orderBy('tag')->limit(Tags::MAX_TAGS + 1)->pluck('tag')->all();
        [$jobs, $jobsLimited] = self::newestJobs($session, self::TAG_JOB_SCAN);
        $limited = count($monitored) > Tags::MAX_TAGS || $jobsLimited;
        $rank = array_flip(array_column($jobs, 'uuid'));
        $rows = [];

        foreach (array_chunk(array_keys($rank), 500) as $chunk) {
            foreach ($session->table('orrery_job_tags')->whereIn('job_uuid', $chunk)->get(['job_uuid', 'tag']) as $row) {
                $rows[] = [$rank[(string) $row->job_uuid] ?? PHP_INT_MAX, (string) $row->tag];
            }
        }

        // The newest job first, and its tags in byte order.
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));

        $values = [];

        foreach (array_slice($monitored, 0, Tags::MAX_TAGS) as $tag) {
            $values[(string) $tag] = true;
        }

        foreach ($rows as [, $tag]) {
            if ($tag !== '') {
                $values[$tag] ??= false;
            }
        }

        return [$values, $limited];
    }
}
