<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Metrics;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The per-minute counts as the tick publishes them (before:
 * the metric counters of each completion): countedSamples() runs the real
 * aggregation (SampleCounts::run()) two minutes after the latest completion,
 * so the minutes of the last five before it are counted, and sums the
 * stored `queue` and `job` rows.
 */
trait CountsSamples
{
    /**
     * @return array<string, array{throughput: int, runtime_sum: float}> keyed "queue:connection:queue" and "job:name"
     */
    protected function countedSamples(): array
    {
        $previous = Carbon::getTestNow();
        // Two minutes after the latest outcome (a test may observe at a fixed time), so its minute is counted.
        $latest = Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_jobs')->max('finished_at');
        Carbon::setTestNow(Carbon::createFromTimestamp((is_numeric($latest) ? (float) $latest : Clock::now()) + 2 * SampleCounts::MINUTE, 'UTC'));

        try {
            $this->app->make(SampleCounts::class)->run();
        } finally {
            Carbon::setTestNow($previous);
        }

        $counters = [];

        foreach (Container::getInstance()->make(PackageSession::class)->mirror()->table(SampleCounts::TABLE)->whereIn('scope', ['queue', 'job'])->get() as $row) {
            $key = $row->scope === 'queue' ? 'queue:'.$row->connection.':'.$row->queue : 'job:'.$row->name;
            $counters[$key] ??= ['throughput' => 0, 'runtime_sum' => 0.0];
            $counters[$key]['throughput'] += (int) $row->completed;
            $counters[$key]['runtime_sum'] += (float) $row->runtime_ms_sum;
        }

        foreach ($counters as $key => $counter) {
            if ($counter['throughput'] === 0) {
                unset($counters[$key]);
            }
        }

        return $counters;
    }
}
