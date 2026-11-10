<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Database\Schema\SchemaOutdated;
use Justrau\Orrery\Events\StuckJobDetected;
use Justrau\Orrery\Http\Lists\ListRange;
use Justrau\Orrery\Http\Lists\Scan;
use Justrau\Orrery\Http\Lists\ScanItem;
use Justrau\Orrery\Http\Lists\StuckList;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use stdClass;

/**
 * The stuck incidents and their delivery as StuckJobDetected.
 * The tick phase `stuck_incidents` creates the incidents once
 * per tick, inside CREATE_SECONDS (2 s) and the tick budget; the late phase
 * `stuck_events` delivers them, inside a share of SHARE_SECONDS (3 s), also
 * after the tick's deadline, so a slow listener of the application delays
 * no other phase.
 *
 * **The incidents.** `orrery_incidents` keeps one row per (`job_uuid`,
 * `cycle`, `signal`): the four signals of StuckSignals and `unknown`
 * (`status = unknown`). The evidence is the signal's facts (numbers and enum
 * values) as a JSON object of at most EVIDENCE_BYTES; a longer encoding is
 * `{"truncated":true}`.
 *
 * **1. Create** (D-4). Per signal, at most CREATE_ROWS (500) examined rows
 * through the signal's index ranges (StuckList::ranges() and fetch()). The
 * creation needs no merged order, so it reads the ranges one after another,
 * each in its list order, after the persisted cursor
 * `incidents_cursor:<signal>` in orrery_settings: `{r, o, k}`, the range and
 * its last examined row (`o` and `k` null for the start of range `r`). After
 * the last range the cursor is deleted, so the next run starts again at the
 * beginning (a wrap). The matching rows get `insertOrIgnore` of (job_uuid,
 * the cycle that the same read gave, signal, now, the evidence), with the
 * cursor, in one package transaction. So 1,200 matching rows all get
 * incidents within 3 runs, however they are spread over the ranges.
 * - The signals start at the one that the settings row `incidents_start`
 *   names, which moves on by one each run, and no signal starts after
 *   CREATE_SECONDS (2 s): a tight share still reaches every signal over
 *   consecutive runs.
 * - `unknown` reads `st_finished` (`status = unknown`, `finished_at >=
 *   incidents_since`, oldest first: both writers of an unknown row set
 *   `finished_at = unknown_since`), with the residual `unknown_since >=
 *   incidents_since`. The settings row `incidents_since` is written by the
 *   first run (insertOrIgnore, so two first runs agree), so an install does
 *   not raise one event per old unknown row.
 * - A signal whose deferred index is missing is skipped.
 *   The missing set is read for every signal before the creation, whatever
 *   the run then reaches. `index_missing` is recorded only when that set
 *   changes
 *   (the settings row `incidents_index_missing`): orrery:tick is a new
 *   process every minute, so the once-per-process rule of DeferredIndexes
 *   alone would record it every tick. /api/health lists the
 *   missing indexes meanwhile.
 *
 * **2. Deliver** (D-3). At most DELIVER_ROWS (50) due incidents
 * (`delivered_at IS NULL AND delivery_failed_at IS NULL AND attempts_made <
 * 3 AND (next_attempt_at IS NULL OR next_attempt_at <= now)`, the `due`
 * index), the retries first (`attempts_made` descending), then the new
 * ones. The delivery stops when the mirror breaker is open: the watcher
 * would skip the dispatch, and a claim would spend an attempt. Each:
 * 1. a guarded claim: `attempts_made = k + 1`, `next_attempt_at = now +
 *    backoff(k + 1)` (60 s, 300 s, none), `delivery_failed_at = now` when
 *    k + 1 = 3, else null, `WHERE` the key `AND attempts_made = k AND
 *    attempts_made < 3 AND delivered_at IS NULL AND delivery_failed_at IS
 *    NULL`. Only one process wins a claim;
 * 2. a read of the job row: a row that is gone, or whose current cycle is
 *    not the incident's cycle, deletes the incident instead;
 * 3. `StuckJobDetected` through Watcher::attempt() (`incidents:dispatch`);
 * 4. on success `delivered_at = now`, `delivery_failed_at = NULL`.
 *
 * The third claim marks `delivery_failed_at` before it dispatches, so no
 * fourth claim is possible: at most 3 dispatches per incident. A process
 * that stops between a dispatch and `delivered_at` can cause a duplicate
 * within those 3; a process killed between the third claim and its
 * dispatch leaves 2 dispatches. Delivery is not guaranteed when a
 * listener of the application fails every time.
 *
 * The notifications of the incidents are a phase of their own,
 * `stuck_notifications` (StuckNotifications),
 * so a slow channel delays no other phase. The creation marks an incident
 * due to notify (`notify_next_at`) when the mirror has schema step 10 and
 * `orrery.stuck.notify` is true; a mirror before step 10 keeps its events.
 *
 * **Times** are the PHP clock (Clock::now()) of the tick.
 *
 * **Schema.** A mirror without `orrery_incidents` (a MySQL or Postgres
 * mirror migrated before step 9) fails the phase with SchemaOutdated
 * (`schema_outdated`), recorded by the watcher.
 *
 * Hold points (tests): AFTER_SIGNAL after each signal's creation;
 * BEFORE_CLAIM after the due read, AFTER_CLAIM after a won claim and
 * AFTER_DISPATCH after a dispatch, each per incident.
 *
 * Built for each tick run; it holds no state.
 */
final class StuckIncidents
{
    public const TABLE = PackageSchema::INCIDENTS;

    /** The signal of a row whose status is `unknown`. */
    public const UNKNOWN = 'unknown';

    /** Every incident signal, in creation order. */
    public const SIGNALS = [...StuckSignals::SIGNALS, self::UNKNOWN];

    /** The most rows that one run examines per signal. */
    public const CREATE_ROWS = 500;

    /** The most incidents that one run delivers. */
    public const DELIVER_ROWS = 50;

    /** The most claims (and so dispatches) per incident. */
    public const MAX_ATTEMPTS = 3;

    /** The seconds after the 1st and the 2nd claim before the next claim; the 3rd has none. */
    public const BACKOFF = [1 => 60, 2 => 300];

    /** The share of one tick, in seconds. */
    public const SHARE_SECONDS = 3.0;

    /** The part of the share after which no further signal starts its creation: the rest is the delivery's. */
    public const CREATE_SECONDS = 2.0;

    /** The longest stored evidence, in bytes. */
    public const EVIDENCE_BYTES = 1024;

    /** The evidence that replaces a longer encoding. */
    public const TRUNCATED = '{"truncated":true}';

    /** The settings key prefix of the creation cursor of each signal. */
    public const CURSOR_PREFIX = 'incidents_cursor:';

    /** The settings key of the first run's time: no `unknown` incident for an older unknown row. */
    public const SINCE = 'incidents_since';

    /** The settings key of the signal that the next run creates first (a rotation). */
    public const START = 'incidents_start';

    /** The settings key of the missing indexes that `index_missing` last recorded. */
    public const INDEX_MISSING = 'incidents_index_missing';

    /** The unknown reasons that the evidence names; another reason is left out. */
    public const UNKNOWN_REASONS = [Reconciliation::UNKNOWN_REASON, BulkResolver::UNRESOLVED_REASON];

    /**
     * The unknown reasons that raise no incident: `connection_removed` rests
     * on this process's queue configuration, and a stale configuration on the
     * scheduler host can mark live jobs so.
     */
    public const SILENT_REASONS = [Reconciliation::REMOVED_REASON];

    /** The watched operation of a dispatch. */
    public const DISPATCH_OPERATION = 'incidents:dispatch';

    public const AFTER_SIGNAL = 'incidents.after_signal';

    public const BEFORE_CLAIM = 'incidents.before_claim';

    public const AFTER_CLAIM = 'incidents.after_claim';

    public const AFTER_DISPATCH = 'incidents.after_dispatch';

    private const SETTINGS = 'orrery_settings';

    /** The columns of the unknown read. */
    private const UNKNOWN_COLUMNS = ['id', 'uuid', 'cycle', 'status', 'finished_at', 'unknown_since', 'unknown_reason'];

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly StuckList $list,
        private readonly StuckSignals $signals,
        private readonly DeferredIndexes $indexes,
        private readonly IncidentCleanup $cleanup,
        private readonly PackageTransaction $transactions,
        private readonly Watcher $watcher,
        private readonly StuckNotifications $notifications,
    ) {
    }

    /**
     * One run: creation, then delivery, within the share and the budget.
     * The tick runs the two in separate phases (createIncidents() in
     * `stuck_incidents`, deliverEvents() in `stuck_events`); this is the
     * whole run of one call (tests, tools).
     *
     * @return array{created: array<string, int>, skipped: list<string>, claimed: int, delivered: int, failed: int, deleted: int}
     *
     * @throws SchemaOutdated
     */
    public function run(TickBudget $budget): array
    {
        $started = Clock::monotonic();
        $over = static fn (): bool => Clock::monotonic() >= $started + self::SHARE_SECONDS || $budget->exhausted();
        $report = $this->createIncidents($budget);

        return $this->deliver($this->sessions->mirror(), $over, $report);
    }

    /**
     * The delivery of the due incidents as StuckJobDetected, within
     * SHARE_SECONDS and the budget: the phase `stuck_events`, late in the
     * tick, so a slow listener of the application delays no
     * other phase.
     *
     * @return array{created: array<string, int>, skipped: list<string>, claimed: int, delivered: int, failed: int, deleted: int}
     *
     * @throws SchemaOutdated
     */
    public function deliverEvents(TickBudget $budget): array
    {
        $started = Clock::monotonic();
        $over = static fn (): bool => Clock::monotonic() >= $started + self::SHARE_SECONDS || $budget->exhausted();
        $mirror = $this->sessions->mirror();

        if (! $this->cleanup->exists($mirror)) {
            throw SchemaOutdated::table(self::TABLE);
        }

        return $this->deliver($mirror, $over, ['created' => [], 'skipped' => [], 'claimed' => 0, 'delivered' => 0, 'failed' => 0, 'deleted' => 0]);
    }

    /**
     * The creation of the incidents, within CREATE_SECONDS and the budget:
     * the phase `stuck_incidents`.
     *
     * @return array{created: array<string, int>, skipped: list<string>, claimed: int, delivered: int, failed: int, deleted: int}
     *
     * @throws SchemaOutdated
     */
    public function createIncidents(TickBudget $budget): array
    {
        $started = Clock::monotonic();
        $creationOver = static fn (): bool => Clock::monotonic() >= $started + self::CREATE_SECONDS || $budget->exhausted();
        $mirror = $this->sessions->mirror();

        if (! $this->cleanup->exists($mirror)) {
            throw SchemaOutdated::table(self::TABLE);
        }

        $report = ['created' => [], 'skipped' => [], 'claimed' => 0, 'delivered' => 0, 'failed' => 0, 'deleted' => 0];
        $since = $this->since($mirror, Clock::now());

        // Every signal's index first, whatever this run reaches (DeferredIndexes caches the catalog read).
        $missing = [];

        foreach (self::SIGNALS as $signal) {
            $index = StuckList::INDEX[$signal] ?? null;

            if ($index !== null && array_key_exists($index, PackageSchema::DEFERRED_INDEXES) && ! $this->indexes->has($mirror, $index)) {
                $missing[$signal] = $index;
            }
        }

        $this->recordMissing($mirror, array_values($missing));

        foreach ($this->rotation($mirror) as $signal) {
            if (array_key_exists($signal, $missing)) {
                $report['skipped'][] = $signal;

                continue;
            }

            if ($creationOver()) {
                break;
            }

            $report['created'][$signal] = $this->create($mirror, $signal, $since, Clock::now());
            Hooks::reach(self::AFTER_SIGNAL);
        }

        return $report;
    }

    /**
     * The signals in this run's order: from the one that the settings row
     * START names, which then moves to the next one. So a tight share that
     * stops the creation after a few signals still reaches every signal
     * over consecutive runs.
     *
     * @return list<string>
     */
    private function rotation(Connection $mirror): array
    {
        $stored = $mirror->table(self::SETTINGS)->where('key', self::START)->value('value');
        $count = count(self::SIGNALS);
        $start = is_numeric($stored) && (int) $stored >= 0 && (int) $stored < $count ? (int) $stored : 0;
        $mirror->table(self::SETTINGS)->upsert([['key' => self::START, 'value' => (string) (($start + 1) % $count)]], ['key'], ['value']);

        return [...array_slice(self::SIGNALS, $start), ...array_slice(self::SIGNALS, 0, $start)];
    }

    /**
     * The evidence column of $facts: a JSON object of at most
     * EVIDENCE_BYTES, else TRUNCATED.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function evidence(array $facts): string
    {
        $json = json_encode((object) $facts, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false || strlen($json) > self::EVIDENCE_BYTES ? self::TRUNCATED : $json;
    }

    /**
     * The backoff after claim $made (1 or 2), or null after the last one.
     */
    public static function backoff(int $made): ?int
    {
        return self::BACKOFF[$made] ?? null;
    }

    /**
     * Step 1 for one signal: the matching rows of at most CREATE_ROWS
     * examined ones after the cursor. Returns the incidents that it
     * inserted (an existing incident is ignored by the insert).
     */
    private function create(Connection $mirror, string $signal, string $since, float $now): int
    {
        if ($signal === self::UNKNOWN) {
            $ranges = [$this->unknownRange($mirror, $since)];
            $ascending = true;
            $fetch = function (ListRange $range, bool $ascending, ?array $after, int $limit) use ($mirror): array {
                /** @var array{0: string|null, 1: int|string}|null $after */
                return $this->fetchUnknown($mirror, $range, $after, $limit);
            };
        } else {
            $ranges = $this->list->ranges($mirror, $signal, null, null, $now);
            $ascending = StuckList::ascending($signal);
            $fetch = function (ListRange $range, bool $ascending, ?array $after, int $limit) use ($mirror): array {
                /** @var array{0: string|null, 1: int|string}|null $after */
                return $this->list->fetch($mirror, $range, $ascending, $after, $limit, ['cycle']);
            };
        }

        $cursorKey = self::CURSOR_PREFIX.$signal;
        $cursor = $this->cursor($mirror, $cursorKey);
        $first = $cursor !== null && $cursor['r'] < count($ranges) ? $cursor['r'] : 0;
        $start = $cursor !== null && $cursor['r'] === $first && $cursor['o'] !== null && $cursor['k'] !== null ? [$cursor['o'], $cursor['k']] : null;
        $time = Clock::toDecimal($now);
        // A mirror before schema step 10 has no notification state: its incidents carry none.
        $columns = $this->cleanup->notifyColumns($mirror);
        $notify = $columns && $this->notifications->enabled();
        $remaining = self::CREATE_ROWS;
        $rows = [];
        // Null: the last range ended, so the next run starts again at the beginning (a wrap).
        $next = null;

        // The ranges one after another, each in its own order: the creation needs no merged order.
        for ($r = $first; $r < count($ranges); $r++) {
            if ($remaining <= 0) {
                $next = ['r' => $r, 'o' => null, 'k' => null];

                break;
            }

            $scan = new Scan([$ranges[$r]], $fetch, $ascending, $r === $first ? $start : null, $remaining, $remaining);
            $last = null;

            while (($item = $scan->next()) !== null) {
                $last = $item;
                $job = $item->job;
                $facts = $job === null ? null : $this->facts($signal, $job, $since, $now);

                if ($job === null || $facts === null) {
                    continue;
                }

                $row = [
                    'job_uuid' => (string) $job->uuid,
                    'cycle' => (int) $job->cycle,
                    'signal' => $signal,
                    'first_seen_at' => $time,
                    'evidence' => self::evidence($facts),
                    'attempts_made' => 0,
                ];

                if ($columns) {
                    // Due to notify now (StuckNotifications), unless the notifications are off.
                    $row['notify_next_at'] = $notify ? $time : null;
                }

                $rows[] = $row;
            }

            $remaining -= $scan->examined;

            if ($scan->limited) {
                // The budget stopped the scan: the next run continues after its last returned row.
                $next = $last === null
                    ? ['r' => $r, 'o' => $r === $first ? $start[0] ?? null : null, 'k' => $r === $first ? $start[1] ?? null : null]
                    : ['r' => $r, 'o' => $last->o, 'k' => $last->k];

                break;
            }
        }

        return $this->transactions->run($mirror, static function (Connection $tx) use ($rows, $cursorKey, $next): int {
            $inserted = 0;

            foreach (array_chunk($rows, 100) as $chunk) {
                $inserted += $tx->table(self::TABLE)->insertOrIgnore($chunk);
            }

            if ($next === null) {
                $tx->table(self::SETTINGS)->where('key', $cursorKey)->delete();
            } else {
                $tx->table(self::SETTINGS)->upsert([['key' => $cursorKey, 'value' => json_encode($next, JSON_THROW_ON_ERROR)]], ['key'], ['value']);
            }

            return $inserted;
        });
    }

    /**
     * The facts of $signal for a scanned row, or null when it does not hold.
     *
     * @return array<string, int|float|string>|null
     */
    private function facts(string $signal, stdClass $job, string $since, float $now): ?array
    {
        if ($signal !== self::UNKNOWN) {
            return $this->signals->facts($signal, $job, $now);
        }

        $unknownSince = $job->unknown_since ?? null;

        if (($job->status ?? null) !== 'unknown' || ! is_numeric($unknownSince) || (float) $unknownSince < (float) $since) {
            return null;
        }

        $reason = $job->unknown_reason ?? null;

        if (in_array($reason, self::SILENT_REASONS, true)) {
            return null;
        }

        return in_array($reason, self::UNKNOWN_REASONS, true) ? ['reason' => $reason] : [];
    }

    /**
     * The read of `unknown`: st_finished, `status = unknown`, `finished_at
     * >= since`, ascending.
     */
    private function unknownRange(Connection $mirror, string $since): ListRange
    {
        return new ListRange(static function () use ($mirror): Builder {
            $query = $mirror->table('orrery_jobs');

            IndexName::force($query, $mirror, 'orrery_jobs', 'st_finished');

            return $query->where('status', 'unknown');
        }, 'finished_at', 'id', false, $since);
    }

    /**
     * Reads one chunk of the unknown range after $after, in index order.
     *
     * @param  array{0: string|null, 1: int|string}|null  $after
     * @return list<ScanItem>
     */
    private function fetchUnknown(Connection $mirror, ListRange $range, ?array $after, int $limit): array
    {
        $query = $range->query();
        $range->bound($query, $after, true);

        if ($after !== null && $after[0] !== null) {
            if (in_array($mirror->getDriverName(), ['mysql', 'mariadb'], true)) {
                $query->where('finished_at', '>=', $after[0])
                    ->where(static function (Builder $query) use ($after): void {
                        $query->where('finished_at', '>', $after[0])->orWhere('id', '>', $after[1]);
                    });
            } else {
                $query->whereRaw('("finished_at", "id") > (?, ?)', [$after[0], $after[1]]);
            }
        }

        $range->orderBy($query, 'asc')->limit($limit);
        $items = [];

        foreach ($query->get(self::UNKNOWN_COLUMNS)->all() as $row) {
            $items[] = new ScanItem(Clock::toDecimal((float) $row->finished_at), (int) $row->id, $row);
        }

        return $items;
    }

    /**
     * Step 2: delivers at most DELIVER_ROWS due incidents.
     *
     * @param  \Closure(): bool  $over
     * @param  array{created: array<string, int>, skipped: list<string>, claimed: int, delivered: int, failed: int, deleted: int}  $report
     * @return array{created: array<string, int>, skipped: list<string>, claimed: int, delivered: int, failed: int, deleted: int}
     */
    private function deliver(Connection $mirror, \Closure $over, array $report): array
    {
        $now = Clock::toDecimal(Clock::now());
        $due = $mirror->table(self::TABLE)
            ->whereNull('delivered_at')
            ->whereNull('delivery_failed_at')
            ->where('attempts_made', '<', self::MAX_ATTEMPTS)
            ->where(static function (Builder $query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            // The retries first (at most 2 per incident), then the new incidents.
            ->orderByDesc('attempts_made')
            ->orderByDesc('next_attempt_at')
            ->limit(self::DELIVER_ROWS)
            ->get(['job_uuid', 'cycle', 'signal', 'attempts_made', 'evidence'])
            ->all();

        foreach ($due as $incident) {
            if ($over()) {
                break;
            }

            if ($this->watcher->breakerOpen()) {
                // The watcher would skip the dispatch: a claim now would spend an attempt for nothing.
                break;
            }

            Hooks::reach(self::BEFORE_CLAIM);
            $uuid = (string) $incident->job_uuid;
            $cycle = (int) $incident->cycle;
            $signal = (string) $incident->signal;
            $made = (int) $incident->attempts_made;

            if ($made >= self::MAX_ATTEMPTS || ! $this->claim($mirror, $uuid, $cycle, $signal, $made)) {
                continue;
            }

            $report['claimed']++;
            Hooks::reach(self::AFTER_CLAIM);

            $job = $mirror->table('orrery_jobs')->where('uuid', $uuid)->first(['cycle', 'status', 'connection', 'queue', 'name']);

            if (! $job instanceof stdClass || (int) $job->cycle !== $cycle || ! self::stillHolds($signal, (string) $job->status)) {
                // The job is gone, its cycle ended, or it left the state of its signal (it finished in
                // the same cycle): the incident no longer describes it.
                $mirror->table(self::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->where('signal', $signal)->delete();
                $report['deleted']++;

                continue;
            }

            $event = new StuckJobDetected($uuid, $cycle, $signal, (string) $job->connection, (string) $job->queue, (string) $job->name, self::decode($incident->evidence));
            $result = $this->watcher->attempt(self::DISPATCH_OPERATION, static function () use ($event): void {
                Container::getInstance()->make(Dispatcher::class)->dispatch($event);
            });
            Hooks::reach(self::AFTER_DISPATCH);

            if (! $result->succeeded()) {
                $report['failed']++;

                continue;
            }

            $this->key($mirror, $uuid, $cycle, $signal)
                ->whereNull('delivered_at')
                ->update(['delivered_at' => Clock::toDecimal(Clock::now()), 'delivery_failed_at' => null]);
            $report['delivered']++;
        }

        return $report;
    }

    /**
     * Whether a job row in $status can still have the incident's signal:
     * `unknown` for UNKNOWN, else an active status (StuckSignals::ACTIVE).
     * The signal's own facts are not evaluated again: they can change
     * within a state without the job leaving it.
     */
    public static function stillHolds(string $signal, string $status): bool
    {
        return $signal === self::UNKNOWN ? $status === 'unknown' : in_array($status, StuckSignals::ACTIVE, true);
    }

    /**
     * The guarded claim of attempt $made + 1. True when this process won it.
     */
    private function claim(Connection $mirror, string $uuid, int $cycle, string $signal, int $made): bool
    {
        $at = Clock::now();
        $next = $made + 1;
        $backoff = self::backoff($next);

        return $this->key($mirror, $uuid, $cycle, $signal)
            ->where('attempts_made', $made)
            ->where('attempts_made', '<', self::MAX_ATTEMPTS)
            ->whereNull('delivered_at')
            ->whereNull('delivery_failed_at')
            ->update([
                'attempts_made' => $next,
                'next_attempt_at' => $backoff === null ? null : Clock::toDecimal($at + $backoff),
                // The last claim marks the failure before it dispatches: no fourth claim is possible.
                'delivery_failed_at' => $next >= self::MAX_ATTEMPTS ? Clock::toDecimal($at) : null,
            ]) === 1;
    }

    private function key(Connection $mirror, string $uuid, int $cycle, string $signal): Builder
    {
        return $mirror->table(self::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->where('signal', $signal);
    }

    /**
     * The stored evidence as an array; anything else as the truncated mark.
     *
     * @return array<string, mixed>
     */
    private static function decode(mixed $evidence): array
    {
        $value = is_string($evidence) ? json_decode($evidence, true) : null;

        if (! is_array($value)) {
            return ['truncated' => true];
        }

        $facts = [];

        foreach ($value as $key => $fact) {
            $facts[(string) $key] = $fact;
        }

        return $facts;
    }

    /**
     * The time of the first run (`incidents_since`), written once.
     */
    private function since(Connection $mirror, float $now): string
    {
        $stored = $mirror->table(self::SETTINGS)->where('key', self::SINCE)->value('value');

        if (! is_numeric($stored)) {
            $mirror->table(self::SETTINGS)->insertOrIgnore(['key' => self::SINCE, 'value' => Clock::toDecimal($now)]);
            $stored = $mirror->table(self::SETTINGS)->where('key', self::SINCE)->value('value');
        }

        return Clock::toDecimal(is_numeric($stored) ? (float) $stored : $now);
    }

    /**
     * The stored cursor of a signal: the range `r` and the last examined row
     * (`o`, `k`) in it, both null for the start of that range; null for the
     * start of the first range.
     *
     * @return array{r: int, o: string|null, k: int|null}|null
     */
    private function cursor(Connection $mirror, string $key): ?array
    {
        $stored = $mirror->table(self::SETTINGS)->where('key', $key)->value('value');
        $value = is_string($stored) ? json_decode($stored, true) : null;

        if (! is_array($value) || ! is_int($value['r'] ?? null) || $value['r'] < 0) {
            return null;
        }

        $order = $value['o'] ?? null;
        $id = $value['k'] ?? null;

        if ($order === null && $id === null) {
            return ['r' => $value['r'], 'o' => null, 'k' => null];
        }

        if (! is_string($order) || ! is_numeric($order) || ! is_int($id)) {
            return null;
        }

        return ['r' => $value['r'], 'o' => $order, 'k' => $id];
    }

    /**
     * Records `index_missing` when the missing indexes differ from those it
     * last recorded (a settings row), and clears the row when none is
     * missing.
     *
     * @param  list<string>  $missing
     */
    private function recordMissing(Connection $mirror, array $missing): void
    {
        $missing = array_values(array_unique($missing));
        sort($missing);
        $stored = $mirror->table(self::SETTINGS)->where('key', self::INDEX_MISSING)->value('value');

        if ($missing === []) {
            if ($stored !== null) {
                $mirror->table(self::SETTINGS)->where('key', self::INDEX_MISSING)->delete();
            }

            return;
        }

        $value = json_encode($missing, JSON_THROW_ON_ERROR);

        if ($stored === $value) {
            return;
        }

        $this->indexes->recordOnce($missing);
        $mirror->table(self::SETTINGS)->upsert([['key' => self::INDEX_MISSING, 'value' => $value]], ['key'], ['value']);
    }
}
