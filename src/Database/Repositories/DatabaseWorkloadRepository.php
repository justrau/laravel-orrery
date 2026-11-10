<?php

namespace Justrau\Orrery\Database\Repositories;

use Illuminate\Container\Container;
use Illuminate\Database\Query\Builder;
use Justrau\Orrery\Contracts\WorkloadRepository;
use Justrau\Orrery\Control\PauseCauses;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Monitoring\MeasurementCache;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\SampleCoverage;
use Justrau\Orrery\Monitoring\Samples;
use Justrau\Orrery\Monitoring\SampleWindow;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\Queue\Instrumentation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Workers\LiveWorkers;

/**
 * The workload of each checked queue, from WaitTime:
 * `length` is the executable backlog, `wait` the estimated time to clear in
 * seconds (null when the runtime is unknown), and `processes` the live
 * workers subscribed to the queue.
 *
 * Queue connections that share one jobs table give one row per queue
 * (WaitTime groups them): `connection` is the first of their names and
 * `connections` lists all of them. `processes` counts the live workers of all
 * of them, each once.
 *
 * The queue state, added to each row:
 * - `oldest_age` and `threshold` from WaitTime, and `over_limit`
 *   (WaitTime::overLimit(), the predicate of the queue samples).
 * - `running`: the reserved mirror rows of the queue on every alias with a
 *   live lease: `reserved_at` later than now − retry_after of the row's
 *   WaitTime target (the lease rule of LiveWorkers). The expired
 *   reservations of a dead worker are not running.
 * - `in_per_min` and `out_per_min`: the rates of the
 *   per-minute samples over the five complete minutes before the current
 *   minute m_now, [m_now − 300, m_now) (`rates_from`, `rates_to`), through
 *   the read rule of the samples (SampleCoverage, one call for all rows),
 *   for the row's primary alias (the samples store every alias under it).
 *   In: Σ `pushed` ÷ 5 (the arrivals by pushed_at: the first push of a row;
 *   a retry is not a new arrival). Out: Σ (`completed` + `failed`) ÷ 5
 *   (completed rows by finished_at, silenced included; every row by
 *   failed_at). Each is null when one of the five minutes is unknown for
 *   its kind (an uncovered, invalid or pre-upgrade minute: unknown, never
 *   an undercount), or when the read failed; `in_per_min` is also null for
 *   an uninstrumented connection.
 * - `pauses`: every cause that pauses the primary alias
 *   (`connection`), each `{scope, source, issued_at, issued_by, possible}`,
 *   from the signal rows and Laravel's native state with the OR rule of
 *   Control\PauseCauses; empty when nothing is known to pause it.
 *   `paused_unknown` is true when the signals or Laravel's isPaused() could
 *   not be read. `queue_cause_unknown` is true while Laravel's global pause
 *   masks the queue's own flag. `native_unknown` (with `native_hint`) is
 *   true when the native state was not read because the cache store is on
 *   an application SQLite file; an Orrery queue annotation is then only a
 *   `possible` cause.
 * - `over_limit_since` and `over_limit_since_censored` from the latest
 *   fresh sample of the primary alias (Samples::latestRuns(), the
 *   values that the sample phase carries), only while `over_limit` is true
 *   now; else null and false.
 * - `backlog_unknown`: true when the backlog of the row's
 *   target could not be read or its target could not be resolved. Then
 *   `length`, `oldest_age`, `wait` and `over_limit` are null; the other
 *   fields stay.
 * - `instrumented`: false when one of the row's
 *   connections is uninstrumented (Queue\Instrumentation: its queue object
 *   is not the package's, or could not be resolved). Such a queue writes no
 *   `pending` or `reserved` mirror row, so `running` and `in_per_min` are
 *   then null (unknown), never 0. `out_per_min` stays: outcomes are
 *   mirrored.
 *
 * The cost per request: one resolution of the queue objects
 * (Instrumentation, no statement), one LiveWorkers read, one measurement (shared for a
 * few seconds through the MeasurementCache, with the native pause state inside
 * it), one signal read, the running read (one statement per distinct
 * retry_after, on the index `st_reserved`), the rates read of the samples
 * (two settings reads and one samples statement for all rows, plus one
 * grouped scan per kind of the minutes from the counts frontier M on, none
 * while M is at the current minute), and, only when a row is over its
 * limit, one samples statement for all rows. A failed read of an extra
 * part is reported through the watcher and gives null fields. The live
 * workers and the measurement are not optional: their failure fails get(),
 * and WorkloadController answers a JSON 503 from its watched read.
 */
class DatabaseWorkloadRepository implements WorkloadRepository
{
    /** The complete minutes of the rate window. */
    public const RATE_MINUTES = 5;

    public function __construct(
        protected WaitTime $waitTime,
        protected LiveWorkers $workers,
        protected SignalState $signals,
        protected Samples $samples,
        protected MeasurementCache $measurements,
        protected Instrumentation $instrumentation,
        protected SampleCoverage $coverage,
    ) {
    }

    /**
     * Get the current workload of each queue.
     *
     * @return array<int, array{name: string, connection: string, connections: list<string>, length: int|null, wait: int|null, processes: int, split_queues: null, oldest_age: float|null, threshold: int, over_limit: bool|null, running: int|null, in_per_min: float|null, out_per_min: float|null, rates_from: int, rates_to: int, pauses: list<array{scope: string, source: string, issued_at: float|null, issued_by: string|null, possible: bool}>, paused_unknown: bool, queue_cause_unknown: bool, native_unknown: bool, native_hint: string|null, backlog_unknown: bool, instrumented: bool, over_limit_since: int|null, over_limit_since_censored: bool}>
     */
    public function get(): array
    {
        // One read of the live workers for every row, shared with the measurement.
        $workers = $this->workers->list();
        $measured = $this->measurements->measure($workers);
        $now = Clock::now();

        if ($measured === []) {
            return [];
        }

        // The lease interval of each row: the retry_after of its WaitTime target.
        $leases = [];

        foreach ($measured as $row) {
            $leases[$row['connection']."\0".$row['queue']] = $this->waitTime->retryAfter($row['connections']);
        }

        $running = Watcher::instance()->run('workload:running', fn (): array => $this->running($now, array_values(array_unique($leases))), null);
        // The five complete minutes before the current minute, from the samples (one read for all rows).
        $ratesTo = SampleCounts::minuteOf($now);
        $ratesFrom = $ratesTo - self::RATE_MINUTES * SampleCounts::MINUTE;
        $rates = Watcher::instance()->run('workload:rates', fn (): SampleWindow => $this->coverage->of('queue', null, $ratesFrom, $ratesTo, SampleWindow::KINDS), null);
        $signals = Watcher::instance()->run('workload:signals', fn (): array => $this->signals->pauseRows(), null);
        // The run of over-limit samples matters only while a queue is over its limit now: one read for all rows.
        $anyOver = array_filter($measured, static fn (array $row): bool => ! $row['backlog_unknown'] && WaitTime::overLimit($row)) !== [];
        $runs = ! $anyOver ? [] : Watcher::instance()->run('workload:over_limit_since', fn (): array => $this->samples->latestRuns($now), []);
        // Once per request: the connections whose pushes and reservations are not mirrored.
        $uninstrumented = array_column($this->instrumentation->uninstrumented(), 'connection');
        $rows = [];

        foreach ($measured as $row) {
            $queue = $row['queue'];
            $instrumented = array_intersect($row['connections'], $uninstrumented) === [];
            $unknown = $row['backlog_unknown'];
            $overLimit = $unknown ? null : WaitTime::overLimit($row);
            $since = $overLimit === true ? ($runs[$row['connection']."\0".$queue] ?? null) : null;
            $pause = PauseCauses::of($signals, $row['native'], $row['connection'], $queue);
            $lease = $leases[$row['connection']."\0".$queue];
            $series = SampleCounts::series('queue', $this->coverage->primary($row['connection']), $queue);
            $in = $rates?->sum($series, 'pushed');
            $completed = $rates?->sum($series, 'completed');
            $failed = $rates?->sum($series, 'failed');

            $rows[] = [
                'name' => $queue,
                'connection' => $row['connection'],
                'connections' => $row['connections'],
                'length' => $row['backlog'],
                'wait' => $row['wait'],
                'processes' => LiveWorkers::subscribed($workers, $row['connections'], $queue),
                'split_queues' => null,
                'oldest_age' => $row['oldest_age'],
                'threshold' => $row['threshold'],
                'over_limit' => $overLimit,
                'running' => $running === null || ! $instrumented ? null : self::sum($running[$lease] ?? [], $row['connections'], $queue),
                'in_per_min' => $in === null || ! $instrumented ? null : $in / (float) self::RATE_MINUTES,
                'out_per_min' => $completed === null || $failed === null ? null : ($completed + $failed) / (float) self::RATE_MINUTES,
                'rates_from' => $ratesFrom,
                'rates_to' => $ratesTo,
                'pauses' => $pause['pauses'],
                'paused_unknown' => $pause['paused_unknown'],
                'queue_cause_unknown' => $pause['queue_cause_unknown'],
                'native_unknown' => $pause['native_unknown'],
                'native_hint' => $pause['native_hint'],
                'backlog_unknown' => $unknown,
                'instrumented' => $instrumented,
                'over_limit_since' => $since['since'] ?? null,
                'over_limit_since_censored' => $since['censored'] ?? false,
            ];
        }

        return $rows;
    }

    /**
     * The running rows per lease interval and `connection\0queue`: `status`
     * reserved with a live lease, `reserved_at` later than now − retry_after.
     * One statement per distinct retry_after of the measured rows (usually
     * one), keyed by that retry_after.
     *
     * @param  list<int>  $leases  the distinct retry_after values
     * @return array<int, array<string, int>>
     */
    private function running(float $now, array $leases): array
    {
        $session = Container::getInstance()->make(PackageSession::class)->mirror();

        $grouped = static function (Builder $query): array {
            $counts = [];

            foreach ($query->groupBy('connection', 'queue')->select(['connection', 'queue'])->selectRaw('COUNT(*) AS n')->get() as $row) {
                $key = $row->connection."\0".$row->queue;
                $counts[$key] = ($counts[$key] ?? 0) + (int) $row->n;
            }

            return $counts;
        };

        $running = [];

        foreach ($leases as $lease) {
            $running[$lease] = $grouped($session->table('orrery_jobs')->where('status', 'reserved')->where('reserved_at', '>', Clock::toDecimal($now - $lease)));
        }

        return $running;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $connections
     */
    private static function sum(array $counts, array $connections, string $queue): int
    {
        $sum = 0;

        foreach ($connections as $connection) {
            $sum += $counts[$connection."\0".$queue] ?? 0;
        }

        return $sum;
    }
}
