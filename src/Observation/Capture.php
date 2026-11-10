<?php

namespace Justrau\Orrery\Observation;

use Illuminate\Database\DatabaseTransactionRecord;

/**
 * The state of a queue connection instance before a hook's parent call
 * (CommitDeferral::capture()).
 *
 * - $levelBefore: the transaction level before the call.
 * - $beginsBefore: the instance's begin counter before the call.
 * - $ownBegins: the transactions that the parent call itself begins (1 for
 *   pop() and deleteAndRelease(), 0 for every insert hook).
 * - $chainBefore: the pending records of the instance's name before the
 *   call (empty at level 0). It holds the records, never their ids, so a
 *   freed record whose id is reused cannot match.
 * - $ownMark: the commit mark of the parent's own transaction, set by the
 *   instance's beforeExecuting callback at the first statement of that
 *   transaction.
 *
 * An unverified capture (unverified()) is a sentinel: after() counts it as
 * a drop and never reads it as an empty chain.
 *
 * @internal
 */
final class Capture
{
    public ?RecordMark $ownMark = null;

    /**
     * @param  list<DatabaseTransactionRecord>  $chainBefore
     */
    private function __construct(
        public readonly bool $verified,
        public readonly int $levelBefore,
        public readonly int $beginsBefore,
        public readonly int $ownBegins,
        public readonly array $chainBefore,
    ) {
    }

    /**
     * @param  list<DatabaseTransactionRecord>  $chainBefore
     */
    public static function of(int $levelBefore, int $beginsBefore, int $ownBegins, array $chainBefore): self
    {
        return new self(true, $levelBefore, $beginsBefore, $ownBegins, $chainBefore);
    }

    public static function unverified(): self
    {
        return new self(false, 0, 0, 0, []);
    }

    /**
     * Whether the instance's beforeExecuting callback still looks for the
     * record of the parent's own transaction.
     */
    public function awaitsOwnRecord(): bool
    {
        return $this->verified && $this->ownBegins > 0 && $this->ownMark === null;
    }
}
