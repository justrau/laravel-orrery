<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\History;

/**
 * History bounds: 50 rows per cycle (the first
 * 5 and the latest 45), 200 rows per job (the oldest cycles first), and at
 * most 50 individual summaries plus the merged row.
 */
final class HistoryBoundsTest extends CycleTestCase
{
    /** Cycle 1 has 60 exceptions and one failure: 61 rows. */
    private const FIRST_CYCLE_EXCEPTIONS = 60;

    /** Cycles 2 to 80 have one exception and one failure each: 158 rows. */
    private const CYCLES = 80;

    /**
     * Rejects: unbounded history.
     */
    public function test_many_cycles_keep_at_most_two_hundred_history_rows_and_fifty_summaries(): void
    {
        $uuid = $this->uuid();
        $gen = static fn (int $cycle): array => ['orreryGen' => str_pad(dechex($cycle), 32, '0', STR_PAD_LEFT)];
        $this->observe('push', $uuid, ['jobId' => 1], $gen(1));

        // Cycle 1: reclaims 1..60, each with an exception, then the failure of attempt 60.
        for ($attempt = 1; $attempt <= self::FIRST_CYCLE_EXCEPTIONS; $attempt++) {
            $this->observe('reserve', $uuid, ['jobId' => 1, 'attempt' => $attempt], $gen(1));
            $this->observe('exception', $uuid, ['jobId' => 1, 'attempt' => $attempt, 'exception' => new \RuntimeException("attempt {$attempt}")], $gen(1));
        }

        $this->observe('fail', $uuid, ['jobId' => 1, 'attempt' => self::FIRST_CYCLE_EXCEPTIONS, 'exception' => new \RuntimeException('cycle 1')], $gen(1));

        $cycleOne = $this->cycleRows($uuid, 1);
        self::assertCount(History::KEEP_FIRST + History::KEEP_LATEST, $cycleOne, 'a cycle keeps 50 rows');
        self::assertSame([1, 2, 3, 4, 5], array_slice(array_column($cycleOne, 'attempt'), 0, History::KEEP_FIRST), 'the first 5 rows stay');
        self::assertSame(array_merge(range(17, self::FIRST_CYCLE_EXCEPTIONS), [self::FIRST_CYCLE_EXCEPTIONS]), array_slice(array_column($cycleOne, 'attempt'), History::KEEP_FIRST), 'the latest 45 rows stay');
        self::assertSame('failed', end($cycleOne)['event']);

        $row = $this->rowOrFail($uuid);
        self::assertTrue((bool) $row->history_truncated);
        self::assertSame(11, (int) $row->history_discarded);
        self::assertSame((int) end($cycleOne)['id'], (int) $row->occurrence_id, 'the failure row that names the occurrence stays');

        for ($cycle = 2; $cycle <= self::CYCLES; $cycle++) {
            $this->observe('reserve', $uuid, ['jobId' => $cycle, 'attempt' => 1], $gen($cycle));
            $this->observe('exception', $uuid, ['jobId' => $cycle, 'attempt' => 1, 'exception' => new \RuntimeException("cycle {$cycle}")], $gen($cycle));
            $this->observe('fail', $uuid, ['jobId' => $cycle, 'attempt' => 1, 'exception' => new \RuntimeException("cycle {$cycle}")], $gen($cycle));

            self::assertLessThanOrEqual(History::PER_JOB, $this->historyRows($uuid), "at most 200 rows after cycle {$cycle}");
        }

        // 61 + 158 rows were written: 11 left cycle 1 by the cycle bound, and 8 more by the job bound.
        self::assertSame(History::PER_JOB, $this->historyRows($uuid));
        self::assertCount(42, $this->cycleRows($uuid, 1), 'the oldest cycle loses its rows first');
        self::assertCount(2, $this->cycleRows($uuid, 2), 'a later cycle keeps its rows');

        $row = $this->rowOrFail($uuid);
        self::assertSame(self::CYCLES, (int) $row->cycle);
        self::assertTrue((bool) $row->history_truncated);
        self::assertSame(19, (int) $row->history_discarded);

        self::assertSame(CycleSummaries::INDIVIDUAL, $this->individualSummaries($uuid));
        self::assertSame(1, $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->where('merged', true)->count());
        self::assertSame(CycleSummaries::INDIVIDUAL + 1, count($this->summaries($uuid)), 'the summaries stay bounded while the history loses rows');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cycleRows(string $uuid, int $cycle): array
    {
        return $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('cycle', $cycle)->orderBy('id')->get()
            ->map(static fn (object $row): array => ['id' => (int) $row->id, 'attempt' => (int) $row->attempt, 'event' => $row->event])->all();
    }
}
