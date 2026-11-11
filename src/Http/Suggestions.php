<?php

declare(strict_types=1);

namespace Justrau\Orrery\Http;

use Illuminate\Database\Connection;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The suggestions of the Jobs filter bar's Class and Tag fields
 * (`GET /api/suggestions`): values the package has seen, read with fixed
 * bounds on indexes, and matched in PHP.
 *
 * - Class: the job names of the newest JOB_SCAN jobs (`orrery_jobs`, index
 *   `pushed`: pushed_at desc, id desc), then the job names of the newest
 *   SAMPLE_SCAN job samples (`orrery_samples`, index `minute`), which keep
 *   the classes of jobs whose rows were trimmed, for
 *   `samples.retention_hours`.
 * - Tag: the monitored tags (at most Tags::MAX_TAGS + 1, in the order of the
 *   tag index), then the tags of the newest TAG_JOB_SCAN jobs
 *   (`orrery_job_tags` by `job_uuid`, index `job`), newest job first.
 * - The match is a case-insensitive substring of the value, in PHP, so `%`
 *   and `_` are only themselves (no LIKE). A value that starts with the
 *   query (or whose short class name does) ranks before one that only
 *   contains it; within a rank the order of the sources is kept (monitored
 *   tags first, then the newest). At most MAX_VALUES values; `truncated`
 *   says more matched. `limited` says that a read hit its bound, so older
 *   values may be missing: the list is a suggestion, never a complete list.
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

    /** The newest job sample rows whose names are read. */
    public const SAMPLE_SCAN = 20_000;

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
     * The distinct job names, newest first, each => false (never monitored).
     *
     * @return array{array<string, bool>, bool}
     */
    private static function classes(Connection $session): array
    {
        $names = $session->table('orrery_jobs')
            ->orderByDesc('pushed_at')->orderByDesc('id')
            ->limit(self::JOB_SCAN + 1)
            ->pluck('name')->all();
        $limited = count($names) > self::JOB_SCAN;

        $recent = $session->table(SampleCounts::TABLE)
            ->select(['name', 'minute'])
            ->where('scope', 'job')
            ->orderByDesc('minute')
            ->limit(self::SAMPLE_SCAN);
        $samples = $session->query()->fromSub($recent, 'recent')
            ->whereNotNull('name')
            ->groupBy('name')
            ->orderByRaw('MAX(minute) DESC')
            ->pluck('name')->all();
        $scanned = $session->table(SampleCounts::TABLE)->where('scope', 'job')->orderByDesc('minute')->offset(self::SAMPLE_SCAN)->limit(1)->exists();

        $values = [];

        foreach ([...array_slice($names, 0, self::JOB_SCAN), ...$samples] as $name) {
            if (is_string($name) && $name !== '') {
                $values[$name] ??= false;
            }
        }

        return [$values, $limited || $scanned];
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
        $newest = $session->table('orrery_jobs')
            ->select(['uuid', 'pushed_at', 'id'])
            ->orderByDesc('pushed_at')->orderByDesc('id')
            ->limit(self::TAG_JOB_SCAN + 1);
        $rows = $session->query()->fromSub($newest, 'newest')
            ->join('orrery_job_tags', 'orrery_job_tags.job_uuid', '=', 'newest.uuid')
            ->orderByDesc('newest.pushed_at')->orderByDesc('newest.id')->orderBy('orrery_job_tags.tag')
            ->pluck('orrery_job_tags.tag')->all();
        $limited = count($monitored) > Tags::MAX_TAGS
            || $session->table('orrery_jobs')->orderByDesc('pushed_at')->orderByDesc('id')->offset(self::TAG_JOB_SCAN)->limit(1)->exists();

        $values = [];

        foreach (array_slice($monitored, 0, Tags::MAX_TAGS) as $tag) {
            $values[(string) $tag] = true;
        }

        foreach ($rows as $tag) {
            if (is_string($tag) && $tag !== '') {
                $values[$tag] ??= false;
            }
        }

        return [$values, $limited];
    }
}
