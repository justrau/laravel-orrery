<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use RuntimeException;
use stdClass;

/**
 * The fence of the operations: the table `orrery_fences`,
 * keyed by (operation_id, slot), in every database where an operation has an
 * effect outside the mirror (the queue database of a retry, the failed-record
 * database of a delete and a restore).
 *
 * Every effect inserts its fence row in the same transaction as the effect,
 * on the session of that database, so a stale or concurrent actor loses on
 * the primary key inside its own transaction. The slots and their states:
 * - publish: published (the publisher; then the job id) or cancelled (a
 *   resolver);
 * - delete: deleted (the deleter) or delete_cancelled (an aborter);
 * - restore: restored, with the restored record's new id.
 *
 * The methods take the session of the caller's transaction and never begin
 * or end one. Each row carries `deadline` (the operation's deadline in this
 * database's clock) and `created_at` (this database's clock).
 *
 * insertBefore() is the conditional fence insert: one statement that inserts
 * the row only while this database's clock is at or before the deadline.
 * The deadline check and the key are one statement, so the transaction
 * needs no clock read of its own. The clock is
 * DatabaseClock::expression(), so the test offset reaches it too.
 *
 * available() checks that the table exists on the session of a database
 * connection and keeps the answer for 60 s.
 */
final class Fence
{
    public const TABLE = 'orrery_fences';

    public const PUBLISH = 'publish';

    public const DELETE = 'delete';

    public const RESTORE = 'restore';

    public const PUBLISHED = 'published';

    public const CANCELLED = 'cancelled';

    public const DELETED = 'deleted';

    public const DELETE_CANCELLED = 'delete_cancelled';

    public const RESTORED = 'restored';

    /** The results of insertBefore(). */
    public const INSERTED = 'inserted';

    public const DUPLICATE = 'duplicate';

    public const DEADLINE_PASSED = 'deadline_passed';

    /** How long available() keeps its answer. */
    public const AVAILABILITY_SECONDS = 60.0;

    private const COLUMNS = 'operation_id, slot, state, deadline, created_at';

    /** @var array<string, array{0: bool, 1: float}> the answers of available() by connection and endpoint, with the monotonic time */
    private array $available = [];

    public function __construct(private readonly PackageSession $sessions)
    {
    }

    /**
     * The conditional fence insert. INSERTED: the row is in, inside the
     * caller's transaction. DUPLICATE: a row of this operation and slot
     * exists (another actor won; the caller rolls back: on Postgres the
     * transaction is aborted). DEADLINE_PASSED: this database's clock is
     * after $deadline, and nothing was inserted.
     */
    public function insertBefore(Connection $session, string $operationId, string $slot, string $state, float $deadline): string
    {
        [$clock, $clockBindings] = $this->clock($session);
        $table = $session->getQueryGrammar()->wrapTable(self::TABLE);
        $columns = self::COLUMNS;
        $sql = match ($session->getDriverName()) {
            'mysql', 'mariadb' => "insert into {$table} ({$columns}) select ?, ?, ?, ?, {$clock} from dual where {$clock} <= cast(? as decimal(16,6))",
            'pgsql' => "insert into {$table} ({$columns}) select ?, ?, ?, cast(? as numeric), {$clock} where {$clock} <= cast(? as numeric)",
            'sqlite' => "insert into {$table} ({$columns}) select ?, ?, ?, ?, {$clock} where {$clock} <= cast(? as real)",
            default => throw new RuntimeException("orrery: the fence does not support the driver [{$session->getDriverName()}]."),
        };
        $decimal = Clock::toDecimal($deadline);

        try {
            $inserted = $session->affectingStatement($sql, [$operationId, $slot, $state, $decimal, ...$clockBindings, ...$clockBindings, $decimal]);
        } catch (UniqueConstraintViolationException) {
            return self::DUPLICATE;
        }

        return $inserted > 0 ? self::INSERTED : self::DEADLINE_PASSED;
    }

    /**
     * Inserts the row unless a row of this operation and slot exists (a
     * cancellation or a delete decision; the first row stays).
     */
    public function insertOrIgnore(Connection $session, string $operationId, string $slot, string $state, float $deadline): void
    {
        [$clock, $clockBindings] = $this->clock($session);
        $table = $session->getQueryGrammar()->wrapTable(self::TABLE);
        $columns = self::COLUMNS;
        $sql = match ($session->getDriverName()) {
            'mysql', 'mariadb' => "insert ignore into {$table} ({$columns}) select ?, ?, ?, ?, {$clock}",
            'pgsql' => "insert into {$table} ({$columns}) select ?, ?, ?, cast(? as numeric), {$clock} on conflict do nothing",
            'sqlite' => "insert or ignore into {$table} ({$columns}) select ?, ?, ?, ?, {$clock}",
            default => throw new RuntimeException("orrery: the fence does not support the driver [{$session->getDriverName()}]."),
        };

        $session->affectingStatement($sql, [$operationId, $slot, $state, Clock::toDecimal($deadline), ...$clockBindings]);
    }

    /**
     * A plain insert (the restore row with the restored record's new id).
     * A duplicate key throws UniqueConstraintViolationException.
     */
    public function insert(Connection $session, string $operationId, string $slot, string $state, float $deadline, ?int $jobId = null): void
    {
        [$clock, $clockBindings] = $this->clock($session);
        $table = $session->getQueryGrammar()->wrapTable(self::TABLE);
        $columns = self::COLUMNS;
        $sql = match ($session->getDriverName()) {
            'pgsql' => "insert into {$table} ({$columns}, job_id) select ?, ?, ?, cast(? as numeric), {$clock}, cast(? as bigint)",
            default => "insert into {$table} ({$columns}, job_id) select ?, ?, ?, ?, {$clock}, ?",
        };

        $session->affectingStatement($sql, [$operationId, $slot, $state, Clock::toDecimal($deadline), ...$clockBindings, $jobId]);
    }

    /**
     * The row of an operation and slot (state, job_id, deadline,
     * created_at), or null. $lock reads it FOR UPDATE inside the caller's
     * transaction (SQLite locks the whole file already).
     */
    public function read(Connection $session, string $operationId, string $slot, bool $lock = false): ?stdClass
    {
        $query = $session->table(self::TABLE)
            ->select(['state', 'job_id', 'deadline', 'created_at'])
            ->where('operation_id', $operationId)
            ->where('slot', $slot);

        if ($lock && $session->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Writes the published job id on the publish row of an operation.
     */
    public function setJobId(Connection $session, string $operationId, int $jobId): int
    {
        return $session->table(self::TABLE)
            ->where('operation_id', $operationId)
            ->where('slot', self::PUBLISH)
            ->update(['job_id' => $jobId]);
    }

    /**
     * Whether the fence table exists on the package session of a database
     * connection (null: database.default). The answer, also a missing table,
     * is kept for AVAILABILITY_SECONDS per connection and endpoint. A failed
     * check throws and keeps nothing.
     */
    public function available(?string $connection): bool
    {
        $session = $this->sessions->forAction($connection);
        $key = (string) $session->getName()."\0".$this->sessions->fingerprint($connection);
        $known = $this->available[$key] ?? null;
        $now = Clock::monotonic();

        if ($known !== null && $now - $known[1] < self::AVAILABILITY_SECONDS) {
            return $known[0];
        }

        $exists = $session->getSchemaBuilder()->hasTable(self::TABLE);
        $this->available[$key] = [$exists, $now];

        return $exists;
    }

    /**
     * Forgets the cached answers of available(): the next call reads the
     * schema again (tests that remove the fence table).
     */
    public function forgetAvailability(): void
    {
        $this->available = [];
    }

    /**
     * @return array{0: string, 1: list<float>}
     */
    private function clock(Connection $session): array
    {
        [$expression, $bindings] = DatabaseClock::expression($session);

        return [(string) $expression->getValue($session->getQueryGrammar()), $bindings];
    }
}
