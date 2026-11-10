<?php

namespace Justrau\Orrery\Observation;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Queue\TimeoutExceededException;
use Justrau\Orrery\Runtime\Watcher;
use PDO;
use Throwable;
use WeakReference;

/**
 * Defers a queue observation to the commit of the transaction that holds the
 * hook's statement. It uses only
 * Laravel's public transaction API: the transaction level of the connection
 * instance, its beforeStartingTransaction() and beforeExecuting() callbacks,
 * the pending and committed lists of the transactions manager, and the
 * commit and rollback callbacks of each DatabaseTransactionRecord.
 *
 * A hook calls it so (around() does all of it):
 *
 *     $capture = $deferral->capture($q, $ownBegins); // never throws
 *     $deferral->enter($q, $capture);
 *     try { $result = parent::…(…); } finally { $deferral->leave($q, $capture); }
 *     $deferral->after($q, $capture, $run);          // only after a normal return
 *
 * The rules:
 * - At level 0 after the call, the item runs at once, unless a tracked root
 *   of the same instance is in its commit walk: then it joins the tail of the
 *   outermost walking root (raise order).
 * - Else the item joins the list of the LAST pending level-1 record of the
 *   instance's name (the root), with its chain: the savepoint records after
 *   the root. The root's commit callback runs the items whose chain records
 *   all have a commit mark. A savepoint is decided by its commit mark, never
 *   by its rollback callback: a rolled-back savepoint and the committed
 *   descendants of one never reach a commit callback.
 * - The root commit callback proves the commit only when the instance is at
 *   level 0, still has the PDO that it had when the root was tracked, and
 *   that PDO is not in a transaction. Else every undecided item is counted.
 * - The stale rule: when a watched instance begins at level 0, every
 *   tracked root of the name that is still pending and whose instance is at
 *   level 0 cannot commit any more: its items are counted and dropped.
 * - A root that rolls back counts its items: `unverified` for the
 *   ambiguous ones (the level changed during the call), `rollback_unverified`
 *   for the others.
 * - endUnit() counts what its unit left behind: a root that is still
 *   pending, and the undecided items of a root whose walk stopped.
 *
 * Isolation: capture(), the connection callbacks, the record callbacks and
 * after() never throw into the host. The parent call is never wrapped.
 *
 * Under Laravel's testing transactions manager (RefreshDatabase and
 * DatabaseTransactions), a pop or a release inside the test's wrapper
 * transaction is counted `unverified` at once (the testing manager clears
 * its own savepoint's committed record), and a push waits for the wrapper's
 * root, which never commits. Neither runs.
 *
 * Lifetimes: the watch registry (WatchRegistry: one entry per connection
 * instance, with its callbacks) is process state, so each instance gets its
 * connection callbacks once. This class is the item store, and its held
 * items are process state too: a container singleton (by design;
 * a scoped state would lose counts). The units of work
 * end them: each unit (a job, a command, a request) ends the roots that it
 * began, so a leaked root is counted at the end of its unit, and the
 * MAX_HELD cap is one limit for the process.
 *
 * Two budgets bound the held items, both checked before a root is tracked:
 * the count (MAX_HELD) and the bytes (byteBudget()). Each item carries the
 * byte estimate of its hook (estimate(): the payload bytes times a fixed
 * factor for the decoded copy; a bulk insert the sum over its payloads), and
 * the held bytes follow every move of an item: hold(), untrack() and the
 * walk transfer of rootCommitted(). An item over a budget is dropped and
 * counted `unverified` with its reason, `count_cap` or `byte_budget`
 * (DropCounter::overBudget()).
 */
final class CommitDeferral
{
    /** The most items that the deferral holds in this process. */
    public const MAX_HELD = 10000;

    /** The ceiling of the byte budget: min(MAX_HELD_BYTES, memory_limit / MEMORY_SHARE). */
    public const MAX_HELD_BYTES = 32 * 1024 * 1024;

    /** The byte budget's share of memory_limit. */
    public const MEMORY_SHARE = 8;

    /** The estimate of the bytes that a held item keeps per payload byte: the payload and its decoded copy. */
    public const BYTES_PER_PAYLOAD_BYTE = 4;

    /** The drop reason of an item over MAX_HELD. */
    public const COUNT_CAP = 'count_cap';

    /** The drop reason of an item over the byte budget. */
    public const BYTE_BUDGET = 'byte_budget';

    public const OPERATION = 'deferral.observe';

    /** @var array<int, TrackedRoot> keyed by spl_object_id() of the record, in tracking order */
    private array $roots = [];

    /** @var array<int, RecordMark> the marks of the savepoint records that items name */
    private array $marks = [];

    private int $held = 0;

    /** The byte estimates of the held items. */
    private int $heldBytes = 0;

    /** @var array<int, array<int, DatabaseTransactionRecord>> the records that each open unit found at its start, keyed by its token, in begin order */
    private array $units = [];

    /** The token of the next unit: tokens are never reused, so an ended token stays ended. */
    private int $nextToken = 0;

    /** @var array<string, true> */
    private array $reported = [];

    private readonly WatchRegistry $registry;

    /**
     * @param  WatchRegistry|null  $registry  the process registry (default: a registry of this store only)
     * @param  (Closure(): DatabaseTransactionsManager)|null  $manager  the manager whose lists the deferral reads (default: `db.transactions`)
     */
    public function __construct(
        private readonly DropCounter $drops,
        private readonly Watcher $watcher,
        ?WatchRegistry $registry = null,
        private readonly ?Closure $manager = null,
    ) {
        $this->registry = $registry ?? new WatchRegistry(fn (): self => $this);
    }

    /**
     * Runs the parent call of a hook with its capture, and defers the
     * observation that $observe builds from the parent's result. The result
     * and the exception of $parent reach the caller unchanged; $parent runs
     * exactly once, outside the watcher. $observe runs at once (eagerly),
     * inside the watcher: it returns the work to defer, or null when there
     * is nothing to observe. A build error records nothing. $bytes is the
     * byte estimate of the item (estimate()), or a closure that gives it
     * from the parent's result; it runs only when there is work to defer.
     *
     * @template T
     *
     * @param  Closure(): T  $parent
     * @param  Closure(T): ((Closure(): mixed)|null)  $observe
     * @param  int|(Closure(T): int)  $bytes
     * @return T
     */
    public function around(Connection $q, int $ownBegins, Closure $parent, Closure $observe, string $operation = self::OPERATION, int|Closure $bytes = 0): mixed
    {
        $capture = $this->capture($q, $ownBegins);
        $this->enter($q, $capture);

        try {
            $result = $parent();
        } finally {
            $this->leave($q, $capture);
        }

        $run = null;
        $estimate = 0;

        try {
            $built = $this->watcher->attempt($operation.'.build', static function () use ($observe, $result, $bytes, &$estimate): mixed {
                $run = $observe($result);

                if ($run instanceof Closure) {
                    $estimate = is_int($bytes) ? $bytes : $bytes($result);
                }

                return $run;
            });
            $run = $built->succeeded() ? $built->value() : null;
        } catch (TimeoutExceededException $timeout) {
            // The worker's own timeout (Worker::$killOnTimeout false) belongs to the job (Watcher).
            throw $timeout;
        } catch (Throwable $error) {
            // The watcher rethrows only its test switch's errors and the worker's timeout.
            $this->reportOnce($operation.'.build', $error);
        }

        if ($run instanceof Closure) {
            $this->after($q, $capture, $run, $operation, $estimate);
        }

        return $result;
    }

    /**
     * The state of $q before a parent call. It never throws: a failure gives
     * the unverified sentinel.
     */
    public function capture(Connection $q, int $ownBegins = 0): Capture
    {
        try {
            $state = $this->registry->watch($q, $ownBegins);
            $level = $q->transactionLevel();

            return Capture::of($level, $state->begins, $ownBegins, $level === 0 ? [] : $this->pendingOf(self::nameOf($q)));
        } catch (Throwable $error) {
            $this->reportOnce('deferral.capture', $error);

            return Capture::unverified();
        }
    }

    /**
     * Puts $capture on top of $q's stack of running parent calls. It never
     * throws.
     */
    public function enter(Connection $q, Capture $capture): void
    {
        try {
            $state = $this->registry->state($q);

            if ($state !== null) {
                $state->active[] = $capture;
            }
        } catch (Throwable $error) {
            $this->reportOnce('deferral.enter', $error);
        }
    }

    /**
     * Removes $capture from $q's stack. Call it in finally. It never throws.
     */
    public function leave(Connection $q, Capture $capture): void
    {
        try {
            $state = $this->registry->state($q);

            if ($state === null) {
                return;
            }

            for ($index = count($state->active) - 1; $index >= 0; $index--) {
                if ($state->active[$index] === $capture) {
                    array_splice($state->active, $index, 1);

                    return;
                }
            }
        } catch (Throwable $error) {
            $this->reportOnce('deferral.leave', $error);
        }
    }

    /**
     * Decides where $run goes, after the parent call of $capture returned
     * normally. It runs in the watcher and never throws. $bytes is the byte
     * estimate of the item (estimate()).
     *
     * @param  Closure(): mixed  $run
     */
    public function after(Connection $q, Capture $capture, Closure $run, string $operation = self::OPERATION, int $bytes = 0): void
    {
        try {
            $this->watcher->attempt('deferral.after', function () use ($q, $capture, $run, $operation, $bytes): void {
                $this->decide($q, $capture, $run, $operation, max(0, $bytes));
            });
        } catch (TimeoutExceededException $timeout) {
            // The worker's own timeout (Worker::$killOnTimeout false) belongs to the job (Watcher).
            throw $timeout;
        } catch (Throwable $error) {
            // The watcher rethrows only its test switch's errors and the worker's timeout.
            $this->reportOnce('deferral.after', $error);
        }
    }

    /**
     * Starts a unit of work (a job, a command, a request). The unit records
     * every pending record of the manager and every tracked root in its
     * walk: they belong to an outer unit, and its end leaves them alone.
     * The token names the unit for endUnit().
     */
    public function beginUnit(): int
    {
        try {
            $owned = [];

            foreach ($this->manager()->getPendingTransactions() as $record) {
                $owned[spl_object_id($record)] = $record;
            }

            foreach ($this->roots as $id => $root) {
                $owned[$id] = $root->record;
            }
        } catch (Throwable $error) {
            $this->reportOnce('deferral.unit', $error);
            $owned = [];
        }

        $token = $this->nextToken++;
        $this->units[$token] = $owned;

        return $token;
    }

    /**
     * Ends the innermost unit of work. A tracked root that the unit did not
     * find at its start is decided now: a root that is still pending (a
     * leaked transaction, a manual commit() whose COMMIT threw) counts its
     * items; a root that left the pending list and never ran (a walk that a
     * throwing callback stopped) runs its decided items and counts the
     * others. With a token, it ends that unit and every unit begun inside
     * it; without one, the innermost unit (or, with no open unit, a unit
     * that owns nothing). A token of a unit that ended already does
     * nothing. It never throws.
     */
    public function endUnit(?int $token = null): void
    {
        if ($token === null) {
            $owned = array_pop($this->units) ?? [];
        } elseif (array_key_exists($token, $this->units)) {
            $owned = $this->units[$token];

            foreach (array_keys($this->units) as $open) {
                if ($open >= $token) {
                    unset($this->units[$open]);
                }
            }
        } else {
            return;
        }

        try {
            $pending = $this->pendingSet();
        } catch (Throwable $error) {
            $this->reportOnce('deferral.unit', $error);

            return;
        }

        foreach ($this->roots as $id => $root) {
            if (($owned[$id] ?? null) === $root->record) {
                continue;
            }

            $this->settle($root, 'deferral.unit', function () use ($root, $pending, $id): void {
                $this->untrack($root);

                if (isset($pending[$id])) {
                    $this->count($root->items, static fn (): bool => true);

                    return;
                }

                $this->runAll(array_values(array_filter($root->items, static fn (array $item): bool => $item['decided'])));
                $this->count($root->items, static fn (array $item): bool => ! $item['decided']);
            });
        }
    }

    /**
     * The registry's beforeStartingTransaction callback, after its cheap
     * checks: $q begins at level 0 while a store holds tracked roots. It
     * never throws.
     *
     * @internal
     */
    public function levelZeroBegin(Connection $q): void
    {
        if ($this->roots === []) {
            return;
        }

        try {
            $this->noteWalkBegin($q);
            $this->staleRule(self::nameOf($q));
        } catch (Throwable $error) {
            $this->reportOnce('deferral.stale', $error);
        }
    }

    /**
     * The registry's beforeExecuting callback, after its cheap check: a
     * statement while $capture still looks for the record of the parent's
     * own transaction. It never throws.
     *
     * @internal
     */
    public function statementOfOwnTransaction(Connection $q, Capture $capture): void
    {
        try {
            $this->witnessOwnRecord($q, $capture);
        } catch (Throwable $error) {
            $this->reportOnce('deferral.witness', $error);
        }
    }

    /**
     * The tracked roots (tests and the end-of-test assertion).
     */
    public function open(): int
    {
        return count($this->roots);
    }

    /**
     * The items that wait for a commit.
     */
    public function held(): int
    {
        return $this->held;
    }

    /**
     * The byte estimates of the items that wait for a commit.
     */
    public function heldBytes(): int
    {
        return $this->heldBytes;
    }

    /**
     * The byte budget of the held items: min(MAX_HELD_BYTES, memory_limit /
     * MEMORY_SHARE), read at each check, so a later ini_set() counts.
     */
    public function byteBudget(): int
    {
        return self::byteBudgetFor((string) ini_get('memory_limit'));
    }

    /**
     * The byte budget for a memory_limit value. No limit (-1), or a value
     * that does not parse to a positive size, gives MAX_HELD_BYTES.
     */
    public static function byteBudgetFor(string $memoryLimit): int
    {
        try {
            $limit = @ini_parse_quantity($memoryLimit);
        } catch (Throwable) {
            $limit = 0;
        }

        return $limit > 0 ? min(self::MAX_HELD_BYTES, intdiv($limit, self::MEMORY_SHARE)) : self::MAX_HELD_BYTES;
    }

    /**
     * The byte estimate of an item whose payloads have $payloadBytes bytes:
     * the payloads and their decoded copies.
     */
    public static function estimate(int $payloadBytes): int
    {
        return max(0, $payloadBytes) * self::BYTES_PER_PAYLOAD_BYTE;
    }

    /**
     * The watched connection instances (tests).
     */
    public function watchedInstances(): int
    {
        return $this->registry->instances();
    }

    /**
     * At the first statement of the parent's own transaction: its record is
     * the pending record of the name at levelBefore + 1 that was not pending
     * before the call. A commit callback marks it committed.
     */
    private function witnessOwnRecord(Connection $q, Capture $capture): void
    {
        $level = $capture->levelBefore + 1;

        if ($q->transactionLevel() !== $level) {
            return;
        }

        $own = null;

        foreach ($this->pendingOf(self::nameOf($q)) as $record) {
            if ((int) $record->level === $level && ! in_array($record, $capture->chainBefore, true)) {
                $own = $record;
            }
        }

        if ($own === null) {
            return;
        }

        $mark = $this->marks[spl_object_id($own)] ?? null;

        if ($mark === null || $mark->record !== $own) {
            $mark = new RecordMark($own);
            $own->addCallback(static function () use ($mark): void {
                $mark->committed = true;
            });
        }

        $capture->ownMark = $mark;
    }

    /**
     * @param  Closure(): mixed  $run
     */
    private function decide(Connection $q, Capture $capture, Closure $run, string $operation, int $bytes): void
    {
        if (! $capture->verified) {
            $this->drops->unverified();

            return;
        }

        $state = $this->registry->state($q);

        if ($state === null) {
            $this->drops->unverified();

            return;
        }

        $level = $q->transactionLevel();
        $begun = $state->begins - $capture->beginsBefore;
        $pending = $this->pendingOf(self::nameOf($q));

        // A record that was open before the call and ended during it.
        foreach ($capture->chainBefore as $index => $record) {
            if (($pending[$index] ?? null) !== $record) {
                $this->drops->unverified();

                return;
            }
        }

        $born = count($pending) - count($capture->chainBefore);

        // A transaction that was born and ended inside the call.
        if ($begun > $capture->ownBegins + $born) {
            $this->drops->unverified();

            return;
        }

        if ($capture->ownBegins > 0 && ! $this->ownTransactionHolds($capture, $pending)) {
            $this->drops->unverified();

            return;
        }

        $item = ['run' => $run, 'operation' => $operation, 'chain' => [], 'ambiguous' => $capture->levelBefore !== $level, 'decided' => false, 'bytes' => $bytes];

        if ($level === 0) {
            $walking = $this->outermostWalkingRoot($q, null);

            if ($walking === null) {
                $this->runAll([$item]);

                return;
            }

            $item['decided'] = true;
            $this->hold($walking, $item);

            return;
        }

        $rootIndex = null;

        foreach ($pending as $index => $record) {
            if ((int) $record->level === 1) {
                $rootIndex = $index;
            }
        }

        if ($rootIndex === null) {
            $this->reportOnce('deferral.no_root', 'The queue connection is inside a transaction, but the transactions manager has no root record of its name.');
            $this->drops->unverified();

            return;
        }

        // The budgets first, so a dropped item leaves no callback on any record.
        if (! $this->admits($bytes)) {
            return;
        }

        $root = $this->track($q, $pending[$rootIndex]);

        if ($root === null) {
            $this->drops->unverified();

            return;
        }

        $chain = [];

        foreach (array_slice($pending, $rootIndex + 1) as $savepoint) {
            $chain[] = $this->markOf($savepoint);
        }

        if ($capture->ownMark !== null && $capture->levelBefore > 0 && $this->isCommitted($capture->ownMark->record)) {
            $chain[] = $this->markOf($capture->ownMark->record, $capture->ownMark);
        }

        $item['chain'] = $chain;
        $this->hold($root, $item);
    }

    /**
     * The parent's own transaction is a witness, not a count: its record was
     * seen at its first statement, and it committed (a root: its commit mark
     * ran before the call returned; a savepoint: it is staged in the
     * committed list, or still pending).
     *
     * @param  list<DatabaseTransactionRecord>  $pending
     */
    private function ownTransactionHolds(Capture $capture, array $pending): bool
    {
        $mark = $capture->ownMark;

        if ($mark === null) {
            return false;
        }

        if ($capture->levelBefore === 0) {
            return $mark->committed;
        }

        return in_array($mark->record, $pending, true) || $this->isCommitted($mark->record);
    }

    /**
     * @param  array{run: Closure(): mixed, operation: string, chain: list<RecordMark>, ambiguous: bool, decided: bool, bytes: int}  $item
     */
    private function hold(TrackedRoot $root, array $item): void
    {
        if (! $this->admits($item['bytes'])) {
            return;
        }

        $root->items[] = $item;
        $this->held++;
        $this->heldBytes += $item['bytes'];
    }

    /**
     * Whether one more item of $bytes fits both budgets. A dropped item is
     * counted with its reason: the count cap first, then the bytes. An item
     * larger than the whole byte budget never fits.
     */
    private function admits(int $bytes): bool
    {
        if ($this->held >= self::MAX_HELD) {
            $this->drops->overBudget(self::COUNT_CAP);

            return false;
        }

        if ($bytes > 0 && $this->heldBytes + $bytes > $this->byteBudget()) {
            $this->drops->overBudget(self::BYTE_BUDGET);

            return false;
        }

        return true;
    }

    private function track(Connection $q, DatabaseTransactionRecord $record): ?TrackedRoot
    {
        $id = spl_object_id($record);
        $root = $this->roots[$id] ?? null;

        if ($root !== null && $root->record === $record) {
            return $root->belongsTo($q) ? $root : null;
        }

        $pdo = $q->getRawPdo();
        $root = new TrackedRoot($record, WeakReference::create($q), $pdo instanceof PDO ? WeakReference::create($pdo) : null);
        $this->roots[$id] = $root;
        $this->registry->holds($this, true);

        $record->addCallback(function () use ($root): void {
            $this->settle($root, 'deferral.commit', fn () => $this->rootCommitted($root));
        });

        $record->addCallbackForRollback(function () use ($root): void {
            $this->settle($root, 'deferral.rollback', fn () => $this->rootRolledBack($root));
        });

        return $root;
    }

    private function markOf(DatabaseTransactionRecord $record, ?RecordMark $known = null): RecordMark
    {
        $id = spl_object_id($record);
        $mark = $this->marks[$id] ?? null;

        if ($mark === null || $mark->record !== $record) {
            $mark = $known ?? new RecordMark($record);
            $this->marks[$id] = $mark;

            if ($known === null) {
                $record->addCallback(static function () use ($mark): void {
                    $mark->committed = true;
                });
            }
        }

        if (! $mark->watchesRollback) {
            $mark->watchesRollback = true;
            $record->addCallbackForRollback(static function () use ($mark): void {
                $mark->rolledBack = true;
            });
        }

        return $mark;
    }

    /**
     * Laravel runs it after the callbacks of the root's committed
     * savepoints.
     */
    private function rootCommitted(TrackedRoot $root): void
    {
        if (! $this->isTracked($root)) {
            return;
        }

        $q = $root->connection->get();
        $pdo = $root->pdo?->get();
        $proved = $q !== null
            && $pdo !== null
            && $q->getRawPdo() === $pdo
            && (($q->transactionLevel() === 0 && ! $pdo->inTransaction()) || $root->cleanAtWalkBegin);

        // Before untrack(): the search stops at this root, so a later root
        // (a walk that stopped inside this walk) never takes these items.
        $walking = $q === null ? null : $this->outermostWalkingRoot($q, $root);
        $this->untrack($root);
        $runnable = [];

        foreach ($root->items as $item) {
            if ($item['decided']) {
                $runnable[] = $item;

                continue;
            }

            if (! $proved) {
                $this->drops->unverified();

                continue;
            }

            if (self::chainCommitted($item['chain'])) {
                $runnable[] = $item;
            } elseif ($item['ambiguous'] || ! self::chainRolledBack($item['chain'], $root->record)) {
                // An ambiguous item may have run outside the chain that rolled back.
                $this->drops->unverified();
            }
        }

        if ($walking !== null) {
            // A move, not a new item: no budget check.
            foreach ($runnable as $item) {
                $item['decided'] = true;
                $walking->items[] = $item;
                $this->held++;
                $this->heldBytes += $item['bytes'];
            }

            return;
        }

        $this->runAll($runnable);
    }

    private function rootRolledBack(TrackedRoot $root): void
    {
        if (! $this->isTracked($root)) {
            return;
        }

        $this->untrack($root);
        $this->runAll(array_values(array_filter($root->items, static fn (array $item): bool => $item['decided'])));

        foreach ($root->items as $item) {
            if ($item['decided']) {
                continue;
            }

            // No stack, PDO state or event proves that no COMMIT was attempted.
            if ($item['ambiguous']) {
                $this->drops->unverified();
            } else {
                $this->drops->rollbackUnverified();
            }
        }
    }

    /**
     * $q begins a new root at level 0: for each of its roots in their walk,
     * remember whether $q still had the root's PDO and no open transaction.
     * A root that a callback of the walk begins on $q (and keeps open while
     * the walking root's commit callback runs) then does not hide the
     * commit of the walking root.
     */
    private function noteWalkBegin(Connection $q): void
    {
        $pending = null;

        foreach ($this->roots as $id => $root) {
            if ($root->cleanAtWalkBegin || ! $root->belongsTo($q)) {
                continue;
            }

            $pending ??= $this->pendingSet();
            $pdo = $root->pdo?->get();

            if (! isset($pending[$id]) && $pdo !== null && $q->getRawPdo() === $pdo && ! $pdo->inTransaction()) {
                $root->cleanAtWalkBegin = true;
            }
        }
    }

    /**
     * A watched instance of $name begins at level 0: every root of the name
     * that is still pending, and whose instance is at level 0 (or gone),
     * cannot commit any more. A root in its walk is not pending, so it is
     * never touched here.
     */
    private function staleRule(string $name): void
    {
        $pending = $this->pendingSet();

        foreach ($this->roots as $id => $root) {
            if ($root->record->connection !== $name || ! isset($pending[$id])) {
                continue;
            }

            $instance = $root->connection->get();

            if ($instance !== null && $instance->transactionLevel() !== 0) {
                continue;
            }

            $this->settle($root, 'deferral.stale', function () use ($root): void {
                $this->untrack($root);
                $this->count($root->items, static fn (): bool => true);
            });
        }
    }

    /**
     * The first tracked root of $q, in tracking order and before $before
     * (when given), that left the pending list and whose commit callback has
     * not run: the root whose walk runs (or stopped).
     */
    private function outermostWalkingRoot(Connection $q, ?TrackedRoot $before): ?TrackedRoot
    {
        if ($this->roots === []) {
            return null;
        }

        $pending = null;

        foreach ($this->roots as $id => $root) {
            if ($root === $before) {
                return null;
            }

            if (! $root->belongsTo($q)) {
                continue;
            }

            $pending ??= $this->pendingSet();

            if (! isset($pending[$id])) {
                return $root;
            }
        }

        return null;
    }

    private function isTracked(TrackedRoot $root): bool
    {
        return ($this->roots[spl_object_id($root->record)] ?? null) === $root;
    }

    /**
     * Runs the decision of one root. When it throws after the root left the
     * tracked roots, the root's undecided items are counted, so no item
     * disappears without a count.
     */
    private function settle(TrackedRoot $root, string $operation, Closure $decision): void
    {
        try {
            $decision();
            $root->settled = true;
        } catch (TimeoutExceededException $timeout) {
            // The worker's own timeout (Worker::$killOnTimeout false) belongs to
            // the job (Watcher). It leaves only runAll(), which counted the
            // items that did not run.
            $root->settled = true;

            throw $timeout;
        } catch (Throwable $error) {
            $this->reportOnce($operation, $error);

            if ($root->settled || $this->isTracked($root)) {
                return;
            }

            $root->settled = true;

            try {
                $this->count($root->items, static fn (array $item): bool => ! $item['decided']);
            } catch (Throwable) {
                // The counter failed too.
            }
        }
    }

    private function untrack(TrackedRoot $root): void
    {
        unset($this->roots[spl_object_id($root->record)]);

        if ($this->roots === []) {
            $this->registry->holds($this, false);
        }

        $this->held = max(0, $this->held - count($root->items));
        $this->heldBytes = max(0, $this->heldBytes - array_sum(array_column($root->items, 'bytes')));

        foreach ($root->items as $item) {
            foreach ($item['chain'] as $mark) {
                $id = spl_object_id($mark->record);

                if (($this->marks[$id] ?? null) === $mark) {
                    unset($this->marks[$id]);
                }
            }
        }
    }

    /**
     * @param  list<array{run: Closure(): mixed, operation: string, chain: list<RecordMark>, ambiguous: bool, decided: bool, bytes: int}>  $items
     */
    private function runAll(array $items): void
    {
        foreach ($items as $index => $item) {
            try {
                $this->watcher->attempt($item['operation'], $item['run']);
            } catch (TimeoutExceededException $timeout) {
                // The worker's timeout ends the walk: the items after it never
                // run, so they are counted before the timeout reaches the job.
                $left = count($items) - $index - 1;

                if ($left > 0) {
                    $this->drops->unverified($left);
                }

                throw $timeout;
            }
        }
    }

    /**
     * @param  list<array{run: Closure(): mixed, operation: string, chain: list<RecordMark>, ambiguous: bool, decided: bool, bytes: int}>  $items
     * @param  Closure(array{run: Closure(): mixed, operation: string, chain: list<RecordMark>, ambiguous: bool, decided: bool, bytes: int}): bool  $filter
     */
    private function count(array $items, Closure $filter): void
    {
        $count = count(array_filter($items, $filter));

        if ($count > 0) {
            $this->drops->unverified($count);
        }
    }

    /**
     * @param  list<RecordMark>  $chain
     */
    private static function chainCommitted(array $chain): bool
    {
        foreach ($chain as $mark) {
            if (! $mark->committed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a rollback explains why the chain did not commit: a record of
     * the chain got its rollback mark, and every link from the root down to
     * it is a true link (Laravel's parent of each chain record is the root
     * or the chain record before it). A mark that comes through a stale
     * parent (Laravel removes a committed record because a record that
     * rolled back earlier is still its parent, or its parent's parent)
     * explains nothing: that record committed into the root.
     *
     * @param  list<RecordMark>  $chain
     */
    private static function chainRolledBack(array $chain, DatabaseTransactionRecord $root): bool
    {
        $expected = $root;

        foreach ($chain as $mark) {
            if ($mark->record->parent !== $expected) {
                return false;
            }

            if ($mark->rolledBack) {
                return true;
            }

            $expected = $mark->record;
        }

        return false;
    }

    private function isCommitted(DatabaseTransactionRecord $record): bool
    {
        foreach ($this->manager()->getCommittedTransactions() as $committed) {
            if ($committed === $record) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<DatabaseTransactionRecord>
     */
    private function pendingOf(string $name): array
    {
        $records = [];

        foreach ($this->manager()->getPendingTransactions() as $record) {
            if ($record->connection === $name) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @return array<int, true> the ids of every pending record of the manager
     */
    private function pendingSet(): array
    {
        $ids = [];

        foreach ($this->manager()->getPendingTransactions() as $record) {
            $ids[spl_object_id($record)] = true;
        }

        return $ids;
    }

    private function manager(): DatabaseTransactionsManager
    {
        if ($this->manager !== null) {
            return ($this->manager)();
        }

        return Container::getInstance()->make('db.transactions');
    }

    /**
     * The name that the manager's records of $q carry.
     */
    private static function nameOf(Connection $q): string
    {
        return $q->getName() ?? '';
    }

    private function reportOnce(string $operation, Throwable|string $problem): void
    {
        if (isset($this->reported[$operation])) {
            return;
        }

        $this->reported[$operation] = true;

        try {
            $this->watcher->report($operation, $problem);
        } catch (Throwable) {
            // Nothing is left to report to.
        }
    }
}
