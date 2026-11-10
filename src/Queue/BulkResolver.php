<?php

namespace Justrau\Orrery\Queue;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue as BaseDatabaseQueue;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\TrustedSql;
use RuntimeException;
use Throwable;

/**
 * The bulkPush observations of a bulk and the resolution of their job ids.
 *
 * - floor(): max(jobs.id) of the queue's table, read before the insert on
 *   the observer session of the queue object's database: its own PDO in
 *   autocommit, at the selected committed-read level (READ COMMITTED, or
 *   REPEATABLE READ on a statement-binlog MySQL server). So a failed read
 *   never touches the application's transaction.
 * - observe(): builds the bulkPush observations of one insert now and returns
 *   the work that the commit deferral runs: it inserts the
 *   bulkPush rows (`pending`, job_id_unresolved, bulk_floor_id = floor), and
 *   then runs one resolution for the whole insert.
 * - The resolution reads the jobs rows with `id > floor` of the queue, in id
 *   order, at most (count + 1,000) rows, on the observer session. A row
 *   matches a job when its payload uuid and generation equal the job's; a job
 *   without a generation matches by uuid only. A match writes the
 *   job_id with a guarded update: only a `pending` row that is still
 *   unresolved, so a reservation that resolved the row first (reserve,
 *   reserveWithoutRow) is never overwritten.
 * - resolveUnresolved(): the tick retries of unresolved rows, grouped by
 *   their queue connection, queue and floor. Each group gets at most 3
 *   attempts of at most 5,000 jobs rows each. The attempt count of a group
 *   and its scan cursor are one setting (the mirror has no column for them): the
 *   first attempt reads from the floor, a later one above the highest id
 *   that the previous read returned, and from the floor again after a read
 *   of fewer than 5,000 rows. After the last attempt, the group is read on
 *   from its cursor, one read per tick, until a read reaches the end of the
 *   queue, or until MAX_ATTEMPTS reads (failed ones included). Reconciliation
 *   never marks these rows `unknown` by absence;
 *   a reservation resolves them.
 * - Rows that never resolve (for example jobs that Laravel's
 *   queue:clear deleted before their resolution): after the last attempt and
 *   a read that reached the end of the queue (or MAX_ATTEMPTS reads), a row
 *   of the group that is still `pending` and unresolved, and older than
 *   max(retry_after, 600) s, becomes `unknown` with the reason
 *   `bulk_unresolved`, in guarded updates of at most UNKNOWN_BATCH rows,
 *   until the tick's deadline. The grace counts from available_at, else
 *   pushed_at. A marked row leaves the unresolved index (job_id_unresolved
 *   false); orrery:clear still matches it by uuid and generation. A later
 *   reservation repairs a row whose job did exist (reserveReplacement).
 *
 * Every stored time goes through Clock::toDecimal().
 */
class BulkResolver
{
    /** The hold point after the bulkPush rows, before the resolution. */
    public const BEFORE_RESOLUTION = 'bulk.before_resolution';

    /** The resolution reads at most (count + MARGIN) rows. */
    public const MARGIN = 1_000;

    public const TICK_ATTEMPTS = 3;

    /**
     * The ceiling of the reads of a group, failed reads included (about 1 h
     * of ticks): then its rows past the grace are marked although no read
     * reached the end (a target that always fails, or a queue that never
     * gives a short read).
     */
    public const MAX_ATTEMPTS = 60;

    public const TICK_ROWS = 5_000;

    /** The prefix of the attempt counters in orrery_settings. */
    public const ATTEMPTS_KEY = 'bulk_resolution:';

    /** The attempt keys that one tick examines at most. */
    public const PRUNE_PAGE = 1_000;

    /** The setting that holds the last attempt key that the cleanup examined. */
    public const CURSOR_KEY = 'bulk_resolution_cursor';

    /** The reason of a bulk row that never resolved. */
    public const UNRESOLVED_REASON = 'bulk_unresolved';

    /** The rows of one guarded update to `unknown`. */
    public const UNKNOWN_BATCH = 500;

    /** The least age of a row before it becomes `unknown`, in seconds. */
    public const MIN_GRACE_SECONDS = 600;

    /** The retry_after of a queue connection that sets none (Laravel's default). */
    private const DEFAULT_RETRY_AFTER = 60;

    /** The most rows of one group that one tick marks `unknown`. */
    private const UNKNOWN_ROWS = self::TICK_ROWS;

    private const GROUP_PAGE = 100;

    private const RESOLUTION = 'bulk:resolution';

    private const P2_ROW = 'bulk:p2_row';

    private const TICK_READ = 'bulk:tick_read';

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly JobStore $store,
    ) {
    }

    /**
     * max(jobs.id) of the queue object's table on its observer session; 0
     * when the table is empty. The floor covers the whole table: ids grow
     * across queues, so it is never above a later insert of this queue.
     */
    public function floor(string $connection, string $queue): int
    {
        $max = $this->withSession($connection, static fn (Connection $session, QueueTarget $target): mixed => $session->table($target->table)->max('id'));

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * The bulkPush observations of one insert, built now, and the work that
     * runs them: the bulkPush rows in insert order, each in its own watched
     * attempt, then one resolution. Null when no payload gives an
     * observation.
     *
     * The commit deferral holds this work as one item, so a dropped bulk
     * insert counts one in `unverified` or `rollback_unverified`: the
     * counters count deferred items, not jobs.
     *
     * @param  list<string>  $payloads  the inserted payloads, in insert order
     * @param  array<int, int|null>  $delays  the delay of each payload in seconds, by position
     * @return (Closure(): void)|null
     */
    public function observe(string $connection, string $queue, array $payloads, int $floor, array $delays = []): ?Closure
    {
        $batch = new BulkBatch($connection, $queue, $floor);
        $at = Clock::now();

        foreach ($payloads as $index => $payload) {
            $observation = Observation::fromPayload(Observation::BULK_PUSH, $payload, $connection, $queue, [
                'at' => $at,
                'instrumented' => true,
                'bulkFloor' => $floor,
                'delay' => $delays[$index] ?? null,
            ]);

            if ($observation !== null) {
                $batch->add($observation);
            }
        }

        if ($batch->count() === 0) {
            return null;
        }

        $store = $this->store;

        return function () use ($store, $batch): void {
            $watcher = Watcher::instance();

            // Each bulkPush row on its own: one row's error skips neither the
            // later rows nor the resolution. Each write frees its decoded
            // copy; the resolution reads only the uuid and the generation.
            foreach ($batch->observations() as $observation) {
                try {
                    $watcher->attempt(self::P2_ROW, static fn () => $store->observe($observation));
                } finally {
                    $observation->forgetDecoded();
                }
            }

            $this->resolveBatch($batch);
        };
    }

    /**
     * The tick retries: at most TICK_ATTEMPTS per group, TICK_ROWS jobs rows
     * per attempt. Returns the number of rows that it resolved.
     */
    public function resolveUnresolved(TickBudget $budget): int
    {
        $mirror = Container::getInstance()->make(PackageSession::class)->mirror();
        $this->pruneAttemptKeys($mirror, $budget);

        $resolved = 0;
        $after = null;

        while (! $budget->exhausted()) {
            $floors = $this->unresolved($mirror)
                ->when($after !== null, static fn ($query) => $query->where('bulk_floor_id', '>', $after))
                ->distinct()
                ->orderBy('bulk_floor_id')
                ->limit(self::GROUP_PAGE)
                ->pluck('bulk_floor_id')
                ->map(static fn (mixed $floor): int => (int) $floor)
                ->all();

            if ($floors === []) {
                break;
            }

            $groups = $this->unresolved($mirror)
                ->select(['connection', 'queue', 'bulk_floor_id'])
                ->whereIn('bulk_floor_id', $floors)
                ->distinct()
                ->orderBy('bulk_floor_id')
                ->get();

            $keys = [];

            foreach ($groups as $group) {
                $keys[] = self::attemptsKey((string) $group->connection, (string) $group->queue, (int) $group->bulk_floor_id);
            }

            $attempts = [];

            foreach ($mirror->table('orrery_settings')->whereIn('key', $keys)->get(['key', 'value']) as $setting) {
                $value = json_decode((string) $setting->value, true);
                $attempts[(string) $setting->key] = is_array($value) ? $value : [];
            }

            foreach ($groups as $group) {
                if ($budget->exhausted()) {
                    break 2;
                }

                $resolved += $this->resolveGroup($mirror, (string) $group->connection, (string) $group->queue, (int) $group->bulk_floor_id, $attempts, $budget);
            }

            $after = max($floors);
        }

        return $resolved;
    }

    /**
     * The session that reads the queue object's jobs table: the observer
     * session of its database connection, never the caller's
     * connection.
     */
    protected function readSession(string $connection): Connection
    {
        return $this->sessions->for($this->target($connection)->name);
    }

    /**
     * The one resolution of an insert, after its bulkPush rows.
     */
    private function resolveBatch(BulkBatch $batch): void
    {
        Watcher::instance()->run(self::RESOLUTION, function () use ($batch): void {
            Hooks::reach(self::BEFORE_RESOLUTION);

            $rows = $this->readAbove($batch->connection, $batch->queue, $batch->floor, $batch->count() + self::MARGIN);
            $matches = self::match(self::wanted($batch->observations()), $rows);

            foreach ($batch->observations() as $observation) {
                $id = $matches[BulkBatch::key($observation)] ?? null;

                if ($id !== null) {
                    $this->resolveRow($observation->uuid, $observation->generation, $id);
                }
            }
        });
    }

    /**
     * One tick attempt for a group. The attempt is counted before the read,
     * so a read that fails also uses one attempt.
     *
     * The scan cursor is stored with the attempt count: the first attempt
     * reads from the floor; each later attempt reads above the highest jobs
     * id that the previous read returned, and from the floor again when that
     * read returned fewer than TICK_ROWS rows. It is never derived from the
     * mirror's job_id: a release moves a resolved member's job_id above the
     * others.
     *
     * After the last attempt, the group is read on, from the stored cursor
     * and one read per tick, until a read reaches the end of the queue (a
     * short read), or until MAX_ATTEMPTS reads, failed ones included. Only
     * then are its rows past the grace marked `unknown`.
     *
     * @param  array<string, array<mixed>>  $attempts  the stored values by key
     */
    private function resolveGroup(Connection $mirror, string $connection, string $queue, int $floor, array $attempts, TickBudget $budget): int
    {
        $key = self::attemptsKey($connection, $queue, $floor);
        ['attempts' => $used, 'cursor' => $cursor, 'scanned' => $scanned] = self::attemptState($attempts[$key] ?? [], $connection, $queue, $floor);
        $after = $cursor !== null && $cursor > $floor ? $cursor : $floor;

        if ($used >= self::TICK_ATTEMPTS && ($scanned || $used >= self::MAX_ATTEMPTS)) {
            $this->markUnresolvable($mirror, $connection, $queue, $floor, $budget);

            return 0;
        }

        $this->storeAttempt($mirror, $key, $connection, $queue, $floor, $used + 1, $cursor, $scanned);

        // D18: no automatic read of a host SQLite file that does not allow
        // it. The attempt still counts, so the group is marked `unknown` at
        // the attempt ceiling instead of staying pending for ever. A target
        // that cannot be resolved (a connection removed or renamed since the
        // push) fails this group's attempt only, never the later groups.
        $readable = Watcher::instance()->attempt(self::TICK_READ, fn (): bool => Container::getInstance()->make(HostFiles::class)->allowsReads($this->target($connection)->name));

        if (! $readable->succeeded() || $readable->value() !== true) {
            return 0;
        }

        $wanted = [];

        foreach ($this->unresolved($mirror)
            ->where('connection', $connection)
            ->where('queue', $queue)
            ->where('bulk_floor_id', $floor)
            ->orderBy('id')
            ->limit(self::TICK_ROWS)
            ->get(['uuid', 'cycle_gen']) as $row) {
            $generation = is_string($row->cycle_gen) && $row->cycle_gen !== '' ? $row->cycle_gen : null;
            $wanted[strtolower((string) $row->uuid)."\0".($generation ?? '')] = [strtolower((string) $row->uuid), $generation];
        }

        if ($wanted === []) {
            return 0;
        }

        $read = Watcher::instance()->attempt(self::TICK_READ, fn (): array => $this->readAbove($connection, $queue, $after, self::TICK_ROWS));

        if (! $read->succeeded()) {
            return 0;
        }

        /** @var array<int, \stdClass> $rows */
        $rows = $read->value();
        $resolved = 0;

        // The next attempt continues above this read, or wraps to the floor
        // after a read that reached the end of the queue.
        $short = count($rows) < self::TICK_ROWS;
        $next = $short ? null : max(array_map(static fn (\stdClass $row): int => (int) $row->id, $rows));

        foreach (self::match($wanted, $rows) as $wantedKey => $id) {
            [$uuid, $generation] = $wanted[$wantedKey];
            $resolved += $this->resolveRow($uuid, $generation, $id);
        }

        if (! $this->groupUnresolved($mirror, $connection, $queue, $floor)) {
            $mirror->table('orrery_settings')->where('key', $key)->delete();
        } elseif ($next !== $cursor || ($short && ! $scanned)) {
            $this->storeAttempt($mirror, $key, $connection, $queue, $floor, $used + 1, $next, $scanned || $short);
        }

        return $resolved;
    }

    /**
     * The stored state of a group: its attempts, its scan cursor and whether
     * a read reached the end of the queue. A value whose names or floor
     * differ from the group's is a fresh key.
     *
     * @param  array<mixed>  $value
     * @return array{attempts: int, cursor: int|null, scanned: bool}
     */
    private static function attemptState(array $value, string $connection, string $queue, int $floor): array
    {
        $fresh = ['attempts' => 0, 'cursor' => null, 'scanned' => false];

        if (($value['names'] ?? null) !== self::names($connection, $queue) || ! is_numeric($value['floor'] ?? null) || (int) $value['floor'] !== $floor) {
            return $fresh;
        }

        return [
            'attempts' => is_numeric($value['attempts'] ?? null) ? (int) $value['attempts'] : 0,
            'cursor' => is_numeric($value['cursor'] ?? null) ? (int) $value['cursor'] : null,
            'scanned' => ($value['scanned'] ?? false) === true,
        ];
    }

    /**
     * The exact names of a group, for any bytes: base64 of
     * connection NUL queue (a queue name need not be valid UTF-8).
     */
    private static function names(string $connection, string $queue): string
    {
        return base64_encode($connection."\0".$queue);
    }

    /**
     * The group of a stored value: the exact names when it has them, else
     * its readable fields.
     *
     * @param  array<mixed>  $value
     * @return array{string, string, int}
     */
    private static function groupOf(array $value): array
    {
        $names = is_string($value['names'] ?? null) ? base64_decode($value['names'], true) : false;
        $floor = is_numeric($value['floor'] ?? null) ? (int) $value['floor'] : 0;

        if (is_string($names) && str_contains($names, "\0")) {
            [$connection, $queue] = explode("\0", $names, 2);

            return [$connection, $queue, $floor];
        }

        return [is_string($value['connection'] ?? null) ? $value['connection'] : '', is_string($value['queue'] ?? null) ? $value['queue'] : '', $floor];
    }

    private function storeAttempt(Connection $mirror, string $key, string $connection, string $queue, int $floor, int $attempts, ?int $cursor, bool $scanned): void
    {
        // The readable names may lose invalid UTF-8 bytes; `names` keeps them.
        $value = json_encode([
            'connection' => $connection,
            'queue' => $queue,
            'names' => self::names($connection, $queue),
            'floor' => $floor,
            'attempts' => $attempts,
            'cursor' => $cursor,
            'scanned' => $scanned,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $mirror->table('orrery_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value]);
    }

    /**
     * After the last attempt of a group: its rows that are still `pending`
     * and unresolved, and whose available_at (else pushed_at) is older than
     * the grace, become `unknown` with the reason `bulk_unresolved`, and
     * leave the unresolved index. Each batch is one guarded update, so a row
     * that a reservation resolved in the meantime is never changed. It stops
     * at the tick's cooperative deadline.
     */
    private function markUnresolvable(Connection $mirror, string $connection, string $queue, int $floor, TickBudget $budget): void
    {
        $now = Clock::now();
        $cutoff = Clock::toDecimal($now - $this->grace($connection));
        $time = Clock::toDecimal($now);
        $marked = 0;

        while ($marked < self::UNKNOWN_ROWS && ! $budget->exhausted()) {
            $ids = $this->unresolved($mirror)
                ->where('connection', $connection)
                ->where('queue', $queue)
                ->where('bulk_floor_id', $floor)
                // COALESCE(available_at, pushed_at) < cutoff: a delayed job
                // gets its grace from the time it becomes available.
                ->where(static fn ($query) => $query
                    ->where('available_at', '<', $cutoff)
                    ->orWhere(static fn ($query) => $query->whereNull('available_at')->where('pushed_at', '<', $cutoff)))
                ->orderBy('id')
                ->limit(self::UNKNOWN_BATCH)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return;
            }

            $this->unresolved($mirror)
                ->where('bulk_floor_id', $floor)
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'unknown',
                    'job_id_unresolved' => false,
                    'finished_at' => $time,
                    'unknown_since' => $time,
                    'unknown_reason' => self::UNRESOLVED_REASON,
                    'activity_at' => $time,
                    'updated_at' => $time,
                ]);

            $marked += count($ids);

            if (count($ids) < self::UNKNOWN_BATCH) {
                return;
            }
        }
    }

    /**
     * The grace of a queue connection: max(retry_after, 600) seconds.
     */
    private function grace(string $connection): float
    {
        $retryAfter = Container::getInstance()->make('config')->get("queue.connections.{$connection}.retry_after", self::DEFAULT_RETRY_AFTER);

        return (float) max(is_numeric($retryAfter) ? (int) $retryAfter : self::DEFAULT_RETRY_AFTER, self::MIN_GRACE_SECONDS);
    }

    /**
     * Removes the attempt counters of groups that have no unresolved row any
     * more (a reservation resolved them). It reads at most PRUNE_PAGE keys
     * per call, from a stored cursor, so the keys of groups that stay
     * unresolved never hide the later keys. A page that it read to its end
     * and that was not full sends the next call back to the first key. It
     * stops at the tick's cooperative deadline.
     */
    private function pruneAttemptKeys(Connection $mirror, TickBudget $budget): void
    {
        if ($budget->exhausted()) {
            return;
        }

        $cursor = $mirror->table('orrery_settings')->where('key', self::CURSOR_KEY)->value('value');
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

        $settings = $mirror->table('orrery_settings')
            ->where('key', 'like', self::ATTEMPTS_KEY.'%')
            ->when($cursor !== null, static fn ($query) => $query->where('key', '>', $cursor))
            ->orderBy('key')
            ->limit(self::PRUNE_PAGE)
            ->get(['key', 'value']);

        $last = null;
        $examined = 0;

        foreach ($settings as $setting) {
            if ($budget->exhausted()) {
                break;
            }

            $last = (string) $setting->key;
            $examined++;
            $value = json_decode((string) $setting->value, true);

            if (is_array($value) && $this->groupUnresolved($mirror, ...self::groupOf($value))) {
                continue;
            }

            $mirror->table('orrery_settings')->where('key', $last)->delete();
        }

        $next = $examined === count($settings) && count($settings) < self::PRUNE_PAGE ? null : ($last ?? $cursor);

        if ($next === null) {
            $mirror->table('orrery_settings')->where('key', self::CURSOR_KEY)->delete();
        } else {
            $mirror->table('orrery_settings')->updateOrInsert(['key' => self::CURSOR_KEY], ['value' => $next]);
        }
    }

    private function groupUnresolved(Connection $mirror, string $connection, string $queue, int $floor): bool
    {
        return $this->unresolved($mirror)
            ->where('connection', $connection)
            ->where('queue', $queue)
            ->where('bulk_floor_id', $floor)
            ->exists();
    }

    /**
     * The unresolved bulk rows. The query starts with the flag, which the
     * index (job_id_unresolved, bulk_floor_id, id) serves: a mirror without
     * unresolved rows costs one index probe, not a read of every pending row
     * (the tick benchmark found it).
     */
    private function unresolved(Connection $mirror): \Illuminate\Database\Query\Builder
    {
        return $mirror->table(JobStore::TABLE)
            ->where('job_id_unresolved', true)
            ->whereNotNull('bulk_floor_id')
            ->where('status', 'pending')
            ->whereNull('job_id');
    }

    /**
     * The guarded resolution of one row: only a `pending` row of that
     * generation that is still unresolved. Returns the affected rows.
     */
    private function resolveRow(string $uuid, ?string $generation, int $jobId): int
    {
        $time = Clock::toDecimal(Clock::now());

        $query = Container::getInstance()->make(PackageSession::class)->mirror()->table(JobStore::TABLE)
            ->where('uuid', $uuid)
            ->where('status', 'pending')
            ->where('job_id_unresolved', true)
            ->whereNull('job_id');

        if ($generation === null) {
            $query->whereNull('cycle_gen');
        } else {
            $query->where('cycle_gen', $generation);
        }

        return $query->update([
            'job_id' => $jobId,
            'job_id_unresolved' => false,
            'cycle_first_job_id' => TrustedSql::expression('COALESCE(cycle_first_job_id, %s)', $jobId),
            'updated_at' => $time,
        ]);
    }

    /**
     * The jobs rows of the queue above the floor, in id order.
     *
     * @return array<int, \stdClass>
     */
    private function readAbove(string $connection, string $queue, int $floor, int $limit): array
    {
        return $this->withSession($connection, static fn (Connection $session, QueueTarget $target): array => $session->table($target->table)
            ->select(['id', 'payload'])
            ->where('id', '>', $floor)
            ->where('queue', $queue)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all());
    }

    /**
     * @template T
     *
     * @param  Closure(Connection, QueueTarget): T  $work
     * @return T
     */
    private function withSession(string $connection, Closure $work): mixed
    {
        $target = $this->target($connection);
        $session = $this->readSession($connection);

        try {
            return $work($session, $target);
        } catch (Throwable $error) {
            // A target session never reconnects transparently: the next read opens a new one.
            try {
                $this->sessions->disconnect($session);
            } catch (Throwable) {
            }

            throw $error;
        }
    }

    /**
     * @param  list<Observation>  $observations
     * @return array<string, array{string, ?string}> [uuid, generation] by observation key
     */
    private static function wanted(array $observations): array
    {
        $wanted = [];

        foreach ($observations as $observation) {
            $wanted[BulkBatch::key($observation)] = [$observation->uuid, $observation->generation];
        }

        return $wanted;
    }

    /**
     * The first row (lowest id) whose payload uuid and generation equal each
     * wanted job's; a wanted job without a generation matches by uuid only.
     *
     * @param  array<string, array{string, ?string}>  $wanted
     * @param  array<int, \stdClass>  $rows
     * @return array<string, int> jobs.id by wanted key
     */
    private static function match(array $wanted, array $rows): array
    {
        $index = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row->payload ?? ''), true);

            if (! is_array($decoded)) {
                continue;
            }

            $uuid = PayloadIdentity::uuidOf((string) $row->payload);

            if ($uuid === null) {
                continue;
            }

            $generation = $decoded['orreryGen'] ?? null;
            $index[$uuid][] = [is_string($generation) ? $generation : null, (int) $row->id];
        }

        $matches = [];

        foreach ($wanted as $key => [$uuid, $generation]) {
            foreach ($index[$uuid] ?? [] as [$rowGeneration, $id]) {
                if ($generation === null || $rowGeneration === $generation) {
                    $matches[$key] = $id;

                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * The database target of the queue object that the queue manager holds
     * for the connection: its connection
     * instance, that instance's name and the queue's table.
     */
    private function target(string $connection): QueueTarget
    {
        $queue = Container::getInstance()->make('queue')->connection($connection);

        if (! $queue instanceof BaseDatabaseQueue) {
            throw new RuntimeException("The queue connection [{$connection}] is not a database queue, so its jobs rows cannot be read.");
        }

        $database = $queue->getDatabase();
        $name = (string) $database->getName();
        $table = (string) Closure::bind(static fn (BaseDatabaseQueue $queue): string => (string) $queue->table, null, BaseDatabaseQueue::class)($queue);

        if ($name === '' || $table === '') {
            throw new RuntimeException("The queue connection [{$connection}] has no database connection name or table.");
        }

        return new QueueTarget($database, $name, $table);
    }

    private static function attemptsKey(string $connection, string $queue, int $floor): string
    {
        return self::ATTEMPTS_KEY.sha1($connection."\0".$queue."\0".$floor);
    }
}
