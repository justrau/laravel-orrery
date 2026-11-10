<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Contracts\Config\Repository;
use Justrau\Orrery\Lifecycle\EnforcedRule;
use Justrau\Orrery\Workers\WorkerLiveness;

/**
 * The stuck signals of a mirror row: a pure
 * evaluator over the row and the configuration. It reads no database.
 *
 * | Signal | Holds when | Facts |
 * | --- | --- | --- |
 * | `reservation_expired` | `status = reserved` and `reserved_at + retry_after <= now`, with the lease interval of WorkerLiveness::retryAfter() (the row's own connection's `retry_after`; never for a connection that is not configured) | `lease_expires_at`, `retry_after` |
 * | `outcome_not_observed` | `status IN (pending, reserved)` and `observed_gap > 0` | `gap` |
 * | `repeated_releases` | `status IN (pending, reserved)` and `releases >= orrery.stuck.release_threshold` (20) | `releases` |
 * | `attempts_not_capped` | `status IN (pending, reserved)`, `attempts >= orrery.stuck.attempt_threshold` (20), and the enforced rule (EnforcedRule::forRow() at now) is `none` or `deadline` | `attempts`, `rule`, and `deadline` for the rule `deadline` |
 *
 * - `observed_gap` is the stored column (OutcomeAccounting writes it),
 *   never recomputed here.
 * - `releases` and `attempts` are the counters of the current cycle.
 * - With an attempt limit, an unknown limit or a passed deadline,
 *   "attempts not capped" does not hold: Laravel fails the job at its next
 *   check, or the limit is not known.
 * - The facts are numbers and enum values only, so an incident's evidence
 *   stays small (4.15).
 * - Worker liveness is not a signal (WorkerLiveness). No signal
 *   claims a process death, a crash, a rate or an eventual pickup.
 *
 * A threshold is a whole number of at least 1; another value, or a
 * published configuration without the `stuck` block, takes the default.
 */
final class StuckSignals
{
    public const RESERVATION_EXPIRED = 'reservation_expired';

    public const OUTCOME_NOT_OBSERVED = 'outcome_not_observed';

    public const REPEATED_RELEASES = 'repeated_releases';

    public const ATTEMPTS_NOT_CAPPED = 'attempts_not_capped';

    /** Every signal, in the order of a row's `signals`. */
    public const SIGNALS = [self::RESERVATION_EXPIRED, self::OUTCOME_NOT_OBSERVED, self::REPEATED_RELEASES, self::ATTEMPTS_NOT_CAPPED];

    /** The statuses whose rows can have a signal. */
    public const ACTIVE = ['pending', 'reserved'];

    /** The default of `orrery.stuck.release_threshold`. */
    public const RELEASE_THRESHOLD = 20;

    /** The default of `orrery.stuck.attempt_threshold`. */
    public const ATTEMPT_THRESHOLD = 20;

    /** The orrery_jobs columns that the signals and the enforced rule read. */
    public const COLUMNS = [
        'status', 'connection', 'reserved_at', 'attempts', 'releases', 'observed_gap', 'last_outcome_attempt',
        'max_tries', 'retry_until', 'max_exceptions', 'fail_on_timeout', 'timeout',
        'worker_tries', 'worker_timeout', 'worker_evidence_attempt',
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly WorkerLiveness $liveness,
    ) {}

    /**
     * The threshold of `repeated_releases`; null when the configuration
     * turns the signal off with false.
     */
    public function releaseThreshold(): ?int
    {
        return $this->threshold('release_threshold', self::RELEASE_THRESHOLD);
    }

    /**
     * The threshold of `attempts_not_capped`; null when the configuration
     * turns the signal off with false.
     */
    public function attemptThreshold(): ?int
    {
        return $this->threshold('attempt_threshold', self::ATTEMPT_THRESHOLD);
    }

    /**
     * Every signal that holds for the row at $now, in SIGNALS order: `[{signal,
     * facts}]`; [] for a row that is not pending or reserved. $leases is the
     * lease map of the list read (WorkerLiveness::leases()), else read here.
     *
     * @param  array<string, int>|null  $leases
     * @return list<array{signal: string, facts: array<string, int|float|string>}>
     */
    public function of(object $row, float $now, ?array $leases = null): array
    {
        $signals = [];

        foreach (self::SIGNALS as $signal) {
            $facts = $this->facts($signal, $row, $now, $leases);

            if ($facts !== null) {
                $signals[] = ['signal' => $signal, 'facts' => $facts];
            }
        }

        return $signals;
    }

    /**
     * The facts of $signal when it holds for the row at $now, else null.
     * $leases as for of().
     *
     * @param  array<string, int>|null  $leases
     * @return array<string, int|float|string>|null
     */
    public function facts(string $signal, object $row, float $now, ?array $leases = null): ?array
    {
        $status = $row->status ?? null;

        if (! in_array($status, self::ACTIVE, true)) {
            return null;
        }

        switch ($signal) {
            case self::RESERVATION_EXPIRED:
                $leases ??= $status === 'reserved' ? $this->liveness->leases() : [];
                $retryAfter = $status === 'reserved' ? $this->liveness->retryAfter((string) ($row->connection ?? ''), $leases) : null;
                $lease = $retryAfter === null ? null : $this->liveness->leaseExpiresAt($row, $leases);

                return $retryAfter !== null && $lease !== null && $lease <= $now
                    ? ['lease_expires_at' => $lease, 'retry_after' => $retryAfter]
                    : null;
            case self::OUTCOME_NOT_OBSERVED:
                $gap = self::int($row->observed_gap ?? null);

                return $gap > 0 ? ['gap' => $gap] : null;
            case self::REPEATED_RELEASES:
                $releases = self::int($row->releases ?? null);

                $threshold = $this->releaseThreshold();

                return $threshold !== null && $releases >= $threshold ? ['releases' => $releases] : null;
            case self::ATTEMPTS_NOT_CAPPED:
                $attempts = self::int($row->attempts ?? null);
                $threshold = $this->attemptThreshold();

                if ($threshold === null || $attempts < $threshold) {
                    return null;
                }

                $rule = EnforcedRule::forRow($row, $now);

                return match ($rule['kind']) {
                    EnforcedRule::NONE => ['attempts' => $attempts, 'rule' => EnforcedRule::NONE],
                    EnforcedRule::DEADLINE => ['attempts' => $attempts, 'rule' => EnforcedRule::DEADLINE, 'deadline' => (int) $rule['deadline']],
                    default => null,
                };
            default:
                throw new \InvalidArgumentException("Not a stuck signal: {$signal}.");
        }
    }

    private function threshold(string $key, int $default): ?int
    {
        $value = $this->config->get("orrery.stuck.{$key}", $default);

        if ($value === false) {
            // The signal is off.
            return null;
        }

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d{0,17}$/D', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
