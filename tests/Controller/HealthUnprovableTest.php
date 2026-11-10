<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Justrau\Orrery\Http\Controllers\HealthController;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Failed\CapturingFailer;
use Justrau\Orrery\Tests\Feature\Lists\ListFixtures;

/**
 * `failed_records.unprovable_failures` of `GET /api/health`:
 * the shell polls health on every screen, so the count reads only the
 * newest failed rows, never every failed row of the mirror.
 */
final class HealthUnprovableTest extends ControllerTest
{
    use CapturingFailer;
    use ListFixtures;

    /**
     * Rejects: a count that reads every failed row on each poll: an unbound
     * row older than the newest HealthController::UNPROVABLE_WINDOW failed
     * rows is not read, and the answer says `at_least` instead of a definite
     * count; an unbound row inside the window is counted. Would still pass: a
     * window ordered by id instead of failed_at (the two orders agree here).
     */
    public function test_the_count_reads_only_the_newest_failed_rows(): void
    {
        $this->useCapturingFailer();
        $window = HealthController::UNPROVABLE_WINDOW;

        // The oldest failed row has no bound record; the newest rows of the window all have one.
        $this->addRow(1, 'failed', 1);

        for ($n = 2; $n <= $window + 1; $n++) {
            $this->addRow($n, 'failed', $n, ['provider_id' => $n]);
        }

        $this->flushRows();

        self::assertSame(['count' => 0, 'at_least' => true], $this->unprovable(), 'the old unbound row is outside the window');

        $this->addRow($window + 2, 'failed', $window + 2);
        $this->flushRows();

        self::assertSame(['count' => 1, 'at_least' => true], $this->unprovable(), 'the newest unbound row is counted');
    }

    /**
     * Rejects: a window that answers `at_least` while it holds every failed row.
     */
    public function test_a_window_that_is_not_full_gives_a_definite_count(): void
    {
        $this->useCapturingFailer();
        $this->addRow(1, 'failed', 1);
        $this->addRow(2, 'failed', 2, ['provider_id' => 2]);
        $this->addRow(3, 'failed', 3);
        $this->addRow(4, 'completed', 4);
        $this->flushRows();

        self::assertSame(['count' => 2, 'at_least' => false], $this->unprovable());
    }

    /**
     * @return array{count: int, at_least: bool}|null
     */
    private function unprovable(): ?array
    {
        return $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('failed_records.unprovable_failures');
    }
}
