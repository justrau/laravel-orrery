<?php

namespace Justrau\Orrery\Observation;

use Illuminate\Database\DatabaseTransactionRecord;

/**
 * The outcome marks of one transaction record (CommitDeferral). The commit
 * callback on the record sets $committed, the rollback callback sets
 * $rolledBack. The mark holds the record strongly, so the record cannot
 * disappear before its items are decided.
 *
 * @internal
 */
final class RecordMark
{
    public bool $committed = false;

    public bool $rolledBack = false;

    /** Whether the rollback callback is on the record (the commit callback always is). */
    public bool $watchesRollback = false;

    public function __construct(public readonly DatabaseTransactionRecord $record) {}
}
