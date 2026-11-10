<?php

namespace Justrau\Orrery\Lifecycle;

/**
 * The rule that Laravel enforces to stop the retries of a job:
 * a pure class over the limit columns and the worker evidence.
 *
 * Laravel's precedence (`Illuminate\Queue\Worker::
 * markJobAsFailedIfAlreadyExceedsMaxAttempts()` and
 * `markJobAsFailedIfWillExceedMaxAttempts()`, checked against the installed
 * Laravel 13 release):
 *
 * - **The tries value.** The payload's `maxTries` (the row's `max_tries`)
 *   when it is not null; else the worker's `--tries` (`worker_tries`) when
 *   the worker evidence belongs to the attempt that is described
 *   (`worker_evidence_attempt` equals it; for a row, its latest attempt);
 *   else unknown. 0 means unlimited.
 * - **The deadline.** The payload's `retryUntil` (`retry_until`), when it is
 *   set and not 0 (Laravel tests it as a truthy value). While a deadline is
 *   set, Laravel never compares the attempts with the tries value. An
 *   attempt runs while the time, in whole seconds, is at or before the
 *   deadline.
 *
 * | Case | `kind` |
 * | --- | --- |
 * | A deadline, the time at or before it | `deadline` (the tries are not enforced until then) |
 * | A deadline, the time after it | `deadline_passed` |
 * | No deadline, tries > 0 | `attempt_limit` |
 * | No deadline, tries 0 | `none` |
 * | No deadline, tries unknown | `unknown` |
 *
 * Every result has the same keys: `kind`, `deadline` (the epoch seconds, or
 * null), `tries` and `tries_source` (`payload`, `worker` or `unknown`), and
 * `other_stops`: `max_exceptions`, `fail_on_timeout`, and the effective
 * `timeout` with its `timeout_source` (the payload's `timeout`, else the
 * worker's `--timeout` of the same attempt, else unknown; 0 is "no alarm",
 * different from null). These stop conditions apply in every case. The
 * server returns facts; the SPA fills the wording.
 *
 * The running view evaluates the rule at the current time, the diagnostics
 * of a cycle at its failure time.
 */
final class EnforcedRule
{
    public const DEADLINE = 'deadline';

    public const DEADLINE_PASSED = 'deadline_passed';

    public const ATTEMPT_LIMIT = 'attempt_limit';

    public const NONE = 'none';

    public const UNKNOWN = 'unknown';

    public const SOURCE_PAYLOAD = 'payload';

    public const SOURCE_WORKER = 'worker';

    public const SOURCE_UNKNOWN = 'unknown';

    /**
     * The rule from resolved values: the effective tries and timeout with
     * their sources (effective()), at the time $at.
     *
     * @return array{kind: string, deadline: int|null, tries: int|null, tries_source: string, other_stops: array{max_exceptions: int|null, fail_on_timeout: bool, timeout: int|null, timeout_source: string}}
     */
    public static function of(
        ?int $tries,
        string $triesSource,
        ?int $retryUntil,
        float $at,
        ?int $maxExceptions,
        bool $failOnTimeout,
        ?int $timeout,
        string $timeoutSource,
    ): array {
        $deadline = self::deadline($retryUntil);

        $kind = match (true) {
            $deadline !== null && (int) floor($at) <= $deadline => self::DEADLINE,
            $deadline !== null => self::DEADLINE_PASSED,
            $tries === null => self::UNKNOWN,
            $tries === 0 => self::NONE,
            default => self::ATTEMPT_LIMIT,
        };

        return [
            'kind' => $kind,
            'deadline' => $deadline,
            'tries' => $tries,
            'tries_source' => $tries === null ? self::SOURCE_UNKNOWN : $triesSource,
            'other_stops' => [
                'max_exceptions' => $maxExceptions,
                'fail_on_timeout' => $failOnTimeout,
                'timeout' => $timeout,
                'timeout_source' => $timeout === null ? self::SOURCE_UNKNOWN : $timeoutSource,
            ],
        ];
    }

    /**
     * The rule of a mirror row's current cycle for its latest attempt
     * (latestAttempt()), at the time $at: the limit columns and the worker
     * evidence of that attempt only.
     *
     * @return array{kind: string, deadline: int|null, tries: int|null, tries_source: string, other_stops: array{max_exceptions: int|null, fail_on_timeout: bool, timeout: int|null, timeout_source: string}}
     */
    public static function forRow(object $row, float $at): array
    {
        $effective = self::effectiveOfRow($row);

        return self::of(
            $effective['tries'],
            $effective['tries_source'],
            self::int($row->retry_until ?? null),
            $at,
            self::int($row->max_exceptions ?? null),
            (bool) ($row->fail_on_timeout ?? false),
            $effective['timeout'],
            $effective['timeout_source'],
        );
    }

    /**
     * The effective tries and timeout of a mirror row's latest attempt
     * (latestAttempt()), with their sources: the payload value when it is
     * not null, else the worker evidence when `worker_evidence_attempt`
     * equals that attempt, else unknown. Evidence of an older attempt is
     * never used.
     *
     * @return array{tries: int|null, tries_source: string, timeout: int|null, timeout_source: string}
     */
    public static function effectiveOfRow(object $row): array
    {
        $attempt = self::latestAttempt($row);
        $evidence = self::int($row->worker_evidence_attempt ?? null);
        $ofAttempt = $evidence !== null && $evidence === $attempt;

        [$tries, $triesSource] = self::effective(self::int($row->max_tries ?? null), $ofAttempt ? self::int($row->worker_tries ?? null) : null);
        [$timeout, $timeoutSource] = self::effective(self::int($row->timeout ?? null), $ofAttempt ? self::int($row->worker_timeout ?? null) : null);

        return ['tries' => $tries, 'tries_source' => $triesSource, 'timeout' => $timeout, 'timeout_source' => $timeoutSource];
    }

    /**
     * The latest attempt of a cycle that the mirror knows: the larger of its
     * observed reservations (`attempts`) and the attempt of its latest
     * observed outcome (`last_outcome_attempt`). A failure whose reservation
     * was not observed names a later attempt than `attempts`, and its worker
     * evidence is that attempt's.
     */
    public static function latestAttempt(object $row): int
    {
        return max(self::int($row->attempts ?? null) ?? 0, self::int($row->last_outcome_attempt ?? null) ?? 0);
    }

    /**
     * A payload value when it is not null, else a worker value, else unknown.
     *
     * @return array{0: int|null, 1: string}
     */
    public static function effective(?int $payload, ?int $worker): array
    {
        if ($payload !== null) {
            return [$payload, self::SOURCE_PAYLOAD];
        }

        if ($worker !== null) {
            return [$worker, self::SOURCE_WORKER];
        }

        return [null, self::SOURCE_UNKNOWN];
    }

    /**
     * The deadline that Laravel tests: a retry_until that is set and not 0.
     */
    public static function deadline(?int $retryUntil): ?int
    {
        return $retryUntil === null || $retryUntil === 0 ? null : $retryUntil;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
