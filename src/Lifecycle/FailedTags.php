<?php

namespace Justrau\Orrery\Lifecycle;

use Illuminate\Database\Connection;

/**
 * The failed-tag rows: they exist exactly for jobs whose
 * current status is `failed`. fail and failWithoutRow write at most 50 of
 * them, in the mirror transaction of the failure, and refresh finished_at
 * of existing ones. A retry deletes them, and its abort restores them.
 */
final class FailedTags
{
    public const TABLE = 'orrery_failed_tags';

    /**
     * @param  array<int, string>  $tags
     */
    public static function write(Connection $tx, string $uuid, array $tags, string $finishedAt): void
    {
        $rows = array_map(
            static fn (string $tag): array => ['tag' => $tag, 'job_uuid' => $uuid, 'finished_at' => $finishedAt],
            array_slice(array_values(array_unique($tags)), 0, Rules::MAX_TAGS),
        );

        if ($rows === []) {
            return;
        }

        $tx->table(self::TABLE)->upsert($rows, ['job_uuid', 'tag'], ['finished_at']);
    }
}
