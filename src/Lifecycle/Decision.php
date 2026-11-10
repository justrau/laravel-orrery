<?php

namespace Justrau\Orrery\Lifecycle;

/**
 * The result of Rules::decide(): the rule, the columns to write, the guard
 * that the conditional statement re-checks in its WHERE clause, and the side
 * effects that run only when the statement changed a row.
 *
 * The rule is the name of the Rules method that decided
 * (docs/architecture/lifecycle-rules.md), or REJECTED, HELD or ACCOUNTED.
 *
 * Effects: 'tags', 'failed_tags', 'history:<event>', 'occurrence',
 * 'fill_generation', 'late' (a late outcome row and its count),
 * 'cycle_start' (the freeze of nativeRetry), 'summary' (olderCycle),
 * 'adopt' (adoption) and 'unmatched' (a rejected observation that counts as
 * unmatched).
 */
final class Decision
{
    public const REJECTED = 'rejected';

    /** The rule of a StoreOutcome whose row already held the observation's result (a duplicate). */
    public const HELD = 'held';

    /** The rule of an observation whose transition is rejected, but whose outcome accounting changes the row. */
    public const ACCOUNTED = 'accounted';

    /** The rule of a known older generation (Rules::olderCycle()): the row does not change; the summary takes the evidence. */
    public const OLDER = 'olderCycle';

    /**
     * @param  string  $rule  the name of a rule method of Rules, or 'accounted' or 'rejected'
     * @param  array<string, mixed>  $set  column => value
     * @param  array<string, mixed>  $guard  column => expected value, re-checked in WHERE; empty for an insert
     * @param  list<string>  $effects
     */
    public function __construct(
        public readonly string $rule,
        public readonly array $set = [],
        public readonly array $guard = [],
        public readonly array $effects = [],
    ) {
    }

    public static function rejected(): self
    {
        return new self(self::REJECTED);
    }

    /**
     * A rejected observation that no retained row or summary matches: it
     * changes nothing, and it counts Degradation::unmatched().
     */
    public static function unmatched(): self
    {
        return new self(self::REJECTED, [], [], ['unmatched']);
    }

    public function isRejected(): bool
    {
        return $this->rule === self::REJECTED;
    }

    /**
     * An insert of a new row (push, bulkPush, reserveWithoutRow,
     * completeWithoutRow and failWithoutRow).
     */
    public function isInsert(): bool
    {
        return $this->guard === [] && $this->set !== [] && ! $this->isRejected();
    }

    public function has(string $effect): bool
    {
        return in_array($effect, $this->effects, true);
    }

    public function historyEvent(): ?string
    {
        foreach ($this->effects as $effect) {
            if (str_starts_with($effect, 'history:')) {
                return substr($effect, 8);
            }
        }

        return null;
    }
}
