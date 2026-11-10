<?php

namespace Justrau\Orrery\Lifecycle;

use Illuminate\Database\Query\Expression;
use Justrau\Orrery\Storage\TrustedSql;

/**
 * Outcome accounting and the observed gap.
 *
 * Accounting is separate from the state transitions: every outcome of an
 * attempt n (a release, an exception, a timeout, a completion or a failure)
 * that belongs to the row's current generation (S) is counted, also when
 * the rules reject its transition:
 * - n > last_outcome_attempt: observed_attempts + 1 and
 *   last_outcome_attempt = n;
 * - n < last_outcome_attempt: a late outcome. The store inserts the history
 *   row `late:<cycle>:<n>`, and only when that insert adds a row,
 *   late_outcomes + 1, in the same transaction;
 * - n = last_outcome_attempt: a duplicate. Nothing is counted.
 *
 * observed_gap = max(0, attempts - observed_attempts - late_outcomes - a),
 * where a = 1 when the row is `reserved` and last_outcome_attempt < attempts
 * (the running attempt has no counted outcome yet), else 0. The gap is a
 * lower bound of the attempts that ended without an observed outcome.
 */
final class OutcomeAccounting
{
    /** The observed terms of gapExpression(): %1$s is the attempt n. */
    private const GAP_COUNTED = '(observed_attempts + late_outcomes + (CASE WHEN last_outcome_attempt < %1$s THEN 1 ELSE 0 END))';

    /** The template of gapExpression(): %1$s is the attempt n. */
    private const GAP_TEMPLATE = 'CASE WHEN '.self::GAP_COUNTED.' < %1$s THEN %1$s - '.self::GAP_COUNTED.' ELSE 0 END';

    /** The observation kinds that are outcomes of an attempt. */
    public const OUTCOMES = [
        Observation::RELEASE, Observation::EXCEPTION, Observation::TIMEOUT, Observation::COMPLETE, Observation::FAIL,
    ];

    public static function isOutcome(Observation $o): bool
    {
        return in_array($o->kind, self::OUTCOMES, true);
    }

    /**
     * The high-water rule for an outcome of attempt $n.
     *
     * @return array{observed: int, last: int, late: bool}
     */
    public static function count(int $observed, int $last, int $n): array
    {
        if ($n > $last) {
            return ['observed' => $observed + 1, 'last' => $n, 'late' => false];
        }

        return ['observed' => $observed, 'last' => $last, 'late' => $n < $last];
    }

    public static function gap(string $status, int $attempts, int $observed, int $late, int $last): int
    {
        $running = $status === 'reserved' && $last < $attempts ? 1 : 0;

        return max(0, $attempts - $observed - $late - $running);
    }

    /**
     * The gap as one SQL expression for a statement that leaves the row with
     * `attempts` = $n and adds one to the observed and running terms when
     * last_outcome_attempt < $n. That covers both fast statements:
     * - the fast reserve (`reserved` at attempt n: a = 1 when last < n);
     * - the fast release of the current attempt n (`pending`, and the release
     *   itself is the outcome of n: observed + 1 when last < n).
     *
     * It reads only columns that neither statement changes before it, so it
     * must be the first assignment of the SET list: MySQL evaluates the
     * assignments from left to right with the new values. The subtraction
     * runs only when it is positive, so unsigned MySQL columns never go
     * below zero.
     *
     * @return Expression<literal-string>
     */
    public static function gapExpression(int $n): Expression
    {
        return TrustedSql::expression(self::GAP_TEMPLATE, $n);
    }
}
