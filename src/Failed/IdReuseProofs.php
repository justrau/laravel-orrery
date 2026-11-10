<?php

namespace Justrau\Orrery\Failed;

/**
 * Whether each failed-record target never reuses an id
 * (SqlFailedRecords::idsNeverReused()): process state, a container singleton.
 * The answer of a target does not change while the process
 * runs, so it is proved once.
 */
final class IdReuseProofs
{
    /** @var array<string, bool> the answers by target */
    public array $answers = [];
}
