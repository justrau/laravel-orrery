<?php

namespace Justrau\Orrery\Lifecycle;

/**
 * What JobStore::observe() found (mutation versus result).
 *
 * $applied is a statement about the row's state, not about which call wrote
 * it: it is true when the row holds this observation's transition after the
 * call. That is a positive count of this call's statement, or, after a zero
 * count, a re-read row that already holds exactly this observation's result
 * (the same generation, job id, attempt, status and, for a reservation, the
 * same worker). So a lost acknowledgement that Laravel repeated and a
 * duplicate observation give the same result.
 *
 * No side effect may depend on $applied. Every side effect stays in the
 * package transaction of its own write, where the row count is reliable.
 * $occurrenceId is set only by the transaction of a failure.
 */
final class StoreOutcome
{
    public function __construct(public readonly string $rule, public readonly bool $applied, public readonly ?int $occurrenceId = null)
    {
    }
}
