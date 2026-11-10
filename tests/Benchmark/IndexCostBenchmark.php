<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Benchmark;

use Illuminate\Database\Connection;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Benchmark\Jobs\ReleaseOnceJob;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * The cost of the deferred indexes (D-9). Not in
 * any test suite; run it by path through bin/test with ORRERY_BENCHMARK=1
 * (without it both tests skip), for example:
 *
 *   ORRERY_BENCHMARK=1 ORRERY_BENCHMARK_OUTPUT=/tmp/cost.json \
 *     bin/test sqlite --sqlite-file -- tests/Benchmark/IndexCostBenchmark.php
 *
 * test_write_cost runs ORRERY_BENCHMARK_CYCLES (default 10,000) untagged
 * push, reserve and complete cycles with `st_attempts` and `st_gap` and as
 * many without them, in blocks of 1,000 in the order A B B A (so the growth
 * of the table weighs on both alike), through the real queue and worker. It
 * times only the package's own statements, through the session recorder:
 * from the recorder's `statement` entry (before the statement) to its
 * `executed` entry (after it). The report gives the median and the 90th
 * percentile of each statement (push insert, reserve update, completion
 * read and update) per variant, and the ratio of the medians.
 *
 * Two options narrow it:
 * - ORRERY_BENCHMARK_INDEXES: a comma list of the deferred index suffixes
 *   that the variants add and drop (default: all of them); the others stay.
 *   For example `st_releases` measures that index alone.
 * - ORRERY_BENCHMARK_JOB=release: each cycle pushes a job that attempt 1
 *   releases and attempt 2 completes (Jobs\ReleaseOnceJob), so the report
 *   also has the `release update` (the statement that changes `releases`).
 *
 * test_index_build seeds orrery_jobs to each size of
 * ORRERY_BENCHMARK_BUILD_SIZES (cumulative, default 500000,1000000) without
 * the deferred indexes, and times `orrery:install --indexes` at each size.
 * On MySQL and Postgres, every size must fit 60% of the container's tmpfs
 * less the used bytes and 200 MB (the bytes per row measured at the first
 * size); a size that does not fit is refused before it is seeded, and the
 * report extrapolates linearly to 1,000,000 rows through the two sizes.
 */
#[FreshSchema]
final class IndexCostBenchmark extends TestCase
{
    private const BLOCK = 1000;

    /** The tmpfs of the test containers, in MB (docker/testing/compose.yaml). */
    private const TMPFS_MB = ['mysql' => 512, 'pgsql' => 1024];

    private string $phase = 'push';

    private bool $recording = false;

    /** @var array<string, array<string, list<float>>> seconds by variant and statement */
    private array $times = [];

    private string $variant = 'with';

    /** @var array{0: string, 1: float}|null */
    private ?array $open = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('ORRERY_BENCHMARK') !== '1') {
            self::markTestSkipped('The index cost benchmark runs only with ORRERY_BENCHMARK=1.');
        }

        Watcher::freezeMonotonicForTesting();
    }

    public function test_write_cost(): void
    {
        $cycles = (int) (getenv('ORRERY_BENCHMARK_CYCLES') ?: 10_000);
        $blocks = max(1, intdiv($cycles, self::BLOCK));
        $mirror = $this->mirror();
        $release = getenv('ORRERY_BENCHMARK_JOB') === 'release';

        $this->app['events']->listen(JobProcessing::class, function (): void {
            $this->phase = 'complete';
        });
        PackageStatements::each(function (string $kind, string $sql, ?string $session): void {
            if (! $this->recording) {
                return;
            }

            if ($kind === PackageSession::STATEMENT) {
                $this->open = [$sql, hrtime(true) / 1e9];
            } elseif ($kind === PackageSession::EXECUTED && $this->open !== null && $this->open[0] === $sql) {
                $elapsed = hrtime(true) / 1e9 - $this->open[1];
                $this->open = null;
                $label = $this->label($sql);

                if ($label !== null) {
                    $this->times[$this->variant][$label][] = $elapsed;
                }
            }
        });

        if ($this->testEnvironment()->inMemory()) {
            throw new RuntimeException('Run the benchmark on a SQLite file, MySQL or Postgres: in memory the package session is the application connection.');
        }

        // A B B A: with, without, without, with, ...
        $order = [];

        for ($block = 0; $block < $blocks * 2; $block++) {
            $order[] = in_array($block % 4, [0, 3], true) ? 'with' : 'without';
        }

        $started = microtime(true);

        foreach ($order as $variant) {
            $this->useVariant($mirror, $variant);
            $this->variant = $variant;

            for ($i = 0; $i < self::BLOCK; $i++) {
                $this->phase = 'push';
                $this->recording = true;
                Queue::connection('database')->push($release ? new ReleaseOnceJob : new BasicJob);
                $this->phase = 'reserve';
                $this->work();

                if ($release) {
                    $this->phase = 'reserve';
                    $this->work();
                }

                $this->recording = false;
            }

            // The jobs table stays empty; the mirror keeps the completed rows, as within a retention window.
        }

        $report = ['engine' => $mirror->getDriverName(), 'cycles_per_variant' => $blocks * self::BLOCK, 'indexes' => $this->toggled(), 'job' => $release ? 'release' : 'basic', 'seconds' => round(microtime(true) - $started, 1), 'statements' => []];

        foreach (['push insert', 'reserve update', 'release update', 'complete read', 'complete update'] as $label) {
            $with = $this->times['with'][$label] ?? [];
            $without = $this->times['without'][$label] ?? [];
            $report['statements'][$label] = [
                'with_median_ms' => self::percentile($with, 0.5),
                'without_median_ms' => self::percentile($without, 0.5),
                'with_p90_ms' => self::percentile($with, 0.9),
                'without_p90_ms' => self::percentile($without, 0.9),
                'median_ratio' => self::percentile($without, 0.5) > 0 ? round(self::percentile($with, 0.5) / self::percentile($without, 0.5), 3) : null,
                'samples' => [count($with), count($without)],
            ];
        }

        $this->write('write_cost', $report);
        self::assertNotSame([], $this->times);
    }

    public function test_index_build(): void
    {
        $mirror = $this->mirror();
        $driver = $mirror->getDriverName();
        $sizes = array_map('intval', explode(',', (string) (getenv('ORRERY_BENCHMARK_BUILD_SIZES') ?: '500000,1000000')));
        $this->useVariant($mirror, 'without');
        $seeded = 0;
        $bytesPerRow = null;
        $results = [];
        $before = $this->usedBytes($mirror);

        foreach ($sizes as $size) {
            if ($bytesPerRow !== null && isset(self::TMPFS_MB[$driver])) {
                $budget = 0.6 * self::TMPFS_MB[$driver] * 1048576 - $this->usedBytes($mirror) - 200 * 1048576;

                if (($size - $seeded) * $bytesPerRow > $budget) {
                    throw new RuntimeException("The size {$size} does not fit the tmpfs budget (".round($budget / 1048576).' MB left, '.round($bytesPerRow).' bytes per row).');
                }
            }

            $this->seedRows($mirror, $seeded, $size);
            $seeded = $size;

            if ($bytesPerRow === null) {
                $bytesPerRow = max(1, ($this->usedBytes($mirror) - $before) / $size);
            }

            $started = microtime(true);
            $code = Artisan::call('orrery:install', ['--indexes' => true]);
            $seconds = microtime(true) - $started;
            self::assertSame(0, $code, Artisan::output());
            $results[] = ['rows' => $size, 'seconds' => round($seconds, 2)];
            $this->useVariant($mirror, 'without');
        }

        $report = ['engine' => $driver, 'bytes_per_row_without_deferred' => round((float) $bytesPerRow), 'builds' => $results];

        if (count($results) >= 2 && end($results)['rows'] < 1_000_000) {
            [$a, $b] = array_slice($results, -2);
            $slope = ($b['seconds'] - $a['seconds']) / max(1, $b['rows'] - $a['rows']);
            $report['extrapolated_1m_seconds'] = round($b['seconds'] + $slope * (1_000_000 - $b['rows']), 1);
        }

        $this->write('index_build', $report);
    }

    private function useVariant(Connection $mirror, string $variant): void
    {
        $existing = array_map('strtolower', array_column($mirror->getSchemaBuilder()->getIndexes('orrery_jobs'), 'name'));

        foreach (array_intersect_key(PackageSchema::DEFERRED_INDEXES, array_flip($this->toggled())) as $suffix => $columns) {
            $name = IndexName::for($mirror, 'orrery_jobs', $suffix);
            $has = in_array(strtolower($name), $existing, true);

            $mirror->getSchemaBuilder()->table('orrery_jobs', static function ($table) use ($variant, $has, $name, $columns): void {
                if ($variant === 'with' && ! $has) {
                    $table->index($columns, $name);
                } elseif ($variant === 'without' && $has) {
                    $table->dropIndex($name);
                }
            });
        }
    }

    /**
     * The deferred index suffixes that the variants add and drop
     * (ORRERY_BENCHMARK_INDEXES, default all).
     *
     * @return list<string>
     */
    private function toggled(): array
    {
        $listed = getenv('ORRERY_BENCHMARK_INDEXES');
        $all = array_keys(PackageSchema::DEFERRED_INDEXES);

        if (! is_string($listed) || trim($listed) === '') {
            return $all;
        }

        $suffixes = array_values(array_filter(array_map('trim', explode(',', $listed)), static fn (string $suffix): bool => $suffix !== ''));
        $unknown = array_diff($suffixes, $all);

        if ($unknown !== []) {
            throw new RuntimeException('ORRERY_BENCHMARK_INDEXES names no deferred index: '.implode(', ', $unknown).'.');
        }

        return $suffixes;
    }

    private function label(string $sql): ?string
    {
        $sql = strtolower($sql);

        if (! str_contains($sql, 'orrery_jobs')) {
            return null;
        }

        return match (true) {
            // The release of attempt 1 (ORRERY_BENCHMARK_JOB=release): the update that adds to `releases`.
            str_starts_with($sql, 'update') && str_contains($sql, 'releases') => 'release update',
            $this->phase === 'push' && str_starts_with($sql, 'insert') => 'push insert',
            $this->phase === 'reserve' && str_starts_with($sql, 'update') => 'reserve update',
            $this->phase === 'complete' && str_starts_with($sql, 'select') => 'complete read',
            $this->phase === 'complete' && str_starts_with($sql, 'update') => 'complete update',
            default => null,
        };
    }

    private function seedRows(Connection $mirror, int $from, int $to): void
    {
        $chunk = $mirror->getDriverName() === 'sqlite' ? 40 : 1000;
        $rows = [];

        for ($n = $from + 1; $n <= $to; $n++) {
            $time = Clock::toDecimal(1_790_000_000 + $n);
            $rows[] = [
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $n),
                'connection' => 'database',
                'queue' => 'default',
                'name' => 'App\\Jobs\\Sync',
                'status' => $n % 10 === 0 ? 'failed' : 'completed',
                'payload' => '{}',
                'payload_bytes' => 2,
                'attempts' => $n % 3 + 1,
                'observed_gap' => $n % 50 === 0 ? 1 : 0,
                'pushed_at' => $time,
                'finished_at' => $time,
                'updated_at' => $time,
            ];

            if (count($rows) === $chunk) {
                $mirror->table('orrery_jobs')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $mirror->table('orrery_jobs')->insert($rows);
        }
    }

    private function usedBytes(Connection $mirror): float
    {
        return match ($mirror->getDriverName()) {
            // information_schema caches the table sizes for a day unless the session asks for fresh ones.
            'mysql', 'mariadb' => (float) ($mirror->statement('SET SESSION information_schema_stats_expiry = 0') ? $mirror->scalar('select coalesce(sum(data_length + index_length), 0) from information_schema.tables') : 0),
            'pgsql' => (float) $mirror->scalar('select sum(pg_database_size(datname)) from pg_database'),
            default => 0.0,
        };
    }

    /**
     * @param  list<float>  $values  seconds
     */
    private static function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return round($values[(int) floor($p * (count($values) - 1))] * 1000, 4);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function write(string $name, array $report): void
    {
        $output = getenv('ORRERY_BENCHMARK_OUTPUT');
        $json = (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        fwrite(STDERR, "{$name}: {$json}\n");

        if (is_string($output) && $output !== '') {
            file_put_contents($output.'.'.$name.'.json', $json);
        }
    }
}
