<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Database\Schema\SchemaStatus;
use Justrau\Orrery\Failed\CapturingUuidFailedJobProvider;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Http\Bulk\BulkRun;
use Justrau\Orrery\Http\Middleware\Authenticate;
use Justrau\Orrery\Http\Projection\Redactor;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Maintenance\RetentionSettings;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\Queue\Instrumentation;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\FenceTargets;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Runtime\HealthEvents;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Throwable;

/**
 * GET /api/health?from=&to=.
 *
 * - `as_of`: the request time, on the server's clock. The SPA takes the
 *   server clock from it, so a skewed browser clock never makes a fresh
 *   tick late.
 * - `windows`: the reported degradation windows that overlap the range,
 *   oldest first, at most MAX_WINDOWS. When more windows overlap, the
 *   response keeps the newest MAX_WINDOWS by `started_at` and sets
 *   `truncated` to true; else `truncated` is false (both null when the read
 *   failed). Every open window overlaps every later range, and retention
 *   deletes an open window only when its start and its last error are both
 *   older than `health.retention_days`, so only this limit bounds the
 *   response. A window overlaps when it started at or before
 *   `to` and it is open or ended at or after `from`. An open window has
 *   `ended_at` null and the label "end unknown". A window is listed for any
 *   count, also for `unverified` without a database error. `unverified`
 *   (named `ownership_unverified` before) counts the deferred queue items
 *   that the commit deferral dropped without a proof of their outcome (a bulk
 *   is one item); `rollback_unverified` counts the items
 *   under a root transaction that rolled back: usually a business rollback
 *   after a push, but a lost COMMIT acknowledgement cannot be ruled out.
 * - `tick`: `observer_tick_at`, the time of the last tick run, or null, and
 *   `state`: `ok` (a time), `none` (no tick has run) or `unreadable` (the
 *   read failed, the breaker is open, or the stored value is not a time),
 *   so a mirror that cannot be read is never "no tick yet".
 * - `missing_fence_tables`: the database queue connections
 *   whose database has no fence table (orrery_fences), or could not be
 *   checked, and the failed-record connection when a retry in mode
 *   `before` needs its fence there (FenceTargets::report()).
 * - `fence_hint`: null, or the command that fixes a missing fence table
 *   (FENCE_HINT: `php artisan orrery:install --fence`, then `migrate`).
 * - `fence_unknown_connections` (D18): the database queue
 *   connections on an application SQLite file that allows no automatic
 *   reads; their fence table is not checked (unknown, never missing).
 * - `sqlite_shared_mirror` (D17): null, or the message that
 *   refuses an orrery.database on the SQLite file of a connection that the
 *   package uses (the package writes nothing there). Then `windows`,
 *   `truncated` and `tick.observer_tick_at` are null (unknown), `tick.state`
 *   is `unreadable`, and `retention` is `{state: "unreadable"}`.
 * - `sqlite_warnings` (D18): `{file, message}` for each application
 *   SQLite file without automatic reads (enable WAL and a busy_timeout),
 *   and the hints of this process.
 * - `notice`: unreported gaps cannot be ruled out, because a process that
 *   dies during an outage reports nothing.
 * - `uninstrumented_connections`: the database queue
 *   connections whose queue object is not the package's DatabaseQueue, each
 *   `{connection, reason}`. The reason is `swap_disabled` (the connector
 *   swap is off), `foreign_queue_class` (another connector won the
 *   `database` binding), or `unknown` (the queue object could not be
 *   resolved, or the breaker is open). For these connections the package
 *   mirrors only the outcomes: no `pending` or `reserved` row.
 * - `retention`: the state that the last completed
 *   retention run wrote (Retention::STATE_KEY): `{state: "ok", at, deleted,
 *   behind, lag_seconds, partitions_unchecked}`; `{state: "none"}` when no
 *   run wrote it yet; `{state: "unreadable"}` when the read failed or the
 *   stored value is not a retention state. The client decides whether `at`
 *   is too old to be current. Rows that the counts floor holds back
 *   are held, not behind: they never count in `behind` or
 *   `lag_seconds`.
 * - `samples`: the per-minute counts frontier M
 *   (SampleCounts::COUNTED_THROUGH): `{state: "ok", counted_through,
 *   lag_seconds}` with lag_seconds = now - M; `{state: "none",
 *   counted_through: null, lag_seconds: null}` before the first count;
 *   `{state: "unreadable", ...: null}` when the read failed or the stored
 *   value is not a number, and `{state: "unknown", counted_through,
 *   lag_seconds: null}` when M is more than 60 s after now (a clock that
 *   moved back): unknown, never a lag of 0.
 *
 * - `queue_pause_cache_local`: null, or the hint that the
 *   default cache store is local: a `file` store is local to one server, so
 *   a queue pause may not reach the workers of another server; an `array`
 *   store is local to one process, so a queue pause has no effect on any
 *   worker. Laravel keeps its queue pause there; global and connection
 *   pauses still work.
 *
 * - `shared_jobs_table_retry_after` (gate fix 4): the queue connections
 *   whose queue objects share one jobs table with different retry_after
 *   values, each `{connections, table, values}` (WaitTime::
 *   sharedTableRetryAfter()): a worker of the shorter retry_after can run a
 *   job again while a worker of the longer one still holds it. Null when it
 *   could not be read.
 *
 * - `failed_records`: the failed-record adapter of the bound
 *   failer (Failed\FailedRecords): `{adapter, reason, message, binding,
 *   unprovable_failures}`. `adapter` is sql, none or read_only; `reason` and
 *   `message` give a read-only adapter's refusal (`provider_read_only`, or
 *   `ids_may_be_reused`: the failed-job table may reuse ids). `binding` is
 *   `sqlite_version` when the failed-record connection is SQLite below 3.35,
 *   where the provider binds no record id, else null. `unprovable_failures`
 *   (sql only, else null): `{count, at_least}` of the failed rows without a
 *   bound record id among the newest UNPROVABLE_WINDOW failed rows, counted
 *   up to orrery.dashboard.count_cap (`at_least` when the window is full or
 *   the count passes the cap), null when
 *   the mirror read fails or is skipped. The adapter does not depend on the
 *   mirror, so it stays known while the mirror is refused or the breaker is
 *   open. The whole field is null only when the adapter itself could not be
 *   decided (unknown, never zero).
 *
 * - `awaiting_resolution`: the active operations that wait
 *   for an operator: `{count, operations}`, the operations (at most
 *   AWAITING_LIMIT, the longest waiting first) each `{id, action, state,
 *   reason, error_code, since}`. `reason` is the awaiting reason
 *   (`sqlite_host_file`, `sqlite_reads_not_allowed`), else `target_changed`
 *   for an operation whose target still differs from the one it was claimed
 *   on (checked again at each read, at most TARGET_SCAN_LIMIT of them; a
 *   connection that is no longer configured differs; `at_least` is true when
 *   the scan reached its cap). Resolve one with
 *   `POST /api/operations/{id}/resolve` or `orrery:resolve`. Null when it
 *   could not be read.
 *
 * - `pooled_sessions`: `{pooled, unknown}`. `pooled` lists the
 *   configured `pgsql` connections whose package sessions are pooled
 *   (`orrery.sessions.pooled`, or Laravel's `pooled` and `direct` keys),
 *   sorted; `unknown` lists the names of `orrery.sessions.pooled` that name
 *   no `pgsql` connection (a mistyped name is otherwise ignored in silence).
 *   It reads no database.
 *
 * - `guard_invalid`: whether the guard that Authenticate
 *   asks for its 401 rule (`orrery.guard`; null or '' for the default guard)
 *   cannot be used. True when the value is not a string, has a dot, names no
 *   entry of `auth.guards`, names an entry that is not an array, or names a
 *   guard that the auth manager cannot build (an unknown driver, or a
 *   default guard that names no guard). Authenticate then never answers
 *   401: a denied request gets 403 `forbidden`. False when the guard builds.
 *   Null (unknown, never valid) when building the guard throws another
 *   error, which is reported (`health:guard`). It reads no database, so it
 *   is known also while the mirror is refused.
 * - `redaction`: `{valid, errors}` of the `orrery.redact`
 *   configuration (Redactor::status()). Each error is `{field}` with the
 *   invalid rule's position (`orrery.redact.patterns.<index>`), never its
 *   text. While it is invalid, every job-data read answers 500
 *   `redaction_config_invalid`. Null when it could not be read.
 * - `retention_config`: `{valid, errors, unmatched}` of
 *   `orrery.trim` and `orrery.trim_overrides` (RetentionSettings::
 *   health()): the validation errors, and the indexes of the override
 *   entries that match no known queue (null when the known queues could not
 *   be read whole, or the mirror is refused). While it is invalid, the
 *   retention phase deletes nothing and records `retention:config_invalid`.
 *   Null when it could not be read.
 * - `schema`: the mirror's installed
 *   schema version (the marker orrery_schema) against PackageSchema::VERSION,
 *   read on each request: `{installed, expected, hint}` (Database\Schema\
 *   SchemaStatus has the rule). On a server mirror `installed` is null when
 *   the marker is missing; `hint` is PackageSchema::UPGRADE_HINT (the
 *   pre-v0.1 path) for a missing marker with package tables or a marker
 *   below BASELINE, INSTALL_HINT without any package table, MIGRATE_HINT
 *   for a marker from BASELINE up to below VERSION, the downgrade hint for a
 *   newer marker, else null. A sibling that the package refuses gives
 *   `{installed: null, expected, hint: <the refusal's message>}`; it and a
 *   server mirror without any package table (INSTALL_HINT) make every
 *   mirror field of the response unknown, as for `sqlite_shared_mirror`.
 *   The whole field is null when the read failed or the mirror is refused
 *   (`sqlite_shared_mirror`).
 * - `missing_indexes`: `[{index, hint}]`, the
 *   deferred orrery_jobs indexes (PackageSchema::DEFERRED_INDEXES) that the
 *   mirror lacks or holds as an invalid Postgres index, read at most once
 *   per 60 s per process (DeferredIndexes); `hint` is DeferredIndexes::HINT
 *   (`php artisan orrery:install --indexes`). [] when all exist; null when
 *   the read failed or the mirror is refused (unknown, never "none
 *   missing").
 *
 * - `app_key_missing`: true when app.key is empty. A bulk
 *   forget is then refused (`app_key_missing`): its freeze cannot be
 *   signed. It reads no database.
 * - `bulk_config`: `{valid, forget_max}` of
 *   orrery.bulk.forget_max: valid for a whole number from 1 to 100,000 (the
 *   default 10,000 when the key is missing), with the effective value; else
 *   `{valid: false, forget_max: null}` and every bulk forget is refused
 *   (`config_invalid`). It reads no database.
 *
 * `from` and `to` are epoch seconds, with decimals allowed. Without `from`,
 * the range starts 86,400 seconds before the request; without `to`, it ends
 * at the request. Any other value, and `from` after `to`, give 422.
 */
class HealthController extends Controller
{
    /** The newest failed rows that the count of unprovable failures reads. */
    public const UNPROVABLE_WINDOW = 10000;

    public const NOTICE = 'Unreported gaps cannot be ruled out.';

    public const OPEN_LABEL = 'end unknown';

    /** The hint of a per-server `file` default cache store. */
    public const QUEUE_PAUSE_CACHE_LOCAL = 'The cache store is local to each server (file), so a queue pause may not reach every server\'s workers. Use a cache store that every worker shares, as for queue:restart.';

    /** The hint of an `array` default cache store: each process has its own values. */
    public const QUEUE_PAUSE_CACHE_ARRAY = 'The cache store keeps its values in one process (array), so a queue pause has no effect on any worker. Use a cache store that every worker shares, as for queue:restart.';

    /** The command that writes the missing fence migrations. */
    public const FENCE_COMMAND = 'php artisan orrery:install --fence';

    /** The hint of `fence_hint` while a fence table is missing. */
    public const FENCE_HINT = 'Run '.self::FENCE_COMMAND.', then php artisan migrate.';

    /** The most windows in one response. */
    public const MAX_WINDOWS = 500;

    /** The default range: the last 24 hours. */
    public const DEFAULT_RANGE = 86_400;

    /** Times stay numbers with a fraction (120.0), so a client always reads a float. */
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private const EPOCH = '/^\d{1,12}(\.\d{1,6})?$/D';

    /** The samples frontier when it cannot be read. */
    private const UNREADABLE_SAMPLES = ['state' => 'unreadable', 'counted_through' => null, 'lag_seconds' => null];

    /** The most awaiting operations in one response. */
    public const AWAITING_LIMIT = 50;

    /** The most target_changed operations whose fingerprints one response checks again. */
    public const TARGET_SCAN_LIMIT = 500;

    public function index(Request $request, FenceTargets $targets, Fence $fence, Instrumentation $instrumentation, PackageSession $sessions, HostFiles $files, WaitTime $waits): JsonResponse
    {
        $now = Clock::now();
        $from = $this->bound($request->query('from'));
        $to = $this->bound($request->query('to'));

        if ($from === false || $to === false) {
            return $this->invalid('from and to are epoch seconds, for example 1790000000 or 1790000000.25.');
        }

        // Every omitted bound comes from the one request time: `from` 86,400 s before it, `to` at it.
        $from ??= $now - self::DEFAULT_RANGE;
        $to ??= $now;

        if ($from > $to) {
            return $this->invalid('from is after to.');
        }

        $refusal = $sessions->mirrorRefusal();
        // A sibling that the package refuses: its schema status, with
        // the refusal as the hint, and every mirror field unknown.
        $siblingRefusal = $refusal === null ? SchemaStatus::siblingRefusal($sessions) : null;
        // The schema status first: a server mirror without any package table (before the first
        // `migrate`) answers like a refused mirror, with INSTALL_HINT, instead of reading tables
        // that do not exist.
        $schema = $refusal === null ? ($siblingRefusal ?? $this->schema($sessions)) : null;
        $uninstalled = $schema !== null && $schema['installed'] === null && $schema['hint'] === PackageSchema::INSTALL_HINT;
        $report = $targets->report($fence, $files);
        $fenceHint = $report['missing'] === [] ? null : self::FENCE_HINT;

        if ($refusal !== null || $siblingRefusal !== null || $uninstalled) {
            // The package refuses its mirror (D17), or the sibling's
            // schema: the stored windows, the tick time and the retention
            // state are unknown.
            return new JsonResponse([
                'as_of' => $now,
                'windows' => null,
                'truncated' => null,
                'tick' => ['observer_tick_at' => null, 'state' => 'unreadable'],
                'missing_fence_tables' => $report['missing'],
                'fence_unknown_connections' => $report['unknown'],
                'fence_hint' => $fenceHint,
                'sqlite_shared_mirror' => $refusal,
                'failed_records' => $this->failedRecords(false),
                'awaiting_resolution' => null,
                'sqlite_warnings' => self::sqliteWarnings($files),
                'uninstrumented_connections' => $instrumentation->uninstrumented(),
                'notice' => self::NOTICE,
                'retention' => ['state' => 'unreadable'],
                'queue_pause_cache_local' => self::queuePauseCacheLocal(),
                'shared_jobs_table_retry_after' => self::sharedTableRetryAfter($waits),
                'samples' => self::UNREADABLE_SAMPLES,
                'guard_invalid' => Authenticate::guardInvalid(),
                'pooled_sessions' => self::pooledSessions($sessions),
                'redaction' => self::redaction(),
                'retention_config' => self::retentionConfig(false),
                'schema' => $schema,
                'missing_indexes' => null,
                ...self::bulk(),
            ], 200, [], self::JSON);
        }

        [$windows, $truncated] = $this->windows($from, $to);

        return new JsonResponse([
            'as_of' => $now,
            'windows' => $windows,
            'truncated' => $truncated,
            'tick' => $this->tick(),
            'missing_fence_tables' => $report['missing'],
            'fence_unknown_connections' => $report['unknown'],
            'fence_hint' => $fenceHint,
            'sqlite_shared_mirror' => null,
            'failed_records' => $this->failedRecords(true),
            'awaiting_resolution' => $this->awaitingResolution(),
            'sqlite_warnings' => self::sqliteWarnings($files),
            'uninstrumented_connections' => $instrumentation->uninstrumented(),
            'notice' => self::NOTICE,
            'retention' => $this->retention(),
            'queue_pause_cache_local' => self::queuePauseCacheLocal(),
            'shared_jobs_table_retry_after' => self::sharedTableRetryAfter($waits),
            'samples' => $this->samples($now),
            'guard_invalid' => Authenticate::guardInvalid(),
            'pooled_sessions' => self::pooledSessions($sessions),
            'redaction' => self::redaction(),
            'retention_config' => self::retentionConfig(true),
            'schema' => $schema,
            'missing_indexes' => $this->missingIndexes($sessions),
            ...self::bulk(),
        ], 200, [], self::JSON);
    }

    /**
     * `app_key_missing` and `bulk_config`.
     *
     * @return array{app_key_missing: bool, bulk_config: array{valid: bool, forget_max: int|null}}
     */
    private static function bulk(): array
    {
        $config = Container::getInstance()->make('config');
        $forgetMax = BulkRun::forgetMaxOf($config);

        return [
            'app_key_missing' => BulkRun::appKeyMissingIn($config),
            'bulk_config' => ['valid' => $forgetMax !== null, 'forget_max' => $forgetMax],
        ];
    }

    /**
     * `missing_indexes`: `[{index, hint}]`.
     *
     * @return list<array{index: string, hint: string}>|null
     */
    private function missingIndexes(PackageSession $sessions): ?array
    {
        $missing = Watcher::instance()->run(
            'http:health_indexes',
            static fn (): array => Container::getInstance()->make(DeferredIndexes::class)->missing($sessions->mirror()),
            null,
        );

        return is_array($missing) ? array_map(static fn (string $index): array => ['index' => $index, 'hint' => DeferredIndexes::HINT], $missing) : null;
    }

    /**
     * @return array{valid: bool, errors: list<array{field: string}>}|null
     */
    private static function redaction(): ?array
    {
        try {
            return Container::getInstance()->make(Redactor::class)->status();
        } catch (Throwable $error) {
            Watcher::instance()->report('http:health_redaction', $error);

            return null;
        }
    }

    /**
     * The retention configuration: `{valid, errors,
     * unmatched}` (RetentionSettings::health()). `unmatched` is null when
     * the known queues could not be read (also while the mirror is
     * refused). Null when it could not be read at all.
     *
     * @return array{valid: bool, errors: list<string>, unmatched: list<int>|null}|null
     */
    private static function retentionConfig(bool $readMirror): ?array
    {
        try {
            return Container::getInstance()->make(RetentionSettings::class)->health($readMirror);
        } catch (Throwable $error) {
            Watcher::instance()->report('http:health_retention_config', $error);

            return null;
        }
    }

    /**
     * The SQLite warnings (D18): one per host file whose configuration allows
     * no automatic reads, and the hints of this process (a file configured
     * for WAL that answered another journal mode).
     *
     * @return list<array{file: string, message: string}>
     */
    public static function sqliteWarnings(HostFiles $files): array
    {
        $warnings = [];

        foreach ($files->filesWithoutReads() as $file) {
            $warnings[] = ['file' => $file, 'message' => HostFiles::READS_WARNING];
        }

        foreach (HostFiles::hints() as $file => $hint) {
            $warnings[] = ['file' => $file, 'message' => $hint];
        }

        return $warnings;
    }

    /**
     * The shared jobs tables with different retry_after values (gate fix 4),
     * or null when the queue objects could not be read.
     *
     * @return list<array{connections: list<string>, table: string, values: list<int>}>|null
     */
    private static function sharedTableRetryAfter(WaitTime $waits): ?array
    {
        return Watcher::instance()->run('health:shared_jobs_table', static fn (): array => $waits->sharedTableRetryAfter(), null);
    }

    /**
     * The `pooled_sessions` field: the pooled `pgsql` connections and the
     * names of orrery.sessions.pooled that name no `pgsql` connection.
     *
     * @return array{pooled: list<string>, unknown: list<string>}
     */
    private static function pooledSessions(PackageSession $sessions): array
    {
        $config = Container::getInstance()->make('config');
        $pooled = [];
        $postgres = [];

        foreach ((array) $config->get('database.connections', []) as $name => $connection) {
            if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql') {
                continue;
            }

            $postgres[] = (string) $name;

            if ($sessions->isPooled((string) $name, $connection)) {
                $pooled[] = (string) $name;
            }
        }

        $setting = PackageSession::pooledSetting($config->get('orrery.sessions.pooled', false));
        $unknown = $setting === true ? [] : array_values(array_unique(array_diff($setting, $postgres)));
        sort($pooled);
        sort($unknown);

        return ['pooled' => $pooled, 'unknown' => $unknown];
    }

    /**
     * The hint when the default cache store's driver is `array` or `file`,
     * else null.
     */
    public static function queuePauseCacheLocal(): ?string
    {
        $config = app('config');
        $store = $config->get('cache.default');
        $driver = is_string($store) ? $config->get("cache.stores.{$store}.driver") : null;

        return match ($driver) {
            'array' => self::QUEUE_PAUSE_CACHE_ARRAY,
            'file' => self::QUEUE_PAUSE_CACHE_LOCAL,
            default => null,
        };
    }

    /**
     * A bound from the query: null when absent, false when it is not epoch seconds.
     */
    private function bound(mixed $value): float|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match(self::EPOCH, $value) !== 1) {
            return false;
        }

        return (float) $value;
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_range', 'message' => $message], 422, [], self::JSON);
    }

    /**
     * The newest MAX_WINDOWS windows that overlap the range, oldest first,
     * and whether more windows overlap; both null when the read failed.
     *
     * @return array{0: list<array{context: string, process: string, started_at: float, ended_at: float|null, end_label: string|null, dropped: int, unmatched_observations: int, unverified: int, rollback_unverified: int, last_error_operation: string|null, last_error_at: float|null}>|null, 1: bool|null}
     */
    private function windows(float $from, float $to): array
    {
        // Inside the watcher: a failed read (or an open breaker) is unknown (null), never a 500.
        $read = Watcher::instance()->attempt('health:windows', static fn (): array => self::readWindows($from, $to));

        if (! $read->succeeded()) {
            return [null, null];
        }

        /** @var array{0: list<array{context: string, process: string, started_at: float, ended_at: float|null, end_label: string|null, dropped: int, unmatched_observations: int, unverified: int, rollback_unverified: int, last_error_operation: string|null, last_error_at: float|null}>, 1: bool} */
        return $read->value();
    }

    /**
     * The read of windows(), outside the watcher: the caller watches it
     * (also Monitoring\Ping's `observations` check).
     *
     * @return array{0: list<array{context: string, process: string, started_at: float, ended_at: float|null, end_label: string|null, dropped: int, unmatched_observations: int, unverified: int, rollback_unverified: int, last_error_operation: string|null, last_error_at: float|null}>, 1: bool}
     */
    public static function readWindows(float $from, float $to): array
    {
        $rows = Container::getInstance()->make(PackageSession::class)->mirror()->table(HealthEvents::TABLE)
            ->where('started_at', '<=', Clock::toDecimal($to))
            ->where(static function ($query) use ($from): void {
                $query->whereNull('ended_at')->orWhere('ended_at', '>=', Clock::toDecimal($from));
            })
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::MAX_WINDOWS + 1)
            ->get()
            ->all();

        $truncated = count($rows) > self::MAX_WINDOWS;
        $rows = array_reverse(array_slice($rows, 0, self::MAX_WINDOWS));
        $windows = [];

        foreach ($rows as $row) {
            $ended = $row->ended_at === null ? null : (float) $row->ended_at;

            $windows[] = [
                'context' => (string) $row->context,
                'process' => (string) $row->process,
                'started_at' => (float) $row->started_at,
                'ended_at' => $ended,
                'end_label' => $ended === null ? self::OPEN_LABEL : null,
                'dropped' => (int) $row->dropped,
                'unmatched_observations' => (int) $row->unmatched_observations,
                'unverified' => (int) $row->unverified,
                'rollback_unverified' => (int) $row->rollback_unverified,
                'last_error_operation' => $row->last_error_operation === null ? null : (string) $row->last_error_operation,
                'last_error_at' => $row->last_error_at === null ? null : (float) $row->last_error_at,
            ];
        }

        return [$windows, $truncated];
    }

    /**
     * @return array{state: string, at?: float, deleted?: int, behind?: bool|null, lag_seconds?: float|null, partitions_unchecked?: int}
     */
    private function retention(): array
    {
        $read = Watcher::instance()->attempt('health:retention', static fn (): mixed => Container::getInstance()->make(PackageSession::class)->mirror()
            ->table('orrery_settings')->where('key', Retention::STATE_KEY)->value('value'));

        // A failed read (or an open breaker) is unreadable, never none.
        if (! $read->succeeded()) {
            return ['state' => 'unreadable'];
        }

        $value = $read->value();

        if ($value === null) {
            return ['state' => 'none'];
        }

        $state = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($state)
            || ! (is_float($state['at'] ?? null) || is_int($state['at'] ?? null))
            || ! is_int($state['deleted'] ?? null)
            || ! array_key_exists('behind', $state) || ! (is_bool($state['behind']) || $state['behind'] === null)
            || ! array_key_exists('lag_seconds', $state) || ! (is_float($state['lag_seconds']) || is_int($state['lag_seconds']) || $state['lag_seconds'] === null)
            || ! is_int($state['partitions_unchecked'] ?? null)) {
            return ['state' => 'unreadable'];
        }

        return [
            'state' => 'ok',
            'at' => (float) $state['at'],
            'deleted' => $state['deleted'],
            'behind' => $state['behind'],
            'lag_seconds' => $state['lag_seconds'] === null ? null : (float) $state['lag_seconds'],
            'partitions_unchecked' => $state['partitions_unchecked'],
        ];
    }

    /**
     * The installed schema version of the mirror against this release's,
     * or null when it could not be read. index() answers a refused sibling
     * before (SchemaStatus::siblingRefusal(), outside the watcher: the
     * refusal opens the breaker, which would skip this read).
     *
     * @return array{installed: int|null, expected: int, hint: string|null}|null
     */
    private function schema(PackageSession $sessions): ?array
    {
        $read = Watcher::instance()->attempt('health:schema', static fn (): array => SchemaStatus::read($sessions));

        if (! $read->succeeded()) {
            return null;
        }

        /** @var array{installed: int|null, expected: int, hint: string|null} */
        return $read->value();
    }

    /**
     * The per-minute counts frontier.
     *
     * @return array{state: string, counted_through: int|null, lag_seconds: float|null}
     */
    private function samples(float $now): array
    {
        $read = Watcher::instance()->attempt('health:samples', static fn (): mixed => Container::getInstance()->make(PackageSession::class)->mirror()
            ->table('orrery_settings')->where('key', SampleCounts::COUNTED_THROUGH)->value('value'));

        if (! $read->succeeded()) {
            return self::UNREADABLE_SAMPLES;
        }

        $value = $read->value();

        if ($value === null) {
            return ['state' => 'none', 'counted_through' => null, 'lag_seconds' => null];
        }

        if (! is_string($value) || preg_match('/^\d{1,12}$/D', $value) !== 1) {
            return self::UNREADABLE_SAMPLES;
        }

        $through = (int) $value;

        if ($through > $now + 60) {
            // M after the current minute: a clock that moved back. The lag is unknown, never 0.
            return ['state' => 'unknown', 'counted_through' => $through, 'lag_seconds' => null];
        }

        return ['state' => 'ok', 'counted_through' => $through, 'lag_seconds' => round(max(0.0, $now - $through), 6)];
    }

    /**
     * The failed-record adapter and its unprovable failures, or null.
     *
     * The adapter, its reason and its message come from the container and
     * the configuration (and the id-reuse proof of a SQL table), outside the
     * watcher, so a skipped or refused mirror read never hides a read-only
     * driver (review fix). A host SQLite file that allows no automatic read
     * of that proof gives null (unknown); another error is reported and
     * gives null. Only the unprovable count reads the mirror: it is null
     * when that read fails or is skipped, and always null when the package
     * refuses its mirror ($mirror false).
     *
     * @return array{adapter: string, reason: string|null, message: string|null, binding: string|null, unprovable_failures: array{count: int, at_least: bool}|null}|null
     */
    private function failedRecords(bool $mirror): ?array
    {
        $app = Container::getInstance();

        try {
            $records = FailedRecords::for($app, $app->make('config'));
        } catch (HostFileAccessNotAllowed) {
            return null;
        } catch (Throwable $error) {
            Watcher::instance()->report('health:failed_records', $error);

            return null;
        }

        $binding = null;
        $unprovable = null;

        if ($records instanceof SqlFailedRecords) {
            $driver = $app->make('config')->get('database.connections.'.$records->connection().'.driver');

            if ($driver === 'sqlite' && ! version_compare(CapturingUuidFailedJobProvider::sqliteVersion(), CapturingUuidFailedJobProvider::SQLITE_RETURNING, '>=')) {
                $binding = 'sqlite_version';
            }

            $unprovable = $mirror ? $this->unprovableFailures() : null;
        }

        return [
            'adapter' => $records->kind(),
            'reason' => $records->refusal(),
            'message' => $records->message(),
            'binding' => $binding,
            'unprovable_failures' => $unprovable,
        ];
    }

    /**
     * The failed rows without a bound record id among the newest
     * UNPROVABLE_WINDOW failed rows (index st_failed, newest failure first),
     * counted up to orrery.dashboard.count_cap; `at_least` when the window is
     * full (older failed rows were not read) or the count passes the cap.
     * Null when the mirror read fails or is skipped (unknown, never 0). The
     * shell polls health on every screen, so the read is bounded however
     * many failed rows the mirror holds.
     *
     * @return array{count: int, at_least: bool}|null
     */
    private function unprovableFailures(): ?array
    {
        $value = null;
        $read = Watcher::instance()->attempt('health:unprovable_failures', static function () use (&$value): void {
            $cap = CountsController::cap();
            $mirror = Container::getInstance()->make(PackageSession::class)->mirror();
            $window = $mirror->table('orrery_jobs')->select('provider_id')->where('status', 'failed')
                ->orderByDesc('failed_at')->orderByDesc('id')->limit(self::UNPROVABLE_WINDOW);
            $row = $mirror->query()->fromSub($window, 'recent')
                ->selectRaw('count(*) as checked, sum(case when provider_id is null then 1 else 0 end) as unprovable')
                ->first();
            $checked = (int) ($row->checked ?? 0);
            $count = (int) ($row->unprovable ?? 0);
            $value = ['count' => min($count, $cap), 'at_least' => $checked >= self::UNPROVABLE_WINDOW || $count > $cap];
        });

        return $read->succeeded() ? $value : null;
    }

    /**
     * The operations that wait for an operator, or null.
     *
     * @return array{count: int, at_least: bool, operations: list<array{id: string, action: string, state: string, reason: string, error_code: string|null, since: float|null}>}|null
     */
    private function awaitingResolution(): ?array
    {
        $value = null;
        $read = Watcher::instance()->attempt('health:awaiting_resolution', function () use (&$value): void {
            $value = $this->readAwaiting();
        });

        return $read->succeeded() ? $value : null;
    }

    /**
     * @return array{count: int, at_least: bool, operations: list<array{id: string, action: string, state: string, reason: string, error_code: string|null, since: float|null}>}
     */
    private function readAwaiting(): array
    {
        $table = static fn () => Container::getInstance()->make(PackageSession::class)->mirror()->table(Operations::TABLE)->whereIn('state', Operations::ACTIVE);
        $columns = ['id', 'action', 'state', 'awaiting_reason', 'awaiting_since', 'error_code', 'updated_at', 'queue_connection', 'queue_fingerprint', 'record_connection', 'record_table', 'record_fingerprint'];
        $count = (int) $table()->whereNotNull('awaiting_since')->count();
        $rows = $table()->whereNotNull('awaiting_since')->orderBy('awaiting_since')->orderBy('id')->limit(self::AWAITING_LIMIT)->get($columns)->all();

        // A target_changed operation counts only while its target still differs: a restored configuration ends it at once.
        $handler = Container::getInstance()->make(RetryHandler::class);

        $changed = $table()->whereNull('awaiting_since')->where('error_code', RetryHandler::TARGET_CHANGED)->orderBy('updated_at')->orderBy('id')->limit(self::TARGET_SCAN_LIMIT + 1)->get($columns)->all();
        $atLeast = count($changed) > self::TARGET_SCAN_LIMIT;

        foreach (array_slice($changed, 0, self::TARGET_SCAN_LIMIT) as $row) {
            try {
                $holds = $handler->targetsHold($row);
            } catch (Throwable) {
                // A connection that is no longer configured: the target differs.
                $holds = false;
            }

            if (! $holds) {
                $count++;
                $rows[] = $row;
            }
        }

        $operations = [];

        foreach (array_slice($rows, 0, self::AWAITING_LIMIT) as $row) {
            $operations[] = [
                'id' => (string) $row->id,
                'action' => (string) $row->action,
                'state' => (string) $row->state,
                'reason' => (string) ($row->awaiting_reason ?? $row->error_code),
                'error_code' => $row->error_code === null ? null : (string) $row->error_code,
                'since' => is_numeric($row->awaiting_since) ? (float) $row->awaiting_since : (is_numeric($row->updated_at) ? (float) $row->updated_at : null),
            ];
        }

        return ['count' => $count, 'at_least' => $atLeast, 'operations' => $operations];
    }

    /**
     * The last tick run: its time, and whether it is known (`ok`), absent
     * (`none`) or could not be read (`unreadable`).
     *
     * @return array{observer_tick_at: float|null, state: 'ok'|'none'|'unreadable'}
     */
    private function tick(): array
    {
        // Inside the watcher: a failed read (or an open breaker) is unknown, never a 500.
        $read = Watcher::instance()->attempt('health:tick', static fn (): mixed => Container::getInstance()->make(PackageSession::class)->mirror()
            ->table('orrery_settings')->where('key', 'observer_tick_at')->value('value'));
        $value = $read->succeeded() ? $read->value() : null;

        if (is_numeric($value)) {
            return ['observer_tick_at' => (float) $value, 'state' => 'ok'];
        }

        return ['observer_tick_at' => null, 'state' => $read->succeeded() && $value === null ? 'none' : 'unreadable'];
    }
}
