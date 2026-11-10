<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Actions\BatchCancel;
use Justrau\Orrery\Actions\OperatorAudit;
use Justrau\Orrery\Database\Repositories\DatabaseBatchRepository;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Lists\ListRequest;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\ErrorKinds;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PooledRelationUnresolved;
use stdClass;
use Throwable;

/**
 * `GET /api/batches` and `GET /api/batches/{id}`:
 * the application's batches, read data-only (DatabaseBatchRepository)
 * and projected by JobProjection::batch(). No viewer read unserializes a
 * batch value.
 *
 * - **Index:** 50 batches per page, newest first; `before_id` pages back,
 *   `query` searches the name and the id literally. `{batches: [...],
 *   available: true}`; a missing batch table gives `{batches: [],
 *   available: false}` ("Batching is not set up"). Each batch of the index
 *   has `running` (withRunning()): true, false, or null when it cannot be
 *   told.
 * - **Show:** `{batch, members}`; `members` holds one capped count per
 *   member tab (MEMBER_TABS: pending, running, completed, silenced,
 *   failed, retrying, unknown), each equal to the `total` of `GET
 *   /api/jobs?batch=<id>&status=<tab>` (JobList::total()), over the
 *   mirror rows still kept (retention trims finished rows). A count that
 *   fails is `{count: null, at_least: false}` and is reported. An unknown
 *   id (also one with invalid UTF-8 or a NUL, never read) gives 404
 *   `not_found`, a missing table 404 `batching_unavailable`.
 * - **Unknown:** D18 or a bound repository that is not exactly Laravel's
 *   database class gives `{batches: null, unknown_reason}` or `{batch:
 *   null, unknown_reason}`, never an empty list or a 404.
 * - **Errors:** an invalid parameter (an array, more than 255 characters,
 *   invalid UTF-8 or a NUL)
 *   gives 422 `invalid` with `field`; an invalid redaction configuration
 *   500 `redaction_config_invalid`; a batch read that passes the statement
 *   timeout 503 `read_timeout`, any other read error 503 `read_failed`
 *   (both reported as `http:batch_read`, without a job value).
 *
 * The retry of failed members is a bulk run with a batch source (4.9);
 * the cancel is cancel().
 */
class BatchesController extends Controller
{
    /** The member tabs that the show counts (the list engine's tabs of a batch source), in this order. */
    public const MEMBER_TABS = ['pending', 'running', 'completed', 'silenced', 'failed', 'retrying', 'unknown'];

    /**
     * The tabs counted together: completed and silenced read one range of
     * the `batch` index and differ only in the silenced flag that the scan
     * checks, so one scan counts both (JobList::totals()).
     */
    private const MEMBER_GROUPS = [['pending'], ['running'], ['completed', 'silenced'], ['failed'], ['retrying'], ['unknown']];

    /** The member tabs of a job that runs or will run: queued, reserved, or failed with a retry in progress. */
    private const ACTIVE_TABS = ['pending', 'running', 'retrying'];

    /** The longest `query` and `before_id`: Laravel's job_batches columns are string(255). */
    public const PARAMETER_CHARACTERS = 255;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly DatabaseBatchRepository $batches,
        private readonly JobProjection $projection,
        private readonly JobList $lists,
    ) {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        // The raw query string: a search text reaches the reader as sent, whatever TrimStrings does.
        try {
            $query = RawQuery::of($request);
        } catch (QueryTooLong $tooLong) {
            return $tooLong->response();
        }

        foreach (['query', 'before_id'] as $field) {
            $value = $query[$field] ?? null;

            if ($value !== null && (! is_string($value) || ! self::isText($value) || mb_strlen($value) > self::PARAMETER_CHARACTERS)) {
                return self::json(['error' => 'invalid', 'message' => "{$field} is a UTF-8 text of at most 255 characters without NUL.", 'field' => $field], 422);
            }
        }

        return $this->answer(function () use ($query): JsonResponse {
            $reason = $this->batches->unknownReason();

            if ($reason !== null) {
                return self::json(['batches' => null, 'unknown_reason' => $reason]);
            }

            $search = is_string($query['query'] ?? null) ? $query['query'] : null;
            $before = is_string($query['before_id'] ?? null) ? $query['before_id'] : null;
            $rows = $this->read(fn (): array => $this->batches->page($search, $before));

            if ($rows === null) {
                return self::json(['batches' => [], 'available' => false]);
            }

            return self::json([
                'batches' => $this->withRunning(array_map(fn (stdClass $row): array => $this->projection->batch($row), $rows)),
                'available' => true,
            ]);
        });
    }

    public function show(string $id): JsonResponse
    {
        if (! self::isText($id)) {
            // No stored id is invalid UTF-8 or holds a NUL: nothing to read.
            return self::json(['error' => 'not_found', 'message' => 'Not found.'], 404);
        }

        return $this->answer(function () use ($id): JsonResponse {
            $reason = $this->batches->unknownReason();

            if ($reason !== null) {
                return self::json(['batch' => null, 'unknown_reason' => $reason]);
            }

            $found = $this->read(fn (): array => [$this->batches->find($id)]);

            if ($found === null) {
                return self::json(['error' => 'batching_unavailable', 'message' => 'Batching is not set up: the batch table is missing.'], 404);
            }

            if ($found[0] === null) {
                return self::json(['error' => 'not_found', 'message' => 'Not found.'], 404);
            }

            return self::json([
                'batch' => $this->projection->batch($found[0]),
                'members' => $this->members($id),
            ]);
        });
    }

    /**
     * POST /api/batches/{id}/cancel (Actions\BatchCancel, inside one
     * operator action, D17); the action is recorded (Actions\OperatorAudit,
     * with the request's actor). 200 `{id, result: "cancelled",
     * cancelled_at}`; 409 `already_cancelled`, `already_finished`,
     * `unsupported_repository` or `batch_state_unknown`; 404 `not_found`
     * (also for an id with invalid UTF-8 or a NUL); 503 `write_failed` on a
     * database error. Laravel's BatchCanceled is not dispatched (it needs a
     * Batch, whose options only unserialize() can build).
     */
    public function cancel(Request $request, string $id, BatchCancel $cancels, OperatorAudit $audit): JsonResponse
    {
        if (! self::isText($id)) {
            return self::json(['error' => 'not_found', 'message' => 'Not found.'], 404);
        }

        $actor = Orrery::actor($request);

        try {
            $outcome = PackageSession::asOperator(static fn (): array => $cancels->cancel($id));
        } catch (Throwable $error) {
            Watcher::instance()->report('http:batch_cancel', $error);

            return self::json(['error' => 'write_failed', 'message' => 'The change may not have been made. Reload to see the current state.'], 503);
        }

        $result = $outcome['result'];

        if ($result === BatchCancel::NOT_FOUND) {
            return self::json(['error' => 'not_found', 'message' => 'Not found.'], 404);
        }

        if ($result !== BatchCancel::CANCELLED) {
            return self::json(['error' => $result, 'message' => 'The batch was not cancelled.'], 409);
        }

        $audit->record('cancel_batch', ['batch_id' => $id], $actor, $result, ['cancelled_at' => $outcome['cancelled_at']]);

        return self::json(['id' => $id, 'result' => $result, 'cancelled_at' => $outcome['cancelled_at']]);
    }

    /**
     * Adds `running` to each batch of the index. A finished or cancelled
     * batch does not run, and a batch with unknown counts is unknown (null).
     * A batch with more pending jobs than failures runs: a job that never
     * failed is still to run. A batch without pending jobs does not run.
     * Else every pending job may be a failed one (Laravel keeps a failed job
     * pending, and keeps counting a failure after its retry succeeded), and
     * the mirror decides: the batch runs while a member is pending, reserved
     * or retrying (ACTIVE_TABS), and does not run when the capped counts of
     * those tabs are an exact 0. A failed member read is reported and gives
     * null; after a dependency error or a timeout of the mirror the
     * remaining batches are null without a read.
     *
     * @param  list<array<string, mixed>>  $batches
     * @return list<array<string, mixed>>
     */
    private function withRunning(array $batches): array
    {
        $readable = true;

        foreach ($batches as $index => $batch) {
            $pending = $batch['pending_jobs'];
            $failed = $batch['failed_jobs'];

            if ($batch['finished_at'] !== null || $batch['cancelled_at'] !== null) {
                $running = false;
            } elseif (! is_int($pending) || ! is_int($failed)) {
                $running = null;
            } elseif ($pending > $failed) {
                $running = true;
            } elseif ($pending <= 0) {
                $running = false;
            } else {
                $running = $readable ? $this->hasActiveMember((string) $batch['id'], $readable) : null;
            }

            $batches[$index]['running'] = $running;
        }

        return $batches;
    }

    /**
     * Whether the batch has a member in ACTIVE_TABS: true for a count above
     * 0, false when every count is an exact 0, else null. A failure is
     * reported and gives null; $readable turns false after a dependency
     * error or a timeout.
     */
    private function hasActiveMember(string $id, bool &$readable): ?bool
    {
        if (mb_strlen($id) > ListRequest::BATCH_MAX) {
            // A list cannot name this batch (a custom table on SQLite keeps longer ids).
            return null;
        }

        try {
            $totals = $this->lists->totals(array_map(static fn (string $tab): ListRequest => ListRequest::from(['status' => $tab, 'batch' => $id]), self::ACTIVE_TABS));
        } catch (Throwable $error) {
            Watcher::instance()->report('http:batch_running', $error);
            $readable = ! (ErrorKinds::isDependency($error) || JobList::isTimeout($error));

            return null;
        }

        $exact = true;

        foreach ($totals as $total) {
            if ($total['count'] > 0) {
                return true;
            }

            $exact = $exact && ! $total['at_least'];
        }

        return $exact ? false : null;
    }

    /**
     * One capped count per tab of the batch's member list: each is the
     * `total` of `GET /api/jobs?batch=<id>&status=<tab>` (JobList::total(),
     * the list engine's own ranges, residual filters, cap and budget), so
     * the screen shows each tab's count as its list shows it. The counts
     * cover the mirror rows that are still kept (retention trims finished
     * rows). The completed and silenced tabs share one residual scan. A
     * batch id longer than ListRequest::BATCH_MAX gives null counts and one
     * report, without a read. A count that fails is `{count: null, at_least: false}` and is
     * reported; after a dependency error or a timeout of the mirror the
     * remaining counts are null without a read, so a failing mirror costs
     * one statement timeout per request, not one per tab.
     *
     * @return array<string, array{count: int|null, at_least: bool}>
     */
    private function members(string $id): array
    {
        $unknown = ['count' => null, 'at_least' => false];

        if (mb_strlen($id) > ListRequest::BATCH_MAX) {
            // A list cannot name this batch (a custom table on SQLite keeps longer ids): one report, no read.
            Watcher::instance()->report('http:batch_members', 'The batch id is longer than '.ListRequest::BATCH_MAX.' characters: its members are not counted.');

            return array_fill_keys(self::MEMBER_TABS, $unknown);
        }

        $members = [];
        $stopped = false;

        foreach (self::MEMBER_GROUPS as $tabs) {
            if ($stopped) {
                $members += array_fill_keys($tabs, $unknown);

                continue;
            }

            try {
                $totals = $this->lists->totals(array_map(static fn (string $tab): ListRequest => ListRequest::from(['status' => $tab, 'batch' => $id]), $tabs));
                $members += array_combine($tabs, $totals);
            } catch (RedactionConfigInvalid $invalid) {
                throw $invalid;
            } catch (Throwable $error) {
                Watcher::instance()->report('http:batch_members', $error);
                $members += array_fill_keys($tabs, $unknown);
                $stopped = ErrorKinds::isDependency($error) || JobList::isTimeout($error);
            }
        }

        // MEMBER_TABS order, whatever the groups.
        return array_merge(array_fill_keys(self::MEMBER_TABS, $unknown), $members);
    }

    /**
     * The batch read, or null when the batch table is missing. Every other
     * error reaches answer().
     *
     * @template T
     *
     * @param  \Closure(): T  $read
     * @return T|null
     */
    private function read(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (PooledRelationUnresolved) {
            // A pooled Postgres session resolves every relation once: no table there.
            return null;
        } catch (QueryException $error) {
            if (self::isMissingTable($error)) {
                return null;
            }

            throw $error;
        }
    }

    /**
     * @param  \Closure(): JsonResponse  $work
     */
    private function answer(\Closure $work): JsonResponse
    {
        try {
            // Checked first: an invalid redaction configuration answers 500 whatever the batch state.
            $this->projection->assertValid();

            return $work();
        } catch (RedactionConfigInvalid $invalid) {
            return self::json($invalid->body(), 500);
        } catch (Throwable $error) {
            Watcher::instance()->report('http:batch_read', $error);

            if (JobList::isTimeout($error)) {
                return self::json(['error' => 'read_timeout', 'message' => 'The batch read took too long.'], 503);
            }

            return self::json(['error' => 'read_failed', 'message' => 'The batches could not be read.'], 503);
        }
    }

    /**
     * The missing-table error of each engine: MySQL 42S02, Postgres 42P01,
     * and SQLite "no such table" (SQLSTATE HY000).
     */
    private static function isMissingTable(QueryException $error): bool
    {
        $state = (string) $error->getCode();
        $previous = $error->getPrevious();

        return $state === '42S02'
            || $state === '42P01'
            || ($previous !== null && str_contains($previous->getMessage(), 'no such table'));
    }

    /**
     * A parameter value that SQL can compare: a string of valid UTF-8 without
     * NUL (Postgres refuses both in a text comparison).
     */
    private static function isText(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, [], self::JSON);
    }
}
