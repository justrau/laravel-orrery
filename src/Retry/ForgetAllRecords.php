<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use LogicException;
use stdClass;

/**
 * orrery:forget --all [--connection=] [--queue=]: forgets
 * every failed record of a scope, one exact record at a time, as an operator
 * action.
 *
 * The scope is byte-exact: the SQL prefilter (`connection = ?`, `queue = ?`)
 * only narrows the read; PHP compares the scope columns of every record and
 * row with === before any delete, so a case-insensitive or accent-insensitive
 * collation never widens it. `--queue` alone matches that queue name on every
 * connection.
 *
 * SqlFailedRecords: a keyset walk over the failed table by `id`, in batches
 * of BATCH, bounded by the largest `id` at the start (a record logged during
 * the walk is never reached). Each record goes to exactly one branch:
 * - mirrored (a mirror row with the record's canonical uuid exists): the
 *   predicate of a single forget, before any delete: the row is `failed`
 *   with `operation_id IS NULL`, its `provider_id` is the record's id, and
 *   its `provider_target` is the walked table's target. Then the guarded
 *   mirror delete with the dependents, and only when it removed the row the
 *   exact `DELETE … WHERE id = ? AND uuid = ?`, so a row that a retry or a
 *   forget claimed meanwhile keeps its record. A record that fails the predicate is skipped and counted
 *   by reason (operation_in_progress, not_failed, occurrence_unprovable,
 *   occurrence_mismatch, target_changed); it never falls through to the
 *   provider-only branch.
 * - provider-only (no mirror row with that uuid): deleted by its exact id
 *   and uuid, counted separately. A record without a canonical uuid (the
 *   payload's uuid, equal to the uuid column) is skipped
 *   (occurrence_unprovable).
 * Every delete is its own autocommit statement: no transaction spans a
 * batch, so a host SQLite file is locked for one delete at a time.
 *
 * NoFailedRecords: the failed mirror rows of the scope with `operation_id
 * IS NULL`, in batches of BATCH by (failed_at, id) up to the largest key at
 * the start; a held row is skipped (operation_in_progress), and a row bound
 * to a failed record (`provider_id` set: the failed-job storage changed
 * after the failure) is skipped as target_changed.
 *
 * Read-only failers are refused, and no provider method is called.
 */
final class ForgetAllRecords
{
    public const BATCH = 500;

    /** The hold point after each batch. */
    public const AFTER_BATCH = 'forget_all.after_batch';

    /** The hold point before the guarded mirror delete of a mirrored record. */
    public const BEFORE_DELETE = 'forget_all.before_delete';

    public const DONE = 'done';

    public const REFUSED = 'refused';

    public function __construct(
        private readonly Application $app,
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
        private readonly IncidentCleanup $incidents,
    ) {}

    /**
     * $bound is the bound of preview() (the run stops there, so the count
     * that the confirmation showed holds); null takes the bound now. A bound
     * of another failed-record kind than the bound failer's (the storage
     * changed after the preview) is refused (`scope_changed`).
     *
     * @param  array{kind: string, id: int, at: ?string}|null  $bound
     * @return array{result: string, code: ?string, message: ?string, deleted: int, provider_only: int, skipped: array<string, int>}
     *
     * @throws LogicException inside an open mirror transaction
     */
    public function run(?string $connection = null, ?string $queue = null, ?array $bound = null): array
    {
        if ($this->transactions->active($this->sessions->mirror())) {
            throw new LogicException('orrery: ForgetAllRecords::run() cannot run inside an open mirror transaction.');
        }

        return $this->sessions->operator(function () use ($connection, $queue, $bound): array {
            $records = FailedRecords::for($this->app, $this->app->make('config'));
            $report = ['result' => self::DONE, 'code' => null, 'message' => null, 'deleted' => 0, 'provider_only' => 0, 'skipped' => []];

            if ($records->kind() === FailedRecords::READ_ONLY) {
                return ['result' => self::REFUSED, 'code' => $records->refusal(), 'message' => $records->message()] + $report;
            }

            $bound ??= $this->bound($records, $connection, $queue);

            if ($bound['kind'] !== self::boundKind($records)) {
                return ['result' => self::REFUSED, 'code' => 'scope_changed', 'message' => 'The failed-job storage changed after the count; run the command again.'] + $report;
            }

            return $records instanceof SqlFailedRecords
                ? $this->walkRecords($records, $connection, $queue, $report, $bound['id'])
                : $this->walkRows($connection, $queue, $report, $bound);
        });
    }

    /**
     * The scope before a run, for its confirmation: refused for a read-only
     * failer, else the bound of the run (bound(), passed to run()) and the
     * number of records up to it that the SQL prefilter of the scope finds
     * (SqlFailedRecords: the records of the failed table; else the failed
     * mirror rows). The byte-exact check of the walk can leave some of them
     * out, so the count is an upper bound of what the run forgets. An
     * operator action, as run().
     *
     * @return array{result: string, code: ?string, message: ?string, count: int, bound: array{kind: string, id: int, at: ?string}|null}
     */
    public function preview(?string $connection = null, ?string $queue = null): array
    {
        return $this->sessions->operator(function () use ($connection, $queue): array {
            $records = FailedRecords::for($this->app, $this->app->make('config'));

            if ($records->kind() === FailedRecords::READ_ONLY) {
                return ['result' => self::REFUSED, 'code' => $records->refusal(), 'message' => $records->message(), 'count' => 0, 'bound' => null];
            }

            $bound = $this->bound($records, $connection, $queue);

            if ($records instanceof SqlFailedRecords) {
                $query = $this->sessions->forAction($records->connection())->table($records->table())->where('id', '<=', $bound['id']);
                self::prefilter($query, $connection, $queue);
            } else {
                $query = $this->failedRows($connection, $queue);
                self::withinBound($query, $bound);
            }

            return ['result' => self::DONE, 'code' => null, 'message' => null, 'count' => $bound['id'] === 0 ? 0 : $query->count(), 'bound' => $bound];
        });
    }

    /**
     * The bound of a walk, taken now: SqlFailedRecords the largest id of the
     * failed table; else the last failed mirror row of the scope by
     * (failed_at, id). Id 0 when there is nothing.
     *
     * @return array{kind: string, id: int, at: ?string}
     */
    private function bound(FailedRecords $records, ?string $connection, ?string $queue): array
    {
        if ($records instanceof SqlFailedRecords) {
            $max = $this->sessions->forAction($records->connection())->table($records->table())->max('id');

            return ['kind' => self::boundKind($records), 'id' => is_numeric($max) ? (int) $max : 0, 'at' => null];
        }

        $last = $this->failedRows($connection, $queue)->orderByDesc('failed_at')->orderByDesc('id')->first(['failed_at', 'id']);

        return $last instanceof stdClass
            ? ['kind' => self::boundKind($records), 'id' => (int) $last->id, 'at' => self::decimal($last->failed_at)]
            : ['kind' => self::boundKind($records), 'id' => 0, 'at' => null];
    }

    private static function boundKind(FailedRecords $records): string
    {
        return $records instanceof SqlFailedRecords ? 'records' : 'rows';
    }

    /**
     * The failed mirror rows of the scope (the SQL prefilter).
     */
    private function failedRows(?string $connection, ?string $queue): Builder
    {
        $query = $this->sessions->mirror()->table(JobStore::TABLE)->where('status', 'failed')->whereNotNull('failed_at');
        self::prefilter($query, $connection, $queue);

        return $query;
    }

    /**
     * Rows up to the bound by (failed_at, id).
     *
     * @param  array{kind: string, id: int, at: ?string}  $bound
     */
    private static function withinBound(Builder $query, array $bound): void
    {
        $query->where(static function (Builder $query) use ($bound): void {
            $query->where('failed_at', '<', $bound['at'])->orWhere(static function (Builder $query) use ($bound): void {
                $query->where('failed_at', '=', $bound['at'])->where('id', '<=', $bound['id']);
            });
        });
    }

    /**
     * @param  array{result: string, code: ?string, message: ?string, deleted: int, provider_only: int, skipped: array<string, int>}  $report
     * @return array{result: string, code: ?string, message: ?string, deleted: int, provider_only: int, skipped: array<string, int>}
     */
    private function walkRecords(SqlFailedRecords $records, ?string $connection, ?string $queue, array $report, int $bound): array
    {
        $session = $this->sessions->forAction($records->connection());
        $target = $records->target();

        if ($bound === 0) {
            return $report;
        }

        $cursor = 0;
        $mirror = $this->sessions->mirror();

        while ($cursor < $bound) {
            $query = $session->table($records->table())->where('id', '>', $cursor)->where('id', '<=', $bound);
            self::prefilter($query, $connection, $queue);
            $batch = $query->orderBy('id')->limit(self::BATCH)->get(['id', 'uuid', 'connection', 'queue', 'payload'])->all();

            if ($batch === []) {
                break;
            }

            $cursor = (int) end($batch)->id;
            $candidates = [];

            foreach ($batch as $record) {
                if (! self::inScope($record, $connection, $queue)) {
                    continue;
                }

                $uuid = self::canonicalUuid($record);

                if ($uuid === null) {
                    self::skip($report, 'occurrence_unprovable');

                    continue;
                }

                $candidates[] = [$record, $uuid];
            }

            $rows = $this->mirrorRows($mirror, array_map(static fn (array $candidate): string => $candidate[1], $candidates));

            foreach ($candidates as [$record, $uuid]) {
                $id = (int) $record->id;
                $row = $rows[$uuid] ?? null;

                if ($row === null) {
                    // Provider-only: only --all deletes these, by the exact id and uuid.
                    $report['provider_only'] += $records->delete($session, $id, (string) $record->uuid);

                    continue;
                }

                $reason = self::mirroredReason($row, $id, $target);

                if ($reason !== null) {
                    self::skip($report, $reason);

                    continue;
                }

                // The guarded mirror delete first: a row that an operation claimed meanwhile keeps its record.
                Hooks::reach(self::BEFORE_DELETE);

                if (! $this->deleteRow($mirror, $uuid, (int) $row->occurrence_id, $id)) {
                    $now = $this->mirrorRows($mirror, [$uuid])[$uuid] ?? null;
                    self::skip($report, $now === null ? 'not_failed' : (self::mirroredReason($now, $id, $target) ?? 'not_failed'));

                    continue;
                }

                $records->delete($session, $id, (string) $record->uuid);
                $report['deleted']++;
            }

            Hooks::reach(self::AFTER_BATCH);
        }

        return $report;
    }

    /**
     * @param  array{result: string, code: ?string, message: ?string, deleted: int, provider_only: int, skipped: array<string, int>}  $report
     * @param  array{kind: string, id: int, at: ?string}  $bound
     * @return array{result: string, code: ?string, message: ?string, deleted: int, provider_only: int, skipped: array<string, int>}
     */
    private function walkRows(?string $connection, ?string $queue, array $report, array $bound): array
    {
        $mirror = $this->sessions->mirror();

        if ($bound['id'] === 0) {
            return $report;
        }

        $cursor = null;

        while (true) {
            $query = $this->failedRows($connection, $queue);
            self::withinBound($query, $bound);

            if ($cursor !== null) {
                $query->where(static function (Builder $query) use ($cursor): void {
                    $query->where('failed_at', '>', $cursor['at'])->orWhere(static function (Builder $query) use ($cursor): void {
                        $query->where('failed_at', '=', $cursor['at'])->where('id', '>', $cursor['id']);
                    });
                });
            }

            $batch = $query->orderBy('failed_at')->orderBy('id')->limit(self::BATCH)->get(['id', 'uuid', 'connection', 'queue', 'occurrence_id', 'operation_id', 'provider_id', 'failed_at'])->all();

            if ($batch === []) {
                break;
            }

            $tail = end($batch);
            $cursor = ['at' => self::decimal($tail->failed_at), 'id' => (int) $tail->id];

            foreach ($batch as $row) {
                if (! self::inScope($row, $connection, $queue)) {
                    continue;
                }

                if ($row->operation_id !== null) {
                    self::skip($report, 'operation_in_progress');

                    continue;
                }

                if ($row->provider_id !== null) {
                    // Bound to a failed record: the failed-job storage changed after the failure.
                    self::skip($report, 'target_changed');

                    continue;
                }

                if ($this->deleteRow($mirror, (string) $row->uuid, (int) $row->occurrence_id, null)) {
                    $report['deleted']++;
                } else {
                    self::skip($report, 'not_failed');
                }
            }

            Hooks::reach(self::AFTER_BATCH);
        }

        return $report;
    }

    /**
     * The guarded mirror delete of one free failed occurrence, with its
     * dependents, in one mirror transaction. $providerId: the record id that
     * the row must still be bound to (SQL records).
     */
    private function deleteRow(Connection $mirror, string $uuid, int $occurrenceId, ?int $providerId): bool
    {
        return $this->transactions->run($mirror, function (Connection $tx) use ($uuid, $occurrenceId, $providerId): bool {
            $guard = $tx->table(JobStore::TABLE)->where('uuid', $uuid)->where('status', 'failed')->where('occurrence_id', $occurrenceId)->whereNull('operation_id');

            if ($providerId !== null) {
                $guard->where('provider_id', $providerId);
            } else {
                // NoFailedRecords: only a row that no failed record is bound to.
                $guard->whereNull('provider_id');
            }

            if ($guard->delete() !== 1) {
                return false;
            }

            foreach (ForgetService::JOB_TABLES as $table) {
                $tx->table($table)->where('job_uuid', $uuid)->delete();
            }

            $this->incidents->deleteForJobs($tx, [$uuid]);

            return true;
        });
    }

    /**
     * The mirror rows of the uuids, by uuid.
     *
     * @param  list<string>  $uuids
     * @return array<string, stdClass>
     */
    private function mirrorRows(Connection $mirror, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $rows = [];

        foreach ($mirror->table(JobStore::TABLE)->whereIn('uuid', array_values(array_unique($uuids)))->get(['uuid', 'status', 'occurrence_id', 'operation_id', 'provider_id', 'provider_target']) as $row) {
            $rows[(string) $row->uuid] = $row;
        }

        return $rows;
    }

    /**
     * The predicate of a single forget on a mirrored record, or null when it
     * passes.
     */
    private static function mirroredReason(stdClass $row, int $recordId, string $target): ?string
    {
        return match (true) {
            $row->operation_id !== null => 'operation_in_progress',
            $row->status !== 'failed' => 'not_failed',
            $row->provider_id === null => 'occurrence_unprovable',
            (int) $row->provider_id !== $recordId => 'occurrence_mismatch',
            ! is_string($row->provider_target) || ! hash_equals($target, $row->provider_target) => 'target_changed',
            default => null,
        };
    }

    /**
     * The record's canonical uuid: its payload's uuid, when the uuid column
     * holds the same uuid; else null.
     */
    private static function canonicalUuid(stdClass $record): ?string
    {
        $uuid = PayloadIdentity::uuidOf($record->payload ?? null);

        return $uuid !== null && is_string($record->uuid) && strtolower($record->uuid) === $uuid ? $uuid : null;
    }

    private static function prefilter(Builder $query, ?string $connection, ?string $queue): void
    {
        if ($connection !== null) {
            $query->where('connection', $connection);
        }

        if ($queue !== null) {
            $query->where('queue', $queue);
        }
    }

    /**
     * The byte-exact recheck of the scope columns.
     */
    private static function inScope(stdClass $record, ?string $connection, ?string $queue): bool
    {
        return ($connection === null || $record->connection === $connection) && ($queue === null || $record->queue === $queue);
    }

    /**
     * @param  array{result: string, code: ?string, message: ?string, deleted: int, provider_only: int, skipped: array<string, int>}  $report
     */
    private static function skip(array &$report, string $reason): void
    {
        $report['skipped'][$reason] = ($report['skipped'][$reason] ?? 0) + 1;
    }

    private static function decimal(mixed $value): string
    {
        return is_string($value) ? $value : Clock::toDecimal((float) $value);
    }
}
