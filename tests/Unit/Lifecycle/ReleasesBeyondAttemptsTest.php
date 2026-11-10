<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Lifecycle;

use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Lifecycle\Rules;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;
use PHPUnit\Framework\TestCase;

/**
 * A cycle's releases can exceed its attempts: so the stuck
 * signal "repeated releases" reads its own index `st_releases` (status,
 * releases, id), not `st_attempts` with `attempts >= release_threshold`.
 *
 * The timeline: a reservation of attempt 1 runs past retry_after, and
 * another worker reclaims the same job id as attempt 2. Laravel's
 * deleteAndRelease() releases unconditionally, so both workers release:
 * attempt 1's release creates job 2 and attempt 2's creates job 3. Two
 * lineages then run side by side, each reservation of either one is
 * accepted (Rules::reserveReplacement() takes an attempt at or above the
 * row's), and each release adds one to `releases`.
 */
final class ReleasesBeyondAttemptsTest extends TestCase
{
    use BuildsObservations;

    private const UUID = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

    /**
     * Rejects: a reader that assumes `releases <= attempts` (an attempts
     * range for "repeated releases" would miss this row), and a rule change
     * that stops counting the releases of a second lineage without a reason
     * in the spec (this test then fails, and the index choice is decided
     * again).
     * Still passes: any other way to reach releases > attempts.
     */
    public function test_two_lineages_after_a_reclaim_reach_more_releases_than_attempts(): void
    {
        $row = null;
        $steps = [
            [Observation::PUSH, ['jobId' => 1]],
            [Observation::RESERVE, ['jobId' => 1, 'attempt' => 1]],
            // The lease of attempt 1 expired: another worker reclaims job 1 as attempt 2.
            [Observation::RESERVE, ['jobId' => 1, 'attempt' => 2]],
            // Both workers release: two lineages, jobs 2 and 3.
            [Observation::RELEASE, ['jobId' => 1, 'attempt' => 1, 'newJobId' => 2, 'delay' => 0]],
            [Observation::RELEASE, ['jobId' => 1, 'attempt' => 2, 'newJobId' => 3, 'delay' => 0]],
            [Observation::RESERVE, ['jobId' => 2, 'attempt' => 2]],
            [Observation::RELEASE, ['jobId' => 2, 'attempt' => 2, 'newJobId' => 4, 'delay' => 0]],
            [Observation::RESERVE, ['jobId' => 3, 'attempt' => 3]],
            [Observation::RELEASE, ['jobId' => 3, 'attempt' => 3, 'newJobId' => 5, 'delay' => 0]],
            [Observation::RESERVE, ['jobId' => 4, 'attempt' => 3]],
            [Observation::RELEASE, ['jobId' => 4, 'attempt' => 3, 'newJobId' => 6, 'delay' => 0]],
        ];

        foreach ($steps as [$kind, $fields]) {
            $decision = Rules::decide($row === null ? null : self::rowState($row), self::observation($kind, self::UUID, $fields));
            $row = array_merge($row ?? [], $decision->set);
        }

        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertSame(3, (int) $row['attempts']);
        self::assertSame(4, (int) $row['releases'], 'releases exceed attempts');
    }
}
