<?php

namespace Justrau\Orrery\Lifecycle;

use Illuminate\Database\Connection;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\TrustedSql;

/**
 * The history rows: exception, timeout, failed, completed
 * (cycle > 1), late_outcome, and the retry events retried, retry_aborted
 * and retry_uncertain.
 *
 * Deduplication uses the unique key (job_uuid, dedupe_key), so two jobs
 * never share a key. Counter idempotency never depends on these rows: the
 * store inserts a keyed row only after its guarded update changed the row.
 * The only exception is late_outcomes, whose `late:<cycle>:<n>` row decides
 * the count.
 *
 * Bounds: every insert that adds a row enforces them in the
 * same transaction. A cycle keeps its first 5 and its latest 45 rows, and a
 * job keeps at most 200 rows; the rows of the oldest cycles go first, and
 * their summaries stay. The job row gets history_truncated = true and
 * history_discarded + the number of dropped rows. The retained summaries of
 * the cycles that lost rows get history_truncated = true.
 *
 * Serialisation: insert() first locks the job row
 * (lockForUpdate), and so does every other path that inserts history (a
 * guarded update of the row, JobStore::applyLocked(),
 * CycleSummaries::applyLateEvidence()). So two transactions that add history
 * rows of one job, also of different cycles, never count the job's rows
 * from the same snapshot, and the 200-row bound holds. The lock order is
 * always the job row, then the summaries, then the history.
 */
final class History
{
    public const TABLE = 'orrery_job_history';

    public const KEEP_FIRST = 5;

    public const KEEP_LATEST = 45;

    public const PER_JOB = 200;

    /** The hold point after the bounds of an added row, before the transaction commits. */
    public const AFTER_BOUNDS = 'history.after_bounds';

    /**
     * @param  array<string, mixed>  $exception  the exception columns of ExceptionText::columns()
     * @return array<string, mixed>
     */
    public static function row(string $uuid, int $cycle, ?int $attempt, string $event, float $at, ?string $dedupeKey = null, array $exception = []): array
    {
        return [
            'job_uuid' => $uuid,
            'cycle' => $cycle,
            'attempt' => $attempt,
            'event' => $event,
            'source' => null,
            'reason' => null,
            'dedupe_key' => $dedupeKey,
            'exception_class' => $exception['exception_class'] ?? null,
            'exception_message' => $exception['exception_message'] ?? null,
            'exception' => $exception['exception'] ?? null,
            'exception_bytes' => $exception['exception_bytes'] ?? null,
            'context' => $exception['context'] ?? null,
            'context_bytes' => $exception['context_bytes'] ?? null,
            'occurred_at' => Clock::toDecimal($at),
        ];
    }

    /**
     * The key of an exception or timeout row: `<event>:<cycle>:<n>`.
     */
    public static function dedupeKey(string $event, int $cycle, int $attempt): string
    {
        return "{$event}:{$cycle}:{$attempt}";
    }

    /**
     * The key of a late outcome row: `late:<cycle>:<n>`.
     */
    public static function lateKey(int $cycle, int $attempt): string
    {
        return "late:{$cycle}:{$attempt}";
    }

    /**
     * Inserts one history row and enforces the bounds in the same
     * transaction. $fields holds `occurred_at` (a Clock::toDecimal() text)
     * and the optional columns (the exception columns, source, reason,
     * context). A keyed row that exists already is ignored.
     *
     * @param  array<string, mixed>  $fields
     * @return int|null the id of the added row, or null when the key existed
     */
    public function insert(Connection $tx, string $uuid, int $cycle, ?int $attempt, string $event, array $fields, ?string $dedupeKey): ?int
    {
        $row = [
            'job_uuid' => $uuid,
            'cycle' => $cycle,
            'attempt' => $attempt,
            'event' => $event,
            'dedupe_key' => $dedupeKey,
        ] + $fields + [
            'source' => null,
            'reason' => null,
            'exception_class' => null,
            'exception_message' => null,
            'exception' => null,
            'exception_bytes' => null,
            'context' => null,
            'context_bytes' => null,
        ];

        // The job row lock serialises the insert and its count per job (a no-op on SQLite, where BEGIN IMMEDIATE already holds the write lock).
        $tx->table(JobStore::TABLE)->where('uuid', $uuid)->lockForUpdate()->first(['id']);

        if ($dedupeKey === null) {
            $id = (int) $tx->table(self::TABLE)->insertGetId($row);
        } else {
            if ($tx->table(self::TABLE)->insertOrIgnore($row) === 0) {
                return null;
            }

            $id = (int) $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('dedupe_key', $dedupeKey)->value('id');
        }

        $this->enforceBounds($tx, $uuid);
        Hooks::reach(self::AFTER_BOUNDS);

        return $id;
    }

    /**
     * 50 rows per cycle (the first 5 and the latest 45), 200 rows per job
     * (the oldest cycles first).
     */
    public function enforceBounds(Connection $tx, string $uuid): void
    {
        /** @var array<int, int> $counts cycle => rows */
        $counts = [];

        foreach ($tx->table(self::TABLE)->where('job_uuid', $uuid)->groupBy('cycle')->orderBy('cycle')->get(['cycle', $tx->raw('COUNT(*) AS rows_in_cycle')]) as $group) {
            $counts[(int) $group->cycle] = (int) $group->rows_in_cycle;
        }

        $perCycle = self::KEEP_FIRST + self::KEEP_LATEST;

        if (array_sum($counts) <= self::PER_JOB && max([0, ...$counts]) <= $perCycle) {
            return;
        }

        $dropped = [];

        foreach ($counts as $cycle => $rows) {
            if ($rows <= $perCycle) {
                continue;
            }

            $ids = $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $drop = array_slice($ids, self::KEEP_FIRST, count($ids) - $perCycle);

            if ($drop !== []) {
                $this->deleteIds($tx, $drop);
                $dropped[$cycle] = ($dropped[$cycle] ?? 0) + count($drop);
                $counts[$cycle] -= count($drop);
            }
        }

        $excess = array_sum($counts) - self::PER_JOB;

        if ($excess > 0) {
            $oldest = $tx->table(self::TABLE)->where('job_uuid', $uuid)->orderBy('cycle')->orderBy('id')->limit($excess)->get(['id', 'cycle']);
            $this->deleteIds($tx, $oldest->map(static fn (object $row): int => (int) $row->id)->all());

            foreach ($oldest as $row) {
                $dropped[(int) $row->cycle] = ($dropped[(int) $row->cycle] ?? 0) + 1;
            }
        }

        if ($dropped === []) {
            return;
        }

        $tx->table(JobStore::TABLE)->where('uuid', $uuid)->update([
            'history_truncated' => true,
            'history_discarded' => TrustedSql::expression('history_discarded + %s', array_sum($dropped)),
        ]);

        $tx->table(CycleSummaries::TABLE)->where('job_uuid', $uuid)->whereIn('cycle', array_keys($dropped))->update(['history_truncated' => true]);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function deleteIds(Connection $tx, array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $tx->table(self::TABLE)->whereIn('id', $chunk)->delete();
        }
    }
}
