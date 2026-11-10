<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Lifecycle;

use Justrau\Orrery\Lifecycle\Decision;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Lifecycle\Rules;
use Justrau\Orrery\Runtime\WorkerEvidence;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The rule table.
 * Swap on, unless the case says otherwise.
 */
final class RulesTest extends TestCase
{
    use BuildsObservations;

    private const UUID = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

    private const W = 'worker-host:4242:1789990000';

    /**
     * Rejects: comparing ids when both generations are known (cases 25 and 26), accepting a fresh swap-off publication as S (case 23), a worker on a workerless reservation (cases 33-35), and a row-creation path without its tag rows (cases 36-38).
     *
     * @param  array<string, mixed>|null  $row
     * @param  array<string, mixed>  $observation
     * @param  array<string, mixed>  $set
     * @param  list<string>  $effects
     * @param  list<string>  $absent  columns that the set must not contain
     */
    #[DataProvider('cases')]
    public function test_rule_table(?array $row, array $observation, string $rule, array $set, array $effects, array $absent = []): void
    {
        $decision = $this->decide($row, $observation);

        self::assertSame($rule, $decision->rule, 'rule');
        RuleNamesTest::assertRuleName($decision->rule);

        foreach ($set as $column => $value) {
            self::assertArrayHasKey($column, $decision->set, "the set writes {$column}");
            self::assertSame($value, $decision->set[$column], "the value of {$column}");
        }

        foreach ($absent as $column) {
            self::assertArrayNotHasKey($column, $decision->set, "the set does not write {$column}");
        }

        $actual = $decision->effects;
        sort($actual);
        sort($effects);
        self::assertSame($effects, $actual, 'effects');

        if ($rule === 'rejected') {
            self::assertSame([], $decision->set, 'a rejected observation changes no column');
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>, 2: string, 3: array<string, mixed>, 4: list<string>, 5?: list<string>}>
     */
    public static function cases(): array
    {
        $t = Clock::toDecimal(self::T);
        $committed = 1790000001.5;
        $verified = ['workerId' => self::W, 'worker' => [3, 60]];
        $evidence = ['reserved_by' => self::W, 'worker_evidence_attempt' => 1, 'worker_tries' => 3, 'worker_timeout' => 60];
        $noWorker = ['reserved_by' => null, 'worker_tries' => null, 'worker_timeout' => null, 'worker_evidence_attempt' => null];
        $retry = ['orreryRetry' => ['cycle' => 3, 'operation' => self::G1]];

        return [
            '1 no row, push' => [null, ['push', 10, null], 'push',
                ['status' => 'pending', 'cycle' => 1, 'cycle_gen' => self::G1, 'job_id' => 10, 'cycle_first_job_id' => 10, 'cycle_source' => 'push', 'activity_at' => $t], []],
            '2 pending, same push' => [['status' => 'pending', 'job_id' => 10], ['push', 10, null], 'rejected', [], []],
            '3 failed, late initial push' => [['status' => 'failed', 'job_id' => 10], ['push', 20, null, [], ['orreryGen' => self::G2]], 'rejected', [], []],
            '4 pending, reserve' => [['status' => 'pending', 'job_id' => 10], ['reserve', 10, 1], 'reserve',
                ['status' => 'reserved', 'job_id' => 10, 'attempts' => 1, 'reserved_at' => $t, 'activity_at' => $t], []],
            '5 pending bulk, reserve' => [['status' => 'pending', 'job_id' => null, 'job_id_unresolved' => 1], ['reserve', 12, 1], 'reserve',
                ['job_id' => 12, 'cycle_first_job_id' => 12, 'job_id_unresolved' => false, 'attempts' => 1], []],
            '6 reserved, reclaim' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['reserve', 10, 2], 'reclaim',
                ['status' => 'reserved', 'attempts' => 2, 'reclaims' => 1], []],
            '7 reserved, same attempt' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 2], ['reserve', 10, 2], 'rejected', [], []],
            '8 reserved, replacement row' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['reserve', 11, 2], 'reserveReplacement',
                ['prev_job_id' => 10, 'job_id' => 11, 'attempts' => 2], []],
            '9 unknown, replacement row' => [['status' => 'unknown', 'job_id' => 10, 'attempts' => 1, 'unknown_since' => '1789999000.000000'], ['reserve', 11, 2], 'reserveReplacement',
                ['status' => 'reserved', 'prev_job_id' => 10, 'job_id' => 11, 'unknown_since' => null, 'unknown_reason' => null], []],
            '10 unknown, same row' => [['status' => 'unknown', 'job_id' => 10, 'attempts' => 1, 'unknown_since' => '1789999000.000000'], ['reserve', 10, 2], 'reserveUnknown',
                ['status' => 'reserved', 'attempts' => 2, 'unknown_since' => null, 'unknown_reason' => null], []],
            '11 no row, reserve of a package retry' => [null, ['reserve', 5, 1, [], $retry], 'reserveWithoutRow',
                ['status' => 'reserved', 'cycle' => 3, 'retried_count' => 2, 'retried_count_inferred' => true, 'job_id' => 5, 'attempts' => 1], []],
            '12 reserved, release' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['release', 10, 1, ['newJobId' => 11]], 'release',
                ['status' => 'pending', 'prev_job_id' => 10, 'job_id' => 11, 'releases' => 1], []],
            // The rejected release is the first counted outcome (attempt 1 ended); the row keeps its reservation.
            '13 reserved, late release after a reclaim' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 2], ['release', 10, 1, ['newJobId' => 11]], 'accounted',
                ['observed_attempts' => 1, 'last_outcome_attempt' => 1, 'observed_gap' => 0], [], ['status', 'job_id', 'releases', 'prev_job_id']],
            '14 pending, exception of the previous row' => [['status' => 'pending', 'job_id' => 11, 'prev_job_id' => 10, 'last_exception_attempt' => 0], ['exception', 10, 1], 'exception',
                ['exceptions' => 1, 'last_exception_attempt' => 1], ['history:exception']],
            '15 pending, repeated exception' => [['status' => 'pending', 'job_id' => 11, 'last_exception_attempt' => 1, 'exceptions' => 1, 'last_outcome_attempt' => 1, 'observed_attempts' => 1], ['exception', 11, 1], 'exception', [], []],
            '16 reserved, exception of another generation' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['exception', 10, 1, [], ['orreryGen' => self::G2]], 'rejected', [], ['unmatched']],
            '17 reserved, complete' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['complete', 10, 1], 'complete',
                ['status' => 'completed', 'finished_at' => $t], []],
            '18 pending after a release, complete' => [['status' => 'pending', 'job_id' => 11, 'attempts' => 2], ['complete', 11, 2], 'complete', ['status' => 'completed'], []],
            '19 reserved, late complete' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 2], ['complete', 10, 1], 'accounted',
                ['observed_attempts' => 1, 'last_outcome_attempt' => 1, 'observed_gap' => 0], [], ['status', 'finished_at']],
            '20 no row, complete' => [null, ['complete', 10, 1], 'completeWithoutRow', ['status' => 'completed', 'finished_at' => $t], []],
            '21 reserved, fail' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['fail', 10, 1, ['exception' => true]], 'fail',
                ['status' => 'failed', 'finished_at' => $t, 'failed_at' => $t, 'exception_class' => RuntimeException::class, 'exception_message' => 'boom'], ['history:failed', 'occurrence']],
            '22 no row, fail' => [null, ['fail', 10, 1, ['exception' => true]], 'failWithoutRow', ['status' => 'failed', 'failed_at' => $t], ['history:failed', 'occurrence']],
            '23 swap off, fresh publication' => [['status' => 'pending', 'job_id' => 10, 'cycle_first_job_id' => 10], ['reserve', 15, 1, ['instrumented' => false]], 'rejected', [], ['unmatched']],
            '24 swap off, replacement row' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1, 'cycle_first_job_id' => 10], ['reserve', 15, 2, ['instrumented' => false]], 'reserveReplacement',
                ['prev_job_id' => 10, 'job_id' => 15, 'attempts' => 2], []],
            '25 missing generation, filled by an id match' => [['status' => 'pending', 'job_id' => 10, 'cycle_gen' => null], ['reserve', 10, 1], 'reserve',
                ['cycle_gen' => self::G1, 'job_id' => 10], ['fill_generation']],
            '26 reserved g2, complete g1 with a reused id' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1, 'cycle_gen' => self::G2], ['complete', 10, 1], 'rejected', [], ['unmatched']],
            '30 R8 fills the worker' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['reserve', 10, 1, $verified], 'fillWorker',
                $evidence, [], ['attempts', 'reserved_at', 'reclaims', 'activity_at', 'status']],
            '31 duplicate verified reservation' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1, 'reserved_by' => self::W, 'worker_evidence_attempt' => 1], ['reserve', 10, 1, $verified], 'rejected', [], []],
            '32 workerless duplicate keeps the worker' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1, 'reserved_by' => self::W, 'worker_evidence_attempt' => 1], ['reserve', 10, 1, ['workerless' => [1, $committed]]], 'rejected', [], []],
            '33 workerless reclaim clears the worker' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1, 'reserved_by' => self::W, 'worker_evidence_attempt' => 1], ['reserve', 10, 2, $verified + ['workerless' => [2, $committed]]], 'reclaim',
                $noWorker + ['attempts' => 2, 'reclaims' => 1], []],
            '34 workerless replacement clears the worker' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1, 'reserved_by' => self::W, 'worker_evidence_attempt' => 1], ['reserve', 11, 2, $verified + ['workerless' => [2, $committed]]], 'reserveReplacement',
                $noWorker + ['job_id' => 11, 'prev_job_id' => 10], []],
            '35 workerless reservation names no worker' => [['status' => 'pending', 'job_id' => 10], ['reserve', 10, 1, $verified + ['workerless' => [2, $committed]]], 'reserve',
                $noWorker + ['attempts' => 2, 'reserved_at' => Clock::toDecimal($committed)], []],
            '36 R7 with tags' => [null, ['reserve', 5, 1, [], ['tags' => ['a', 'b']]], 'reserveWithoutRow', ['status' => 'reserved', 'tags_truncated' => false], ['tags']],
            '37 C3 with tags' => [null, ['complete', 5, 1, [], ['tags' => ['a']]], 'completeWithoutRow', ['status' => 'completed'], ['tags']],
            '38 F1 insert with tags' => [null, ['fail', 5, 1, [], ['tags' => ['a']]], 'failWithoutRow', ['status' => 'failed'], ['failed_tags', 'history:failed', 'occurrence', 'tags']],
            // The reclaim of attempt 2 was not observed (a dropped observation).
            '39 reserved, complete of a higher attempt' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['complete', 10, 2], 'complete',
                ['status' => 'completed', 'attempts' => 2], []],
            '40 reserved, fail of a higher attempt' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['fail', 10, 2, ['exception' => true]], 'fail',
                ['status' => 'failed', 'attempts' => 2], ['history:failed', 'occurrence']],
            // A release whose reclaim or reservation was not observed moves the row off the deleted job id.
            '41 reserved, release of a higher attempt' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['release', 10, 2, ['newJobId' => 11]], 'release',
                ['status' => 'pending', 'prev_job_id' => 10, 'job_id' => 11, 'releases' => 1, 'attempts' => 2], []],
            '42 pending, release of an unobserved reservation' => [['status' => 'pending', 'job_id' => 10, 'attempts' => 0], ['release', 10, 1, ['newJobId' => 11]], 'release',
                ['status' => 'pending', 'prev_job_id' => 10, 'job_id' => 11, 'releases' => 1], [], ['attempts']],
            '44 pending after a release, release of the old id' => [['status' => 'pending', 'job_id' => 11, 'prev_job_id' => 10, 'attempts' => 1], ['release', 10, 1, ['newJobId' => 12]], 'accounted',
                ['observed_attempts' => 1, 'last_outcome_attempt' => 1], [], ['status', 'job_id', 'releases', 'prev_job_id']],
        ];
    }

    /**
     * Rejects: U1 by uuid only, or U1 of a row whose bulk id is unresolved (cases 27-29 of the rule table).
     *
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('unknownCases')]
    public function test_mark_unknown_rule(array $row, int $jobId, int $cycle, string $rule): void
    {
        $decision = Rules::markUnknown(self::rowState($row), $jobId, $cycle, 'absent', self::T);

        self::assertSame($rule, $decision->rule);
        RuleNamesTest::assertRuleName($decision->rule);

        if ($rule === 'markUnknown') {
            self::assertSame('unknown', $decision->set['status']);
            self::assertSame('absent', $decision->set['unknown_reason']);
            self::assertSame(Clock::toDecimal(self::T), $decision->set['unknown_since']);
            self::assertSame(Clock::toDecimal(self::T), $decision->set['finished_at']);
            self::assertSame(['status' => $row['status'], 'job_id' => $jobId, 'cycle' => $cycle, 'job_id_unresolved' => false], array_intersect_key($decision->guard, array_flip(['status', 'job_id', 'cycle', 'job_id_unresolved'])));
        } else {
            self::assertSame([], $decision->set);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: int, 3: string}>
     */
    public static function unknownCases(): array
    {
        return [
            '27 pending, the inspected id and cycle' => [['status' => 'pending', 'job_id' => 10, 'cycle' => 1], 10, 1, 'markUnknown'],
            '28 pending, unresolved bulk id' => [['status' => 'pending', 'job_id' => 10, 'job_id_unresolved' => 1], 10, 1, 'rejected'],
            '29 reserved, another cycle' => [['status' => 'reserved', 'job_id' => 10, 'cycle' => 2], 10, 1, 'rejected'],
        ];
    }

    /**
     * Rejects: a sameGeneration() that compares ids when both generations are known, or generations with the swap off.
     */
    public function test_same_generation_follows_the_swap_mode(): void
    {
        $row = self::rowState(['status' => 'reserved', 'job_id' => 10, 'attempts' => 1, 'cycle_first_job_id' => 10]);

        self::assertTrue(Rules::sameGeneration($row, self::observation('complete', self::UUID, ['jobId' => 99, 'attempt' => 1])), 'the swap on compares only the generations');
        self::assertFalse(Rules::sameGeneration($row, self::observation('complete', self::UUID, ['jobId' => 10, 'attempt' => 1], ['orreryGen' => self::G2])), 'another generation at the same id');
        self::assertTrue(Rules::sameGeneration($row, self::observation('complete', self::UUID, ['jobId' => 10, 'attempt' => 1, 'instrumented' => false], ['orreryGen' => self::G2])), 'the swap off ignores generations: j = job_id');
        self::assertFalse(Rules::sameGeneration($row, self::observation('complete', self::UUID, ['jobId' => 12, 'attempt' => 1, 'instrumented' => false])), 'the swap off: a higher id at n = 1 is a fresh publication');
        self::assertTrue(Rules::sameGeneration($row, self::observation('complete', self::UUID, ['jobId' => 12, 'attempt' => 2, 'instrumented' => false])), 'the swap off: a higher id at n >= 2 is a release row');
        self::assertTrue(Rules::sameGeneration($row, self::observation('complete', self::UUID, ['jobId' => 10, 'attempt' => 1], ['orreryGen' => null])), 'an unknown generation uses the id rules');
    }

    /**
     * Rejects: an observation from a payload without a canonical uuid, or a uuid that keeps its upper case.
     */
    public function test_from_payload_needs_a_canonical_uuid(): void
    {
        self::assertNull(Observation::fromPayload('push', '{"displayName":"x"}', 'database', 'default', ['jobId' => 1]));
        self::assertNull(Observation::fromPayload('push', '{"uuid":"not-a-uuid"}', 'database', 'default', ['jobId' => 1]));
        self::assertNull(Observation::fromPayload('push', 'not json', 'database', 'default', ['jobId' => 1]));

        $fromId = Observation::fromPayload('push', '{"id":"9B1DEB4D-3B7D-4BAD-9BDD-2B0D7B3DCB6D"}', 'database', 'default', ['jobId' => 1]);
        self::assertNotNull($fromId);
        self::assertSame(self::UUID, $fromId->uuid);
        self::assertNull($fromId->generation);

        $retry = self::observation('reserve', self::UUID, ['jobId' => 1, 'attempt' => 1], ['orreryRetry' => ['cycle' => 3, 'operation' => self::G1]]);
        self::assertSame(self::G1, $retry->retryOperation);
        self::assertSame(3, $retry->retryCycle);
        self::assertSame(self::G1, $retry->generation);
        self::assertTrue($retry->isOperationRetry());
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @param  array<string, mixed>  $spec  [kind, jobId, attempt, fields?, payload overrides?]
     */
    private function decide(?array $row, array $spec): Decision
    {
        [$kind, $jobId, $attempt] = $spec;
        $fields = $spec[3] ?? [];
        $payload = $spec[4] ?? [];

        $workerless = $fields['workerless'] ?? null;
        unset($fields['workerless']);

        if (isset($fields['worker']) && is_array($fields['worker'])) {
            $fields['worker'] = new WorkerEvidence($fields['worker'][0], $fields['worker'][1], 'database', 'default', false);
            $fields['inWorker'] = true;
        }

        if (($fields['exception'] ?? null) === true) {
            $fields['exception'] = new RuntimeException('boom');
        }

        $observation = self::observation($kind, self::UUID, ['jobId' => $jobId, 'attempt' => $attempt] + $fields, $payload);

        if ($workerless !== null) {
            $observation = self::workerless($observation, $workerless[0], $workerless[1]);
        }

        return Rules::decide($row === null ? null : self::rowState($row), $observation);
    }

    /**
     * Rejects: a limit without an upper bound (a `$tries = PHP_INT_MAX` job overflows the int4 column on Postgres,
     * so the push is never mirrored) and one that drops a numeric string that Laravel still enforces
     * (`"maxTries":"5"`); a negative, fractional or other value stays null.
     */
    public function test_limit_columns_accept_numeric_strings_and_clamp(): void
    {
        self::assertSame(
            ['max_tries' => 5, 'retry_until' => PHP_INT_MAX, 'max_exceptions' => 2147483647, 'timeout' => 2147483647, 'fail_on_timeout' => false],
            Rules::limitColumns(['maxTries' => '5', 'retryUntil' => PHP_INT_MAX, 'maxExceptions' => PHP_INT_MAX, 'timeout' => '99999999999999999999999']),
        );
        self::assertSame(
            ['max_tries' => null, 'retry_until' => null, 'max_exceptions' => null, 'timeout' => null, 'fail_on_timeout' => false],
            Rules::limitColumns(['maxTries' => -1, 'retryUntil' => '1.5', 'maxExceptions' => '-3', 'timeout' => " 5\n"]),
        );
        self::assertSame(2147483647, Rules::limitColumns(['maxTries' => 2147483647])['max_tries']);
        self::assertSame(0, Rules::limitColumns(['timeout' => 0])['timeout']);
    }
}
