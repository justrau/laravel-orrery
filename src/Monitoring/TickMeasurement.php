<?php

namespace Justrau\Orrery\Monitoring;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Workers\LiveWorkers;
use WeakMap;

/**
 * The run-scoped measurement of one tick run.
 *
 * - The long-wait phase and the queue-sample phase share one
 *   WaitTime::measure() and one LiveWorkers::list() per tick run.
 * - The run is the TickBudget object that TickRunner gives to every phase of
 *   one run (TickBudget::run(): a phase past the deadline gets a budget of
 *   the same run). The measurement is kept in a WeakMap keyed by that object, so it
 *   ends with the run, and the next run measures again.
 * - The live workers are read first, inside the watcher
 *   (`tick:live_workers`). When that read fails, `workers` is null: the waits
 *   then read the workers themselves, as before (so the long-wait alerts do
 *   not change), and the sample phase writes no sample.
 * - A measurement that throws is not kept, so a later phase of the same run
 *   measures again.
 * - The tick always measures: it never reads the MeasurementCache of the
 *   HTTP callers, so an alert never comes from a cached value.
 * - The rows can be unknown (`backlog_unknown`).
 *
 * @phpstan-import-type MeasuredRow from WaitTime
 *
 * @phpstan-type Measurement array{measured_at: float, rows: list<MeasuredRow>, workers: list<object{connection: string, subscribed_queues: list<string>}>|null}
 */
final class TickMeasurement
{
    /** @var WeakMap<TickBudget, Measurement> */
    private WeakMap $runs;

    /** The number of measurements taken (for tests). */
    private int $measurements = 0;

    public function __construct(
        private readonly WaitTime $waits,
        private readonly LiveWorkers $workers,
    ) {
        $this->runs = new WeakMap;
    }

    /**
     * @return Measurement
     */
    public function forRun(TickBudget $run): array
    {
        // A phase past the deadline has a budget of its own, of the same run (TickBudget::atLeast()).
        $run = $run->run();

        if (isset($this->runs[$run])) {
            return $this->runs[$run];
        }

        $workers = Watcher::instance()->attempt('tick:live_workers', fn (): array => $this->workers->list());
        /** @var list<object{connection: string, subscribed_queues: list<string>}>|null $rows */
        $rows = $workers->succeeded() ? $workers->value() : null;
        $measuredAt = Clock::now();
        $this->measurements++;

        return $this->runs[$run] = [
            'measured_at' => $measuredAt,
            'rows' => $this->waits->measure($rows),
            'workers' => $rows,
        ];
    }

    public function measurements(): int
    {
        return $this->measurements;
    }
}
