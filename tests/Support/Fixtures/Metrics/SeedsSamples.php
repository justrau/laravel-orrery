<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Metrics;

use Illuminate\Container\Container;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * Seeds the counted samples of orrery_samples (before: the
 * metric counters): one completion of a series in the previous minute, as
 * the tick's aggregation counts it (ten minutes ago by default). A queue series is named
 * `connection:queue` with its primary connection.
 */
trait SeedsSamples
{
    /**
     * @param  'queue'|'job'  $scope
     */
    protected function seedCompletion(string $scope, string $name, float $runtimeMs, ?int $minute = null): void
    {
        // Ten minutes ago by default: outside the recount window, so a tick keeps it.
        $minute ??= SampleCounts::minuteOf(Clock::now()) - 10 * SampleCounts::MINUTE;
        $session = Container::getInstance()->make(PackageSession::class)->mirror();

        if ($scope === 'queue') {
            [$connection, $queue] = explode(':', $name, 2);
            $key = ['scope' => 'queue', 'series' => SampleCounts::series('queue', $connection, $queue), 'minute' => $minute];
            $labels = ['connection' => $connection, 'queue' => $queue];
        } else {
            $key = ['scope' => 'job', 'series' => SampleCounts::series('job', $name), 'minute' => $minute];
            $labels = ['name' => $name];
        }

        $row = $session->table(SampleCounts::TABLE)->where($key)->first(['completed', 'runtime_ms_sum', 'runtime_count']);
        $runtime = (int) round($runtimeMs);

        if ($row === null) {
            $session->table(SampleCounts::TABLE)->insert($key + $labels + ['completed' => 1, 'runtime_ms_sum' => $runtime, 'runtime_count' => 1]);

            return;
        }

        $session->table(SampleCounts::TABLE)->where($key)->update([
            'completed' => (int) $row->completed + 1,
            'runtime_ms_sum' => (int) $row->runtime_ms_sum + $runtime,
            'runtime_count' => (int) $row->runtime_count + 1,
        ]);
    }

    /**
     * Covers the minutes [from, to) as the aggregation does: a `total` row
     * for each (the sums of the `queue` rows of the minute), and M = $to
     * with its time.
     */
    protected function coverMinutes(int $from, int $to): void
    {
        $session = Container::getInstance()->make(PackageSession::class)->mirror();

        for ($minute = $from; $minute < $to; $minute += SampleCounts::MINUTE) {
            $sums = $session->table(SampleCounts::TABLE)->where('scope', 'queue')->where('minute', $minute)
                ->selectRaw('COALESCE(SUM(completed), 0) AS completed, COALESCE(SUM(failed), 0) AS failed, COALESCE(SUM(runtime_ms_sum), 0) AS runtime_ms_sum, COALESCE(SUM(runtime_count), 0) AS runtime_count')
                ->first();
            $session->table(SampleCounts::TABLE)->upsert([[
                'scope' => 'total', 'series' => SampleCounts::series('total'), 'minute' => $minute,
                'completed' => (int) ($sums->completed ?? 0), 'failed' => (int) ($sums->failed ?? 0),
                'runtime_ms_sum' => (int) ($sums->runtime_ms_sum ?? 0), 'runtime_count' => (int) ($sums->runtime_count ?? 0),
            ]], ['scope', 'series', 'minute'], ['completed', 'failed', 'runtime_ms_sum', 'runtime_count']);
        }

        $session->table('orrery_settings')->upsert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) $to],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal(Clock::now())],
        ], ['key'], ['value']);
    }
}
