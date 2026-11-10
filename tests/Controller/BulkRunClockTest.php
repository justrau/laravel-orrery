<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\TestClock;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `operations_complete` of GET /api/bulk/{ref} decides with
 * the clock that the operation retention uses: the mirror's database clock
 * (OperationRetentionPhase deletes finished operations older than
 * `orrery.operations.retention_days` by DatabaseClock::now($mirror)).
 */
final class BulkRunClockTest extends ControllerTest
{
    /**
     * @return iterable<string, array{0: float, 1: float, 2: bool}>
     */
    public static function clocks(): iterable
    {
        // A run 31 days old by the PHP clock, while the mirror's clock is 2 days behind: 29 days by the retention's clock.
        yield 'the mirror clock behind' => [31 * 86400.0, -2 * 86400.0, true];
        // A run 29 days old by the PHP clock, while the mirror's clock is 2 days ahead: 31 days by the retention's clock.
        yield 'the mirror clock ahead' => [29 * 86400.0, 2 * 86400.0, false];
    }

    /**
     * Rejects: an `operations_complete` decided by the PHP clock (each case
     * gives the other answer by the PHP clock), so it disagrees with the
     * retention that deletes the run's operations.
     */
    #[DataProvider('clocks')]
    public function test_operations_complete_uses_the_mirror_database_clock(float $age, float $offset, bool $complete): void
    {
        $now = Clock::now();
        $ref = str_repeat('5', 32);

        $this->mirror()->table('orrery_bulk_runs')->insert([
            'ref' => $ref, 'action' => 'retry', 'source' => '{}', 'source_hash' => str_repeat('0', 64), 'max_occurrence' => 1, 'total' => 1,
            'created_at' => Clock::toDecimal($now - $age), 'last_run_at' => Clock::toDecimal($now),
        ]);
        TestClock::shift(TestClock::EVERY, $offset);

        $this->getJson('/orrery/api/bulk/'.$ref)->assertOk()->assertJsonPath('operations_complete', $complete);
    }
}
