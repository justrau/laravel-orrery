<?php

namespace Justrau\Orrery\Http\Bulk;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Justrau\Orrery\Failures\KeyedFailures;
use Justrau\Orrery\Http\Controllers\CountsController;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Lists\Scan;
use Justrau\Orrery\Http\Lists\ScanItem;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\SafeText;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use stdClass;
use Throwable;

/**
 * The bulk runs: a signed freeze, then
 * frozen pages through the operation ledger, with an audit row per run.
 *
 * **freeze()** reads, writes nothing: the global MAX(occurrence_id) first
 * (D-5), then the total and the held count of the members with the
 * source's caps (`dashboard.group_count_cap` per key, summed;
 * `dashboard.list_count_cap` for a list; `dashboard.count_cap` for a batch),
 * and a preview of the first PREVIEW members (JobProjection::summary()). A
 * forget is refused unless its total is exact and at most
 * `orrery.bulk.forget_max` (counted with that cap + 1), and with an empty
 * `app.key`. The answer carries the signed token (FrozenToken) and the same
 * fields for display.
 *
 * **run()** verifies the token, checks the age (the request that creates
 * the audit row: frozen_at at most EXPIRY_SECONDS old; every later request:
 * last_run_at), the forget confirmation and the audit row's fields, then
 * processes the next page after the cursor: at most PAGE_MEMBERS members,
 * no new member after PAGE_SECONDS, at most `dashboard.search_candidates`
 * examined rows. The cursor advances past every examined row (members,
 * non-members, residual misses); `done` is true only when every range is
 * exhausted. Each member is read again and checked; a held (`retrying`)
 * member is `uncertain` and is not acted on; a failed member goes to
 * RetryService::retry() or ForgetService::forget() with the run's ref, one
 * by one, inside one operator action for the page, outside any mirror
 * transaction. At the end of the page one statement adds the page's outcome
 * counts to the audit row.
 *
 * Write answers carry ids, outcomes, codes and the services' operator texts
 * only, never job text: a run answers also while the
 * redaction configuration is invalid.
 */
final class BulkRun
{
    public const RETRY = 'retry';

    public const FORGET = 'forget';

    /** The outcomes of a member (the classification of the group operations before the bulk runs, plus not_confirmed). */
    public const ACCEPTED = 'accepted';

    public const REFUSED = 'refused';

    public const UNCERTAIN = 'uncertain';

    public const NOT_MEMBER = 'not_member';

    public const ALREADY_DONE = 'already_done';

    public const NOT_CONFIRMED = 'not_confirmed';

    /** The most members of one page. */
    public const PAGE_MEMBERS = 200;

    /** No page starts a new member after this many seconds. */
    public const PAGE_SECONDS = 10;

    /** The members that a freeze shows. */
    public const PREVIEW = 20;

    /** The default of orrery.bulk.forget_max. */
    public const FORGET_MAX = 10_000;

    /** The largest valid orrery.bulk.forget_max. */
    public const FORGET_MAX_LIMIT = 100_000;

    /** A token older than this cannot start a run; a run idle for longer expires. */
    public const EXPIRY_SECONDS = 3600;

    /** In a freeze, after the read of MAX(occurrence_id) and before the member reads. */
    public const AFTER_MAX_OCCURRENCE = 'bulk.after_max_occurrence';

    /** Between two members of a page (a test holds a page here). */
    public const BETWEEN_MEMBERS = 'bulk.between_members';

    /** In a run page, after its members and before the display-only remaining count. */
    public const BEFORE_REMAINING = 'bulk.before_remaining';

    /** The service codes after which the row is classified again from a fresh read. */
    private const MOVED = ['occurrence_mismatch', 'not_failed'];

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly Repository $config,
        private readonly RetryService $retries,
        private readonly ForgetService $forgets,
        private readonly JobProjection $projection,
    ) {}

    /**
     * orrery.bulk.forget_max: a whole number from 1 to FORGET_MAX_LIMIT
     * (an integer or a string of digits), its default when the key is
     * missing, else null: every bulk forget is refused
     * (`config_invalid`).
     */
    public function forgetMax(): ?int
    {
        return self::forgetMaxOf($this->config);
    }

    /**
     * forgetMax() of a configuration (the health report reads it too).
     */
    public static function forgetMaxOf(Repository $config): ?int
    {
        $value = $config->get('orrery.bulk.forget_max', self::FORGET_MAX);

        if (is_string($value) && preg_match('/^[1-9]\d{0,5}$/D', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 && $value <= self::FORGET_MAX_LIMIT ? $value : null;
    }

    /**
     * Whether app.key is empty: a bulk forget is refused (`app_key_missing`).
     */
    public function appKeyMissing(): bool
    {
        return self::appKeyMissingIn($this->config);
    }

    public static function appKeyMissingIn(Repository $config): bool
    {
        $key = $config->get('app.key');

        return ! is_string($key) || $key === '';
    }

    /**
     * The freeze answer (POST /api/bulk/freeze).
     *
     * @return array{frozen: array<string, mixed>, held: array{count: int, at_least: bool}, preview: list<array<string, mixed>>}
     *
     * @throws BulkRefusal
     */
    public function freeze(mixed $action, mixed $source, bool $preview = true): array
    {
        $action = self::action($action);
        $source = BulkSource::from($source);
        $mirror = $this->sessions->mirror();
        $this->assertInstalled($mirror);
        $forgetMax = null;

        if ($action === self::FORGET) {
            $forgetMax = $this->assertForgetAllowed();
        }

        return $this->sessions->bounded($mirror, function (Connection $session) use ($action, $source, $forgetMax, $preview): array {
            // D-5: the global bound, read before any member read; never derived from the members.
            $maxOccurrence = (int) $session->table(JobStore::TABLE)->max('occurrence_id');
            Hooks::reach(self::AFTER_MAX_OCCURRENCE);
            $members = new BulkMembers($session, $source, $maxOccurrence);
            $sourceCap = $this->sourceCap($source);
            $cap = $forgetMax === null ? $sourceCap : min($sourceCap, $forgetMax);
            $budget = $this->budget();
            $total = $members->count($cap, null, $budget);
            $held = $members->count($cap, null, $budget, ['retrying']);
            $exact = ! $total['at_least'] && ! $total['limited'];

            if ($forgetMax !== null) {
                if ($total['limited'] || ($total['at_least'] && $forgetMax > $sourceCap)) {
                    throw new BulkRefusal(BulkErrors::TOTAL_NOT_EXACT, 'The total is not exact, so a forget cannot start. Narrow the selection.');
                }

                if ($total['at_least'] || $total['count'] > $forgetMax) {
                    throw new BulkRefusal(BulkErrors::TOO_MANY, "More than {$forgetMax} records match, so a forget cannot start. Narrow the selection.", 422, ['maximum' => $forgetMax]);
                }
            }

            $count = ['count' => $total['count'], 'at_least' => $total['at_least'] || $total['limited']];
            $token = FrozenToken::freeze(Operations::newId(), $action, $source, $maxOccurrence, Clock::now(), $count, $exact);

            // Beside the token, for display only: the server reads every field from the token.
            $display = ['token' => $token->encode($this->appKey())] + $token->fields();
            $display['total'] = $count;

            return [
                'frozen' => $display,
                'held' => ['count' => $held['count'], 'at_least' => $held['at_least'] || $held['limited']],
                'preview' => $preview ? $this->preview($session, $members) : [],
            ];
        });
    }

    /**
     * One page of a run (POST /api/bulk/run). $source is the operation
     * source of the members (Operations::SOURCES): null gives `batch` for the
     * retry of a batch source, else `bulk` (a forget is always `bulk`); the
     * command passes `cli`. $stop is asked
     * before each new member (the command's SIGINT).
     *
     * @return array{results: list<array<string, mixed>>, totals: array<string, int>, refused_reasons: object, next_after: string|null, done: bool, remaining: array{count: int|null, at_least: bool}, ref: string}
     *
     * @throws BulkRefusal
     */
    public function run(mixed $token, mixed $after, mixed $confirmedTotal, ?string $actor, ?string $source = null, ?Closure $stop = null): array
    {
        $mirror = $this->sessions->mirror();
        $this->assertInstalled($mirror);
        $frozen = FrozenToken::verify($token, $this->appKey());
        $confirmed = null;

        if ($frozen->action === self::FORGET) {
            $forgetMax = $this->assertForgetAllowed();
            $confirmed = is_int($confirmedTotal) ? $confirmedTotal : null;

            if (! $frozen->exact || $frozen->total['at_least'] || $frozen->total['count'] > $forgetMax || $confirmed !== $frozen->total['count']) {
                throw new BulkRefusal(BulkErrors::CONFIRMATION_MISMATCH, 'The confirmed total is not the exact frozen total: type the total that is shown.');
            }
        }

        $cursor = BulkCursor::decode($after, $frozen->ref, max(1, $frozen->source->kind === BulkSource::GROUPS ? count($frozen->source->keys) : 1));
        $this->admit($mirror, $frozen, $confirmed, $actor === null ? null : SafeText::clean($actor));

        // The page resolves the mirror at each read: a member's failed step (a publication whose
        // COMMIT failed, a fenced delete) can release the session object, and when the queue's or
        // the failed records' connection name is the mirror's, that object is the mirror session.
        $members = new BulkMembers(fn (): Connection => $this->sessions->mirror(), $frozen->source, $frozen->maxOccurrence);
        $page = $this->page($members, $frozen, $cursor, $actor, $source ?? ($frozen->action === self::RETRY && $frozen->source->kind === BulkSource::BATCH ? 'batch' : 'bulk'), $stop);

        $remaining = ['count' => 0, 'at_least' => false];

        if (! $page['done']) {
            // Display only: the page already acted, so a failed count answers unknown, never an error.
            try {
                Hooks::reach(self::BEFORE_REMAINING);
                $next = $page['next'] ?? $cursor;
                $counted = $this->sessions->bounded($this->sessions->mirror(), fn (Connection $session): array => (new BulkMembers($session, $frozen->source, $frozen->maxOccurrence))
                    ->count($this->sourceCap($frozen->source), $next, $this->budget()));
                $remaining = ['count' => $counted['count'], 'at_least' => $counted['at_least'] || $counted['limited']];
            } catch (Throwable $error) {
                Watcher::instance()->report('bulk:remaining', $error);
                $remaining = ['count' => null, 'at_least' => false];
            }
        }

        return [
            'ref' => $frozen->ref,
            'results' => $page['results'],
            'totals' => $page['totals'],
            'refused_reasons' => $page['reasons'],
            'next_after' => $page['done'] ? null : ($page['next'] ?? $cursor)?->encode(),
            'done' => $page['done'],
            'remaining' => $remaining,
        ];
    }

    /**
     * The audit row and the state of one run (GET /api/bulk/{ref}), or null.
     *
     * @return array<string, mixed>|null
     *
     * @throws BulkRefusal `schema_outdated`
     */
    public function show(string $ref): ?array
    {
        $mirror = $this->sessions->mirror();
        $this->assertInstalled($mirror);

        return $this->sessions->bounded($mirror, function (Connection $session) use ($ref): ?array {
            $row = BulkRuns::find($session, $ref);

            return $row === null ? null : BulkRuns::present($row) + [
                'operations' => BulkRuns::operationStates($session, $ref),
                'operations_complete' => BulkRuns::operationsComplete($row, $this->retentionDays(), DatabaseClock::now($session)),
            ];
        });
    }

    /**
     * The most recent runs (GET /api/bulk).
     *
     * @return list<array<string, mixed>>
     *
     * @throws BulkRefusal `schema_outdated`
     */
    public function recent(): array
    {
        $mirror = $this->sessions->mirror();
        $this->assertInstalled($mirror);

        return $this->sessions->bounded($mirror, static fn (Connection $session): array => array_map(
            static fn (stdClass $row): array => BulkRuns::present($row),
            BulkRuns::recent($session),
        ));
    }

    /**
     * Creates the audit row on the run's first request, or checks it on a
     * later one; the age rule of each.
     *
     * @throws BulkRefusal `frozen_expired`, `frozen_mismatch`
     */
    private function admit(Connection $mirror, FrozenToken $frozen, ?int $confirmed, ?string $actor): void
    {
        $now = Clock::now();
        $row = BulkRuns::find($mirror, $frozen->ref);

        if ($row === null) {
            if ($now - $frozen->frozenAt > self::EXPIRY_SECONDS) {
                throw self::expired();
            }

            BulkRuns::create($mirror, $frozen, $confirmed, $actor, $now);
            $row = BulkRuns::find($mirror, $frozen->ref);

            if ($row === null || ! BulkRuns::matches($row, $frozen, $confirmed)) {
                throw self::mismatch();
            }

            return;
        }

        if (! BulkRuns::matches($row, $frozen, $confirmed)) {
            throw self::mismatch();
        }

        if ($now - Clock::stored($row->last_run_at) > self::EXPIRY_SECONDS) {
            throw self::expired();
        }
    }

    /**
     * Processes one page after the cursor.
     *
     * @return array{results: list<array<string, mixed>>, totals: array<string, int>, reasons: object, next: BulkCursor|null, done: bool}
     */
    private function page(BulkMembers $members, FrozenToken $frozen, ?BulkCursor $cursor, ?string $actor, string $source, ?Closure $stop): array
    {
        $results = [];
        $next = null;
        $done = false;
        $deadline = Clock::monotonic() + self::PAGE_SECONDS;
        $budget = $this->budget();

        try {
            PackageSession::asOperator(function () use ($members, $frozen, $cursor, $actor, $source, $stop, $deadline, &$budget, &$results, &$next, &$done): void {
                for ($segment = $cursor->segment ?? 0; $segment < $members->segments(); $segment++) {
                    $position = $cursor !== null && $cursor->segment === $segment ? [$cursor->order, $cursor->id] : null;
                    $scan = $members->scan($segment, $position, $budget);

                    while (true) {
                        if (count($results) >= self::PAGE_MEMBERS || Clock::monotonic() >= $deadline || ($stop !== null && $stop())) {
                            // The end of the page: done only when no row is left anywhere.
                            if ($segment === $members->segments() - 1 && $this->exhausted($scan)) {
                                $done = true;
                            }

                            return;
                        }

                        $item = $scan->next();

                        if ($item === null) {
                            $budget -= $scan->examined;

                            if ($scan->limited || $budget <= 0) {
                                return;
                            }

                            break;
                        }

                        $position = BulkCursor::at($frozen->ref, $segment, (string) $item->o, (int) $item->k);

                        if (! $members->accepts($item)) {
                            // The cursor moves past every examined row, members or not.
                            $next = $position;

                            continue;
                        }

                        if ($results !== []) {
                            Hooks::reach(self::BETWEEN_MEMBERS);

                            if ($stop !== null && $stop()) {
                                // A stop (SIGINT) before this member: the cursor stays at the previous one.
                                return;
                            }
                        }

                        $results[] = $this->member($item, $frozen, $members, $actor, $source);
                        $next = $position;
                    }
                }

                $done = true;
            });
        } catch (Throwable $error) {
            if ($results === []) {
                throw $error;
            }

            // A scan read failed after members were acted on: the page keeps their results and
            // ends as not done at the last examined row, so the next page continues from there.
            Watcher::instance()->report('bulk:scan', $error);
            $done = false;
        } finally {
            $report = self::report($results);

            try {
                BulkRuns::addCounters($this->sessions->mirror(), $frozen->ref, $report['totals'], Clock::now());
            } catch (Throwable $error) {
                // The ledger keeps the truth of every member; only the counters of this page are lost.
                Watcher::instance()->report('bulk:counters', $error);
            }
        }

        return $report + ['next' => $next, 'done' => $done];
    }

    /**
     * Whether a scan has no row left (one more read past the page's last
     * member; the cursor stays at that member).
     */
    private function exhausted(Scan $scan): bool
    {
        return $scan->next() === null && ! $scan->limited;
    }

    /**
     * One member: read again, checked, then acted on.
     *
     * @return array{id: string, occurrence_id: int, outcome: string, code: string|null, message: string|null, operation_id: string|null}
     */
    private function member(ScanItem $item, FrozenToken $frozen, BulkMembers $members, ?string $actor, string $source): array
    {
        $job = $item->job;
        $uuid = strtolower((string) $job?->uuid);
        $occurrence = (int) $job?->occurrence_id;
        $item = static fn (string $outcome, ?string $code, ?string $operation = null, ?string $message = null): array => [
            'id' => $uuid,
            'occurrence_id' => $occurrence,
            'outcome' => $outcome,
            'code' => $code,
            'message' => $message,
            'operation_id' => $operation,
        ];

        try {
            [$outcome, $code] = $this->classify($frozen->action, $uuid, $this->row($uuid), $occurrence, $members);
        } catch (Throwable $error) {
            Watcher::instance()->report('bulk:member', $error);

            return $item(self::REFUSED, 'mirror_unreachable');
        }

        if ($outcome !== null) {
            return $item($outcome, $code);
        }

        try {
            if ($frozen->action === self::RETRY) {
                $result = $this->retries->retry($uuid, $occurrence, $source, $actor, $frozen->ref);
                [$outcome, $code] = match ($result->result) {
                    RetryResult::ACCEPTED => [self::ACCEPTED, $result->code],
                    RetryResult::UNCERTAIN => [self::UNCERTAIN, $result->code ?? 'retry_uncertain'],
                    RetryResult::NOT_ELIGIBLE => $this->notEligible($frozen->action, $uuid, $occurrence, $members, $result->code),
                    default => [self::REFUSED, $result->code],
                };
            } else {
                $result = $this->forgets->forget($uuid, $occurrence, $actor, $source, $frozen->ref);
                [$outcome, $code] = match ($result->result) {
                    ForgetResult::DELETED => [self::ACCEPTED, $result->code],
                    // The record is deleted (or there is none); the runner finishes the mirror delete.
                    ForgetResult::MIRROR_PENDING => [self::ACCEPTED, 'mirror_pending'],
                    ForgetResult::UNCERTAIN => [self::UNCERTAIN, $result->code],
                    ForgetResult::NOT_CONFIRMED => [self::NOT_CONFIRMED, null],
                    ForgetResult::NOT_ELIGIBLE => $this->notEligible($frozen->action, $uuid, $occurrence, $members, $result->code),
                    default => [self::REFUSED, $result->code],
                };
            }
        } catch (Throwable $error) {
            // A service lets no error out after its claim; an error here leaves the ledger as the authority.
            Watcher::instance()->report('bulk:member', $error);

            return $item(self::UNCERTAIN, 'claim_uncertain');
        }

        // The service's operator text, only while the item keeps the service's code.
        $message = $code === $result->code && $result->message !== null && $result->message !== '' ? $result->message : null;

        return $item($outcome, $code, $result->operationId, $message);
    }

    /**
     * A not_eligible answer of a service: a row that another operation holds
     * is uncertain; a row that moved is classified again from a fresh read;
     * any other code is refused.
     *
     * @return array{0: string, 1: string|null}
     */
    private function notEligible(string $action, string $uuid, int $occurrence, BulkMembers $members, ?string $code): array
    {
        if ($code === 'operation_in_progress') {
            return [self::UNCERTAIN, $code];
        }

        if (! in_array($code, self::MOVED, true)) {
            return [self::REFUSED, $code];
        }

        try {
            [$outcome, $fresh] = $this->classify($action, $uuid, $this->row($uuid), $occurrence, $members);
        } catch (Throwable $error) {
            Watcher::instance()->report('bulk:member', $error);

            return [self::REFUSED, $code];
        }

        return $outcome === null ? [self::REFUSED, $code] : [$outcome, $fresh];
    }

    /**
     * The outcome of a member before any work, from a fresh read of its row,
     * or [null, null] when it is a failed member and goes to the service.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function classify(string $action, string $uuid, ?stdClass $row, int $occurrence, BulkMembers $members): array
    {
        if ($row === null) {
            // Retention also deletes mirror rows and keeps the failed record: only a done forget proves a forget.
            return $action === self::FORGET && $this->forgets->forgotten($uuid, $occurrence) ? [self::ALREADY_DONE, 'gone'] : [self::NOT_MEMBER, 'missing'];
        }

        if ((int) $row->occurrence_id !== $occurrence) {
            return [self::NOT_MEMBER, 'occurrence_changed'];
        }

        if ($row->status === 'retrying') {
            // An operation holds it: that operation decides it, and the run never acts on it.
            return [self::UNCERTAIN, 'operation_in_progress'];
        }

        if ($row->status !== 'failed') {
            // Only a retry moves a row of this occurrence out of `failed` and writes retried_at.
            return $action === self::RETRY && $row->retried_at !== null ? [self::ALREADY_DONE, 'retried'] : [self::NOT_MEMBER, 'not_failed'];
        }

        $reason = $members->notMember($row);

        return $reason === null ? [null, null] : [self::NOT_MEMBER, $reason];
    }

    private function row(string $uuid): ?stdClass
    {
        $row = $this->sessions->mirror()->table(JobStore::TABLE)->where('uuid', $uuid)->first(BulkMembers::COLUMNS);

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The first PREVIEW members in the run's order, as list summary rows
     * (JobProjection, redacted).
     *
     * @return list<array<string, mixed>>
     */
    private function preview(Connection $session, BulkMembers $members): array
    {
        $ids = [];
        $budget = $this->budget();

        for ($segment = 0; $segment < $members->segments() && count($ids) < self::PREVIEW && $budget > 0; $segment++) {
            $scan = $members->scan($segment, null, $budget);

            while (count($ids) < self::PREVIEW && ($item = $scan->next()) !== null) {
                if ($members->accepts($item)) {
                    $ids[] = (int) $item->k;
                }
            }

            $budget -= $scan->examined;
        }

        if ($ids === []) {
            return [];
        }

        $rows = $session->table(JobStore::TABLE)->whereIn('id', $ids)->get(JobProjection::SUMMARY_COLUMNS)->keyBy('id')->all();
        $tags = [];

        foreach ($session->table('orrery_job_tags')->whereIn('job_uuid', array_map(static fn (stdClass $row): string => (string) $row->uuid, array_values($rows)))->get(['job_uuid', 'tag']) as $tag) {
            $tags[(string) $tag->job_uuid][] = (string) $tag->tag;
        }

        $preview = [];

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;

            if ($row instanceof stdClass) {
                $list = $tags[(string) $row->uuid] ?? [];
                sort($list, SORT_STRING);
                $preview[] = $this->projection->summary($row, ['tags' => $list]);
            }
        }

        return $preview;
    }

    /**
     * The outcome totals and refused reasons of a page.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array{results: list<array<string, mixed>>, totals: array<string, int>, reasons: object}
     */
    private static function report(array $results): array
    {
        $totals = array_fill_keys(BulkRuns::COUNTERS, 0);
        $reasons = [];

        foreach ($results as $result) {
            $totals[(string) $result['outcome']]++;

            if ($result['outcome'] === self::REFUSED) {
                $reason = is_string($result['code'] ?? null) ? $result['code'] : 'unknown';
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        ksort($reasons);

        // An object, so an empty map is {} in JSON, never [].
        return ['results' => $results, 'totals' => $totals, 'reasons' => (object) $reasons];
    }

    /**
     * The count cap of a source: per key for groups, the list cap, the
     * batch (count) cap.
     */
    private function sourceCap(BulkSource $source): int
    {
        return match ($source->kind) {
            BulkSource::GROUPS => KeyedFailures::cap(),
            BulkSource::LIST => self::positive($this->config->get('orrery.dashboard.list_count_cap', JobList::COUNT_CAP), JobList::COUNT_CAP),
            default => CountsController::cap(),
        };
    }

    /**
     * orrery.operations.retention_days: the ledger keeps a finished
     * operation this long.
     */
    private function retentionDays(): float
    {
        $days = $this->config->get('orrery.operations.retention_days', 30);

        return is_numeric($days) && (float) $days >= 0 ? (float) $days : 30.0;
    }

    /**
     * The rows that one freeze count or one page examines at most
     * (`dashboard.search_candidates`).
     */
    private function budget(): int
    {
        return self::positive($this->config->get('orrery.dashboard.search_candidates', JobList::SEARCH_CANDIDATES), JobList::SEARCH_CANDIDATES);
    }

    /**
     * @throws BulkRefusal
     */
    private function assertForgetAllowed(): int
    {
        $max = $this->forgetMax();

        if ($max === null) {
            throw new BulkRefusal(BulkErrors::CONFIG_INVALID, 'orrery.bulk.forget_max is not a whole number from 1 to 100000, so every bulk forget is refused. Check config/orrery.php.');
        }

        if ($this->appKeyMissing()) {
            throw new BulkRefusal(BulkErrors::APP_KEY_MISSING, 'The application has no APP_KEY, so a bulk forget cannot be signed. Set APP_KEY first.');
        }

        return $max;
    }

    /**
     * @throws BulkRefusal `schema_outdated` (503)
     */
    private function assertInstalled(Connection $mirror): void
    {
        $outdated = BulkRuns::outdated($mirror);

        if ($outdated !== null) {
            throw new BulkRefusal(BulkErrors::SCHEMA_OUTDATED, $outdated->getMessage(), 503);
        }
    }

    private function appKey(): string
    {
        $key = $this->config->get('app.key');

        return is_string($key) ? $key : '';
    }

    /**
     * @throws BulkRefusal
     */
    private static function action(mixed $action): string
    {
        if ($action !== self::RETRY && $action !== self::FORGET) {
            throw BulkRefusal::invalid('action', 'action is retry or forget.');
        }

        return $action;
    }

    private static function expired(): BulkRefusal
    {
        return new BulkRefusal(BulkErrors::FROZEN_EXPIRED, 'This run was idle for more than an hour; start a new one.');
    }

    private static function mismatch(): BulkRefusal
    {
        return new BulkRefusal(BulkErrors::FROZEN_MISMATCH, 'Another request runs this selection with other frozen fields or another confirmation: start a new one.');
    }

    private static function positive(mixed $value, int $default): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d{0,17}$/D', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }
}
