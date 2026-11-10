<?php

namespace Justrau\Orrery\Failures;

use Illuminate\Container\Container;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The failure-group reads by stored key: the
 * list and the detail.
 *
 * Every failed row has its stored key (JobStore writes failure_key with
 * every failure), so every read goes through KeyedFailures: a head scan of
 * the newest FailureScan::CAP rows finds and orders the groups, and bounded
 * per-key statements give their figures:
 * - `count` is the keyed count, at most the cap; `count_capped` is true
 *   when it passed the cap ("at least");
 * - `first_failed_at` and `last_failed_at` are exact;
 * - `exact` is false when the count is capped;
 * - `queues` are the distinct `connection:queue` of the scanned members,
 *   and `queues_complete` is false when the count is capped or larger than
 *   the scanned members.
 *
 * The buckets of a capped count are unknown before the oldest counted row
 * (absent), and its bucket is partial.
 *
 * The list's `truncated` means only this: groups whose failures are all
 * older than the scanned rows may be missing. `scanned_from` is then the
 * oldest scanned time. The detail is `complete`: no scan bounds it, only
 * the count cap.
 *
 * The text fields go through the job projection:
 * `message` is the shown row's whole stored message, redacted, then its
 * first line cut to 500 characters; `latest_trace` is the newest member's exception text, read
 * with the size CASE and redacted whole, or {"omitted": "too_large",
 * "bytes": n} over JobProjection::EXCEPTION_BYTES. An invalid redaction
 * configuration throws RedactionConfigInvalid (the controller answers 500).
 */
final class GroupReads
{
    /**
     * @return array<string, mixed>
     *
     * @throws RedactionConfigInvalid
     */
    public static function index(FailureScope $scope, int $offset, float $asOf, ?float $retentionFrom): array
    {
        $projection = self::projection();
        $keyed = KeyedFailures::scan($scope);
        $groups = [];

        foreach ($keyed['rows'] as $row) {
            $key = $row['key'];
            $groups[$key] ??= ['last' => 0.0, 'queues' => [], 'scanned' => 0, 'keyed_id' => $row['id']];
            $groups[$key]['queues'][$row['queue']] = true;
            // The scan is newest first, so the first row of a key (keyed_id) is its newest.
            $groups[$key]['scanned']++;
            $groups[$key]['last'] = max($groups[$key]['last'], $row['failed_at']);
        }

        $order = array_keys($groups);
        usort($order, static fn (string $a, string $b): int => [$groups[$b]['last'], $a] <=> [$groups[$a]['last'], $b]);
        $keys = array_slice($order, $offset, FailureGroups::PAGE_SIZE);
        $size = FailureGroups::bucketSeconds($scope);
        $stats = KeyedFailures::stats($scope, $keys);
        $buckets = KeyedFailures::buckets($scope, $keys, $size);
        $display = KeyedFailures::rowsById(array_map(static fn (string $key): int => $groups[$key]['keyed_id'], $keys));
        $messages = FailureScan::displayMessages(array_values($display));
        $page = [];

        foreach ($keys as $key) {
            $group = $groups[$key];
            $shown = $display[$group['keyed_id']] ?? null;
            $message = $shown === null ? null : $projection->message($messages[$shown->id] ?? null);
            $page[] = self::summary($key, $shown, $message, $stats[$key], array_keys($group['queues']), $group['scanned'])
                + ['buckets' => self::buckets($stats[$key], $buckets[$key], $scope, $size, $asOf, $retentionFrom)];
        }

        return [
            'truncated' => $keyed['truncated'],
            'scanned' => count($keyed['rows']),
            'scanned_from' => $keyed['oldest'],
            'total' => count($groups),
            'offset' => $offset,
            'per_page' => FailureGroups::PAGE_SIZE,
            'bucket_seconds' => $size,
            'bucket_label' => FailureGroups::bucketLabel($size),
            'groups' => $page,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidFailureRequest when the offset is not below the cap
     * @throws RedactionConfigInvalid
     */
    public static function detail(FailureScope $scope, string $key, int $offset, float $asOf, ?float $retentionFrom): array
    {
        $projection = self::projection();
        $cap = KeyedFailures::cap();

        if ($offset >= $cap) {
            throw new InvalidFailureRequest("offset is below the group count cap ({$cap}).");
        }

        $stats = KeyedFailures::stats($scope, [$key], true)[$key];
        $size = FailureGroups::bucketSeconds($scope);
        $page = KeyedFailures::members($scope, $key, FailureGroups::PAGE_SIZE, $offset);
        $selection = KeyedFailures::members($scope, $key, KeyedFailures::SELECTION);
        $newest = $selection[0] ?? null;

        $message = $newest === null ? null : $projection->message(FailureScan::displayMessages([$newest])[$newest->id] ?? null);
        $group = $newest === null ? null : self::summary($key, $newest, $message, $stats, self::queuesOf($selection), count($selection))
            + ['buckets' => self::buckets($stats, KeyedFailures::buckets($scope, [$key], $size)[$key], $scope, $size, $asOf, $retentionFrom)];

        $attempts = array_values(array_filter([$stats['attempts_min'], $stats['attempts_max']], static fn (?int $value): bool => $value !== null));

        return [
            // The total is a lower bound when the keyed count passed the cap.
            'truncated' => $stats['capped'],
            'complete' => true,
            'count_capped' => $stats['capped'],
            'scanned_from' => null,
            'bucket_seconds' => $size,
            'bucket_label' => FailureGroups::bucketLabel($size),
            'group' => $group,
            'attempts' => $attempts === [] ? null : ['min' => min($attempts), 'max' => max($attempts), 'of_newest' => $stats['capped'] ? $cap : null],
            'latest_trace' => $newest === null ? null : self::trace($newest, $projection),
            'total' => $stats['count'],
            'offset' => $offset,
            'per_page' => FailureGroups::PAGE_SIZE,
            'jobs' => self::jobs($page),
            // The confirmed operation selection: the newest pairs, independent of offset.
            'selection' => self::pairs($selection),
            'selection_total' => $stats['count'],
        ];
    }

    /**
     * @param  array{count: int, capped: bool, first_failed_at: float|null, last_failed_at: float|null, counted_from: float|null, attempts_min: int|null, attempts_max: int|null}  $stats
     * @param  list<string>  $queues
     * @return array<string, mixed>
     */
    private static function summary(string $key, ?FailedRow $shown, ?string $message, array $stats, array $queues, int $scanned): array
    {
        sort($queues);

        return [
            'key' => $key,
            'exception_class' => $shown?->exceptionClass,
            'message' => $message,
            'job' => $shown?->name,
            'queues' => $queues,
            'queues_complete' => ! $stats['capped'] && $stats['count'] <= $scanned,
            'count' => $stats['count'],
            'count_capped' => $stats['capped'],
            'exact' => ! $stats['capped'],
            'first_failed_at' => $stats['first_failed_at'],
            'last_failed_at' => $stats['last_failed_at'],
        ];
    }

    /**
     * @param  array{count: int, capped: bool, counted_from: float|null}  $stats
     * @param  array<int, int>  $keyedCounts
     * @return list<array{at: int, count: int, partial: bool}>
     */
    private static function buckets(array $stats, array $keyedCounts, FailureScope $scope, int $size, float $asOf, ?float $retentionFrom): array
    {
        return FailureGroups::bucketsOf($keyedCounts, $scope, $size, $asOf, $retentionFrom, $stats['capped'] ? $stats['counted_from'] : null);
    }

    /**
     * @param  list<FailedRow>  $rows
     * @return list<string>
     */
    private static function queuesOf(array $rows): array
    {
        return array_values(array_unique(array_map(static fn (FailedRow $row): string => $row->queueKey(), $rows)));
    }

    /**
     * @param  list<FailedRow>  $rows
     * @return list<array{uuid: string, occurrence_id: int|null, failed_at: float|null}>
     */
    private static function pairs(array $rows): array
    {
        return array_map(static fn (FailedRow $row): array => [
            'uuid' => $row->uuid,
            'occurrence_id' => $row->occurrenceId,
            'failed_at' => $row->failedAt,
        ], $rows);
    }

    /**
     * The explicit safe projection of the members of one page: no payload,
     * no trace.
     *
     * @param  list<FailedRow>  $rows
     * @return list<array{uuid: string, occurrence_id: int|null, name: string, connection: string, queue: string, pushed_at: float|null, failed_at: float|null, attempts: int, tags: list<string>}>
     */
    private static function jobs(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $uuids = array_map(static fn (FailedRow $row): string => $row->uuid, $rows);
        $pushed = Container::getInstance()->make(PackageSession::class)->mirror()->table(JobStore::TABLE)->whereIn('id', array_map(static fn (FailedRow $row): int => $row->id, $rows))->pluck('pushed_at', 'id')->all();
        $tags = [];

        foreach (Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_job_tags')->whereIn('job_uuid', $uuids)->orderBy('tag')->get(['job_uuid', 'tag']) as $tag) {
            $tags[strtolower((string) $tag->job_uuid)][] = (string) $tag->tag;
        }

        return array_map(static fn (FailedRow $row): array => [
            'uuid' => $row->uuid,
            'occurrence_id' => $row->occurrenceId,
            'name' => $row->name,
            'connection' => $row->connection,
            'queue' => $row->queue,
            'pushed_at' => is_numeric($pushed[$row->id] ?? null) ? (float) $pushed[$row->id] : null,
            'failed_at' => $row->failedAt,
            'attempts' => $row->attempts,
            'tags' => $tags[$row->uuid] ?? [],
        ], $rows);
    }

    /**
     * The full exception text of one row, as UTF-8 and redacted, read only
     * when its byte count is within JobProjection::EXCEPTION_BYTES (the size
     * decision and the value come from one row version).
     *
     * @return string|array{omitted: string, bytes: int}|null
     */
    private static function trace(FailedRow $row, JobProjection $projection): string|array|null
    {
        $stored = Container::getInstance()->make(PackageSession::class)->mirror()->table(JobStore::TABLE)
            ->where('id', $row->id)
            ->select([JobProjection::bounded('exception'), 'exception_bytes'])
            ->first();
        $text = $stored === null ? null : $stored->exception;
        $bytes = $stored === null ? null : $stored->exception_bytes;

        return $projection->exception(is_string($text) ? mb_convert_encoding($text, 'UTF-8') : null, is_numeric($bytes) ? (int) $bytes : null);
    }

    private static function projection(): JobProjection
    {
        $projection = Container::getInstance()->make(JobProjection::class);
        $projection->assertValid();

        return $projection;
    }
}
