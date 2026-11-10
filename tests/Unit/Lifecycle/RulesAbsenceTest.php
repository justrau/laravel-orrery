<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Lifecycle;

use Justrau\Orrery\Lifecycle\Rules;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * absent_since in the rules: the
 * absence time belongs to the inspected job id, and a failure starts the
 * absence check of its provider record.
 */
final class RulesAbsenceTest extends TestCase
{
    use BuildsObservations;

    private const UUID = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

    /**
     * Rejects: a transition that keeps the absence time of another job id, or of an earlier failure.
     *
     * @param  array<string, mixed>  $row
     * @param  array{0: string, 1: int, 2: int, 3?: array<string, mixed>}  $spec
     */
    #[DataProvider('cases')]
    public function test_absent_since_follows_the_job_id(array $row, array $spec, string $rule, bool $cleared): void
    {
        [$kind, $jobId, $attempt] = $spec;
        $decision = Rules::decide(self::rowState($row + ['absent_since' => '1789999000.000000']), self::observation($kind, self::UUID, ['jobId' => $jobId, 'attempt' => $attempt] + ($spec[3] ?? [])));

        self::assertSame($rule, $decision->rule);
        RuleNamesTest::assertRuleName($decision->rule);

        if ($cleared) {
            self::assertArrayHasKey('absent_since', $decision->set);
            self::assertNull($decision->set['absent_since']);
        } else {
            self::assertArrayNotHasKey('absent_since', $decision->set);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<int, mixed>, 2: string, 3: bool}>
     */
    public static function cases(): array
    {
        return [
            'L1 to a new id' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['release', 10, 1, ['newJobId' => 11]], 'release', true],
            'R3 to a replacement id' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['reserve', 11, 2], 'reserveReplacement', true],
            'R1 of the same id' => [['status' => 'pending', 'job_id' => 10, 'attempts' => 0], ['reserve', 10, 1], 'reserve', false],
            'F1 of the same id' => [['status' => 'reserved', 'job_id' => 10, 'attempts' => 1], ['fail', 10, 1, ['exception' => new \RuntimeException('boom')]], 'fail', true],
        ];
    }
}
