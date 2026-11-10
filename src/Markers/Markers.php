<?php

namespace Justrau\Orrery\Markers;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\SafeText;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;

/**
 * The chart markers: orrery_markers.
 *
 * - `restart`: a change of Laravel's `illuminate:queue:restart` cache value,
 *   seen by the tick phase `restart_markers` (observeRestart()). The label is
 *   always "Restart requested": one sampled key cannot prove that a worker
 *   exited, or that a deploy happened.
 * - `deploy`: written by `orrery:deployed` at now, once per label within
 *   its window (deploy()).
 * - The unique key (kind, at, source) removes duplicates.
 * - Retention: RETENTION_SECONDS (7 days), oldest first, at most
 *   RETENTION_MAX_ROWS rows per tick (prune()).
 */
final class Markers
{
    public const TABLE = 'orrery_markers';

    public const RESTART = 'restart';

    public const DEPLOY = 'deploy';

    public const RESTART_LABEL = 'Restart requested';

    public const RESTART_SOURCE = 'queue:restart';

    public const DEPLOY_SOURCE = 'orrery:deployed';

    /** The cache key of Laravel's RestartCommand and Worker. */
    public const CACHE_KEY = 'illuminate:queue:restart';

    /** The last observed restart value, kept independently of the marker retention. */
    public const CHECKPOINT_KEY = 'restart_marker_checkpoint';

    /** The orrery_settings row that serialises the deploy markers (deploy()). */
    public const DEPLOY_LOCK_KEY = 'deploy_marker_lock';

    /** The hook point of deploy(): after the window check, before the insert, inside the lock. */
    public const BEFORE_DEPLOY_INSERT = 'markers.deploy.before_insert';

    /** The default window of `orrery:deployed`, in seconds. */
    public const DEPLOY_WINDOW_SECONDS = 600;

    public const RETENTION_SECONDS = 604_800;

    public const RETENTION_MAX_ROWS = 5000;

    public const MAX_RESULTS = 200;

    /** Keeps each statement at 999 bindings or fewer (old SQLite builds). */
    private const PRUNE_CHUNK = 500;

    public function __construct(private readonly PackageTransaction $transaction)
    {
    }

    /**
     * Applies one read of the restart cache value. Returns true when it inserted a marker.
     *
     * Laravel's Worker restarts on any change of the value (it compares with
     * `!=`), so any change from the checkpoint is a restart request:
     *
     * - A missing or cleared key (null), or a value that is not a positive
     *   number, changes nothing.
     * - The first observation (no checkpoint value) sets the checkpoint, and
     *   inserts a marker only when the value is less than RETENTION_SECONDS
     *   old.
     * - Later, a value that differs from the checkpoint (newer or older)
     *   inserts a marker and moves the checkpoint to the value. An equal
     *   value changes nothing.
     * - The marker is at min(value, now), so a host whose clock runs ahead
     *   cannot put a marker in the future.
     * - The checkpoint row is created with insertOrIgnore before the
     *   transaction (as SignalState::atomically() does), so two first
     *   observations lock one existing row and never deadlock on MySQL's
     *   gap locks. The change of the checkpoint and the marker insert are one
     *   package transaction that locks that row; the unique key removes a
     *   duplicate marker.
     */
    public function observeRestart(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return false;
        }

        $observed = (float) $value;

        if (! is_finite($observed) || $observed <= 0) {
            return false;
        }

        $now = Clock::now();
        $decimal = Clock::toDecimal($observed);
        $session = Container::getInstance()->make(PackageSession::class)->mirror();

        if (! $session->table('orrery_settings')->where('key', self::CHECKPOINT_KEY)->exists()) {
            $session->table('orrery_settings')->insertOrIgnore(['key' => self::CHECKPOINT_KEY, 'value' => null]);
        }

        return $this->transaction->run($session, static function (Connection $tx) use ($observed, $decimal, $now): bool {
            $checkpoint = $tx->table('orrery_settings')->where('key', self::CHECKPOINT_KEY)->lockForUpdate()->value('value');
            $first = ! is_numeric($checkpoint);

            if (! $first && Clock::toDecimal((float) $checkpoint) === $decimal) {
                return false;
            }

            $tx->table('orrery_settings')->where('key', self::CHECKPOINT_KEY)->update(['value' => $decimal]);

            if ($first && $observed <= $now - self::RETENTION_SECONDS) {
                return false;
            }

            return $tx->table(self::TABLE)->insertOrIgnore([
                'kind' => self::RESTART,
                'at' => Clock::toDecimal(min($observed, $now)),
                'label' => self::RESTART_LABEL,
                'source' => self::RESTART_SOURCE,
            ]) > 0;
        });
    }

    /**
     * Records a deploy marker at now, at most once per cleaned label within
     * $windowSeconds, so `orrery:deployed` on N servers
     * writes one marker. In one package transaction it:
     *
     * 1. locks the settings row DEPLOY_LOCK_KEY (created before the
     *    transaction with insertOrIgnore, as observeRestart() does);
     * 2. looks for a deploy marker with the same cleaned label (NULL equals
     *    NULL; the SQL match narrows, and PHP compares byte for byte, so no
     *    collation folds case or spaces) and `at` >= now - $windowSeconds;
     * 3. inserts only when there is none.
     *
     * The time is sampled after the lock, so a waiting process sees the
     * marker of the process before it. $windowSeconds 0 always records.
     *
     * @return array{recorded: bool, at: float} the new marker's time, or the time of the marker that it found
     */
    public function deploy(?string $label, int $windowSeconds = 0): array
    {
        $label = SafeText::clean($label);
        $window = max(0, $windowSeconds);
        $session = Container::getInstance()->make(PackageSession::class)->mirror();

        if (! $session->table('orrery_settings')->where('key', self::DEPLOY_LOCK_KEY)->exists()) {
            $session->table('orrery_settings')->insertOrIgnore(['key' => self::DEPLOY_LOCK_KEY, 'value' => null]);
        }

        return $this->transaction->run($session, static function (Connection $tx) use ($label, $window): array {
            $tx->table('orrery_settings')->where('key', self::DEPLOY_LOCK_KEY)->lockForUpdate()->value('value');
            $now = Clock::now();

            if ($window > 0) {
                $recent = $tx->table(self::TABLE)
                    ->where('kind', self::DEPLOY)
                    ->where('at', '>=', Clock::toDecimal($now - $window))
                    // The SQL match may fold case or trailing spaces (MySQL collations); PHP decides.
                    ->when($label === null, static fn ($query) => $query->whereNull('label'), static fn ($query) => $query->where('label', $label))
                    ->orderByDesc('at')
                    ->orderByDesc('id')
                    ->limit(self::MAX_RESULTS)
                    ->get(['at', 'label']);

                foreach ($recent as $row) {
                    if (($row->label === null ? null : (string) $row->label) === $label) {
                        return ['recorded' => false, 'at' => (float) $row->at];
                    }
                }
            }

            Hooks::reach(self::BEFORE_DEPLOY_INSERT);

            // The unique key (kind, at, source) ignores a second marker in the same microsecond.
            $inserted = $tx->table(self::TABLE)->insertOrIgnore([
                'kind' => self::DEPLOY,
                'at' => Clock::toDecimal($now),
                'label' => $label,
                'source' => self::DEPLOY_SOURCE,
            ]);

            return ['recorded' => $inserted > 0, 'at' => $now];
        });
    }

    /**
     * The markers with `at` >= $since, newest first, at most MAX_RESULTS,
     * and whether more exist.
     *
     * @return array{0: list<array{id: int, kind: string, at: float, label: ?string, source: string}>, 1: bool}
     */
    public function since(float $since): array
    {
        $rows = Container::getInstance()->make(PackageSession::class)->mirror()->table(self::TABLE)
            ->where('at', '>=', Clock::toDecimal($since))
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS + 1)
            ->get(['id', 'kind', 'at', 'label', 'source'])
            ->all();

        $markers = [];

        foreach (array_slice($rows, 0, self::MAX_RESULTS) as $row) {
            $markers[] = [
                'id' => (int) $row->id,
                'kind' => (string) $row->kind,
                'at' => (float) $row->at,
                'label' => $row->label === null ? null : (string) $row->label,
                'source' => (string) $row->source,
            ];
        }

        return [$markers, count($rows) > self::MAX_RESULTS];
    }

    /**
     * Deletes the markers older than RETENTION_SECONDS, oldest first, at most
     * RETENTION_MAX_ROWS. Returns the number of deleted rows.
     */
    public function prune(TickBudget $budget): int
    {
        $session = Container::getInstance()->make(PackageSession::class)->mirror();
        $cutoff = Clock::toDecimal(Clock::now() - self::RETENTION_SECONDS);
        $deleted = 0;

        while ($deleted < self::RETENTION_MAX_ROWS && ! $budget->exhausted()) {
            $ids = $session->table(self::TABLE)
                ->where('at', '<', $cutoff)
                ->orderBy('at')
                ->orderBy('id')
                ->limit(min(self::PRUNE_CHUNK, self::RETENTION_MAX_ROWS - $deleted))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += $session->table(self::TABLE)->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }
}
