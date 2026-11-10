<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Benchmark;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Registration\Modules\MonitoringModule;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * The tick benchmark. It is not in any test suite:
 * bin/benchmark-tick runs this file through bin/test, so the run gets the
 * harness database, its lease and its endpoint proof. Without
 * ORRERY_BENCHMARK=1 both tests skip.
 *
 * test_server_bytes seeds nothing and writes the bytes that the server's
 * databases use now: bin/benchmark-tick checks with it that the probe fits.
 *
 * test_bytes_per_row seeds ORRERY_BENCHMARK_PROBE_ROWS rows (default 5,000)
 * and writes the measured bytes per seeded row of every table (the optional
 * jobs index included) and the bytes that the server's databases use now.
 * bin/benchmark-tick sizes the server runs from it, so a run never fills the
 * tmpfs of the test containers.
 *
 * test_tick_phases seeds the sizes of ORRERY_BENCHMARK_SIZES one after the
 * other (cumulative row counts). At each size it runs three ticks: a first
 * tick, which also does the one-time work of the new rows; a second tick
 * without the optional jobs index; and, after it adds the index, a third
 * tick with it. Then it drops the index. The second and third ticks meet the
 * same kind of work (retention and reconciliation still have a backlog), so
 * they compare the index. For each phase it records the latency, the
 * statements, the rows read and written (MySQL: the session Handler
 * counters of every open connection; Postgres: pg_stat_user_tables of the
 * run's database; SQLite has no such counter), and the peak memory above
 * the start of the phase, and the phase's status from its TickReport (ran,
 * failed, skipped, overrun). For the retention phase it also records the
 * deleted rows (from the state row `retention_state`) and the deleted rows
 * per second of the phase. Each phase starts with new package sessions, as
 * each orrery:tick process does, and gets its own deadline: the totals are
 * sums of isolated phase runs, not one whole tick with a shared deadline. A
 * measurement that did not complete fails the test after the report is
 * written, so bin/benchmark-tick exits 1.
 *
 * The seed (per row i of N, deterministic):
 * - `jobs`: 10 queues; 80% ready, 10% delayed, 5% reserved, 5% expired
 *   reservations; payloads of ORRERY_BENCHMARK_PAYLOAD bytes (default 700).
 * - `orrery_jobs`: 40% completed (half past the 60-minute retention, 10% of
 *   them silenced), 20% pending (a quarter of them with a job id that the
 *   jobs table does not have), 5% reserved, 20% failed with a `present`
 *   provider identity (half past the 7-day retention) and their failed_jobs
 *   records, 5% unknown (half past retention), 10% completed and tagged with
 *   a stale monitor_version (the monitoring repair's work).
 * - orrery_samples of 25 hours: per minute one `total` row,
 *   10 `queue` rows (with the queue state) and 5 `job` rows; the first hour
 *   is past the 24-hour retention (the sample retention's work). The counts
 *   frontier M is one minute behind, so `sample_counts` recounts the last 5
 *   minutes from the mirror rows. 1,000 closed and 10 open health windows,
 *   50 heartbeats (half stale).
 * - test_tick_phases only (seedBulk(), not in the byte probe): 100 unresolved
 *   bulk groups of 20 pending rows each (a quarter with a floor near the
 *   first jobs id, so each attempt reads up to 5,000 jobs rows; the others
 *   above every jobs id), and
 *   their `bulk_resolution:` attempt keys: a third at the last attempt and
 *   scanned (the rows become `unknown`), a third at their first attempt, a
 *   third without a key, plus 500 keys of groups that have no row any more
 *   (the key cleanup's work).
 */
#[FreshSchema]
final class TickBenchmark extends TestCase
{
    private const QUEUES = 10;

    /** The first mirror row number of the bulk groups (above every scaled row). */
    private const BULK_BASE = 900_000_000;

    private int $statements = 0;

    private ?string $target = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['events']->listen(QueryExecuted::class, function (): void {
            $this->statements++;
        });
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The failed rows name the numeric provider ids of failed_jobs.
        $app['config']->set('queue.failed', ['driver' => 'database-uuids', 'database' => TestEnvironment::PRIMARY, 'table' => 'failed_jobs']);

        // A control of the status check: ORRERY_BENCHMARK_DEADLINE=0 skips every phase.
        $deadline = getenv('ORRERY_BENCHMARK_DEADLINE');

        if (is_string($deadline) && is_numeric($deadline)) {
            $app['config']->set('orrery.tick.deadline_seconds', (float) $deadline);
        }
    }

    /**
     * Writes only the bytes that the server's databases use now. It seeds nothing, so
     * bin/benchmark-tick can check the free budget before the probe seeds.
     */
    public function test_server_bytes(): void
    {
        self::requireOptIn();

        $this->write(['engine' => $this->engine(), 'server_bytes' => $this->serverBytes()]);

        self::assertGreaterThan(0, $this->serverBytes());
    }

    public function test_bytes_per_row(): void
    {
        self::requireOptIn();
        $rows = (int) (getenv('ORRERY_BENCHMARK_PROBE_ROWS') ?: 5000);

        $this->seedFixed();
        $this->seedRows(0, $rows);
        $this->addIndex();

        $tables = $this->tableBytes();

        $this->write([
            'engine' => $this->engine(),
            'probe_rows' => $rows,
            'payload_bytes' => $this->payloadBytes(),
            'table_bytes' => $tables,
            'bytes_per_row' => (int) ceil(array_sum($tables) / $rows),
            'server_bytes' => $this->serverBytes(),
        ]);

        self::assertGreaterThan(0, array_sum($tables));
    }

    public function test_tick_phases(): void
    {
        self::requireOptIn();
        $sizes = array_map('intval', array_filter(explode(',', (string) getenv('ORRERY_BENCHMARK_SIZES'))));

        if ($sizes === []) {
            throw new RuntimeException('Set ORRERY_BENCHMARK_SIZES, for example 50000,100000.');
        }

        sort($sizes);
        Watcher::rethrowForTesting(true);

        $report = ['engine' => $this->engine(), 'payload_bytes' => $this->payloadBytes(), 'php' => PHP_VERSION, 'laravel' => $this->app->version(), 'sizes' => []];
        $seeded = 0;

        $started = Clock::monotonic();
        $this->seedFixed();
        $this->seedBulk();
        $report['fixed_seed_seconds'] = round(Clock::monotonic() - $started, 1);

        foreach ($sizes as $size) {
            $started = Clock::monotonic();
            $this->seedRows($seeded, $size);
            $seedSeconds = round(Clock::monotonic() - $started, 1);
            $seeded = $size;

            $entry = ['rows' => $size, 'seed_seconds' => $seedSeconds, 'table_bytes' => $this->tableBytes()];
            // The first tick after a seed does the one-time work (for example
            // the sample retention and the last bulk attempts); the next two
            // ticks compare the index.
            $entry['first_tick'] = $this->phases();
            $entry['without_index'] = $this->phases();

            $started = Clock::monotonic();
            $this->addIndex();
            $entry['index_seconds'] = round(Clock::monotonic() - $started, 2);
            $entry['with_index'] = $this->phases();
            $this->dropIndex();

            $report['sizes'][] = $entry;
            $this->write($report);
        }

        self::assertNotEmpty($report['sizes']);
        self::assertSame([], self::incomplete($report), 'every phase measurement is complete (the report is written in any case)');
    }

    /**
     * The phase runs that did not complete: failed, skipped, overrun or not run.
     *
     * @param  array{sizes: list<array<string, mixed>>}  $report
     * @return list<string>
     */
    public static function incomplete(array $report): array
    {
        $incomplete = [];

        foreach ($report['sizes'] as $size) {
            foreach (['first_tick', 'without_index', 'with_index'] as $tick) {
                foreach ($size[$tick] ?? [] as $phase) {
                    if (($phase['complete'] ?? false) !== true) {
                        $incomplete[] = $size['rows'].' rows, '.$tick.', '.$phase['phase'].($phase['error'] !== null ? ': '.$phase['error'] : '');
                    }
                }
            }
        }

        return $incomplete;
    }

    /**
     * Runs every tagged tick phase once, in priority order, each through
     * TickRunner (the watcher and the maintenance session of orrery:tick).
     *
     * Each result records the TickReport status of its phase (ran, failed,
     * skipped, overrun) and whether the measurement is complete.
     *
     * @return list<array{phase: string, priority: int, ms: float, statements: int, rows_read: ?int, rows_written: ?int, memory_kb: int, error: ?string, ran: bool, failed: bool, skipped: bool, overrun: bool, complete: bool}>
     */
    private function phases(): array
    {
        /** @var list<TickPhase> $phases */
        $phases = iterator_to_array($this->app->tagged(TickCommand::PHASES_TAG), false);
        usort($phases, static fn (TickPhase $a, TickPhase $b): int => [$a->priority(), $a->name()] <=> [$b->priority(), $b->name()]);
        $runner = $this->app->make(TickRunner::class);
        $results = [];

        foreach ($phases as $phase) {
            $this->newSessions();
            $before = $this->counters();
            $this->statements = 0;
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $error = null;
            $report = null;
            $started = hrtime(true);

            try {
                $report = $runner->run([$phase]);
            } catch (Throwable $failure) {
                // Watcher::rethrowForTesting(true): a failed phase throws here.
                $error = $failure::class.': '.mb_substr($failure->getMessage(), 0, 300);
            }

            $name = $phase->name();
            $ran = $report !== null && in_array($name, $report->ran(), true);
            $failed = $error !== null || ($report !== null && in_array($name, $report->failed(), true));
            $skipped = $report !== null && in_array($name, $report->skipped(), true);
            $overrun = $report !== null && in_array($name, $report->overruns(), true);

            $ms = (hrtime(true) - $started) / 1e6;
            $statements = $this->statements;
            $peak = memory_get_peak_usage() - $memory;
            $after = $this->counters(true);

            $results[] = [
                'phase' => $phase->name(),
                'priority' => $phase->priority(),
                'ms' => round($ms, 1),
                'statements' => $statements,
                'rows_read' => $after === null || $before === null ? null : $after['read'] - $before['read'],
                'rows_written' => $after === null || $before === null ? null : $after['written'] - $before['written'],
                'memory_kb' => (int) round(max(0, $peak) / 1024),
                'error' => $error,
                'ran' => $ran,
                'failed' => $failed,
                'skipped' => $skipped,
                'overrun' => $overrun,
                'complete' => $ran && ! $failed && ! $skipped && ! $overrun,
            ] + ($name === 'retention' ? $this->retentionThroughput($ms) : []);
        }

        return $results;
    }

    /**
     * The rows that the retention phase deleted (from its state row,
     * Retention::STATE_KEY), and the deleted rows per second of the phase.
     *
     * @return array{retention_deleted: ?int, retention_rows_per_second: ?int, retention_behind: ?bool}
     */
    private function retentionThroughput(float $ms): array
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', Retention::STATE_KEY)->value('value');
        $state = is_string($value) ? json_decode($value, true) : null;
        $deleted = is_array($state) && is_int($state['deleted'] ?? null) ? $state['deleted'] : null;

        return [
            'retention_deleted' => $deleted,
            'retention_rows_per_second' => $deleted === null || $ms <= 0 ? null : (int) round($deleted / ($ms / 1000)),
            'retention_behind' => is_array($state) && is_bool($state['behind'] ?? null) ? $state['behind'] : null,
        ];
    }

    /**
     * The fixed rows that do not scale with N: the samples, health windows,
     * heartbeats, the monitoring version and one monitored tag.
     */
    private function seedFixed(): void
    {
        $mirror = $this->mirror();
        $now = Clock::now();
        $old = $now - 30 * 86_400;

        $mirror->table('orrery_settings')->insert(['key' => MonitoredTagCache::SETTING, 'value' => '1']);
        $mirror->table('orrery_monitored_tags')->insert(['tag' => 'bench:tag0', 'created_at' => Clock::toDecimal($old)]);

        $current = SampleCounts::minuteOf($now);
        $samples = [];

        for ($minute = $current - 25 * 3600; $minute < $current; $minute += 60) {
            $samples[] = ['scope' => 'total', 'series' => SampleCounts::series('total'), 'connection' => null, 'queue' => null, 'name' => null, 'minute' => $minute, 'completed' => 100, 'failed' => 10, 'runtime_ms_sum' => 10_000, 'runtime_count' => 100, 'ready' => null, 'workers' => null, 'oldest_age' => null, 'over_limit' => null];

            for ($q = 0; $q < self::QUEUES; $q++) {
                $samples[] = ['scope' => 'queue', 'series' => SampleCounts::series('queue', 'database', 'q'.$q), 'connection' => 'database', 'queue' => 'q'.$q, 'name' => null, 'minute' => $minute, 'completed' => 10, 'failed' => 1, 'runtime_ms_sum' => 1000, 'runtime_count' => 10, 'ready' => 50, 'workers' => 2, 'oldest_age' => '30.000000', 'over_limit' => false];
            }

            for ($j = 0; $j < 5; $j++) {
                $samples[] = ['scope' => 'job', 'series' => SampleCounts::series('job', 'App\\Jobs\\Bench'.$j), 'connection' => null, 'queue' => null, 'name' => 'App\\Jobs\\Bench'.$j, 'minute' => $minute, 'completed' => 20, 'failed' => 2, 'runtime_ms_sum' => 2000, 'runtime_count' => 20, 'ready' => null, 'workers' => null, 'oldest_age' => null, 'over_limit' => null];
            }
        }

        $this->insertChunks($mirror, SampleCounts::TABLE, $samples);
        $mirror->table('orrery_settings')->insert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) ($current - 60)],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal($now - 60)],
        ]);

        $windows = [];

        for ($w = 0; $w < 1010; $w++) {
            $start = $w < 1000 ? $old - $w : $now - 3600 - $w;
            $windows[] = ['context' => 'worker', 'process' => 'bench:'.$w, 'started_at' => Clock::toDecimal($start), 'ended_at' => $w < 1000 ? Clock::toDecimal($start + 10) : null, 'dropped' => 1, 'unmatched_observations' => 0, 'unverified' => 0, 'last_error_operation' => null, 'last_error_at' => null];
        }

        $this->insertChunks($mirror, 'orrery_health_events', $windows);

        $heartbeats = [];

        for ($h = 0; $h < 50; $h++) {
            $seen = $h % 2 === 0 ? $now - 5 : $now - 3600;
            $heartbeats[] = ['worker_id' => 'bench:'.$h.':1', 'host' => 'bench', 'pid' => 1000 + $h, 'started_at' => Clock::toDecimal($now - 7200), 'ppid' => null, 'parent_started_at' => null, 'mode' => 'daemon', 'connection' => 'database', 'subscribed_queues' => 'q'.($h % self::QUEUES), 'current_queue' => null, 'options' => '{}', 'last_seen_at' => Clock::toDecimal($seen)];
        }

        $this->insertChunks($mirror, 'orrery_worker_heartbeats', $heartbeats);
    }

    /**
     * The unresolved bulk groups and their attempt keys (the
     * class comment has the mix). The rows have mirror row numbers above
     * BULK_BASE, so they never meet a scaled row.
     */
    private function seedBulk(): void
    {
        $mirror = $this->mirror();
        $now = (int) Clock::now();
        $payload = $this->payload(self::uuid(self::BULK_BASE), $this->payloadBytes());
        $rows = [];
        $keys = [];

        for ($g = 0; $g < 100; $g++) {
            $queue = 'q'.($g % self::QUEUES);
            // A quarter reads the jobs table from near its start (each group its own floor), the others above every id.
            $floor = $g % 4 === 0 ? $g : 1_000_000_000 + $g;

            for ($r = 0; $r < 20; $r++) {
                $i = self::BULK_BASE + $g * 20 + $r;
                $row = $this->mirrorRow($i, self::uuid($i), $queue, $payload, 50, $now);
                $rows[] = ['job_id' => null, 'job_id_unresolved' => true, 'bulk_floor_id' => $floor, 'available_at' => Clock::toDecimal($now - 3600), 'pushed_at' => Clock::toDecimal($now - 3600)] + $row;
            }

            if ($g % 3 !== 2) {
                $keys[] = $this->attemptKey('database', $queue, $floor, $g % 3 === 0 ? BulkResolver::TICK_ATTEMPTS : 1, $g % 3 === 0);
            }
        }

        for ($k = 0; $k < 500; $k++) {
            $keys[] = $this->attemptKey('database', 'q'.($k % self::QUEUES), 2_000_000_000 + $k, 1, false);
        }

        $this->transaction($mirror, function () use ($mirror, $rows, $keys): void {
            $this->insertChunks($mirror, 'orrery_jobs', $rows);
            $this->insertChunks($mirror, 'orrery_settings', $keys);
        });
    }

    /**
     * One `bulk_resolution:` attempt key, as BulkResolver stores it.
     *
     * @return array{key: string, value: string}
     */
    private function attemptKey(string $connection, string $queue, int $floor, int $attempts, bool $scanned): array
    {
        return [
            'key' => BulkResolver::ATTEMPTS_KEY.sha1($connection."\0".$queue."\0".$floor),
            'value' => (string) json_encode([
                'connection' => $connection,
                'queue' => $queue,
                'names' => base64_encode($connection."\0".$queue),
                'floor' => $floor,
                'attempts' => $attempts,
                'cursor' => null,
                'scanned' => $scanned,
            ]),
        ];
    }

    /**
     * Seeds the rows i = $from .. $to - 1 of every scaled table.
     */
    private function seedRows(int $from, int $to): void
    {
        $jobs = DB::connection(TestEnvironment::PRIMARY);
        $mirror = $this->mirror();
        $now = (int) Clock::now();
        $payloadBytes = $this->payloadBytes();
        $target = $this->providerTarget();
        $chunk = $this->engine() === 'sqlite' ? 20_000 : 5_000;

        for ($start = $from; $start < $to; $start += $chunk) {
            $end = min($to, $start + $chunk);
            $jobRows = [];
            $mirrorRows = [];
            $failedRows = [];
            $tagRows = [];

            for ($i = $start; $i < $end; $i++) {
                $uuid = self::uuid($i);
                $queue = 'q'.($i % self::QUEUES);
                $category = $i % 100;
                $payload = $this->payload($uuid, $payloadBytes);

                $jobRows[] = [
                    'id' => $i + 1,
                    'queue' => $queue,
                    'payload' => $payload,
                    'attempts' => $category >= 90 ? 1 : 0,
                    'reserved_at' => match (true) {
                        $category >= 95 => $now - 7200,
                        $category >= 90 => $now - 5,
                        default => null,
                    },
                    'available_at' => $category >= 80 && $category < 90 ? $now + 3600 : $now - 600 - ($i % 3600),
                    'created_at' => $now - 600 - ($i % 3600),
                ];

                $row = $this->mirrorRow($i, $uuid, $queue, $payload, $category, $now);

                if ($category >= 65 && $category < 85) {
                    $failedRows[] = ['id' => $i + 1, 'uuid' => $uuid, 'connection' => 'database', 'queue' => $queue, 'payload' => '{"uuid":"'.$uuid.'"}', 'exception' => 'RuntimeException: bench', 'failed_at' => date('Y-m-d H:i:s', (int) $row['failed_at'])];
                    $row['provider_id'] = $i + 1;
                    $row['provider_target'] = $target;
                }

                if ($category >= 90) {
                    $tagRows[] = ['tag' => 'bench:tag'.($i % 5), 'job_uuid' => $uuid, 'pushed_at' => $row['pushed_at']];
                }

                $mirrorRows[] = $row;
            }

            $this->transaction($jobs, function () use ($jobs, $jobRows, $failedRows): void {
                $this->insertChunks($jobs, 'jobs', $jobRows);
                $this->insertChunks($jobs, 'failed_jobs', $failedRows);
            });

            $this->transaction($mirror, function () use ($mirror, $mirrorRows, $tagRows): void {
                $this->insertChunks($mirror, 'orrery_jobs', $mirrorRows);
                $this->insertChunks($mirror, 'orrery_job_tags', $tagRows);
            });
        }

        if ($this->engine() === 'pgsql') {
            // The explicit ids leave the sequences behind.
            foreach ([[$jobs, 'jobs'], [$jobs, 'failed_jobs'], [$mirror, 'orrery_jobs']] as [$connection, $table]) {
                $name = $connection->getTablePrefix().$table;
                $connection->statement("select setval(pg_get_serial_sequence('{$name}', 'id'), (select max(id) from {$name}))");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mirrorRow(int $i, string $uuid, string $queue, string $payload, int $category, int $now): array
    {
        $old = $i % 2 === 0;
        $status = match (true) {
            $category < 40 || $category >= 90 => 'completed',
            $category < 60 => 'pending',
            $category < 65 => 'reserved',
            $category < 85 => 'failed',
            default => 'unknown',
        };

        $finished = match ($status) {
            'completed' => $old ? $now - 7200 : $now - 60,
            'failed' => $old ? $now - 8 * 86_400 : $now - 3600,
            'unknown' => $old ? $now - 8 * 86_400 : $now - 3600,
            default => null,
        };

        $jobId = match ($status) {
            'pending' => $category < 45 ? 100_000_000 + $i : $i + 1,
            'reserved' => $i + 1,
            default => null,
        };

        return [
            'uuid' => $uuid,
            'cycle' => 1,
            'cycle_gen' => substr(hash('sha256', $uuid), 0, 32),
            'cycle_first_job_id' => $i + 1,
            'cycle_started_at' => Clock::toDecimal($now - 7200),
            'cycle_source' => 'push',
            'occurrence_id' => $status === 'failed' ? $i + 1 : null,
            'job_id' => $jobId,
            'provider_id' => null,
            'provider_target' => null,
            'connection' => 'database',
            'queue' => $queue,
            'name' => 'App\\Jobs\\Bench'.($i % 20),
            'status' => $status,
            'silenced' => $status === 'completed' && $category < 40 && $i % 10 === 9,
            'monitored' => false,
            'monitor_version' => $category >= 90 ? 0 : null,
            'payload' => $payload,
            'payload_bytes' => strlen($payload),
            'attempts' => $status === 'pending' ? 0 : 1,
            'observed_attempts' => in_array($status, ['completed', 'failed'], true) ? 1 : 0,
            'last_outcome_attempt' => in_array($status, ['completed', 'failed'], true) ? 1 : 0,
            'reserved_by' => $status === 'reserved' ? 'bench:'.($i % 50).':1' : null,
            'pushed_at' => Clock::toDecimal($now - 7200 - ($i % 3600)),
            'available_at' => Clock::toDecimal($now - 7200),
            'reserved_at' => $status === 'pending' ? null : Clock::toDecimal($now - 7100),
            'finished_at' => $finished === null ? null : Clock::toDecimal($finished),
            'failed_at' => $status === 'failed' ? Clock::toDecimal((float) $finished) : null,
            'unknown_since' => $status === 'unknown' ? Clock::toDecimal((float) $finished) : null,
            'unknown_reason' => $status === 'unknown' ? 'absent' : null,
            'activity_at' => Clock::toDecimal($finished ?? $now - 7100),
            'updated_at' => Clock::toDecimal($finished ?? $now - 7100),
        ];
    }

    private function payload(string $uuid, int $bytes): string
    {
        $head = '{"uuid":"'.$uuid.'","displayName":"App\\\\Jobs\\\\Bench","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"timeout":null,"data":{"commandName":"App\\\\Jobs\\\\Bench","command":"';
        $tail = '"}}';

        return $head.str_repeat('x', max(0, $bytes - strlen($head) - strlen($tail))).$tail;
    }

    /**
     * The target of failed_jobs as the package's provider binds it,
     * so reconcile_failed checks the failed rows.
     */
    private function providerTarget(): string
    {
        if ($this->target !== null) {
            return $this->target;
        }

        $records = FailedRecords::for($this->app, $this->app['config']);

        if (! $records instanceof SqlFailedRecords) {
            throw new RuntimeException('The benchmark needs the database-uuids failed-job provider.');
        }

        return $this->target = $records->target();
    }

    private function addIndex(): void
    {
        $this->indexMigration()->up();
    }

    private function dropIndex(): void
    {
        $this->indexMigration()->down();
    }

    private function indexMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require MonitoringModule::source();

        return $migration;
    }

    /**
     * Closes every package session, so the next phase opens new ones, and
     * a Postgres backend flushes its statistics when it ends.
     */
    private function newSessions(): void
    {
        PackageSession::releaseAll();

        foreach (array_keys(DB::getConnections()) as $name) {
            if (str_starts_with((string) $name, 'orrery.')) {
                DB::purge($name);
            }
        }
    }

    /**
     * The rows read and written so far.
     *
     * @return array{read: int, written: int}|null
     */
    private function counters(bool $afterPhase = false): ?array
    {
        return match ($this->engine()) {
            'mysql' => $this->mysqlCounters(),
            'pgsql' => $this->pgsqlCounters($afterPhase),
            default => null,
        };
    }

    /**
     * The Handler counters of every open MySQL session, summed. The package
     * sessions of a phase are new, so their counters start at 0.
     *
     * @return array{read: int, written: int}
     */
    private function mysqlCounters(): array
    {
        $read = 0;
        $written = 0;

        foreach (DB::getConnections() as $connection) {
            if ($connection->getDriverName() !== 'mysql' || $connection->getRawPdo() === null) {
                continue;
            }

            foreach ($connection->select("show session status where Variable_name like 'Handler_%'") as $row) {
                $row = (array) $row;
                $name = (string) ($row['Variable_name'] ?? '');
                $value = (int) ($row['Value'] ?? 0);

                if (str_starts_with($name, 'Handler_read')) {
                    $read += $value;
                } elseif (in_array($name, ['Handler_write', 'Handler_update', 'Handler_delete'], true)) {
                    $written += $value;
                }
            }
        }

        return ['read' => $read, 'written' => $written];
    }

    /**
     * The tuples read and written in the run's databases, from
     * pg_stat_user_tables. The phase's package sessions end first, so their
     * backends flush their statistics.
     *
     * @return array{read: int, written: int}
     */
    private function pgsqlCounters(bool $afterPhase): array
    {
        if ($afterPhase) {
            $this->newSessions();
            usleep(1_500_000);
        }

        $read = 0;
        $written = 0;
        $seen = [];

        foreach ([DB::connection(TestEnvironment::PRIMARY), $this->mirror()] as $connection) {
            $database = $connection->getDatabaseName();

            if (isset($seen[$database])) {
                continue;
            }

            $seen[$database] = true;
            // This backend's own statements (the seed) are flushed at the end of the next statement.
            $connection->select('select pg_stat_force_next_flush()');
            $connection->select('select pg_stat_clear_snapshot()');
            $row = (array) $connection->selectOne('select coalesce(sum(seq_tup_read + coalesce(idx_tup_fetch, 0)), 0) as r, coalesce(sum(n_tup_ins + n_tup_upd + n_tup_del), 0) as w from pg_stat_user_tables');
            $read += (int) $row['r'];
            $written += (int) $row['w'];
        }

        return ['read' => $read, 'written' => $written];
    }

    /**
     * The bytes of each table of the run, the indexes included.
     *
     * @return array<string, int>
     */
    private function tableBytes(): array
    {
        $tables = ['jobs', 'failed_jobs', 'orrery_jobs', 'orrery_job_tags'];
        $bytes = [];

        foreach ($tables as $table) {
            $connection = str_starts_with($table, 'orrery_') ? $this->mirror() : DB::connection(TestEnvironment::PRIMARY);
            $name = $connection->getTablePrefix().$table;

            $bytes[$table] = match ($this->engine()) {
                'mysql' => $this->mysqlTableBytes($connection, $name),
                'pgsql' => (int) $connection->selectOne("select pg_total_relation_size('{$name}') as b")->b,
                default => $this->sqliteTableBytes($connection, $name),
            };
        }

        return $bytes;
    }

    private function mysqlTableBytes(Connection $connection, string $name): int
    {
        $connection->statement("analyze table `{$name}`");
        $row = $connection->selectOne('select data_length + index_length as b from information_schema.tables where table_schema = database() and table_name = ?', [$name]);

        return (int) ($row->b ?? 0);
    }

    private function sqliteTableBytes(Connection $connection, string $name): int
    {
        // The dbstat table exists only when SQLite was built with it; then
        // the table and its indexes are summed, else the whole file counts.
        try {
            $row = $connection->selectOne("select sum(pgsize) as b from dbstat where name = ? or name in (select name from sqlite_master where type = 'index' and tbl_name = ?)", [$name, $name]);

            return (int) ($row->b ?? 0);
        } catch (Throwable) {
            return (int) filesize((string) $connection->getDatabaseName());
        }
    }

    /**
     * The bytes that every database of the server uses now (the tmpfs
     * budget of bin/benchmark-tick), or the database files on SQLite.
     */
    private function serverBytes(): int
    {
        $connection = DB::connection(TestEnvironment::PRIMARY);

        // MySQL caches the information_schema sizes (24 hours by default), so
        // without this the server-wide sum misses the rows the run just wrote.
        if ($this->engine() === 'mysql') {
            $connection->statement('SET SESSION information_schema_stats_expiry = 0');
        }

        return match ($this->engine()) {
            'mysql' => (int) ($connection->selectOne('select coalesce(sum(data_length + index_length), 0) as b from information_schema.tables')->b ?? 0),
            'pgsql' => (int) ($connection->selectOne('select coalesce(sum(pg_database_size(datname)), 0) as b from pg_database')->b ?? 0),
            default => (int) filesize((string) $connection->getDatabaseName()),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunks(Connection $connection, string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // Stay below the bound-parameter limits (SQLite 32,766, MySQL and Postgres 65,535).
        $size = max(1, intdiv(30_000, count($rows[0])));

        foreach (array_chunk($rows, $size) as $chunk) {
            $connection->table($table)->insert($chunk);
        }
    }

    private function transaction(Connection $connection, callable $work): void
    {
        $connection->beginTransaction();

        try {
            $work();
            $connection->commit();
        } catch (Throwable $error) {
            $connection->rollBack();

            throw $error;
        }
    }

    private static function requireOptIn(): void
    {
        if (getenv('ORRERY_BENCHMARK') !== '1') {
            self::markTestSkipped('The tick benchmark runs only through bin/benchmark-tick (ORRERY_BENCHMARK=1).');
        }
    }

    private function engine(): string
    {
        return $this->testEnvironment()->driver;
    }

    private function payloadBytes(): int
    {
        return max(200, (int) (getenv('ORRERY_BENCHMARK_PAYLOAD') ?: 700));
    }

    private static function uuid(int $i): string
    {
        return sprintf('0b22be0c-%04x-4%03x-8%03x-%012x', ($i >> 24) & 0xFFFF, ($i >> 12) & 0xFFF, $i & 0xFFF, $i);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function write(array $report): void
    {
        $path = getenv('ORRERY_BENCHMARK_OUT');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Set ORRERY_BENCHMARK_OUT to the result file.');
        }

        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
