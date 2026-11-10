<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;

/**
 * Monitoring repair.
 *
 * - Tick selects tagged rows with monitor_version < V through the index
 *   (monitor_version, id), in chunks of 1,000 and at most 20,000 rows per
 *   tick. An untagged row has a null version, so it is never selected.
 * - Each chunk is one package transaction: V with a shared lock
 *   (MonitoringVersion::locked()), the chunk's ids, and the set-based
 *   recompute(). A row that commits late with an old version is selected
 *   by its version, whatever its id, so commit order never hides a row.
 * - recompute() is also the inline verification of retention.
 *   It compares the tags byte for byte, as the hot path does: on MySQL and
 *   MariaDB with CAST(... AS BINARY), because utf8mb4_bin pads trailing
 *   spaces.
 */
final class MonitoringRepair
{
    public const CHUNK = 1000;

    public const MAX_ROWS = 20000;

    private ?int $chunkForTesting = null;

    private ?int $rowsForTesting = null;

    public function __construct(
        private readonly PackageTransaction $transactions,
        private readonly MonitoringVersion $versions,
    ) {}

    /**
     * Repairs the rows with an old version. Returns the number of rows that
     * it recomputed.
     */
    public function run(?TickBudget $budget = null): int
    {
        $chunk = $this->chunkForTesting ?? self::CHUNK;
        $limit = $this->rowsForTesting ?? self::MAX_ROWS;
        $repaired = 0;
        $this->versions->ensureRow();

        while ($repaired < $limit && ! ($budget?->exhausted() ?? false)) {
            $size = min($chunk, $limit - $repaired);

            $done = $this->transactions->run(Container::getInstance()->make(PackageSession::class)->mirror(), function (Connection $tx) use ($size): int {
                $version = $this->versions->locked($tx);

                /** @var list<int> $ids */
                $ids = $tx->table('orrery_jobs')
                    ->where('monitor_version', '<', $version)
                    ->orderBy('monitor_version')
                    ->orderBy('id')
                    ->limit($size)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                if ($ids !== []) {
                    $this->recompute($tx, $ids, $version);
                }

                return count($ids);
            });

            $repaired += $done;

            if ($done < $size) {
                break;
            }
        }

        return $repaired;
    }

    /**
     * Recomputes `monitored` from the tag rows and the monitored-tag table,
     * and writes monitor_version = $version, for the rows of $ids whose
     * version is older. Two set-based updates, inside the caller's package
     * transaction, which holds V.
     *
     * @param  list<int>  $ids
     */
    public function recompute(Connection $tx, array $ids, int $version): void
    {
        $stale = fn (): Builder => $tx->table('orrery_jobs')
            ->whereIn('id', $ids)
            ->where('monitor_version', '<', $version);

        $binary = in_array($tx->getDriverName(), ['mysql', 'mariadb'], true);

        $stale()
            ->whereExists(static function (Builder $query) use ($binary): void {
                $query->selectRaw('1')
                    ->from('orrery_job_tags')
                    ->whereColumn('orrery_job_tags.job_uuid', 'orrery_jobs.uuid');

                if ($binary) {
                    // utf8mb4_bin pads trailing spaces (`orders ` = `orders`): the bytes decide, as
                    // the hot path's isset() on the monitored tags. Each unqualified `tag` names the
                    // table of its own FROM.
                    $query->whereIn(new Expression('CAST(`tag` AS BINARY)'), static fn (Builder $monitored): Builder => $monitored
                        ->from('orrery_monitored_tags')
                        ->select(new Expression('CAST(`tag` AS BINARY)')));
                } else {
                    $query->join('orrery_monitored_tags', 'orrery_monitored_tags.tag', '=', 'orrery_job_tags.tag');
                }
            })
            ->update(['monitored' => true, 'monitor_version' => $version]);

        $stale()->update(['monitored' => false, 'monitor_version' => $version]);
    }

    public static function useLimitsForTesting(?int $chunk = null, ?int $rows = null): void
    {
        $state = self::bound();

        $state->chunkForTesting = $chunk;
        $state->rowsForTesting = $rows;
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
