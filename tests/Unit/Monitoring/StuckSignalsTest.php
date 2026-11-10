<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Monitoring;

use Illuminate\Config\Repository;
use Justrau\Orrery\Monitoring\StuckSignals;
use Justrau\Orrery\Workers\WorkerLiveness;
use PHPUnit\Framework\TestCase;

/**
 * The stuck signals of one row: a pure
 * evaluator over the row and the configuration.
 */
final class StuckSignalsTest extends TestCase
{
    private const NOW = 1_790_000_000.0;

    /**
     * A list read passes one lease map (WorkerLiveness::leases()) to every
     * row: the expired-reservation facts then come from the map.
     *
     * Rejects: facts that ignore the given map and walk the queue
     * configuration again per row.
     */
    public function test_reservation_expired_uses_the_given_lease_map(): void
    {
        $signals = $this->signals(['queue.connections.database' => ['driver' => 'database', 'retry_after' => 300]]);
        $row = $this->row(['reserved_at' => self::NOW - 100]);

        self::assertNull($signals->facts(StuckSignals::RESERVATION_EXPIRED, $row, self::NOW));
        self::assertSame(['lease_expires_at' => self::NOW - 50, 'retry_after' => 50], $signals->facts(StuckSignals::RESERVATION_EXPIRED, $row, self::NOW, ['database' => 50]));
        self::assertNull($signals->facts(StuckSignals::RESERVATION_EXPIRED, $row, self::NOW, []), 'not in the map: unknown lease');
        self::assertSame([StuckSignals::RESERVATION_EXPIRED], array_column($signals->of($row, self::NOW, ['database' => 50]), 'signal'));
    }

    /**
     * Rejects: an expiry test with `<` instead of `<=` (Laravel reclaims at
     * reserved_at + retry_after), a fixed 60 s lease instead of the
     * connection's retry_after, and an expired reservation on a pending row
     * (its old reserved_at stays) or on a row without reserved_at.
     */
    public function test_reservation_expired_holds_from_the_lease_end_of_a_reserved_row(): void
    {
        $signals = $this->signals(['queue.connections.database' => ['driver' => 'database', 'retry_after' => 300]]);

        self::assertNull($signals->facts(StuckSignals::RESERVATION_EXPIRED, $this->row(['reserved_at' => self::NOW - 299.5]), self::NOW));
        self::assertSame(
            ['lease_expires_at' => self::NOW, 'retry_after' => 300],
            $signals->facts(StuckSignals::RESERVATION_EXPIRED, $this->row(['reserved_at' => self::NOW - 300]), self::NOW),
        );
        self::assertSame(
            ['lease_expires_at' => self::NOW - 100, 'retry_after' => 300],
            $signals->facts(StuckSignals::RESERVATION_EXPIRED, $this->row(['reserved_at' => (string) (self::NOW - 400)]), self::NOW),
            'a decimal column read as a string',
        );
        self::assertNull($signals->facts(StuckSignals::RESERVATION_EXPIRED, $this->row(['status' => 'pending', 'reserved_at' => self::NOW - 400]), self::NOW));
        self::assertNull($signals->facts(StuckSignals::RESERVATION_EXPIRED, $this->row(['reserved_at' => null]), self::NOW));
    }

    /**
     * Rejects: an expiry from the shortest retry_after of the connections on
     * the row's jobs table (a long job on its own long-lease
     * connection is not expired after another connection's short lease), and
     * an expiry claimed for a connection that is not a configured `database`
     * queue connection (its lease is unknown).
     */
    public function test_reservation_expired_uses_the_rows_own_lease_and_never_guesses_one(): void
    {
        $signals = $this->signals([
            'queue.connections.a' => ['driver' => 'database', 'retry_after' => 600],
            'queue.connections.b' => ['driver' => 'database', 'retry_after' => 60],
        ]);

        self::assertNull($signals->facts(StuckSignals::RESERVATION_EXPIRED, $this->row(['connection' => 'a', 'reserved_at' => self::NOW - 100]), self::NOW), 'inside the own lease of 600 s');
        self::assertSame(
            ['lease_expires_at' => self::NOW - 40, 'retry_after' => 600],
            $signals->facts(StuckSignals::RESERVATION_EXPIRED, $this->row(['connection' => 'a', 'reserved_at' => self::NOW - 640]), self::NOW),
        );
        self::assertSame(
            ['lease_expires_at' => self::NOW - 40, 'retry_after' => 60],
            $signals->facts(StuckSignals::RESERVATION_EXPIRED, $this->row(['connection' => 'b', 'reserved_at' => self::NOW - 100]), self::NOW),
        );
        self::assertNull($signals->facts(StuckSignals::RESERVATION_EXPIRED, $this->row(['connection' => 'gone', 'reserved_at' => self::NOW - 100_000]), self::NOW));
    }

    /**
     * Rejects: a gap recomputed in the reader instead of the stored column,
     * a gap signal at 0, and a gap signal on a finished row.
     */
    public function test_outcome_not_observed_reads_the_stored_gap_of_an_active_row(): void
    {
        $signals = $this->signals();

        self::assertNull($signals->facts(StuckSignals::OUTCOME_NOT_OBSERVED, $this->row(['observed_gap' => 0, 'attempts' => 5]), self::NOW));
        self::assertSame(['gap' => 2], $signals->facts(StuckSignals::OUTCOME_NOT_OBSERVED, $this->row(['observed_gap' => '2', 'attempts' => 2]), self::NOW));
        self::assertSame(['gap' => 1], $signals->facts(StuckSignals::OUTCOME_NOT_OBSERVED, $this->row(['status' => 'pending', 'observed_gap' => 1]), self::NOW));

        foreach (['completed', 'failed', 'retrying', 'unknown'] as $status) {
            self::assertSame([], $signals->of($this->row(['status' => $status, 'observed_gap' => 3, 'releases' => 30, 'attempts' => 30, 'max_tries' => 0]), self::NOW), "no signal on a {$status} row");
        }
    }

    /**
     * Rejects: a release threshold compared with `>`, a fixed threshold
     * that ignores `orrery.stuck.release_threshold`, and the attempts read
     * instead of the releases.
     */
    public function test_repeated_releases_holds_at_the_threshold(): void
    {
        $default = $this->signals();
        self::assertNull($default->facts(StuckSignals::REPEATED_RELEASES, $this->row(['releases' => 19, 'attempts' => 40]), self::NOW));
        self::assertSame(['releases' => 20], $default->facts(StuckSignals::REPEATED_RELEASES, $this->row(['status' => 'pending', 'releases' => 20, 'attempts' => 21]), self::NOW));

        $configured = $this->signals(['orrery.stuck.release_threshold' => 5]);
        self::assertSame(5, $configured->releaseThreshold());
        self::assertSame(['releases' => 5], $configured->facts(StuckSignals::REPEATED_RELEASES, $this->row(['releases' => 5, 'attempts' => 6]), self::NOW));
    }

    /**
     * Rejects: "attempts not capped" for an attempt limit, an unknown limit
     * or a passed deadline (Laravel fails the job at its next check), a
     * missing signal for a deadline that has not passed although the tries
     * are below the attempts (Laravel does not compare them then), and a
     * payload maxTries of 0 overridden by the worker's --tries.
     */
    public function test_attempts_not_capped_follows_the_enforced_rule(): void
    {
        $signals = $this->signals();
        $base = ['status' => 'pending', 'attempts' => 31, 'last_outcome_attempt' => 31];

        self::assertSame(['attempts' => 31, 'rule' => 'none'], $signals->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row(['max_tries' => 0] + $base), self::NOW));
        self::assertSame(
            ['attempts' => 31, 'rule' => 'none'],
            $signals->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row(['max_tries' => 0, 'worker_tries' => 1, 'worker_evidence_attempt' => 31] + $base), self::NOW),
            'the payload maxTries 0 wins over the worker --tries=1',
        );
        self::assertSame(
            ['attempts' => 31, 'rule' => 'deadline', 'deadline' => (int) self::NOW + 3600],
            $signals->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row(['max_tries' => 2, 'retry_until' => (int) self::NOW + 3600] + $base), self::NOW),
        );
        self::assertNull($signals->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row(['max_tries' => 100, 'retry_until' => (int) self::NOW - 1] + $base), self::NOW), 'a passed deadline');
        self::assertNull($signals->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row(['max_tries' => 50] + $base), self::NOW), 'an attempt limit');
        self::assertNull($signals->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row(['worker_tries' => 50, 'worker_evidence_attempt' => 31] + $base), self::NOW), "the worker's attempt limit");
        self::assertNull($signals->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row($base), self::NOW), 'an unknown limit');
        self::assertNull($signals->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row(['max_tries' => 0, 'attempts' => 19, 'last_outcome_attempt' => 19] + $base), self::NOW), 'below the threshold');

        $configured = $this->signals(['orrery.stuck.attempt_threshold' => 3]);
        self::assertSame(3, $configured->attemptThreshold());
        self::assertSame(['attempts' => 3, 'rule' => 'none'], $configured->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row(['max_tries' => 0, 'attempts' => 3]), self::NOW));
    }

    /**
     * Rejects: of() that returns only the first signal that holds, another
     * order than SIGNALS, and facts that differ from facts().
     */
    public function test_of_lists_every_signal_that_holds_in_order(): void
    {
        $signals = $this->signals(['queue.connections.database' => ['driver' => 'database', 'retry_after' => 60]]);
        $row = $this->row(['reserved_at' => self::NOW - 600, 'observed_gap' => 1, 'releases' => 25, 'attempts' => 27, 'max_tries' => 0]);

        self::assertSame([
            ['signal' => 'reservation_expired', 'facts' => ['lease_expires_at' => self::NOW - 540, 'retry_after' => 60]],
            ['signal' => 'outcome_not_observed', 'facts' => ['gap' => 1]],
            ['signal' => 'repeated_releases', 'facts' => ['releases' => 25]],
            ['signal' => 'attempts_not_capped', 'facts' => ['attempts' => 27, 'rule' => 'none']],
        ], $signals->of($row, self::NOW));
        self::assertSame([], $signals->of($this->row(['reserved_at' => self::NOW]), self::NOW));
    }

    /**
     * Rejects: a threshold signal that cannot be turned off, so jobs
     * throttled by RateLimited with retryUntil() raise two incidents each
     * after 20 releases. It would still pass a rule that also
     * reads the string "false" as off; only the boolean is asserted.
     */
    public function test_false_turns_a_threshold_signal_off(): void
    {
        $off = $this->signals(['orrery.stuck.release_threshold' => false, 'orrery.stuck.attempt_threshold' => false]);

        self::assertNull($off->releaseThreshold());
        self::assertNull($off->attemptThreshold());
        self::assertNull($off->facts(StuckSignals::REPEATED_RELEASES, $this->row(['releases' => 500]), self::NOW));
        self::assertNull($off->facts(StuckSignals::ATTEMPTS_NOT_CAPPED, $this->row(['attempts' => 500]), self::NOW));
        self::assertNotNull($this->signals([])->facts(StuckSignals::REPEATED_RELEASES, $this->row(['releases' => 500]), self::NOW));
    }

    /**
     * Rejects: an invalid threshold (0, negative, text, a float) taken as it
     * is (a 0 would flag every row), and a published configuration without
     * the `stuck` block that fails instead of taking the defaults.
     */
    public function test_invalid_or_missing_thresholds_take_the_defaults(): void
    {
        foreach ([0, -1, 'many', 2.5, null] as $invalid) {
            $signals = $this->signals(['orrery.stuck.release_threshold' => $invalid, 'orrery.stuck.attempt_threshold' => $invalid]);
            self::assertSame(20, $signals->releaseThreshold(), var_export($invalid, true));
            self::assertSame(20, $signals->attemptThreshold(), var_export($invalid, true));
        }

        $published = $this->signals(['orrery' => ['path' => 'orrery']]);
        self::assertSame(20, $published->releaseThreshold());
        self::assertSame(20, $published->attemptThreshold());
        self::assertSame(7, $this->signals(['orrery.stuck.release_threshold' => '7'])->releaseThreshold());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function row(array $values = []): object
    {
        return (object) ($values + [
            'status' => 'reserved',
            'connection' => 'database',
            'reserved_at' => self::NOW - 10,
            'reserved_by' => null,
            'attempts' => 1,
            'releases' => 0,
            'observed_gap' => 0,
            'last_outcome_attempt' => 0,
            'max_tries' => null,
            'retry_until' => null,
            'max_exceptions' => null,
            'fail_on_timeout' => false,
            'timeout' => null,
            'worker_tries' => null,
            'worker_timeout' => null,
            'worker_evidence_attempt' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function signals(array $values = []): StuckSignals
    {
        $config = new Repository;

        foreach ($values as $key => $value) {
            $config->set($key, $value);
        }

        return new StuckSignals($config, new WorkerLiveness($config));
    }
}
