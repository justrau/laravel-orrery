<?php

namespace Justrau\Orrery\Queue;

use Illuminate\Database\DatabaseTransactionRecord;

/**
 * The pending bulk captures and their units of work (BulkCapture): process
 * state, a container singleton. A pending capture waits for
 * the commit of a transaction that can outlive the unit of work that opened
 * it, so it is never scoped.
 */
final class PendingBulkCaptures
{
    /** @var array<int, BulkCapture> the pending captures by id, oldest first */
    public array $captures = [];

    /** @var array<int, array{sequence: int, owned: array<int, DatabaseTransactionRecord>}> the open units by token */
    public array $units = [];

    /** The id of the newest capture. */
    public int $sequence = 0;

    /** The token of the next unit. */
    public int $nextUnit = 0;
}
