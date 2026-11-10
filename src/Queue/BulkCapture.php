<?php

namespace Justrau\Orrery\Queue;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Justrau\Orrery\Observation\Capture;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

/**
 * The capture of one bulk() call. One capture path
 * serves every Laravel release: parent::bulk() runs unchanged.
 *
 * - bulk() opens its capture (open()) and closes it (close()). Every call
 *   owns its capture object; nothing is a queue-wide buffer.
 * - While a capture is open, the payload callback of the package records
 *   the identity (canonical uuid and generation) of every payload that it
 *   classifies (classified()). Laravel 13 creates the payloads of its
 *   after-commit jobs during the call, but builds and inserts their
 *   records later, in a callback that it registers with
 *   db.transactions->addCallback().
 * - buildDatabaseRecord() routes each record by its payload identity
 *   (route()): inside pushToDatabase() (a suspension) nowhere; else to a
 *   pending capture that expects it; else to the open capture of the
 *   innermost bulk() call; else nowhere.
 * - After the call, the records built during it are observed at once
 *   (records()). The identities that the call classified but did not build
 *   are the capture's expected deferred identities: pend() registers the
 *   capture as pending and adds its flush to the same transaction record
 *   as Laravel's own callback, right after it.
 * - The first deferred record that Laravel's callback builds is the moment
 *   just before that insert's SQL: there the capture takes the C3 capture
 *   of the queue connection, and the flush passes it to the deferral.
 * - A rollback of that record discards the pending capture: Laravel never
 *   inserts the after-commit jobs then. A pending capture whose flush never
 *   ran (Laravel's callback threw, so the walk stopped) is dropped and
 *   counted `unverified` at the end of its unit of work (endUnit()), and
 *   when more than MAX_PENDING captures are pending.
 *
 * The counters count deferred items, not jobs: a dropped pending capture
 * counts 1.
 *
 * Lifetimes: the open captures are unit-of-work state
 * (OpenBulkCaptures, scoped); the pending captures and the units are process
 * state (PendingBulkCaptures, a singleton).
 */
final class BulkCapture
{
    /** The most pending captures; a new one drops the oldest. */
    public const MAX_PENDING = 100;

    public const FLUSH = 'hook:bulk.flush';

    public readonly int $id;

    /** @var array<string, true> the identities classified during the call and not built yet */
    private array $classified = [];

    /** @var list<array<string, mixed>> the records built during the call */
    private array $records = [];

    /** @var array<string, true> the expected deferred identities that were not built yet */
    private array $expected = [];

    /** @var list<array<string, mixed>> the deferred records, in build order */
    private array $deferred = [];

    private ?Capture $insert = null;

    private ?Connection $connection = null;

    private ?CommitDeferral $deferral = null;

    private ?DatabaseTransactionRecord $target = null;

    /** @var (Closure(Capture, list<array<string, mixed>>): void)|null */
    private ?Closure $deliver = null;

    private bool $settled = false;

    private function __construct()
    {
        $pendings = self::pending();

        $this->id = ++$pendings->sequence;
    }

    /**
     * Opens the capture of one bulk() call.
     */
    public static function open(): self
    {
        $opened = self::opened();

        $capture = new self;
        $opened->entries[] = $capture;

        return $capture;
    }

    /**
     * Removes this capture, and every entry that a nested call left above
     * it, from the open captures. It keeps the capture's own state.
     */
    public function close(): void
    {
        $opened = self::opened();

        foreach ($opened->entries as $position => $entry) {
            if ($entry === $this) {
                $opened->entries = array_slice($opened->entries, 0, $position);

                return;
            }
        }
    }

    /**
     * The records built during the call, in build order.
     *
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * pushToDatabase() suspends every capture: its record belongs to its own
     * hook. Returns the handle for restore().
     */
    public static function suspend(): int
    {
        $opened = self::opened();

        $opened->entries[] = null;

        return (int) array_key_last($opened->entries);
    }

    public static function restore(int $handle): void
    {
        $opened = self::opened();

        $opened->entries = array_slice($opened->entries, 0, $handle);
    }

    /**
     * The payload callback: records the identity of a payload that a bulk()
     * call classifies. Nothing while no capture is open, or inside a
     * suspension.
     *
     * @param  array<mixed>  $payload
     */
    public static function classified(array $payload): void
    {
        $opened = self::opened();

        $top = self::top($opened);

        if (! $top instanceof self) {
            return;
        }

        $key = self::keyOf($payload);

        if ($key !== null) {
            $top->classified[$key] = true;
        }
    }

    /**
     * Routes a record that buildDatabaseRecord() built (the class doc).
     *
     * @param  array<string, mixed>  $record
     */
    public static function route(array $record): void
    {
        $opened = self::opened();
        $pendings = self::pending();

        if ($opened->entries === [] && $pendings->captures === []) {
            return;
        }

        $top = self::top($opened);

        if ($top === null && $opened->entries !== []) {
            // Inside pushToDatabase(): its own hook observes it. Its identity
            // is no deferred identity of any capture (a push that a bulk's
            // classification dispatched was classified into that bulk).
            if (self::expectsAny($opened, $pendings)) {
                self::forget(self::keyOfRecord($record), $opened, $pendings);
            }

            return;
        }

        $key = self::keyOfRecord($record);

        if ($key !== null) {
            foreach ($pendings->captures as $pending) {
                if (isset($pending->expected[$key])) {
                    $pending->take($key, $record);

                    return;
                }
            }
        }

        if ($top instanceof self) {
            $top->records[] = $record;

            if ($key !== null) {
                unset($top->classified[$key]);
            }
        }
    }

    /**
     * After the call: when the call classified identities that it did not
     * build, registers this capture as pending and adds its flush to the
     * newest pending transaction record (the record of Laravel's own
     * callback). Returns whether it is pending. It never throws.
     *
     * @param  Closure(Capture, list<array<string, mixed>>): void  $deliver  observes the deferred records with the deferred insert's capture
     */
    public function pend(Connection $connection, CommitDeferral $deferral, Closure $deliver): bool
    {
        if ($this->classified === []) {
            return false;
        }

        $pendings = self::pending();

        $this->expected = $this->classified;
        $this->classified = [];
        $this->connection = $connection;
        $this->deferral = $deferral;
        $this->deliver = $deliver;

        try {
            $manager = Container::getInstance()->make('db.transactions');
            $target = $manager->callbackApplicableTransactions()->last();
            $this->target = $target instanceof DatabaseTransactionRecord ? $target : null;

            $pendings->captures[$this->id] = $this;

            while (count($pendings->captures) > self::MAX_PENDING) {
                $oldest = $pendings->captures[array_key_first($pendings->captures)];
                $oldest->drop();
            }

            if ($this->target !== null) {
                $this->target->addCallbackForRollback(function (): void {
                    $this->discard();
                });
            }

            // Without a pending transaction this runs the flush at once.
            $manager->addCallback(function (): void {
                $this->flush();
            });
        } catch (Throwable $error) {
            self::report('hook:bulk.pend', $error);
            $this->drop();
        }

        return true;
    }

    /**
     * Starts a unit of work (a job, a command, a request). The pending
     * captures of an outer unit, and every pending transaction record at
     * the start, belong to the outer unit: the end of this unit leaves a
     * capture on such a record alone.
     */
    public static function beginUnit(): int
    {
        $pendings = self::pending();

        $owned = [];

        try {
            foreach (Container::getInstance()->make('db.transactions')->getPendingTransactions() as $record) {
                $owned[spl_object_id($record)] = $record;
            }
        } catch (Throwable $error) {
            self::report('hook:bulk.unit', $error);
        }

        $token = $pendings->nextUnit++;
        $pendings->units[$token] = ['sequence' => $pendings->sequence, 'owned' => $owned];

        return $token;
    }

    /**
     * Ends the unit $token and every unit begun inside it: each capture
     * that became pending during the unit and whose transaction record does
     * not belong to an outer unit is dropped and counted. A token of a unit
     * that ended already does nothing. It never throws.
     */
    public static function endUnit(int $token): void
    {
        $pendings = self::pending();

        if (! array_key_exists($token, $pendings->units)) {
            return;
        }

        $unit = $pendings->units[$token];

        foreach (array_keys($pendings->units) as $open) {
            if ($open >= $token) {
                unset($pendings->units[$open]);
            }
        }

        foreach ($pendings->captures as $pending) {
            if ($pending->id <= $unit['sequence']) {
                continue;
            }

            if (self::ownedByOuterUnit($pending->target, $unit['owned'])) {
                continue;
            }

            $pending->drop();
        }
    }

    /**
     * Whether the record, or one of its ancestors, was pending when the unit
     * began: a savepoint that the unit created and committed still waits
     * for the caller's root, so its capture belongs to the outer unit.
     *
     * @param  array<int, DatabaseTransactionRecord>  $owned
     */
    private static function ownedByOuterUnit(?DatabaseTransactionRecord $record, array $owned): bool
    {
        while ($record !== null) {
            if (($owned[spl_object_id($record)] ?? null) === $record) {
                return true;
            }

            $record = $record->parent;
        }

        return false;
    }

    /**
     * The open captures and suspensions (tests).
     */
    public static function openCount(): int
    {
        $opened = self::opened();

        return count($opened->entries);
    }

    /**
     * The pending captures (tests).
     */
    public static function pendingCount(): int
    {
        $pendings = self::pending();

        return count($pendings->captures);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function take(string $key, array $record): void
    {
        if ($this->insert === null && $this->deferral !== null && $this->connection !== null) {
            // Just before the deferred insert's SQL: its own C3 capture.
            $this->insert = $this->deferral->capture($this->connection);
        }

        unset($this->expected[$key]);
        $this->deferred[] = $record;
    }

    /**
     * Runs right after Laravel's deferred insert. It never throws: it runs
     * in a commit walk.
     */
    private function flush(): void
    {
        $pendings = self::pending();

        if ($this->settled) {
            return;
        }

        $this->settled = true;
        unset($pendings->captures[$this->id]);

        try {
            if ($this->insert === null || $this->deferred === [] || $this->deliver === null) {
                // No record of the capture was built: nothing is observed.
                // When no expected identity is left (each one was built by
                // its own push, for example an after-commit push that the
                // bulk's classification dispatched), nothing was dropped.
                if ($this->expected !== []) {
                    Degradation::unverified();
                }

                return;
            }

            ($this->deliver)($this->insert, $this->deferred);
        } catch (Throwable $error) {
            self::report(self::FLUSH, $error);
        } finally {
            $this->deferred = [];
        }
    }

    private function discard(): void
    {
        $pendings = self::pending();

        $this->settled = true;
        $this->deferred = [];
        unset($pendings->captures[$this->id]);
    }

    private function drop(): void
    {
        $pendings = self::pending();

        if ($this->settled) {
            unset($pendings->captures[$this->id]);

            return;
        }

        // The rule of flush(): nothing was dropped when no expected identity
        // is left and no record was taken.
        $dropped = $this->expected !== [] || $this->deferred !== [] || $this->insert !== null;
        $this->discard();

        if (! $dropped) {
            return;
        }

        try {
            Degradation::unverified();
        } catch (Throwable) {
            // Nothing is left to count with.
        }
    }

    /**
     * The innermost open entry: a capture, null for a suspension, or false
     * when nothing is open.
     */
    private static function top(OpenBulkCaptures $opened): self|null|false
    {
        if ($opened->entries === []) {
            return false;
        }

        return $opened->entries[array_key_last($opened->entries)];
    }

    private static function expectsAny(OpenBulkCaptures $opened, PendingBulkCaptures $pendings): bool
    {
        if ($pendings->captures !== []) {
            return true;
        }

        foreach ($opened->entries as $entry) {
            if ($entry instanceof self && $entry->classified !== []) {
                return true;
            }
        }

        return false;
    }

    private static function forget(?string $key, OpenBulkCaptures $opened, PendingBulkCaptures $pendings): void
    {
        if ($key === null) {
            return;
        }

        foreach ($opened->entries as $entry) {
            if ($entry instanceof self) {
                unset($entry->classified[$key]);
            }
        }

        foreach ($pendings->captures as $pending) {
            unset($pending->expected[$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function keyOfRecord(array $record): ?string
    {
        $payload = $record['payload'] ?? null;

        if (! is_string($payload)) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? self::keyOf($decoded) : null;
    }

    /**
     * The identity of a payload: its canonical uuid (the rule of
     * PayloadIdentity) and its generation, or null when it has no
     * canonical uuid (it is never mirrored).
     *
     * @param  array<mixed>  $payload
     */
    private static function keyOf(array $payload): ?string
    {
        // Only the two identity keys, so the rule of PayloadIdentity decides.
        $encoded = json_encode(['uuid' => $payload['uuid'] ?? null, 'id' => $payload['id'] ?? null]);
        $uuid = is_string($encoded) ? PayloadIdentity::uuidOf($encoded) : null;

        if ($uuid === null) {
            return null;
        }

        $generation = $payload['orreryGen'] ?? null;

        return $uuid."\0".(is_string($generation) ? $generation : '');
    }

    private static function report(string $operation, Throwable $error): void
    {
        try {
            Watcher::instance()->report($operation, $error);
        } catch (Throwable) {
            // Nothing is left to report to.
        }
    }

    private static function opened(): OpenBulkCaptures
    {
        return Container::getInstance()->make(OpenBulkCaptures::class);
    }

    private static function pending(): PendingBulkCaptures
    {
        return Container::getInstance()->make(PendingBulkCaptures::class);
    }
}
