<?php

namespace Justrau\Orrery\Observation;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use PDO;
use WeakReference;

/**
 * A root transaction record that holds deferred items (CommitDeferral).
 *
 * The record is held strongly. The connection instance and its PDO are weak
 * references: a purged instance can be freed, and its roots are still
 * counted at the end of the unit.
 *
 * @internal
 */
final class TrackedRoot
{
    /**
     * The items in raise order. A decided item is decided already (an
     * autocommit item raised during this root's walk, or the runnable item
     * of a root that committed inside the walk); it only waits for its turn.
     *
     * @var list<array{run: Closure(): mixed, operation: string, chain: list<RecordMark>, ambiguous: bool, decided: bool, bytes: int}>
     */
    public array $items = [];

    /**
     * The instance began a new root at level 0 during this root's walk, and
     * at that begin it had this root's PDO and no open transaction: the
     * state that the root check reads, seen before a callback of the walk
     * changed it.
     */
    public bool $cleanAtWalkBegin = false;

    /** The root's decision ended (normally, or with its items counted). */
    public bool $settled = false;

    /**
     * @param  WeakReference<Connection>  $connection
     * @param  WeakReference<PDO>|null  $pdo
     */
    public function __construct(
        public readonly DatabaseTransactionRecord $record,
        public readonly WeakReference $connection,
        public readonly ?WeakReference $pdo,
    ) {}

    public function belongsTo(Connection $connection): bool
    {
        return $this->connection->get() === $connection;
    }
}
