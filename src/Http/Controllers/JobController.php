<?php

namespace Justrau\Orrery\Http\Controllers;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Database\Repositories\DatabaseBatchRepository;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Http\Lists\Cursor;
use Justrau\Orrery\Http\Lists\InvalidListRequest;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\Diagnostics;
use Justrau\Orrery\Lifecycle\EnforcedRule;
use Justrau\Orrery\Lifecycle\History;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Monitoring\StuckSignals;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\PackageSession;
use stdClass;
use Throwable;

/**
 * The job detail and its history.
 *
 * **`GET /api/jobs/{uuid}`** ({uuid} matches UUID_PATTERN in any case and is
 * lower-cased before the read): the summary row of the list (4.3) at the top
 * level, and:
 *
 * - `payload`, `command`, `exception`, `context`: through JobProjection. The
 *   row is read with JobProjection::bounded() (`CASE WHEN <column>_bytes <=
 *   <limit> THEN <column> END`), so an oversized value is never loaded and
 *   its size decision and its value come from one row version. The whole
 *   response is fitted to JobProjection::RESPONSE_BYTES (2 MB).
 * - `cycles`: one entry per retained cycle, newest first: the current cycle
 *   from the row (its effective tries and timeout from the limit columns and
 *   the worker evidence of its latest attempt only), each older cycle from
 *   its frozen orrery_cycle_summaries row (never from the row), the merged
 *   row last as `{cycle, merged: true, first_cycle, merged_cycles,
 *   merged_retries}`. Each entry has its `enforced_rule` (EnforcedRule, at
 *   the cycle's finish, or now for a cycle in progress; null for a frozen
 *   cycle without a finish time) and, for a failed cycle, its `terminal`
 *   explanation (Diagnostics), else null. The message is the redacted first
 *   line, at most 500 characters.
 * - `retries`: `{entries, retries_retained, retries_folded,
 *   retries_unrecorded, retried_count, retried_count_inferred}`. One
 *   `{kind: "retry", cycle, started_at, source, outcome}` per cycle k >= 2
 *   with a source, newest first; the merged row gives one `{kind: "earlier",
 *   first_cycle, last_cycle, count}` when it folded a retry. No entry is
 *   invented for an unrecorded retry.
 * - `history`: the first page of `GET /api/jobs/{uuid}/history`.
 * - `chained`: pending rows only. Whether the job carries a chain of later
 *   jobs, which a delete drops (chained()); null when it cannot be told.
 * - `signals` and `enforced_rule`: pending and reserved rows only. Every
 *   stuck signal that holds now (Monitoring\StuckSignals),
 *   `[{signal, facts}]`, and the enforced rule now (EnforcedRule::forRow()),
 *   as on a row of GET /api/jobs/stuck.
 * - `operation`: the holding operation from the ledger (OPERATION_COLUMNS,
 *   never `payload`, `saved_record` or `error`; `bulk_ref` only where the
 *   column exists), `{id, action: null, …}` when the ledger row is gone or
 *   cannot be read (held, state unknown), or null.
 * - `actions`: `{retry, forget, resolve, read_only_reason, delete,
 *   pending_reason, run_now}`. Delete: the row is pending and no operation holds it;
 *   run_now also needs an available_at after now (a delayed job);
 *   `pending_reason` is `job_id_unknown` for an unresolved bulk row, whose
 *   delete waits until its jobs id is known. Retry and
 *   forget: the row is failed, no operation holds it, and the failed-record
 *   adapter is not read-only (`provider_read_only`, `ids_may_be_reused`) and
 *   proves the occurrence (`occurrence_unprovable` for a null `provider_id`
 *   under SqlFailedRecords). Resolve: the holding operation awaits an
 *   operator. On a host SQLite file that allows no automatic read, the
 *   adapter is decided by the operator action: Retry and Forget are offered
 *   and the server decides at write time. When the adapter cannot be built
 *   (the bound failer throws), neither is offered and `read_only_reason`
 *   is null.
 * - `batch`: `{id, name}`, the name through the pattern rules; `{id, name:
 *   null}` when the batch table cannot be read; null without a batch.
 *
 * **`GET /api/jobs/{uuid}/history?cycle=&cursor=`**: 50 entries per page,
 * (`cycle` desc, `id` desc), through the index `job_cycle` (job_uuid,
 * cycle, id) backwards; `cycle` limits the page to one cycle. An entry:
 * `{id, cycle, attempt, event, source, reason, exception_class,
 * exception_message, occurred_at, has_exception}`. The response adds
 * `next_cursor` (null at the end) and the row's `history_truncated` and
 * `history_discarded`.
 *
 * **`GET /api/jobs/{uuid}/history/{id}`**: one entry with its full
 * `exception` (at most 64 KB, else omitted) and `context` (at most 256 KB),
 * redacted; 404 when the entry does not belong to the job.
 *
 * Errors: 404 `not_found` for an unknown job or entry; 422 `invalid` or
 * `cursor_mismatch` with `field`; 500 `redaction_config_invalid` before any
 * job data is read; 503 `read_timeout` or `read_failed` when the job row
 * cannot be read. A section that cannot be read is null (reported through
 * the watcher, without a job value), never empty.
 */
class JobController extends Controller
{
    /** The route pattern of {uuid}: a full uuid in any case. */
    public const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /** The route pattern of a history entry id. */
    public const ENTRY_PATTERN = '[0-9]{1,19}';

    public const HISTORY_PER_PAGE = 50;

    /** The largest cycle that a request may name: the `cycle` column is a 32-bit `integer` on Postgres. */
    public const MAX_CYCLE = 2_147_483_647;

    /** The characters of a batch name: Laravel's job_batches.name is a string(255). */
    public const BATCH_NAME_CHARACTERS = 255;

    /** The orrery_jobs columns of the detail, besides the bounded large columns. */
    public const ROW_COLUMNS = [
        ...JobProjection::SUMMARY_COLUMNS,
        'cycle_started_at', 'cycle_source', 'max_tries', 'retry_until', 'max_exceptions', 'timeout', 'fail_on_timeout',
        'worker_tries', 'worker_timeout', 'worker_evidence_attempt', 'releases', 'exceptions', 'timeouts', 'reclaims',
        'observed_gap', 'late_outcomes', 'last_outcome_attempt', 'history_truncated', 'history_discarded', 'provider_id', 'job_id',
        'payload_bytes', 'exception_bytes', 'context_bytes',
    ];

    /** The ledger columns of a response: never payload, saved_record or error. */
    public const OPERATION_COLUMNS = [
        'id', 'action', 'state', 'mode', 'source', 'actor', 'error_code', 'awaiting_reason', 'created_at', 'updated_at', 'next_check_at',
    ];

    /** The orrery_cycle_summaries columns of a cycle entry. */
    private const SUMMARY_COLUMNS = [
        'cycle', 'outcome', 'merged', 'first_cycle', 'merged_cycles', 'merged_retries', 'attempts', 'last_outcome_attempt', 'releases', 'exceptions',
        'timeouts', 'reclaims', 'observed_gap', 'late_outcomes', 'exception_class', 'exception_message', 'max_tries',
        'max_exceptions', 'timeout', 'fail_on_timeout', 'retry_until', 'effective_tries', 'effective_tries_source',
        'effective_timeout', 'effective_timeout_source', 'started_at', 'started_source', 'finished_at',
    ];

    /** The orrery_job_history columns of a history entry. */
    private const HISTORY_COLUMNS = [
        'id', 'cycle', 'attempt', 'event', 'source', 'reason', 'exception_class', 'exception_message', 'exception_bytes', 'occurred_at',
    ];

    /** The counters of a cycle entry. */
    private const COUNTERS = ['attempts', 'releases', 'exceptions', 'timeouts', 'reclaims', 'observed_gap', 'late_outcomes'];

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly JobProjection $projection,
        private readonly JobList $jobs,
        private readonly StuckSignals $stuck,
    ) {
        parent::__construct();
    }

    public function show(string $uuid): JsonResponse
    {
        $uuid = strtolower($uuid);

        return $this->answer('http:job_detail', function () use ($uuid): JsonResponse {
            $mirror = $this->sessions->mirror();
            $columns = [...self::ROW_COLUMNS, JobProjection::bounded('payload'), JobProjection::bounded('exception'), JobProjection::bounded('context')];
            /** @var stdClass|null $row */
            $row = $this->sessions->bounded($mirror, static fn (Connection $session): ?stdClass => $session->table(JobStore::TABLE)->where('uuid', $uuid)->first($columns));

            if ($row === null) {
                return self::notFoundJson();
            }

            return new JsonResponse($this->projection->fit($this->detail($mirror, $row)), 200, [], self::JSON);
        });
    }

    public function history(Request $request, string $uuid): JsonResponse
    {
        $uuid = strtolower($uuid);

        try {
            $cycle = self::cycleParameter($request);
            $cursor = self::cursorParameter($request, $uuid, $cycle);
        } catch (InvalidListRequest $invalid) {
            return new JsonResponse($invalid->body(), 422, [], self::JSON);
        }

        return $this->answer('http:job_history', function () use ($uuid, $cycle, $cursor): JsonResponse {
            $mirror = $this->sessions->mirror();
            $page = $this->sessions->bounded($mirror, function (Connection $session) use ($uuid, $cycle, $cursor): ?array {
                $row = $session->table(JobStore::TABLE)->where('uuid', $uuid)->first(['uuid', 'history_truncated', 'history_discarded']);

                return $row === null ? null : $this->historyPage($session, $row, $cycle, $cursor);
            });

            return $page === null ? self::notFoundJson() : new JsonResponse($page, 200, [], self::JSON);
        });
    }

    public function entry(string $uuid, string $id): JsonResponse
    {
        $uuid = strtolower($uuid);

        return $this->answer('http:job_history_entry', function () use ($uuid, $id): JsonResponse {
            $mirror = $this->sessions->mirror();
            $columns = [...self::HISTORY_COLUMNS, 'context_bytes', JobProjection::bounded('exception'), JobProjection::bounded('context')];
            /** @var stdClass|null $entry */
            $entry = $this->sessions->bounded($mirror, static fn (Connection $session): ?stdClass => $session->table(History::TABLE)
                ->where('job_uuid', $uuid)
                ->where('id', (int) $id)
                ->first($columns));

            if ($entry === null) {
                return self::notFoundJson();
            }

            $exception = $this->projection->exception(self::string($entry->exception ?? null), self::int($entry->exception_bytes ?? null));
            $context = $this->projection->context(self::string($entry->context ?? null), self::int($entry->context_bytes ?? null));

            return new JsonResponse($this->historyEntry($entry) + ['exception' => $exception, 'context' => $context], 200, [], self::JSON);
        });
    }

    /**
     * Runs one request: 500 `redaction_config_invalid` before any job data,
     * 503 when the read fails.
     *
     * @param  Closure(): JsonResponse  $work
     */
    private function answer(string $operation, Closure $work): JsonResponse
    {
        try {
            $this->projection->assertValid();

            return $work();
        } catch (RedactionConfigInvalid $invalid) {
            return new JsonResponse($invalid->body(), 500, [], self::JSON);
        } catch (Throwable $error) {
            Watcher::instance()->report($operation, $error);

            if (JobList::isTimeout($error)) {
                return new JsonResponse(['error' => 'read_timeout', 'message' => 'The job could not be read in time. Try again.'], 503, [], self::JSON);
            }

            return new JsonResponse(['error' => 'read_failed', 'message' => 'The job could not be read.'], 503, [], self::JSON);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Connection $mirror, stdClass $row): array
    {
        $now = Clock::now();
        $uuid = (string) $row->uuid;
        $parts = $this->section($mirror, 'parts', fn (Connection $session): array => $this->jobs->rowParts($session, $row));
        $operation = self::string($row->operation_id ?? null) === null ? null
            : ($this->section($mirror, 'operation', fn (Connection $session): ?array => $this->operation($session, (string) $row->operation_id))
                ?? self::unknownOperation((string) $row->operation_id));
        $summaries = $this->section($mirror, 'cycles', static fn (Connection $session): array => array_values($session->table(CycleSummaries::TABLE)
            ->where('job_uuid', $uuid)
            ->orderByDesc('cycle')
            // 50 individual summaries and the merged row, whose cycle is the lowest.
            ->limit(CycleSummaries::INDIVIDUAL + 1)
            ->get(self::SUMMARY_COLUMNS)
            ->all()));
        $history = $this->section($mirror, 'history', fn (Connection $session): array => $this->historyPage($session, $row, null, null));

        $summary = $this->projection->summary($row, ($parts ?? []) + ['operation' => $operation]);

        if ($parts === null) {
            // The tags and the worker could not be read: unknown, never "none".
            $summary['tags'] = null;
            $summary['worker'] = null;
            $summary['lease_expires_at'] = null;
        }

        $projected = $this->projection->payload(self::string($row->payload ?? null), self::int($row->payload_bytes ?? null));
        $detail = $summary + [
            'payload' => $projected['payload'],
            'command' => $projected['command'],
            'exception' => $this->projection->exception(self::string($row->exception ?? null), self::int($row->exception_bytes ?? null)),
            'context' => $this->projection->context(self::string($row->context ?? null), self::int($row->context_bytes ?? null)),
            'cycles' => $summaries === null ? null : $this->cycles($row, $summaries, $now),
            'retries' => $summaries === null ? null : $this->retries($row, $summaries),
            'history' => $history,
        ];

        if (in_array($row->status, ['pending', 'reserved'], true)) {
            $detail['signals'] = $this->signals($row, $now);
            $detail['enforced_rule'] = EnforcedRule::forRow($row, $now);
        }

        if ($row->status === 'pending') {
            $detail['chained'] = self::chained(self::string($row->payload ?? null));
        }

        return $detail + [
            'operation' => $operation,
            'actions' => $this->actions($row, $operation),
            'batch' => $this->batch(self::string($row->batch_id ?? null)),
        ];
    }

    /**
     * Whether the payload's command carries a chain of later jobs: the count
     * of its serialized `chained` property (Laravel's Queueable), read as
     * text, never unserialized. A serialized object without the property has
     * no chain: Laravel's serialization leaves out a property at its default
     * (the empty chain). Null when it cannot be told: a payload over its
     * bound, or a command that is not a serialized object (an encrypted one).
     */
    public static function chained(?string $payload): ?bool
    {
        $decoded = $payload === null ? null : json_decode($payload, true, 64);
        $command = is_array($decoded) && is_array($decoded['data'] ?? null) ? ($decoded['data']['command'] ?? null) : null;

        if (! is_string($command) || ! str_starts_with($command, 'O:')) {
            return null;
        }

        return preg_match('/s:7:"chained";a:(\d+):/', $command, $match) === 1 && (int) $match[1] > 0;
    }

    /**
     * Runs one section of the detail on its own bounded read; null when it
     * fails (reported, without a job value).
     *
     * @template T
     *
     * @param  Closure(Connection): T  $work
     * @return T|null
     */
    private function section(Connection $mirror, string $name, Closure $work): mixed
    {
        try {
            return $this->sessions->bounded($mirror, $work);
        } catch (RedactionConfigInvalid $invalid) {
            throw $invalid;
        } catch (Throwable $error) {
            Watcher::instance()->report('http:job_detail_'.$name, $error);

            return null;
        }
    }

    /**
     * The stuck signals of a pending or reserved row: every
     * signal that holds at $now, `[{signal, facts}]`. The row has
     * ROW_COLUMNS, which hold StuckSignals::COLUMNS.
     *
     * @return list<array{signal: string, facts: array<string, int|float|string>}>
     */
    private function signals(stdClass $row, float $now): array
    {
        return $this->stuck->of($row, $now);
    }

    /**
     * @param  list<stdClass>  $summaries
     * @return list<array<string, mixed>>
     */
    private function cycles(stdClass $row, array $summaries, float $now): array
    {
        $current = self::int($row->cycle) ?? 1;
        $outcome = self::outcomeOf((string) $row->status);
        $finished = self::time($row->finished_at ?? null);
        $failedAt = self::time($row->failed_at ?? null) ?? $finished;
        $effective = EnforcedRule::effectiveOfRow($row);
        $at = $outcome === 'in_progress' ? $now : ($outcome === 'failed' ? $failedAt : $finished) ?? $now;
        $rule = EnforcedRule::of(
            $effective['tries'],
            $effective['tries_source'],
            self::int($row->retry_until ?? null),
            $at,
            self::int($row->max_exceptions ?? null),
            (bool) ($row->fail_on_timeout ?? false),
            $effective['timeout'],
            $effective['timeout_source'],
        );
        // The failing attempt: a failure whose reservation was not observed names a later attempt than `attempts`.
        $attempts = EnforcedRule::latestAttempt($row);

        $cycles = [$this->cycleEntry($row, $current, true, $outcome, self::time($row->cycle_started_at ?? null), self::string($row->cycle_source ?? null), $finished, $effective, $rule,
            $outcome === 'failed' ? Diagnostics::terminal(self::string($row->exception_class ?? null), $attempts, $failedAt, $rule) : null)];
        $merged = null;

        foreach ($summaries as $summary) {
            $cycle = self::int($summary->cycle) ?? 0;

            if ((bool) $summary->merged) {
                $merged = [
                    'cycle' => $cycle,
                    'merged' => true,
                    'first_cycle' => self::int($summary->first_cycle ?? null),
                    'merged_cycles' => self::int($summary->merged_cycles ?? null),
                    'merged_retries' => self::int($summary->merged_retries ?? null),
                ];

                continue;
            }

            if ($cycle >= $current) {
                // Not an older cycle of this row.
                continue;
            }

            $summaryOutcome = self::string($summary->outcome ?? null);
            $summaryFinished = self::time($summary->finished_at ?? null);
            $frozen = [
                'tries' => self::int($summary->effective_tries ?? null),
                'tries_source' => (string) ($summary->effective_tries_source ?? EnforcedRule::SOURCE_UNKNOWN),
                'timeout' => self::int($summary->effective_timeout ?? null),
                'timeout_source' => (string) ($summary->effective_timeout_source ?? EnforcedRule::SOURCE_UNKNOWN),
            ];
            $summaryRule = EnforcedRule::of(
                $frozen['tries'],
                $frozen['tries_source'],
                self::int($summary->retry_until ?? null),
                $summaryFinished ?? $now,
                self::int($summary->max_exceptions ?? null),
                (bool) ($summary->fail_on_timeout ?? false),
                $frozen['timeout'],
                $frozen['timeout_source'],
            );

            $cycles[] = $this->cycleEntry(
                $summary,
                $cycle,
                false,
                $summaryOutcome,
                self::time($summary->started_at ?? null),
                self::string($summary->started_source ?? null),
                $summaryFinished,
                $frozen,
                // A frozen cycle without a finish time has no time to evaluate its rule at.
                $summaryFinished === null ? null : $summaryRule,
                $summaryOutcome === 'failed' ? Diagnostics::terminal(self::string($summary->exception_class ?? null), EnforcedRule::latestAttempt($summary), $summaryFinished, $summaryRule) : null,
            );
        }

        if ($merged !== null) {
            $cycles[] = $merged;
        }

        return $cycles;
    }

    /**
     * @param  array{tries: int|null, tries_source: string, timeout: int|null, timeout_source: string}  $effective
     * @param  array<string, mixed>|null  $rule
     * @param  array{kind: string, facts: array<string, mixed>}|null  $terminal
     * @return array<string, mixed>
     */
    private function cycleEntry(stdClass $source, int $cycle, bool $current, ?string $outcome, ?float $startedAt, ?string $startedSource, ?float $finishedAt, array $effective, ?array $rule, ?array $terminal): array
    {
        $entry = [
            'cycle' => $cycle,
            'current' => $current,
            'outcome' => $outcome,
            'started_at' => $startedAt,
            'started_source' => $startedSource,
            'finished_at' => $finishedAt,
        ];

        foreach (self::COUNTERS as $counter) {
            $entry[$counter] = self::int($source->{$counter} ?? null) ?? 0;
        }

        return $entry + [
            'exception_class' => self::string($source->exception_class ?? null),
            'exception_message' => $this->projection->message(self::string($source->exception_message ?? null)),
            'limits' => [
                'max_tries' => self::int($source->max_tries ?? null),
                'retry_until' => self::int($source->retry_until ?? null),
                'max_exceptions' => self::int($source->max_exceptions ?? null),
                'timeout' => self::int($source->timeout ?? null),
                'fail_on_timeout' => (bool) ($source->fail_on_timeout ?? false),
            ],
            'effective' => $effective,
            'enforced_rule' => $rule,
            'terminal' => $terminal,
        ];
    }

    /**
     * @param  list<stdClass>  $summaries
     * @return array<string, mixed>
     */
    private function retries(stdClass $row, array $summaries): array
    {
        $current = self::int($row->cycle) ?? 1;
        $entries = [];
        $earlier = null;
        $folded = 0;

        if ($current >= 2 && self::string($row->cycle_source ?? null) !== null) {
            $entries[] = ['kind' => 'retry', 'cycle' => $current, 'started_at' => self::time($row->cycle_started_at ?? null), 'source' => (string) $row->cycle_source, 'outcome' => self::outcomeOf((string) $row->status)];
        }

        foreach ($summaries as $summary) {
            $cycle = self::int($summary->cycle) ?? 0;

            if ((bool) $summary->merged) {
                $folded = self::int($summary->merged_retries ?? null) ?? 0;

                if ($folded > 0) {
                    $earlier = ['kind' => 'earlier', 'first_cycle' => self::int($summary->first_cycle ?? null), 'last_cycle' => $cycle, 'count' => $folded];
                }

                continue;
            }

            if ($cycle >= 2 && $cycle < $current && self::string($summary->started_source ?? null) !== null) {
                $entries[] = ['kind' => 'retry', 'cycle' => $cycle, 'started_at' => self::time($summary->started_at ?? null), 'source' => (string) $summary->started_source, 'outcome' => self::string($summary->outcome ?? null)];
            }
        }

        $retained = count($entries);
        $retried = self::int($row->retried_count ?? null) ?? 0;

        if ($earlier !== null) {
            $entries[] = $earlier;
        }

        return [
            'entries' => $entries,
            'retries_retained' => $retained,
            'retries_folded' => $folded,
            'retries_unrecorded' => max(0, $retried - $retained - $folded),
            'retried_count' => $retried,
            'retried_count_inferred' => (bool) ($row->retried_count_inferred ?? false),
        ];
    }

    /**
     * One page of a job's history, newest first.
     *
     * @return array{entries: list<array<string, mixed>>, next_cursor: string|null, history_truncated: bool, history_discarded: int}
     */
    private function historyPage(Connection $session, stdClass $row, ?int $cycle, ?Cursor $cursor): array
    {
        $uuid = (string) $row->uuid;
        $query = $session->table(History::TABLE)->where('job_uuid', $uuid);

        if ($cycle !== null) {
            $query->where('cycle', $cycle);
        }

        if ($cursor !== null) {
            $afterCycle = (int) (float) $cursor->order;
            $afterId = (int) $cursor->key;

            if ($cycle !== null) {
                $query->where('id', '<', $afterId);
            } elseif (in_array($session->getDriverName(), ['mysql', 'mariadb'], true)) {
                // (cycle, id) < (c, i) as nested terms, which MySQL's range optimizer serves.
                $query->where('cycle', '<=', $afterCycle)->where(static function ($query) use ($afterCycle, $afterId): void {
                    $query->where('cycle', '<', $afterCycle)->orWhere('id', '<', $afterId);
                });
            } else {
                $query->whereRaw('("cycle", "id") < (?, ?)', [$afterCycle, $afterId]);
            }
        }

        $rows = $query->orderByDesc('cycle')->orderByDesc('id')->limit(self::HISTORY_PER_PAGE + 1)->get(self::HISTORY_COLUMNS)->all();
        $more = count($rows) > self::HISTORY_PER_PAGE;
        $rows = array_slice($rows, 0, self::HISTORY_PER_PAGE);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            'entries' => array_map(fn (stdClass $entry): array => $this->historyEntry($entry), $rows),
            'next_cursor' => $more && $last !== null ? Cursor::at((int) $last->cycle, (int) $last->id, Cursor::AFTER, self::historyHash($uuid, $cycle))->encode() : null,
            'history_truncated' => (bool) ($row->history_truncated ?? false),
            'history_discarded' => self::int($row->history_discarded ?? null) ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyEntry(stdClass $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'cycle' => self::int($entry->cycle) ?? 0,
            'attempt' => self::int($entry->attempt ?? null),
            'event' => (string) $entry->event,
            'source' => self::string($entry->source ?? null),
            'reason' => self::string($entry->reason ?? null),
            'exception_class' => self::string($entry->exception_class ?? null),
            'exception_message' => $this->projection->message(self::string($entry->exception_message ?? null)),
            'occurred_at' => self::time($entry->occurred_at ?? null),
            'has_exception' => ($entry->exception_bytes ?? null) !== null,
        ];
    }

    /**
     * The ledger row of the holding operation, or null when it is gone.
     *
     * @return array<string, mixed>|null
     */
    private function operation(Connection $session, string $id): ?array
    {
        $columns = self::OPERATION_COLUMNS;
        $withBulkRef = $session->getSchemaBuilder()->hasColumn(Operations::TABLE, 'bulk_ref');

        if ($withBulkRef) {
            $columns[] = 'bulk_ref';
        }

        $operation = $session->table(Operations::TABLE)->where('id', $id)->first($columns);

        if ($operation === null) {
            return null;
        }

        return [
            'id' => (string) $operation->id,
            'action' => (string) $operation->action,
            'state' => (string) $operation->state,
            'mode' => self::string($operation->mode ?? null),
            'source' => self::string($operation->source ?? null),
            'actor' => self::string($operation->actor ?? null),
            'error_code' => self::string($operation->error_code ?? null),
            'awaiting_reason' => self::string($operation->awaiting_reason ?? null),
            'bulk_ref' => $withBulkRef ? self::string($operation->bulk_ref ?? null) : null,
            'created_at' => self::time($operation->created_at ?? null),
            'updated_at' => self::time($operation->updated_at ?? null),
            'next_check_at' => self::time($operation->next_check_at ?? null),
        ];
    }

    /**
     * A holding operation whose ledger row is gone or cannot be read: held,
     * state unknown.
     *
     * @return array<string, mixed>
     */
    private static function unknownOperation(string $id): array
    {
        return ['id' => $id] + array_fill_keys(['action', 'state', 'mode', 'source', 'actor', 'error_code', 'awaiting_reason', 'bulk_ref', 'created_at', 'updated_at', 'next_check_at'], null);
    }

    /**
     * @param  array<string, mixed>|null  $operation
     * @return array{retry: bool, forget: bool, resolve: bool, read_only_reason: string|null}
     */
    private function actions(stdClass $row, ?array $operation): array
    {
        $failed = $row->status === 'failed' && $operation === null;
        [$allowed, $reason] = $failed ? $this->adapterDecision($row) : [false, null];
        $pending = $row->status === 'pending' && $operation === null;
        $availableAt = self::time($row->available_at ?? null);

        return [
            'retry' => $allowed,
            'forget' => $allowed,
            'resolve' => $operation !== null && is_string($operation['awaiting_reason'] ?? null),
            'read_only_reason' => $reason,
            'delete' => $pending,
            // An unresolved bulk row: its jobs id is not known yet, so the delete waits (the button is disabled).
            'pending_reason' => $pending && ($row->job_id ?? null) === null ? 'job_id_unknown' : null,
            // A delayed job: its available_at is after now, by PHP's clock (the workers' clock).
            'run_now' => $pending && $availableAt !== null && $availableAt > Clock::now(),
        ];
    }

    /**
     * Whether the failed-record adapter lets this failed row be retried and
     * forgotten, and its refusal code. A host SQLite file that allows no
     * automatic read leaves the decision to the operator action (allowed, no
     * reason); an adapter that cannot be built at all (the bound failer
     * throws) offers nothing, because the server would refuse it too.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function adapterDecision(stdClass $row): array
    {
        $app = Container::getInstance();

        try {
            $records = FailedRecords::for($app, $app->make('config'));
        } catch (HostFileAccessNotAllowed) {
            return [true, null];
        } catch (Throwable $error) {
            Watcher::instance()->report('http:job_detail_failed_records', $error);

            return [false, null];
        }

        if ($records->kind() === FailedRecords::READ_ONLY) {
            return [false, $records->refusal()];
        }

        if ($records instanceof SqlFailedRecords && ($row->provider_id ?? null) === null) {
            return [false, 'occurrence_unprovable'];
        }

        return [true, null];
    }

    /**
     * @return array{id: string, name: string|null}|null
     */
    private function batch(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $name = null;

        try {
            $batches = Container::getInstance()->make(DatabaseBatchRepository::class);
            $session = $batches->searchSession();

            if ($session !== null) {
                $name = $this->sessions->bounded($session, static fn (Connection $session): mixed => $session->table($batches->table())->where('id', $id)->value('name'));
            }
        } catch (Throwable $error) {
            Watcher::instance()->report('http:job_detail_batch', $error);
            $name = null;
        }

        $name = is_string($name) ? $this->projection->text($name) : null;

        // The name is redacted whole, then cut: a custom batch table can hold a longer text than Laravel's string(255).
        return ['id' => $id, 'name' => $name === null ? null : mb_substr($name, 0, self::BATCH_NAME_CHARACTERS)];
    }

    private static function outcomeOf(string $status): string
    {
        return match ($status) {
            'pending', 'reserved' => 'in_progress',
            'failed', 'retrying' => 'failed',
            'completed' => 'completed',
            default => 'unknown',
        };
    }

    /**
     * @throws InvalidListRequest
     */
    private static function cycleParameter(Request $request): ?int
    {
        $value = $request->query('cycle');

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^[1-9]\d{0,9}$/D', $value) !== 1 || (int) $value > self::MAX_CYCLE) {
            throw new InvalidListRequest('cycle', 'cycle must be a whole number of at least 1.');
        }

        return (int) $value;
    }

    /**
     * @throws InvalidListRequest
     */
    private static function cursorParameter(Request $request, string $uuid, ?int $cycle): ?Cursor
    {
        $value = $request->query('cursor');

        if ($value === null) {
            return null;
        }

        $cursor = Cursor::decode($value, 'cursor', self::historyHash($uuid, $cycle), false);

        if ($cursor->direction !== Cursor::AFTER || $cursor->order === null || preg_match('/^\d{1,10}\.0{6}$/D', $cursor->order) !== 1 || (int) $cursor->order > self::MAX_CYCLE) {
            throw new InvalidListRequest('cursor', 'cursor is not a cursor of this list.');
        }

        return $cursor;
    }

    /**
     * The filter hash of a history cursor: the job and the cycle filter.
     */
    private static function historyHash(string $uuid, ?int $cycle): string
    {
        return hash('sha256', 'history|'.$uuid.'|'.($cycle ?? ''));
    }

    private static function notFoundJson(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found', 'message' => 'Not found.'], 404, [], self::JSON);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function time(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
