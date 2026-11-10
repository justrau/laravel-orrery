<?php

namespace Justrau\Orrery\Lifecycle;

use Illuminate\Database\Connection;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Storage\Clock;

/**
 * The retention of the stuck incidents: an
 * incident lives while its cycle is the job's current cycle or has its own
 * cycle summary.
 *
 * - mergedCycle(): the freeze that merges a cycle's summary into the
 *   job's merged row (CycleSummaries::compact()) deletes that cycle's
 *   incidents in the same transaction. So a job keeps at most 51 cycles × 5
 *   signals = 255 incidents.
 * - deleteForJobs(): every path that deletes a job's mirror row (a forget,
 *   `orrery:forget --all`, a trim and `orrery:clear`) deletes its incidents
 *   in the same transaction.
 *
 * `orrery_incidents` comes with schema step 9. Until v0.1 a MySQL or
 * Postgres mirror that was migrated before step 9 has no such table, so
 * every method first checks it with a cached hasTable (60 s per process and
 * connection): a missing table is not an error (S-12).
 *
 * Not final: a test binds a subclass that records what
 * beforeMergedCycleDelete() sees. LifecycleModule binds this class as a
 * singleton, and CycleSummaries resolves it from the container when it
 * compacts, so a bound subclass is used.
 */
class IncidentCleanup
{
    public const TABLE = PackageSchema::INCIDENTS;

    public const CACHE_SECONDS = 60.0;

    /** @var array<string, array{exists: bool, at: float}> the hasTable results per connection name */
    private array $tables = [];

    /** @var array<string, array{exists: bool, at: float}> the notification-column results per connection name */
    private array $notifyColumns = [];

    /**
     * Deletes the merged cycle's incidents. It runs in the freeze
     * transaction $tx, through the primary key (job_uuid, cycle, signal).
     */
    public function mergedCycle(Connection $tx, string $uuid, int $cycle, float $at): void
    {
        if (! $this->exists($tx)) {
            return;
        }

        $this->beforeMergedCycleDelete($tx, $uuid, $cycle, $at);

        $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->delete();
    }

    /**
     * Deletes every incident of the jobs (trim, forget and clear delete a
     * job with its history, summaries and incidents).
     *
     * @param  list<string>  $uuids
     */
    public function deleteForJobs(Connection $tx, array $uuids): void
    {
        if ($uuids === [] || ! $this->exists($tx)) {
            return;
        }

        foreach (array_chunk($uuids, 500) as $chunk) {
            $tx->table(self::TABLE)->whereIn('job_uuid', $chunk)->delete();
        }
    }

    /**
     * Whether `orrery_incidents` exists on the connection, cached for 60 s
     * on the monotonic clock.
     */
    public function exists(Connection $connection): bool
    {
        $key = (string) $connection->getName();
        $now = Clock::monotonic();
        $cached = $this->tables[$key] ?? null;

        if ($cached !== null && $now - $cached['at'] < self::CACHE_SECONDS) {
            return $cached['exists'];
        }

        $exists = $connection->getSchemaBuilder()->hasTable(self::TABLE);
        $this->tables[$key] = ['exists' => $exists, 'at' => $now];

        return $exists;
    }

    /**
     * Whether `orrery_incidents` has the notification state of schema step
     * 10 (`notify_next_at`), cached for 60 s as exists(). A mirror migrated
     * before step 10 keeps its stuck events; only its notifications wait.
     */
    public function notifyColumns(Connection $connection): bool
    {
        $key = (string) $connection->getName();
        $now = Clock::monotonic();
        $cached = $this->notifyColumns[$key] ?? null;

        if ($cached !== null && $now - $cached['at'] < self::CACHE_SECONDS) {
            return $cached['exists'];
        }

        $exists = $this->exists($connection) && $connection->getSchemaBuilder()->hasColumn(self::TABLE, 'notify_next_at');
        $this->notifyColumns[$key] = ['exists' => $exists, 'at' => $now];

        return $exists;
    }

    /**
     * Runs in $tx before mergedCycle() deletes the merged cycle's incidents
     * (a test seam). Does nothing.
     */
    protected function beforeMergedCycleDelete(Connection $tx, string $uuid, int $cycle, float $at): void
    {
    }
}
