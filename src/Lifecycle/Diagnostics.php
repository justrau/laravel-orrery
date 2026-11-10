<?php

namespace Justrau\Orrery\Lifecycle;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;

/**
 * The terminal explanation of a failed cycle: a
 * pure class over the exception class and the cycle's own limits at its
 * failure time (EnforcedRule::of() at `finished_at`).
 *
 * | Exception | `kind` | `facts` |
 * | --- | --- | --- |
 * | TimeoutExceededException | `timeout` | the effective timeout and its source, failOnTimeout, the attempt |
 * | MaxAttemptsExceededException, a deadline before the failure time, no maxExceptions | `deadline_passed` | the deadline, the failure time, the attempt |
 * | MaxAttemptsExceededException, no deadline, and the attempt is more than tries > 0 (or the tries are unknown and no maxExceptions is set) | `attempt_limit` | the tries and their source, maxExceptions, the attempt |
 * | any other MaxAttemptsExceededException | `max_attempts_other` | the deadline, the failure time, the tries and their source, maxExceptions, the attempt |
 * | any other class, or none | `other` | the limits at the failure time, as conditions |
 *
 * - TimeoutExceededException is checked first: it extends
 *   MaxAttemptsExceededException.
 * - Laravel throws MaxAttemptsExceededException from two checks: the
 *   attempt limit or the passed deadline before an attempt
 *   (`markJobAsFailedIfAlreadyExceedsMaxAttempts()`), and, on releases
 *   with #[CountCrashesAsExceptions] (13.40 has it, 13.30 does not), the
 *   maxExceptions of a job with that attribute reached through counted
 *   crashes (`markJobAsFailedIfAlreadyExceedsMaxExceptions()`).
 *   So `attempt_limit` and `deadline_passed` are given only when the limits
 *   prove them, and `max_attempts_other` shows the values otherwise: a
 *   maxExceptions that is set, an attempt within the tries value, unlimited
 *   tries, a deadline that had not passed (different host clocks), or a
 *   passed deadline with a maxExceptions set (a counted crash can fail the
 *   job within the deadline's last second).
 * - The stored class name is compared as text, and with a subclass only
 *   when that class is already loaded: the class is never autoloaded from
 *   stored data. An application subclass that is not loaded reads as
 *   `other`.
 * - For `other` the facts are conditions, never a cause: a job can call
 *   fail() itself at any attempt, and Laravel 13's exception handler can stop
 *   retries (`shouldStopRetries`). The words "root cause" are never used.
 */
final class Diagnostics
{
    public const TIMEOUT = 'timeout';

    public const DEADLINE_PASSED = 'deadline_passed';

    public const ATTEMPT_LIMIT = 'attempt_limit';

    public const MAX_ATTEMPTS_OTHER = 'max_attempts_other';

    public const OTHER = 'other';

    /**
     * @param  array{kind: string, deadline: int|null, tries: int|null, tries_source: string, other_stops: array{max_exceptions: int|null, fail_on_timeout: bool, timeout: int|null, timeout_source: string}}  $rule  the enforced rule at the failure time
     * @return array{kind: string, facts: array<string, mixed>}
     */
    public static function terminal(?string $exceptionClass, int $attempt, ?float $failedAt, array $rule): array
    {
        $deadline = $rule['deadline'];
        $stops = $rule['other_stops'];
        $passed = $deadline === null || $failedAt === null ? null : (int) floor($failedAt) > $deadline;

        if (self::is($exceptionClass, TimeoutExceededException::class)) {
            return ['kind' => self::TIMEOUT, 'facts' => [
                'timeout' => $stops['timeout'],
                'timeout_source' => $stops['timeout_source'],
                'fail_on_timeout' => $stops['fail_on_timeout'],
                'attempt' => $attempt,
            ]];
        }

        if (self::is($exceptionClass, MaxAttemptsExceededException::class)) {
            $tries = $rule['tries'];
            $maxExceptions = $stops['max_exceptions'];

            if ($passed === true && $maxExceptions === null) {
                return ['kind' => self::DEADLINE_PASSED, 'facts' => ['deadline' => $deadline, 'failed_at' => $failedAt, 'attempt' => $attempt]];
            }

            if ($deadline === null && (($tries !== null && $tries > 0 && $attempt > $tries) || ($tries === null && $maxExceptions === null))) {
                return ['kind' => self::ATTEMPT_LIMIT, 'facts' => ['tries' => $tries, 'tries_source' => $rule['tries_source'], 'max_exceptions' => $maxExceptions, 'attempt' => $attempt]];
            }

            return ['kind' => self::MAX_ATTEMPTS_OTHER, 'facts' => [
                'deadline' => $deadline,
                'failed_at' => $failedAt,
                'tries' => $tries,
                'tries_source' => $rule['tries_source'],
                'max_exceptions' => $maxExceptions,
                'attempt' => $attempt,
            ]];
        }

        return ['kind' => self::OTHER, 'facts' => [
            'attempt' => $attempt,
            'tries' => $rule['tries'],
            'tries_source' => $rule['tries_source'],
            'deadline' => $deadline,
            'deadline_passed' => $passed,
            'max_exceptions' => $stops['max_exceptions'],
            'fail_on_timeout' => $stops['fail_on_timeout'],
            'timeout' => $stops['timeout'],
            'timeout_source' => $stops['timeout_source'],
        ]];
    }

    /**
     * Whether a stored class name is $target or a loaded subclass of it,
     * without autoloading the stored name.
     *
     * @param  class-string  $target
     */
    private static function is(?string $class, string $target): bool
    {
        if ($class === null || $class === '') {
            return false;
        }

        $class = ltrim($class, '\\');

        if (strcasecmp($class, $target) === 0) {
            return true;
        }

        return class_exists($class, false) && is_subclass_of($class, $target);
    }
}
