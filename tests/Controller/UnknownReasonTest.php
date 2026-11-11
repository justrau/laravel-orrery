<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Lists\ListFixtures;

/**
 * `unknown_reason` on the job rows of GET /api/jobs and on GET
 * /api/jobs/{uuid}: why an unknown row is unknown, null for every other
 * status.
 */
final class UnknownReasonTest extends ControllerTest
{
    use ListFixtures;

    /**
     * Rejects: a list or detail without the reason of an unknown row (the SPA could not say "Orrery was off or not
     * observing"), and a reason shown for a row that is not unknown (a pending row keeps the classification of
     * its first absence in the column until it is marked).
     */
    public function test_unknown_rows_carry_their_reason_and_other_rows_none(): void
    {
        $this->addRow(1, 'unknown', 1, ['unknown_reason' => Reconciliation::BLIND_GAP_REASON]);
        $this->addRow(2, 'unknown', 2, ['unknown_reason' => Reconciliation::UNKNOWN_REASON]);
        $this->addRow(3, 'pending', 3, ['unknown_reason' => Reconciliation::BLIND_GAP_REASON]);
        $this->flushRows();

        $rows = $this->actingAs(new User)->getJson('/orrery/api/jobs?status=unknown')->assertOk()->json('jobs');
        $reasons = [];

        foreach ($rows as $row) {
            $reasons[$row['id']] = $row['unknown_reason'];
        }

        ksort($reasons);

        self::assertSame([self::uuidOf(1) => Reconciliation::BLIND_GAP_REASON, self::uuidOf(2) => Reconciliation::UNKNOWN_REASON], $reasons);
        self::assertSame(Reconciliation::BLIND_GAP_REASON, $this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::uuidOf(1))->assertOk()->json('unknown_reason'));
        self::assertNull($this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::uuidOf(3))->assertOk()->json('unknown_reason'));
        $pending = $this->actingAs(new User)->getJson('/orrery/api/jobs?status=pending')->assertOk()->json('jobs.0');
        self::assertSame([self::uuidOf(3), null], [$pending['id'], $pending['unknown_reason']]);
    }
}
