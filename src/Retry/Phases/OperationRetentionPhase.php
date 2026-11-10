<?php

namespace Justrau\Orrery\Retry\Phases;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Justrau\Orrery\Http\Bulk\BulkRun;
use Justrau\Orrery\Http\Bulk\BulkRuns;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\FenceTargets;
use Justrau\Orrery\Retry\OperationRunner;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;

/**
 * The tick phase `operation_retention` (priority 520): the
 * fence rows and the finished operations.
 *
 * The fence: per database connection with a fence (every
 * `database` queue connection's database and the failed-record connection
 * of `database-uuids`), never on a host SQLite file (D17), a keyset position
 * POSITION_PREFIX.<connection> (created_at, operation_id, slot) in
 * orrery_settings. Each run inspects at most FENCE_ROWS rows after the
 * position. A row may go only when its operation is terminal (done or
 * aborted in the ledger, or absent from it: the ledger deletes only terminal
 * operations), and its deadline is more than orrery.retry.fence_retention_days
 * before now by that database's clock (read once per run). All slots of one
 * operation in that database go together in one transaction, so a restore
 * row never outlives its delete row. The position moves past every
 * inspected row, held ones included, and wraps at the end.
 *
 * The ledger: done and aborted operations created more than
 * orrery.operations.retention_days before the mirror database's clock, at
 * most LEDGER_ROWS per run.
 *
 * The bulk runs: the audit rows whose last_run_at is more
 * than orrery.operations.retention_days before the mirror database's clock,
 * at most BulkRuns::RETENTION_ROWS per run, through the index (last_run_at,
 * ref). A mirror before schema step 8 has no such table: nothing to do.
 */
final class OperationRetentionPhase implements TickPhase
{
    public const FENCE_ROWS = 500;

    public const LEDGER_ROWS = 1000;

    public const POSITION_PREFIX = 'fence_retention:';

    /** Inside the delete transaction of one operation, between its slots. */
    public const BETWEEN_SLOTS = 'fence_retention.between_slots';

    private const SETTINGS = 'orrery_settings';

    /** How long a connection whose fence run failed is skipped, in seconds. */
    public const UNAVAILABLE_SECONDS = 60.0;

    /** @var array<string, float> the connections whose last fence run failed, with the monotonic time */
    private array $unavailable = [];

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
        private readonly Fence $fence,
        private readonly HostFiles $files,
        private readonly FenceTargets $targets,
    ) {}

    public function name(): string
    {
        return 'operation_retention';
    }

    public function priority(): int
    {
        return 520;
    }

    public function run(TickBudget $budget): void
    {
        $deferred = $this->deferred();

        foreach ($this->connections() as $connection) {
            if ($budget->exhausted()) {
                return;
            }

            // A connection that the operation runner deferred after a connect or lock failure waits too.
            if (in_array($connection, $deferred, true) || $this->recentlyUnavailable($connection)) {
                continue;
            }

            $result = Watcher::instance()->attempt('operation_retention:fence', fn () => $this->fenceRun($connection));

            if (! $result->succeeded()) {
                $this->unavailable[$connection] = Clock::monotonic();
            }
        }

        if (! $budget->exhausted()) {
            $this->ledgerRun();
        }

        if (! $budget->exhausted()) {
            Watcher::instance()->attempt('operation_retention:bulk_runs', fn (): int => $this->bulkRunRun());
        }
    }

    /**
     * One run of the bulk run retention; the number of deleted runs.
     */
    public function bulkRunRun(): int
    {
        $mirror = $this->sessions->mirror();

        if (! $mirror->getSchemaBuilder()->hasTable(BulkRuns::TABLE)) {
            return 0;
        }

        $now = DatabaseClock::now($mirror);

        // Never a run whose token may still continue it: its audit row holds the forget ceiling.
        return BulkRuns::deleteOlderThan($mirror, min($now - $this->days('orrery.operations.retention_days', 30) * 86400, $now - BulkRun::EXPIRY_SECONDS));
    }

    /**
     * One fence run on one database connection: the inspected and the
     * deleted rows.
     *
     * @return array{inspected: int, deleted: int}
     */
    public function fenceRun(string $connection): array
    {
        // D17: no automatic write to a host SQLite file; its rows are tiny and only operator actions create them.
        if ($this->files->isHost($connection) || ! $this->fence->available($connection)) {
            return ['inspected' => 0, 'deleted' => 0];
        }

        $session = $this->sessions->for($connection);
        $mirror = $this->sessions->mirror();
        $cutoff = DatabaseClock::now($session) - $this->days('orrery.retry.fence_retention_days', 30) * 86400;
        $key = self::POSITION_PREFIX.$connection;
        $position = $this->position($mirror, $key);

        $query = $session->table(Fence::TABLE)->select(['operation_id', 'slot', 'deadline', 'created_at']);

        if ($position !== null) {
            $query->where(static function (Builder $query) use ($position): void {
                $query->where('created_at', '>', $position['at'])
                    ->orWhere(static function (Builder $query) use ($position): void {
                        $query->where('created_at', '=', $position['at'])->where(static function (Builder $query) use ($position): void {
                            $query->where('operation_id', '>', $position['id'])
                                ->orWhere(static function (Builder $query) use ($position): void {
                                    $query->where('operation_id', '=', $position['id'])->where('slot', '>', $position['slot']);
                                });
                        });
                    });
            });
        }

        $rows = $query->orderBy('created_at')->orderBy('operation_id')->orderBy('slot')->limit(self::FENCE_ROWS)->get()->all();

        if ($rows === []) {
            $mirror->table(self::SETTINGS)->where('key', $key)->delete();

            return ['inspected' => 0, 'deleted' => 0];
        }

        $old = [];

        foreach ($rows as $row) {
            if ((float) $row->deadline < $cutoff) {
                $old[(string) $row->operation_id] = true;
            }
        }

        $held = $old === [] ? [] : $mirror->table(Operations::TABLE)
            ->whereIn('id', array_keys($old))
            ->whereNotIn('state', Operations::TERMINAL)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $deleted = 0;

        foreach (array_keys($old) as $operationId) {
            $operationId = (string) $operationId;

            if (in_array($operationId, $held, true)) {
                continue;
            }

            $deleted += $this->transactions->run($session, function (Connection $tx) use ($operationId, $cutoff): int {
                $slots = $tx->table(Fence::TABLE)->where('operation_id', $operationId)->orderBy('slot')->pluck('slot')->all();
                $count = 0;

                foreach (array_values($slots) as $index => $slot) {
                    if ($index > 0) {
                        Hooks::reach(self::BETWEEN_SLOTS);
                    }

                    $count += $tx->table(Fence::TABLE)
                        ->where('operation_id', $operationId)
                        ->where('slot', (string) $slot)
                        ->where('deadline', '<', Clock::toDecimal($cutoff))
                        ->delete();
                }

                return $count;
            });
        }

        $last = $rows[count($rows) - 1];

        if (count($rows) < self::FENCE_ROWS) {
            // The end of the table: the next run starts at the beginning.
            $mirror->table(self::SETTINGS)->where('key', $key)->delete();
        } else {
            $value = json_encode([
                'at' => is_string($last->created_at) ? $last->created_at : Clock::toDecimal((float) $last->created_at),
                'id' => (string) $last->operation_id,
                'slot' => (string) $last->slot,
            ], JSON_THROW_ON_ERROR);
            $mirror->table(self::SETTINGS)->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
        }

        return ['inspected' => count($rows), 'deleted' => $deleted];
    }

    /**
     * One ledger run; the number of deleted operations.
     */
    public function ledgerRun(): int
    {
        $mirror = $this->sessions->mirror();
        $cutoff = DatabaseClock::now($mirror) - $this->days('orrery.operations.retention_days', 30) * 86400;
        $ids = $mirror->table(Operations::TABLE)
            ->where('created_at', '<', Clock::toDecimal($cutoff))
            ->whereIn('state', Operations::TERMINAL)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::LEDGER_ROWS)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return $mirror->table(Operations::TABLE)->whereIn('id', $ids)->whereIn('state', Operations::TERMINAL)->delete();
    }

    /**
     * The database connections with a fence, each once (FenceTargets).
     *
     * @return list<string>
     */
    public function connections(): array
    {
        return $this->targets->connections();
    }

    private function recentlyUnavailable(string $connection): bool
    {
        $at = $this->unavailable[$connection] ?? null;

        return $at !== null && Clock::monotonic() - $at < self::UNAVAILABLE_SECONDS;
    }

    /**
     * The database connections that the operation runner deferred until a
     * time after now (OperationRunner::DEFER_PREFIX in orrery_settings).
     *
     * @return list<string>
     */
    private function deferred(): array
    {
        $mirror = $this->sessions->mirror();
        $now = DatabaseClock::now($mirror);
        $deferred = [];

        foreach ($mirror->table(self::SETTINGS)->where('key', 'like', OperationRunner::DEFER_PREFIX.'%')->get(['key', 'value']) as $row) {
            $value = is_string($row->value) ? json_decode($row->value, true) : null;

            if (is_array($value) && is_numeric($value['until'] ?? null) && (float) $value['until'] > $now) {
                $deferred[] = substr((string) $row->key, strlen(OperationRunner::DEFER_PREFIX));
            }
        }

        return $deferred;
    }

    private function days(string $key, int $default): float
    {
        $days = $this->config()->get($key, $default);

        return is_numeric($days) && (float) $days >= 0 ? (float) $days : (float) $default;
    }

    /**
     * @return array{at: string, id: string, slot: string}|null
     */
    private function position(Connection $mirror, string $key): ?array
    {
        $stored = $mirror->table(self::SETTINGS)->where('key', $key)->value('value');
        $value = is_string($stored) ? json_decode($stored, true) : null;

        if (! is_array($value) || ! is_string($value['at'] ?? null) || ! is_string($value['id'] ?? null) || ! is_string($value['slot'] ?? null)) {
            return null;
        }

        return ['at' => $value['at'], 'id' => $value['id'], 'slot' => $value['slot']];
    }

    /**
     * The configuration of the running request, read when it is used: this
     * object is resolved at boot, and an Octane request has its
     * own configuration sandbox.
     */
    private function config(): Repository
    {
        return Container::getInstance()->make('config');
    }
}
