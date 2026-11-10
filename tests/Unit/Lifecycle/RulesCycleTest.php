<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Lifecycle;

use Justrau\Orrery\Lifecycle\Decision;
use Justrau\Orrery\Lifecycle\Rules;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cycle rules: O, R5, the ambiguity and the
 * unmatched observations. Swap on, unless the case says otherwise.
 */
final class RulesCycleTest extends TestCase
{
    use BuildsObservations;

    private const UUID = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

    private const G3 = 'cccccccccccccccccccccccccccccccc';

    /**
     * Rejects: an R5 without the id-order condition, or a new cycle from an unknown generation at n >= 2;
     * and a swap-off outcome rule that starts a cycle without a requested native retry, for an older or the
     * current job id, at n >= 2, on a row that is not failed, with a request older than the failure, or with the swap on.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $observation  [kind, jobId, attempt, fields?, payload overrides?]
     * @param  array<string, mixed>  $set
     * @param  list<string>  $effects
     */
    #[DataProvider('cases')]
    public function test_cycle_rule_table(array $row, array $observation, ?int $olderCycle, string $rule, array $set, array $effects, ?bool $same = null): void
    {
        [$kind, $jobId, $attempt] = $observation;
        $o = self::observation($kind, self::UUID, ['jobId' => $jobId, 'attempt' => $attempt] + ($observation[3] ?? []), $observation[4] ?? []);
        $state = self::rowState($row);

        if ($same !== null) {
            self::assertSame($same, Rules::sameGeneration($state, $o), 'S');
        }

        $decision = Rules::decide($state, $o, $olderCycle);

        self::assertSame($rule, $decision->rule, 'rule');
        RuleNamesTest::assertRuleName($decision->rule);

        foreach ($set as $column => $value) {
            self::assertArrayHasKey($column, $decision->set, "the set writes {$column}");
            self::assertSame($value, $decision->set[$column], "the value of {$column}");
        }

        $actual = $decision->effects;
        sort($actual);
        sort($effects);
        self::assertSame($effects, $actual, 'effects');

        if (in_array($rule, ['rejected', 'olderCycle'], true)) {
            self::assertSame([], $decision->set, 'the row does not change');
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<int, mixed>, 2: ?int, 3: string, 4: array<string, mixed>, 5: list<string>, 6?: ?bool}>
     */
    public static function cases(): array
    {
        $t = Clock::toDecimal(self::T);
        $requested = Clock::toDecimal(self::T - 30);
        $stale = Clock::toDecimal(self::T - 90);
        // Cycle 2 (generation G2) is current; cycle 1 (G1) has a retained summary.
        $cycleTwo = ['status' => 'reserved', 'cycle' => 2, 'cycle_gen' => self::G2, 'job_id' => 30, 'cycle_first_job_id' => 30, 'attempts' => 1];
        // Cycle 1 failed at attempt 1 of job 10.
        $failed = [
            'status' => 'failed', 'cycle' => 1, 'cycle_gen' => self::G1, 'job_id' => 10, 'cycle_first_job_id' => 10, 'attempts' => 1,
            'exceptions' => 1, 'last_exception_attempt' => 1, 'observed_attempts' => 1, 'last_outcome_attempt' => 1, 'retried_count' => 0,
            'failed_at' => Clock::toDecimal(self::T - 60),
        ];
        $reset = [
            'prev_job_id' => null, 'job_id_unresolved' => false, 'bulk_floor_id' => null, 'absent_since' => null,
            'exceptions' => 0, 'releases' => 0, 'timeouts' => 0, 'reclaims' => 0, 'observed_attempts' => 0, 'late_outcomes' => 0,
            'last_outcome_attempt' => 0, 'last_exception_attempt' => 0, 'last_timeout_attempt' => 0, 'observed_gap' => 0,
        ];

        return [
            'O: an outcome of a summarised generation' => [$cycleTwo, ['complete', 10, 4, [], ['orreryGen' => self::G1]], 1, 'olderCycle', [], ['summary']],
            'O: an exception of a summarised generation' => [$cycleTwo, ['exception', 10, 4, [], ['orreryGen' => self::G1]], 1, 'olderCycle', [], ['summary']],
            'O: a reservation of a summarised generation' => [$cycleTwo, ['reserve', 10, 4, [], ['orreryGen' => self::G1]], 1, 'rejected', [], []],
            'O needs the swap on' => [$cycleTwo + ['prev_job_id' => 29], ['complete', 10, 4, ['instrumented' => false], ['orreryGen' => self::G1]], 1, 'rejected', [], ['unmatched']],
            'an unknown generation is unmatched' => [$cycleTwo, ['complete', 10, 4, [], ['orreryGen' => self::G3]], null, 'rejected', [], ['unmatched']],
            'R5 swap on: n 1, a newer j, a known g' => [$failed, ['reserve', 20, 1, [], ['orreryGen' => self::G2]], null, 'nativeRetry',
                ['status' => 'reserved', 'cycle' => 2, 'cycle_gen' => self::G2, 'cycle_first_job_id' => 20, 'cycle_source' => 'native', 'cycle_started_at' => $t,
                    'job_id' => 20, 'attempts' => 1, 'retried_count' => 1, 'retried_at' => $t, 'finished_at' => null] + $reset, ['cycle_start'], false],
            'R5 with an older j' => [$failed, ['reserve', 5, 1, [], ['orreryGen' => self::G2]], null, 'rejected', [], ['unmatched'], false],
            'R5 with the current j' => [$failed, ['reserve', 10, 1, [], ['orreryGen' => self::G2]], null, 'rejected', [], ['unmatched'], false],
            'R5 with n 2 and an unknown g (the ambiguity)' => [$failed, ['reserve', 20, 2, [], ['orreryGen' => self::G2]], null, 'rejected', [], ['unmatched'], false],
            'R5 swap on without a generation' => [$failed, ['reserve', 20, 1, [], ['orreryGen' => null]], null, 'rejected', [], ['unmatched'], false],
            'R5 is not O' => [$failed + ['cycle' => 3, 'cycle_gen' => self::G3], ['reserve', 20, 1, [], ['orreryGen' => self::G2]], 2, 'rejected', [], [], false],
            'swap off: n 1, a newer j' => [$failed, ['reserve', 20, 1, ['instrumented' => false]], null, 'nativeRetry',
                ['cycle' => 2, 'cycle_gen' => self::G1, 'cycle_first_job_id' => 20, 'cycle_source' => 'native', 'job_id' => 20, 'attempts' => 1, 'retried_count' => 1] + $reset, ['cycle_start'], false],
            // The native retry at the outcome: only outcomes of a foreign queue are observed.
            'swap off outcome: a native retry completes in a new cycle' => [$failed + ['retry_requested_at' => $requested], ['complete', 20, 1, ['instrumented' => false]], null, 'complete',
                ['status' => 'completed', 'cycle' => 2, 'cycle_gen' => self::G1, 'cycle_first_job_id' => 20, 'cycle_source' => 'native', 'cycle_started_at' => $t,
                    'job_id' => 20, 'attempts' => 1, 'retried_count' => 1, 'retried_at' => $t, 'finished_at' => $t, 'reserved_at' => null,
                    'observed_attempts' => 1, 'last_outcome_attempt' => 1, 'observed_gap' => 0] + array_diff_key($reset, array_flip(['observed_attempts', 'last_outcome_attempt', 'observed_gap'])),
                ['cycle_start', 'history:completed'], false],
            'swap off outcome: a native retry fails in a new cycle' => [$failed + ['retry_requested_at' => $requested], ['fail', 20, 1, ['instrumented' => false]], null, 'fail',
                ['status' => 'failed', 'cycle' => 2, 'cycle_first_job_id' => 20, 'cycle_source' => 'native', 'job_id' => 20, 'attempts' => 1, 'retried_count' => 1,
                    'failed_at' => $t, 'finished_at' => $t, 'absent_since' => null, 'observed_attempts' => 1, 'last_outcome_attempt' => 1, 'exceptions' => 0],
                ['cycle_start', 'history:failed', 'occurrence'], false],
            'swap off outcome: no native retry was requested' => [$failed, ['complete', 20, 1, ['instrumented' => false]], null, 'rejected', [], ['unmatched'], false],
            'swap off outcome: an older j' => [$failed + ['retry_requested_at' => $requested], ['complete', 5, 1, ['instrumented' => false]], null, 'rejected', [], ['unmatched'], false],
            'swap off outcome: the current j' => [$failed + ['retry_requested_at' => $requested], ['fail', 10, 1, ['instrumented' => false]], null, 'rejected', [], [], true],
            'swap off outcome: n 2' => [$failed + ['retry_requested_at' => $requested], ['complete', 20, 2, ['instrumented' => false]], null, Decision::ACCOUNTED,
                ['observed_attempts' => 2, 'last_outcome_attempt' => 2], [], true],
            'swap off outcome: a request older than the failure (stale)' => [$failed + ['retry_requested_at' => $stale], ['complete', 20, 1, ['instrumented' => false]], null, 'rejected', [], ['unmatched'], false],
            'swap off outcome: n 0' => [$failed + ['retry_requested_at' => $requested], ['complete', 20, 0, ['instrumented' => false]], null, 'rejected', [], ['unmatched'], false],
            'swap off outcome: a row that is not failed' => [['status' => 'completed', 'job_id' => 10, 'cycle_first_job_id' => 10, 'attempts' => 1, 'retry_requested_at' => $requested],
                ['complete', 20, 1, ['instrumented' => false]], null, 'rejected', [], ['unmatched'], false],
            'swap on outcome: R5 decides at the reservation, not here' => [$failed + ['retry_requested_at' => $requested], ['complete', 20, 1, [], ['orreryGen' => self::G2]], null, 'rejected', [], ['unmatched'], false],
            'swap off: n 2, a newer j is S (the ambiguity on a failed row)' => [$failed, ['reserve', 20, 2, ['instrumented' => false]], null, 'rejected', [], ['unmatched'], true],
            'swap off: n 2, a newer j on a pending row is a release row' => [['status' => 'pending', 'job_id' => 10, 'cycle_first_job_id' => 10, 'attempts' => 1], ['reserve', 20, 2, ['instrumented' => false]], null, 'reserveReplacement',
                ['job_id' => 20, 'attempts' => 2, 'prev_job_id' => 10], [], true],
        ];
    }

    /**
     * Rejects: a row-creation rule that takes orreryRetry.cycle from any payload (a republish that kept a stale
     * orreryRetry), or that never takes it: R7, C3 and F1 without a row start cycle 3 with an inferred retry count
     * only when orreryRetry.operation is the observation's own generation.
     */
    #[DataProvider('retryCycles')]
    public function test_rowless_observation_takes_the_retry_cycle_only_for_its_own_operation(string $kind, string $rule, bool $own): void
    {
        $operation = str_repeat('a', 32);
        $fields = ['jobId' => 40, 'attempt' => 1] + ($kind === 'fail' ? ['exception' => new \RuntimeException('again')] : []);
        $payload = ['orreryGen' => $own ? $operation : str_repeat('b', 32), 'orreryRetry' => ['cycle' => 3, 'operation' => $operation]];

        $decision = Rules::decide(null, self::observation($kind, self::UUID, $fields, $payload));

        self::assertSame($rule, $decision->rule);
        RuleNamesTest::assertRuleName($decision->rule);
        self::assertSame($own ? 3 : 1, $decision->set['cycle']);
        self::assertSame($own ? 2 : 0, $decision->set['retried_count']);
        self::assertSame($own, $decision->set['retried_count_inferred']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function retryCycles(): array
    {
        return [
            'R7 of its own operation' => ['reserve', 'reserveWithoutRow', true],
            'C3 of its own operation' => ['complete', 'completeWithoutRow', true],
            'F1 of its own operation' => ['fail', 'failWithoutRow', true],
            'R7 of another operation' => ['reserve', 'reserveWithoutRow', false],
            'C3 of another operation' => ['complete', 'completeWithoutRow', false],
            'F1 of another operation' => ['fail', 'failWithoutRow', false],
        ];
    }
}
