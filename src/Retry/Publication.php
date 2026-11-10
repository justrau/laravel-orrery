<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use PDO;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The publication of a package retry and its resolution, on the package
 * session of the queue database Q (retry steps 5 and 7).
 *
 * publish() runs one transaction, in this order:
 * 1. BEGIN (the session has its lock and idle timeouts);
 * 2. the conditional fence insert (publish, published) with Q's deadline: a
 *    duplicate key means that a resolver cancelled first, 0 rows that Q's
 *    clock passed the deadline; both roll back;
 * 3. mode in_tx only: the exact delete of the failed record, exactly 1 row,
 *    else roll back (record_gone);
 * 4. pushRaw() through Laravel's own DatabaseQueue on this session (never
 *    the swapped class, whose pushRaw() would stamp a new generation) → J;
 * 5. the fence row's job id J;
 * 6. COMMIT.
 * The caller builds the payload and checks the fingerprints before, so the
 * transaction does no PHP work beyond binding, no clock read and no wait.
 * After a failed attempt the session is rolled back. An error from COMMIT
 * always disconnects it; an error before COMMIT disconnects it only when the
 * rollback did not end at level 0 with no open transaction (the server then
 * discards what is left). A clean session stays: when the queue's
 * connection name is the mirror's, it is the mirror session that the
 * caller (a bulk run, the runner) still holds. An error before
 * COMMIT is FAILED (nothing was published); an error from COMMIT is
 * UNCERTAIN (the resolution decides).
 *
 * resolve() is one package transaction on Q's session: the cancelling
 * insertOrIgnore of the publish slot, then the read of that row. A
 * published row with a job id is PUBLISHED, a cancelled row CANCELLED; an
 * error (a connect error, a lock timeout) decides nothing (UNDECIDED).
 * read() reads the publish row without a write (host files with automatic
 * reads, D18).
 *
 * On in-memory SQLite (tests only) the session is the application
 * connection, and the transaction runs through PackageTransaction::run().
 */
final class Publication
{
    public const PUBLISHED = 'published';

    public const CANCELLED = 'cancelled';

    public const DEADLINE_PASSED = 'deadline_passed';

    public const RECORD_GONE = 'record_gone';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    public const UNDECIDED = 'undecided';

    /** Before BEGIN of the publication (no lock held). */
    public const BEFORE_FENCE_INSERT = 'retry.before_fence_insert';

    /** Inside the publication, before COMMIT. */
    public const BEFORE_COMMIT = 'retry.before_commit';

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
        private readonly Fence $fence,
    ) {}

    /**
     * The fingerprint of a queue connection's target: sha256 of the endpoint
     * identity of its database connection and its jobs table.
     */
    public function queueFingerprint(string $queueConnection): string
    {
        return SqlFailedRecords::targetOf($this->sessions->writerConfig($this->sessions->databaseConnectionFor($queueConnection)), $this->jobsTable($queueConnection));
    }

    /**
     * @param  stdClass  $operation  the ledger row (queue_connection, queue, mode, record_id, record_uuid, deadline, id)
     * @param  SqlFailedRecords|null  $records  mode in_tx: the failed-record table on Q's database
     * @return array{state: string, jobId: int|null, error: Throwable|null}
     */
    public function publish(Connection $session, stdClass $operation, string $payload, ?SqlFailedRecords $records): array
    {
        $inTx = $operation->mode === Operations::IN_TX;

        if ($inTx && $records === null) {
            throw new RuntimeException('orrery: a publication in mode in_tx needs its failed-record table.');
        }

        Hooks::reach(self::BEFORE_FENCE_INSERT);

        if (! PackageSession::isPackageSession($session->getName())) {
            // In-memory SQLite (tests only): the session is the application connection.
            return $this->publishShared($session, $operation, $payload, $records);
        }

        $outcome = null;

        try {
            $session->beginTransaction();
            $outcome = $this->statements($session, $operation, $payload, $inTx ? $records : null);

            if ($outcome['state'] === self::PUBLISHED) {
                Hooks::reach(self::BEFORE_COMMIT);
            } else {
                if (! $this->rollBack($session)) {
                    $this->release($session);
                }

                return $outcome;
            }
        } catch (Throwable $error) {
            // Released only when the rollback did not end cleanly: when the
            // queue's connection name is the mirror's, the caller (a bulk run)
            // still holds this session.
            if (! $this->rollBack($session)) {
                $this->release($session);
            }

            return self::outcome(self::FAILED, error: $error);
        }

        try {
            $session->commit();
        } catch (Throwable $error) {
            // The COMMIT may have reached the server; a rollback ends a
            // transaction that is still open. A session whose COMMIT failed
            // is not trusted again: it is always released.
            $this->rollBack($session);
            $this->release($session);

            return self::outcome(self::UNCERTAIN, error: $error);
        }

        return $outcome;
    }

    /**
     * The resolution: cancel unless published, then read the publish row.
     *
     * @return array{state: string, jobId: int|null, error: Throwable|null}
     */
    public function resolve(Connection $session, stdClass $operation): array
    {
        try {
            $row = $this->transactions->run($session, function (Connection $tx) use ($operation): ?stdClass {
                $this->fence->insertOrIgnore($tx, (string) $operation->id, Fence::PUBLISH, Fence::CANCELLED, (float) $operation->deadline);

                return $this->fence->read($tx, (string) $operation->id, Fence::PUBLISH);
            });
        } catch (Throwable $error) {
            // PackageTransaction rolled back (or disconnected after a failed rollback). The session is
            // not released here: it can be the mirror session that the operation runner holds.
            return self::outcome(self::UNDECIDED, error: $error);
        }

        return self::decided($row);
    }

    /**
     * The publish row without a write: PUBLISHED, CANCELLED, or UNDECIDED when
     * there is no row yet.
     *
     * @return array{state: string, jobId: int|null, error: Throwable|null}
     */
    public function read(Connection $session, stdClass $operation): array
    {
        return self::decided($this->fence->read($session, (string) $operation->id, Fence::PUBLISH));
    }

    /**
     * The unprefixed jobs table of a queue connection.
     */
    public function jobsTable(string $queueConnection): string
    {
        $table = $this->config()->get("queue.connections.{$queueConnection}.table");

        return is_string($table) && $table !== '' ? $table : 'jobs';
    }

    /**
     * @return array{state: string, jobId: int|null, error: Throwable|null}
     */
    private static function decided(?stdClass $row): array
    {
        if ($row === null) {
            return self::outcome(self::UNDECIDED);
        }

        if ($row->state === Fence::PUBLISHED && is_numeric($row->job_id)) {
            return self::outcome(self::PUBLISHED, (int) $row->job_id);
        }

        if ($row->state === Fence::CANCELLED) {
            return self::outcome(self::CANCELLED);
        }

        // A published row without its job id cannot exist after COMMIT; it decides nothing.
        return self::outcome(self::UNDECIDED);
    }

    /**
     * Steps 2–5 inside the caller's transaction.
     *
     * @return array{state: string, jobId: int|null, error: Throwable|null}
     */
    private function statements(Connection $session, stdClass $operation, string $payload, ?SqlFailedRecords $records): array
    {
        $id = (string) $operation->id;
        $fenced = $this->fence->insertBefore($session, $id, Fence::PUBLISH, Fence::PUBLISHED, (float) $operation->deadline);

        if ($fenced === Fence::DUPLICATE) {
            return self::outcome(self::CANCELLED);
        }

        if ($fenced === Fence::DEADLINE_PASSED) {
            return self::outcome(self::DEADLINE_PASSED);
        }

        if ($records !== null && $records->delete($session, (int) $operation->record_id, (string) $operation->record_uuid) !== 1) {
            return self::outcome(self::RECORD_GONE);
        }

        $queueConnection = (string) $operation->queue_connection;
        $queue = new LaravelDatabaseQueue(
            $session,
            $this->jobsTable($queueConnection),
            (string) ($this->config()->get("queue.connections.{$queueConnection}.queue") ?? 'default'),
            (int) ($this->config()->get("queue.connections.{$queueConnection}.retry_after") ?? 60),
        );
        // As Laravel's queue:retry pushes through the named connection: a queue forward scoped to it applies.
        $queue->setConnectionName($queueConnection);
        $jobId = (int) $queue->pushRaw($payload, (string) $operation->queue);

        if ($this->fence->setJobId($session, $id, $jobId) !== 1) {
            throw new RuntimeException('orrery: the publish fence row is missing inside its own transaction.');
        }

        return self::outcome(self::PUBLISHED, $jobId);
    }

    /**
     * @return array{state: string, jobId: int|null, error: Throwable|null}
     */
    private function publishShared(Connection $session, stdClass $operation, string $payload, ?SqlFailedRecords $records): array
    {
        $rollback = new RuntimeException('orrery: roll the publication back.');
        /** @var array{state: string, jobId: int|null, error: Throwable|null}|null $outcome the closure sets it by reference */
        $outcome = null;
        /** @var bool $done the closure sets it by reference */
        $done = false;

        try {
            $this->transactions->run($session, function (Connection $tx) use ($operation, $payload, $records, $rollback, &$outcome, &$done): void {
                $done = false;
                $outcome = $this->statements($tx, $operation, $payload, $operation->mode === Operations::IN_TX ? $records : null);

                if ($outcome['state'] !== self::PUBLISHED) {
                    throw $rollback;
                }

                Hooks::reach(self::BEFORE_COMMIT);
                $done = true;
            });
        } catch (Throwable $error) {
            if ($error === $rollback && $outcome !== null) {
                return $outcome;
            }

            return self::outcome($done ? self::UNCERTAIN : self::FAILED, error: $error);
        }

        return $outcome ?? self::outcome(self::FAILED);
    }

    /**
     * Rolls the publication back. False when the rollback threw or the
     * session is still inside a transaction: the caller then releases it.
     */
    private function rollBack(Connection $session): bool
    {
        $clean = true;

        try {
            if ($session->transactionLevel() > 0) {
                $session->rollBack();
            }
        } catch (Throwable) {
            // The release ends the server transaction.
            $clean = false;
        }

        $pdo = $session->getRawPdo();

        if ($pdo instanceof PDO) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                $clean = false;
            }

            try {
                if ($pdo->inTransaction()) {
                    $clean = false;
                }
            } catch (Throwable) {
                $clean = false;
            }
        }

        return $clean && $session->transactionLevel() === 0;
    }

    private function release(Connection $session): void
    {
        try {
            $this->sessions->disconnect($session);
        } catch (Throwable) {
            // A failed disconnect leaves nothing: the PDO goes with the session.
        }
    }

    /**
     * @return array{state: string, jobId: int|null, error: Throwable|null}
     */
    private static function outcome(string $state, ?int $jobId = null, ?Throwable $error = null): array
    {
        return ['state' => $state, 'jobId' => $jobId, 'error' => $error];
    }

    /**
     * The configuration of the running request, read when it is used: this
     * object is resolved at boot, and an Octane request has its
     * own configuration sandbox.
     */
    private function config(): Repository
    {
        return Container::getInstance()->make('config');
    }
}
