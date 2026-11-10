<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Runtime\ErrorKinds;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use PDOException;
use stdClass;
use Throwable;

/**
 * The operation runner:
 * one fair pass over the due operations of the ledger. The
 * phase `operations` calls runOnce(); tests call it directly. The
 * handlers of the actions (OperationHandler, tagged HANDLERS) take each step.
 *
 * Selection: `state IN (claimed, published, aborting)`, `awaiting_since IS
 * NULL OR awaiting_reason = 'sqlite_reads_not_allowed'`, `next_check_at <=
 * mirror_now`, the queue and failed-record connections not deferred, in key
 * order (next_check_at, id), after the persisted cursor CURSOR_KEY and not
 * beyond the pass boundary PASS_END_KEY (the largest due key when the pass
 * began). The cursor moves past every inspected row. When it reaches the
 * boundary, or no row is left before it, the pass ends, and the next run
 * starts a new pass with a new boundary.
 *
 * Per run: at most MAX_INSPECTED operations and a cooperative cap of
 * CAP_SECONDS (and the tick budget). The run stops at its first blocking
 * failure (a connect error, a connect timeout or a lock timeout), right
 * after it deferred that connection: a settings row DEFER_PREFIX.<connection>
 * with `until = mirror_now + backoff` by the connection's consecutive
 * failures; deferred connections are excluded until `until`. An undecided
 * operation gets `checks + 1` and `next_check_at = mirror_now + backoff`.
 * The backoff steps are 1, 5, 15 and 60 minutes, then hourly.
 *
 * A claimed retry whose deadline is still ahead in its queue database's
 * clock (read once per connection and run) gets `next_check_at =
 * mirror_now + (deadline − q_now)` without a check count. On a host SQLite
 * file without automatic reads (D18) that clock is not read: the handler
 * decides (it awaits).
 *
 * Every time of the runner is the mirror database's clock; the ledger rows
 * and settings rows are on the mirror.
 */
final class OperationRunner
{
    /** The tag of the handlers. */
    public const HANDLERS = 'orrery.operation_handlers';

    public const MAX_INSPECTED = 100;

    public const CAP_SECONDS = 5.0;

    /** Rows per selection statement. */
    public const PAGE = 25;

    public const CURSOR_KEY = 'operations_cursor';

    public const PASS_END_KEY = 'operations_pass_end';

    public const DEFER_PREFIX = 'operations_defer:';

    /** The backoff in seconds after the 1st, 2nd, 3rd and every later failure. */
    public const BACKOFF = [60, 300, 900, 3600];

    /** The reasons why a run stops. */
    public const STOP_EMPTY = 'empty';

    public const STOP_PASS_END = 'pass_end';

    public const STOP_LIMIT = 'limit';

    public const STOP_CAP = 'cap';

    public const STOP_BLOCKED = 'blocked';

    private const SETTINGS = 'orrery_settings';

    /** The ledger columns of the selection: every column except the large ones. */
    private const COLUMNS = [
        'id', 'action', 'state', 'mode', 'job_uuid', 'occurrence_id', 'queue_connection', 'queue', 'record_connection',
        'record_table', 'record_id', 'record_uuid', 'record_deadline', 'queue_fingerprint', 'record_fingerprint',
        'deadline', 'next_check_at', 'awaiting_since', 'awaiting_reason', 'checks', 'result_job_id', 'error_code',
        'actor', 'source', 'created_at', 'updated_at',
    ];

    /** @var array<string, OperationHandler>|null */
    private ?array $handlers = null;

    public function __construct(
        private readonly Application $app,
        private readonly PackageSession $sessions,
        private readonly Operations $operations,
        private readonly HostFiles $files,
    ) {}

    /**
     * Adds or replaces the handler of its action (besides the tagged ones).
     */
    public function handle(OperationHandler $handler): void
    {
        $this->handlers ??= $this->taggedHandlers();
        $this->handlers[$handler->action()] = $handler;
    }

    /**
     * One run. The report: the inspected and decided counts, the deferred
     * connection (or null) and why the run stopped.
     *
     * @return array{inspected: int, decided: int, deferred: string|null, stopped: string}
     */
    public function runOnce(TickBudget $budget): array
    {
        $mirror = $this->sessions->mirror();
        $context = new OperationContext(DatabaseClock::now($mirror), $budget, $this->sessions);
        $now = $context->mirrorNow;
        $started = Clock::monotonic();
        $report = ['inspected' => 0, 'decided' => 0, 'deferred' => null, 'stopped' => self::STOP_EMPTY];

        $deferred = $this->deferred($mirror, $now);
        $passEnd = $this->key($mirror, self::PASS_END_KEY);
        $cursor = $passEnd === null ? null : $this->key($mirror, self::CURSOR_KEY);

        if ($passEnd === null) {
            // A new pass: its boundary is the largest due key now.
            $last = $this->due($mirror, $now)->orderByDesc('next_check_at')->orderByDesc('id')->first(['next_check_at', 'id']);

            if (! $last instanceof stdClass) {
                return $report;
            }

            $passEnd = ['at' => self::decimal($last->next_check_at), 'id' => (string) $last->id];
            $this->putKey($mirror, self::PASS_END_KEY, $passEnd);
            $this->forgetKey($mirror, self::CURSOR_KEY);
        }

        while (true) {
            $remaining = self::MAX_INSPECTED - $report['inspected'];

            if ($remaining <= 0) {
                $report['stopped'] = self::STOP_LIMIT;

                break;
            }

            if (Clock::monotonic() - $started >= self::CAP_SECONDS || $budget->exhausted()) {
                $report['stopped'] = self::STOP_CAP;

                break;
            }

            $rows = $this->page($mirror, $now, $cursor, $passEnd, $deferred, min($remaining, self::PAGE));

            if ($rows === []) {
                $this->endPass($mirror);
                $report['stopped'] = self::STOP_PASS_END;

                break;
            }

            $stop = false;

            foreach ($rows as $row) {
                $report['inspected']++;
                $cursor = ['at' => self::decimal($row->next_check_at), 'id' => (string) $row->id];
                $step = $this->inspect($row, $context);

                if ($step->kind === OperationStep::DECIDED) {
                    $report['decided']++;
                }

                $this->apply($mirror, $row, $step, $now);

                if ($step->kind === OperationStep::BLOCKED) {
                    $report['deferred'] = $step->connection;
                    $report['stopped'] = self::STOP_BLOCKED;
                    $stop = true;

                    break;
                }

                if ($report['inspected'] >= self::MAX_INSPECTED || Clock::monotonic() - $started >= self::CAP_SECONDS || $budget->exhausted()) {
                    break;
                }
            }

            if ($cursor['at'] === $passEnd['at'] && $cursor['id'] === $passEnd['id']) {
                // The cursor reached the boundary: the next run starts a new pass.
                $this->endPass($mirror);
                $report['stopped'] = $stop ? self::STOP_BLOCKED : self::STOP_PASS_END;

                return $report;
            }

            $this->putKey($mirror, self::CURSOR_KEY, $cursor);

            if ($stop) {
                break;
            }
        }

        return $report;
    }

    /**
     * Whether an error is a blocking failure of its database connection: a
     * connect error or a lost connection (ErrorKinds), a connect timeout, or
     * a lock timeout (MySQL 1205, Postgres 55P03, SQLite busy).
     */
    public static function isBlockingFailure(Throwable $error): bool
    {
        if (ErrorKinds::isDependency($error)) {
            return true;
        }

        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            // A QueryException's message holds the SQL with its bindings (a
            // job's text): only its driver error, the next in the chain, counts.
            if (! $current instanceof PDOException || $current instanceof QueryException) {
                continue;
            }

            $info = $current->errorInfo;
            $state = is_array($info) && is_string($info[0] ?? null) ? $info[0] : (string) $current->getCode();
            $code = is_array($info) ? ($info[1] ?? null) : null;
            $message = $current->getMessage();

            if (str_starts_with($state, '08') || $state === '55P03' || $code === 1205
                || str_contains($message, 'database is locked') || str_contains($message, 'Lock wait timeout exceeded')
                || str_contains($message, '[2002]') || str_contains($message, 'timeout expired') || str_contains($message, 'could not connect to server')
                || str_contains($message, 'Connection refused') || str_contains($message, 'timed out')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The backoff after the $failures-th consecutive failure (1-based).
     */
    public static function backoff(int $failures): int
    {
        return self::BACKOFF[max(0, min($failures, count(self::BACKOFF)) - 1)];
    }

    private function inspect(stdClass $row, OperationContext $context): OperationStep
    {
        try {
            $early = $this->beforeDeadline($row, $context);

            if ($early !== null) {
                return $early;
            }

            $handler = $this->handlers()[(string) $row->action] ?? null;

            if ($handler === null) {
                return OperationStep::undecided('handler_missing');
            }

            return $handler->step($row, $context);
        } catch (Throwable $error) {
            Watcher::instance()->report('operations:check', $error);

            return OperationStep::undecided('check_failed', Operations::errorText('operations:check', $error));
        }
    }

    /**
     * A claimed retry whose deadline is still ahead in its queue database's
     * clock: later, without a check.
     */
    private function beforeDeadline(stdClass $row, OperationContext $context): ?OperationStep
    {
        if ($row->action !== Operations::RETRY || $row->state !== Operations::CLAIMED) {
            return null;
        }

        $database = $this->sessions->databaseConnectionFor((string) $row->queue_connection);

        if ($this->files->isHost($database) && ! $this->files->allowsReads($database)) {
            return null;
        }

        try {
            $queueNow = $context->clock($database);
        } catch (Throwable $error) {
            $text = Operations::errorText('operations:queue_clock', $error);

            return self::isBlockingFailure($error)
                ? OperationStep::blocked($database, 'queue_unreachable', $text)
                : OperationStep::undecided('queue_unreachable', $text);
        }

        $deadline = (float) $row->deadline;

        return $deadline > $queueNow ? OperationStep::later($context->mirrorNow + ($deadline - $queueNow)) : null;
    }

    private function apply(Connection $mirror, stdClass $row, OperationStep $step, float $now): void
    {
        $id = (string) $row->id;
        $checks = (int) $row->checks;

        switch ($step->kind) {
            case OperationStep::DECIDED:
                $this->clearFailures($mirror, $row);

                return;
            case OperationStep::LATER:
                $this->operations->reschedule($mirror, $id, (float) $step->nextCheckAt, false);
                $this->clearReadsWait($mirror, $row);
                $this->clearFailures($mirror, $row);

                return;
            case OperationStep::AWAITING:
                $this->operations->await($mirror, $id, (string) $step->reason, $now);

                if ($step->reason === Operations::SQLITE_READS_NOT_ALLOWED) {
                    $this->operations->reschedule($mirror, $id, $now + self::backoff($checks + 1), true);
                }

                return;
            case OperationStep::BLOCKED:
                $this->defer($mirror, (string) $step->connection, $now);
                $this->operations->reschedule($mirror, $id, $now + self::backoff($checks + 1), true, $step->errorCode, $step->error);

                return;
            default:
                $this->operations->reschedule($mirror, $id, $now + self::backoff($checks + 1), true, $step->errorCode, $step->error);
                $this->clearReadsWait($mirror, $row);
                $this->clearFailures($mirror, $row);
        }
    }

    /**
     * A read-blocked operation whose step ran (later or undecided, not
     * awaiting again) waits no more for reads: its awaiting fields go, and
     * its error_code stays.
     */
    private function clearReadsWait(Connection $mirror, stdClass $row): void
    {
        if ($row->awaiting_reason === Operations::SQLITE_READS_NOT_ALLOWED) {
            $this->operations->clearAwaiting($mirror, (string) $row->id);
        }
    }

    /**
     * The due rows of one page, after the cursor and up to the boundary.
     *
     * @param  array{at: string, id: string}|null  $cursor
     * @param  array{at: string, id: string}  $passEnd
     * @param  list<string>  $deferred
     * @return list<stdClass>
     */
    private function page(Connection $mirror, float $now, ?array $cursor, array $passEnd, array $deferred, int $limit): array
    {
        $query = $this->due($mirror, $now)
            ->where(static function (Builder $query) use ($passEnd): void {
                $query->where('next_check_at', '<', $passEnd['at'])
                    ->orWhere(static function (Builder $query) use ($passEnd): void {
                        $query->where('next_check_at', '=', $passEnd['at'])->where('id', '<=', $passEnd['id']);
                    });
            });

        if ($cursor !== null) {
            $query->where(static function (Builder $query) use ($cursor): void {
                $query->where('next_check_at', '>', $cursor['at'])
                    ->orWhere(static function (Builder $query) use ($cursor): void {
                        $query->where('next_check_at', '=', $cursor['at'])->where('id', '>', $cursor['id']);
                    });
            });
        }

        if ($deferred !== []) {
            $queues = $this->queueConnectionsOf($deferred);

            if ($queues !== []) {
                // A forget acts only on its failed-record connection, so a queue deferral never holds it.
                $query->where(static function (Builder $query) use ($queues): void {
                    $query->where('action', Operations::FORGET)->orWhereNotIn('queue_connection', $queues);
                });
            }

            $query->where(static function (Builder $query) use ($deferred): void {
                $query->whereNull('record_connection')->orWhereNotIn('record_connection', $deferred);
            });
        }

        return array_values($query->orderBy('next_check_at')->orderBy('id')->limit($limit)->get(self::COLUMNS)->all());
    }

    private function due(Connection $mirror, float $now): Builder
    {
        return $mirror->table(Operations::TABLE)
            ->whereIn('state', Operations::ACTIVE)
            ->where(static function (Builder $query): void {
                $query->whereNull('awaiting_since')->orWhere('awaiting_reason', Operations::SQLITE_READS_NOT_ALLOWED);
            })
            ->where('next_check_at', '<=', Clock::toDecimal($now));
    }

    /**
     * The deferred database connections at $now.
     *
     * @return list<string>
     */
    private function deferred(Connection $mirror, float $now): array
    {
        $deferred = [];

        foreach ($mirror->table(self::SETTINGS)->where('key', 'like', self::DEFER_PREFIX.'%')->get(['key', 'value']) as $row) {
            $key = (string) $row->key;
            $value = is_string($row->value) ? json_decode($row->value, true) : null;

            if (str_starts_with($key, self::DEFER_PREFIX) && is_array($value) && is_numeric($value['until'] ?? null) && (float) $value['until'] > $now) {
                $deferred[] = substr($key, strlen(self::DEFER_PREFIX));
            }
        }

        return $deferred;
    }

    /**
     * The queue connections whose database connection is deferred.
     *
     * @param  list<string>  $deferred
     * @return list<string>
     */
    private function queueConnectionsOf(array $deferred): array
    {
        $queues = [];

        foreach (array_keys((array) $this->app->make('config')->get('queue.connections', [])) as $queue) {
            if (in_array($this->sessions->databaseConnectionFor((string) $queue), $deferred, true)) {
                $queues[] = (string) $queue;
            }
        }

        return $queues;
    }

    private function defer(Connection $mirror, string $connection, float $now): void
    {
        $key = self::DEFER_PREFIX.$connection;
        $stored = $mirror->table(self::SETTINGS)->where('key', $key)->value('value');
        $value = is_string($stored) ? json_decode($stored, true) : null;
        $failures = (is_array($value) && is_int($value['failures'] ?? null) ? $value['failures'] : 0) + 1;

        $mirror->table(self::SETTINGS)->upsert(
            [['key' => $key, 'value' => json_encode(['until' => $now + self::backoff($failures), 'failures' => $failures], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]],
            ['key'],
            ['value'],
        );
    }

    /**
     * A step that did not block ends the consecutive failures of the
     * operation's connections.
     */
    private function clearFailures(Connection $mirror, stdClass $row): void
    {
        // A forget's step touches no queue connection, so it ends no queue deferral.
        $keys = $row->action === Operations::FORGET ? [] : [self::DEFER_PREFIX.$this->sessions->databaseConnectionFor((string) $row->queue_connection)];

        if (is_string($row->record_connection) && $row->record_connection !== '') {
            $keys[] = self::DEFER_PREFIX.$row->record_connection;
        }

        if ($keys === []) {
            return;
        }

        $mirror->table(self::SETTINGS)->whereIn('key', $keys)->delete();
    }

    private function endPass(Connection $mirror): void
    {
        $mirror->table(self::SETTINGS)->whereIn('key', [self::PASS_END_KEY, self::CURSOR_KEY])->delete();
    }

    /**
     * @return array{at: string, id: string}|null
     */
    private function key(Connection $mirror, string $setting): ?array
    {
        $stored = $mirror->table(self::SETTINGS)->where('key', $setting)->value('value');
        $value = is_string($stored) ? json_decode($stored, true) : null;

        if (! is_array($value) || ! is_string($value['at'] ?? null) || ! is_string($value['id'] ?? null)) {
            return null;
        }

        return ['at' => $value['at'], 'id' => $value['id']];
    }

    /**
     * @param  array{at: string, id: string}  $key
     */
    private function putKey(Connection $mirror, string $setting, array $key): void
    {
        $mirror->table(self::SETTINGS)->upsert([['key' => $setting, 'value' => json_encode($key, JSON_THROW_ON_ERROR)]], ['key'], ['value']);
    }

    private function forgetKey(Connection $mirror, string $setting): void
    {
        $mirror->table(self::SETTINGS)->where('key', $setting)->delete();
    }

    /**
     * A stored decimal as the exact text of the ledger (a server engine
     * gives a string; SQLite a float).
     */
    private static function decimal(mixed $value): string
    {
        return is_string($value) ? $value : Clock::toDecimal((float) $value);
    }

    /**
     * @return array<string, OperationHandler>
     */
    private function handlers(): array
    {
        return $this->handlers ??= $this->taggedHandlers();
    }

    /**
     * @return array<string, OperationHandler>
     */
    private function taggedHandlers(): array
    {
        $handlers = [];

        foreach ($this->app->tagged(self::HANDLERS) as $handler) {
            if ($handler instanceof OperationHandler) {
                $handlers[$handler->action()] = $handler;
            }
        }

        return $handlers;
    }
}
