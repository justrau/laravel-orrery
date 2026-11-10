<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Lifecycle;

use Justrau\Orrery\Lifecycle\EnforcedRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The enforced rule: Laravel's precedence of the
 * deadline over the tries value, and the per-attempt worker evidence.
 */
final class EnforcedRuleTest extends TestCase
{
    private const NOW = 1_790_000_000.75;

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: int|null, 3: int|null, 4: string}>
     */
    public static function cases(): array
    {
        $deadline = (int) floor(self::NOW);

        return [
            'a deadline in the future, tries 2' => [['max_tries' => 2, 'retry_until' => $deadline + 3600], 'deadline', $deadline + 3600, 2, 'payload'],
            'the deadline is this second' => [['max_tries' => 2, 'retry_until' => $deadline], 'deadline', $deadline, 2, 'payload'],
            'the deadline passed a second ago' => [['max_tries' => 100, 'retry_until' => $deadline - 1], 'deadline_passed', $deadline - 1, 100, 'payload'],
            'no deadline, tries 3' => [['max_tries' => 3], 'attempt_limit', null, 3, 'payload'],
            'no deadline, tries 0' => [['max_tries' => 0], 'none', null, 0, 'payload'],
            'no deadline, tries unknown' => [[], 'unknown', null, null, 'unknown'],
            'retry_until 0 is no deadline' => [['max_tries' => 4, 'retry_until' => 0], 'attempt_limit', null, 4, 'payload'],
            'the worker tries of the same attempt' => [['worker_tries' => 5, 'worker_evidence_attempt' => 2], 'attempt_limit', null, 5, 'worker'],
            'worker tries 0 with no payload value' => [['worker_tries' => 0, 'worker_evidence_attempt' => 2], 'none', null, 0, 'worker'],
            'the worker tries of an older attempt are unknown' => [['worker_tries' => 5, 'worker_evidence_attempt' => 1], 'unknown', null, null, 'unknown'],
            'a failure whose reservation was not observed names the later attempt' => [['attempts' => 1, 'last_outcome_attempt' => 2, 'worker_tries' => 5, 'worker_evidence_attempt' => 2], 'attempt_limit', null, 5, 'worker'],
            'the payload wins over the worker' => [['max_tries' => 0, 'worker_tries' => 1, 'worker_evidence_attempt' => 2], 'none', null, 0, 'payload'],
        ];
    }

    /**
     * Rejects: the attempt limit as the enforced rule while a deadline is set
     * (Laravel ignores the tries until it passes), a deadline compared with
     * the fractional time (Laravel compares whole seconds, so the deadline's
     * own second still runs), retry_until 0 taken as a deadline, worker
     * evidence of another attempt, the observed reservations alone as the
     * latest attempt, and the worker value over the payload's.
     *
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('cases')]
    public function test_the_rule_follows_laravels_precedence(array $row, string $kind, ?int $deadline, ?int $tries, string $source): void
    {
        $rule = EnforcedRule::forRow((object) ($row + ['attempts' => 2, 'last_outcome_attempt' => 1]), self::NOW);

        self::assertSame($kind, $rule['kind']);
        self::assertSame($deadline, $rule['deadline']);
        self::assertSame($tries, $rule['tries']);
        self::assertSame($source, $rule['tries_source']);
    }

    /**
     * Rejects: a timeout of 0 shown as unknown (0 is "no alarm"), a worker
     * timeout of another attempt, and other stops left out of a deadline
     * rule.
     */
    public function test_the_other_stops_and_the_effective_timeout(): void
    {
        $base = ['attempts' => 3, 'retry_until' => (int) self::NOW + 60, 'max_exceptions' => 2, 'fail_on_timeout' => true];

        self::assertSame(
            ['max_exceptions' => 2, 'fail_on_timeout' => true, 'timeout' => 0, 'timeout_source' => 'payload'],
            EnforcedRule::forRow((object) ($base + ['timeout' => 0, 'worker_timeout' => 30, 'worker_evidence_attempt' => 3]), self::NOW)['other_stops'],
        );
        self::assertSame(
            ['max_exceptions' => 2, 'fail_on_timeout' => true, 'timeout' => 30, 'timeout_source' => 'worker'],
            EnforcedRule::forRow((object) ($base + ['worker_timeout' => 30, 'worker_evidence_attempt' => 3]), self::NOW)['other_stops'],
        );
        self::assertSame(
            ['max_exceptions' => 2, 'fail_on_timeout' => true, 'timeout' => null, 'timeout_source' => 'unknown'],
            EnforcedRule::forRow((object) ($base + ['worker_timeout' => 30, 'worker_evidence_attempt' => 2]), self::NOW)['other_stops'],
        );
    }

    /**
     * Rejects: of() that reads a source for an unknown value.
     */
    public function test_of_names_an_unknown_value_unknown(): void
    {
        $rule = EnforcedRule::of(null, 'worker', null, self::NOW, null, false, null, 'payload');

        self::assertSame('unknown', $rule['kind']);
        self::assertSame('unknown', $rule['tries_source']);
        self::assertSame('unknown', $rule['other_stops']['timeout_source']);
    }
}
