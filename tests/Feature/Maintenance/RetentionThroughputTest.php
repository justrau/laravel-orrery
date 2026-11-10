<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Maintenance;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\HookedJob;
use Justrau\Orrery\Tests\Feature\Retention\RetentionTestCase;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * The retention throughput and its state: no row cap
 * per run, the pause between batches on a SQLite file, the lookahead of the
 * unfinished partitions, and the settings row `retention_state`.
 */
final class RetentionThroughputTest extends RetentionTestCase
{
    /** The completed retention (trim.completed = 60 minutes), in seconds. */
    private const COMPLETED_SECONDS = 3600;

    private const NOW = 1_790_000_000.0;

    /** @var list<int> the microseconds of each pause of the run */
    private array $pauses = [];

    protected function setUp(): void
    {
        parent::setUp();

        // No real sleep: each pause is recorded and passes on the monotonic clock.
        Retention::useLockModeForTesting(null, function (int $microseconds): void {
            $this->pauses[] = $microseconds;
            Clock::advanceMonotonicForTesting($microseconds / 1e6);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: the cap of 5,000 rows per run.
     */
    public function test_one_run_deletes_more_than_5000_due_rows(): void
    {
        $this->freeze();
        // A share that a slow machine cannot use up: the test checks the cap, not the time.
        Retention::useLimitsForTesting(seconds: 3600);
        $this->seedDue(12_000, self::NOW - 7200);
        $kept = $this->jobRow(['finished_at' => self::NOW - 60]);

        self::assertSame(12_000, $this->retention()->run());

        self::assertSame(1, $this->mirror()->table('orrery_jobs')->count(), 'One run deleted every due row.');
        self::assertTrue($this->exists($kept), 'The row inside its retention stays.');
        self::assertSame(['at' => self::NOW, 'deleted' => 12_000, 'behind' => false, 'lag_seconds' => null, 'partitions_unchecked' => 0], $this->state());
    }

    /**
     * Rejects: the lag of the last deleted candidate (the ancient batch), instead of a row that is still due.
     */
    public function test_lag_is_read_from_a_row_that_is_still_due(): void
    {
        $this->freeze();
        Retention::useLimitsForTesting(batch: 5);
        $this->seedDue(5, self::NOW - 30 * 86_400);
        // Five seconds past its cutoff.
        $due = $this->jobRow(['finished_at' => self::NOW - self::COMPLETED_SECONDS - 5]);
        $this->endTimeShareAtSelect(1);

        self::assertSame(5, $this->retention()->run());

        self::assertTrue($this->exists($due), 'The time share ended after the ancient batch.');
        $state = $this->state();
        self::assertTrue($state['behind'], 'The lookahead found a due row.');
        self::assertIsFloat($state['lag_seconds']);
        self::assertEqualsWithDelta(5.0, $state['lag_seconds'], 0.001, 'The lag is the lag of the row that is still due.');
        self::assertSame([5, 0], [$state['deleted'], $state['partitions_unchecked']]);
    }

    /**
     * Rejects: `behind: true` from the length of a full batch, without a read of a row that is still due.
     */
    public function test_exact_final_batch_is_not_behind(): void
    {
        $this->freeze();
        Retention::useLimitsForTesting(batch: Retention::BATCH);
        $this->seedDue(Retention::BATCH, self::NOW - 7200);

        // The run continues after the full batch and finds the range empty.
        self::assertSame(Retention::BATCH, $this->retention()->run());
        self::assertSame(['at' => self::NOW, 'deleted' => Retention::BATCH, 'behind' => false, 'lag_seconds' => null, 'partitions_unchecked' => 0], $this->state());

        // The run stops right after the full batch: the lookahead finds no due row.
        $this->seedDue(Retention::BATCH, self::NOW - 7200);
        $this->endTimeShareAtSelect(1);

        self::assertSame(Retention::BATCH, $this->retention()->run());
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->count());
        self::assertSame(['at' => self::NOW, 'deleted' => Retention::BATCH, 'behind' => false, 'lag_seconds' => null, 'partitions_unchecked' => 0], $this->state());
    }

    /**
     * Rejects: `behind: false` while a partition that the run never visited can still hold due rows.
     */
    public function test_unvisited_partition_gives_unknown(): void
    {
        $this->freeze();
        Retention::useLimitsForTesting(batch: 2);
        // Queue a: exactly one full batch. Queue b: never visited.
        $this->seedDue(2, self::NOW - 7200, 'a');
        $this->seedDue(4, self::NOW - 7200, 'b');
        $this->endTimeShareAtSelect(1);

        self::assertSame(2, $this->retention()->run());

        self::assertSame(4, $this->mirror()->table('orrery_jobs')->where('queue', 'b')->count(), 'Queue b was not visited.');
        self::assertSame(['at' => self::NOW, 'deleted' => 2, 'behind' => null, 'lag_seconds' => null, 'partitions_unchecked' => 1], $this->state());
    }

    /**
     * Rejects: a lookahead without a bound (one read per unfinished partition, however many).
     */
    public function test_lookahead_checks_at_most_20_partitions(): void
    {
        $this->freeze();
        Retention::useLimitsForTesting(batch: 1);

        for ($queue = 0; $queue < 25; $queue++) {
            $this->seedDue(2, self::NOW - 7200 - $queue, sprintf('q%02d', $queue));
        }

        // After the 25th batch only the lookahead reads orrery_jobs with `limit 1`.
        $reads = null;
        $this->endTimeShareAtSelect(25, static function () use (&$reads): void {
            $reads = 0;
        });
        DB::listen(static function ($query) use (&$reads): void {
            if ($reads !== null && str_contains($query->sql, 'limit 1') && str_contains($query->sql, 'orrery_jobs') && ! str_contains($query->sql, 'orrery_settings')) {
                $reads++;
            }
        });

        self::assertSame(25, $this->retention()->run());

        $state = $this->state();
        self::assertSame([25, true, 5], [$state['deleted'], $state['behind'], $state['partitions_unchecked']], 'The lookahead checked 20 of the 25 unfinished partitions.');
        // q00 is the newest partition: its remaining row is 7,200 − 3,600 s past its cutoff.
        self::assertEqualsWithDelta(3600.0 + 19, $state['lag_seconds'], 0.001, 'The lag is the largest of the 20 checked partitions (q00 to q19).');
        self::assertSame(20, $reads, 'One indexed read per checked partition.');
    }

    /**
     * Rejects: a run without partitions that writes no state, or reports itself unknown.
     */
    public function test_empty_mirror_is_not_behind(): void
    {
        $this->freeze();

        self::assertSame(0, $this->retention()->run());
        self::assertSame(['at' => self::NOW, 'deleted' => 0, 'behind' => false, 'lag_seconds' => null, 'partitions_unchecked' => 0], $this->state());
    }

    /**
     * Rejects: a state written by a run that deleted nothing because its policy is invalid.
     */
    public function test_invalid_policy_writes_no_state(): void
    {
        $this->freeze();
        $this->app['config']->set('orrery.trim.completed', 'never');
        $this->seedDue(3, self::NOW - 7200);

        self::assertSame(0, $this->retention()->run());
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', Retention::STATE_KEY)->value('value'));
    }

    /**
     * Rejects: a state invented without a run (`ok` or `behind: false` from
     * nothing), and `none` on a read error, which would hide a failure.
     */
    public function test_health_returns_the_retention_state(): void
    {
        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        Orrery::auth(fn () => true);
        $this->freeze();

        self::assertSame(['state' => 'none'], $this->getJson('/orrery/api/health')->assertOk()->json('retention'), 'No run wrote the state yet.');

        Retention::useLimitsForTesting(batch: 5);
        $this->seedDue(5, self::NOW - 30 * 86_400);
        $this->jobRow(['finished_at' => self::NOW - self::COMPLETED_SECONDS - 700]);
        $this->endTimeShareAtSelect(1);
        $this->retention()->run();
        Hooks::$callback = null;

        $ok = $this->getJson('/orrery/api/health')->assertOk();
        self::assertSame(['state', 'at', 'deleted', 'behind', 'lag_seconds', 'partitions_unchecked'], array_keys((array) $ok->json('retention')));
        self::assertSame(['ok', self::NOW, 5, true, 0], [$ok->json('retention.state'), $ok->json('retention.at'), $ok->json('retention.deleted'), $ok->json('retention.behind'), $ok->json('retention.partitions_unchecked')]);
        self::assertEqualsWithDelta(700.0, $ok->json('retention.lag_seconds'), 0.001);
        self::assertStringContainsString('"at":1790000000.0', (string) $ok->getContent(), 'Times stay numbers with a fraction.');

        // The read fails: unreadable, never none.
        Watcher::failOperationsForTesting(['health:retention']);
        self::assertSame(['state' => 'unreadable'], $this->getJson('/orrery/api/health')->assertOk()->json('retention'));
        Watcher::failOperationsForTesting([]);

        // A value that is not a retention state is unreadable too.
        foreach (['not json', '[]', '{"at":"soon","deleted":1,"behind":true,"lag_seconds":null,"partitions_unchecked":0}', '{"at":1.0,"deleted":1,"behind":"yes","lag_seconds":null,"partitions_unchecked":0}'] as $value) {
            $this->mirror()->table('orrery_settings')->where('key', Retention::STATE_KEY)->update(['value' => $value]);
            self::assertSame(['state' => 'unreadable'], $this->getJson('/orrery/api/health')->assertOk()->json('retention'), $value);
        }
    }

    /**
     * The `file` lock mode: a 150 ms pause before a batch that follows a
     * write, at most once per 150 ms of work.
     *
     * Rejects: no pause, and a pause after every small batch, which uses up
     * the 10 s share on many small partitions.
     */
    public function test_file_mode_pauses_once_per_150_ms_of_work(): void
    {
        // Only the hook and the pauses move the clock, so the count does not
        // depend on how fast a loaded machine runs the batches.
        Clock::freezeMonotonicForTesting();
        Retention::useLockModeForTesting('file', function (int $microseconds): void {
            $this->pauses[] = $microseconds;
            Clock::advanceMonotonicForTesting($microseconds / 1e6);
        });
        // Three batches that each take 0.2 s of work: a pause before the
        // second and the third batch, and before the empty batch that ends the range.
        Retention::useLimitsForTesting(batch: 2);
        $this->seedDue(6, Clock::now() - 7200);
        Hooks::$callback = static function (string $point): void {
            if ($point === Retention::AFTER_SELECT) {
                Clock::advanceMonotonicForTesting(0.2);
            }
        };

        self::assertSame(6, $this->retention()->run());
        self::assertSame([150_000, 150_000, 150_000], $this->pauses);
        Hooks::$callback = null;

        // Twenty small partitions with batches of 60 ms share their pauses:
        // one per three batches (a pause after each would be 19).
        $this->pauses = [];

        for ($queue = 0; $queue < 20; $queue++) {
            $this->seedDue(1, Clock::now() - 7200, 'small'.$queue);
        }

        Hooks::$callback = static function (string $point): void {
            if ($point === Retention::AFTER_SELECT) {
                Clock::advanceMonotonicForTesting(0.06);
            }
        };

        self::assertSame(20, $this->retention()->run());
        self::assertSame(array_fill(0, 6, 150_000), $this->pauses, 'The small batches share their pauses.');
    }

    /**
     * Rejects: a pause on MySQL, Postgres or in-memory SQLite.
     */
    public function test_rows_mode_does_not_pause(): void
    {
        Retention::useLockModeForTesting('rows', function (int $microseconds): void {
            $this->pauses[] = $microseconds;
        });
        Retention::useLimitsForTesting(batch: 2);
        $this->seedDue(6, Clock::now() - 7200);

        self::assertSame(6, $this->retention()->run());
        self::assertSame([], $this->pauses);
    }

    /**
     * A SQLite mirror is always a file of its own (a mirror on the file
     * of a used connection is refused), so the lock mode is `file` for every
     * SQLite file and `rows` elsewhere; the `shared` mode is gone.
     *
     * Rejects: a `shared` mode left in, and an in-memory database treated as a file.
     */
    public function test_lock_mode_is_file_for_a_sqlite_mirror(): void
    {
        $directory = sys_get_temp_dir().'/orrery-h6-'.bin2hex(random_bytes(4));
        mkdir($directory);
        touch($directory.'/mirror.sqlite');
        touch($directory.'/jobs.sqlite');
        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        });
        $config = $this->app['config'];
        $saved = [$config->get('queue.connections'), $config->get('database.default')];

        try {
            $this->lockModes($config, $directory);
        } finally {
            // The teardown rolls back the fence tables of the queue connections.
            $config->set('queue.connections', $saved[0]);
            $config->set('database.default', $saved[1]);
        }
    }

    private function lockModes(Repository $config, string $directory): void
    {
        $config->set('database.connections.h6_mirror', ['driver' => 'sqlite', 'database' => $directory.'/mirror.sqlite', 'prefix' => '']);
        $config->set('database.connections.h6_jobs', ['driver' => 'sqlite', 'database' => $directory.'/jobs.sqlite', 'prefix' => '']);
        // The same file through another spelling of the path.
        $config->set('database.connections.h6_alias', ['driver' => 'sqlite', 'database' => $directory.'/../'.basename($directory).'/mirror.sqlite', 'prefix' => '']);
        $mirror = DB::connection('h6_mirror');

        $config->set('queue.connections', ['database' => ['driver' => 'database', 'connection' => 'h6_jobs', 'table' => 'jobs']]);
        self::assertSame('file', $this->retention()->lockMode($mirror), 'The jobs table is in another file.');

        // A queue on the mirror's own file through another spelling of the
        // path: the mirror is refused there (D17), and the mode stays `file`.
        $config->set('queue.connections.other', ['driver' => 'database', 'connection' => 'h6_alias', 'table' => 'jobs']);
        self::assertSame('file', $this->retention()->lockMode($mirror));

        $config->set('database.connections.h6_memory', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        self::assertSame('rows', $this->retention()->lockMode(DB::connection('h6_memory')), 'In-memory SQLite has no other process.');
    }

    /**
     * Rejects: a fixed starting partition, so that a run that ends early
     * never reaches the later partitions.
     */
    public function test_the_starting_partition_rotates_between_runs(): void
    {
        Retention::useLimitsForTesting(batch: 2);

        foreach (['a', 'b', 'c'] as $queue) {
            $this->seedDue(4, Clock::now() - 7200, $queue);
        }

        $order = [];

        for ($run = 0; $run < 4; $run++) {
            $before = $this->counts();
            // The share ends at the first select: one batch per run.
            $this->endTimeShareAtSelect(1);
            self::assertSame(2, $this->retention()->run());
            $after = $this->counts();
            $order[] = implode(',', array_keys(array_filter($before, static fn (int $was, string $queue): bool => $was - $after[$queue] === 2, ARRAY_FILTER_USE_BOTH)));
        }

        self::assertSame(['a', 'b', 'c', 'a'], $order);
    }

    /**
     * Rejects: a run that stops before its first batch once the share or
     * the tick budget is used up (on a large mirror it then deletes nothing,
     * run after run), and `behind: false` from a run that deleted nothing.
     */
    public function test_first_batch_runs_after_the_share_ends(): void
    {
        $this->freeze();
        Retention::useLimitsForTesting(batch: 2);
        $this->seedDue(4, self::NOW - 7200, 'a');
        $this->seedDue(4, self::NOW - 7200, 'b');
        $spent = new TickBudget(Clock::monotonic() - 1);

        self::assertSame(2, $this->retention()->run($spent), 'One batch, then the run stops.');
        self::assertSame([true, 1], [$this->state()['behind'], $this->state()['partitions_unchecked']]);

        // The first batch deletes nothing (its candidates are gone before
        // its transaction): no claim that the run is not behind.
        Hooks::$callback = function (string $point): void {
            if ($point === Retention::AFTER_SELECT) {
                $this->mirror()->table('orrery_jobs')->where('queue', 'b')->delete();
            }
        };

        self::assertSame(0, $this->retention()->run($spent));
        self::assertSame(['at' => self::NOW, 'deleted' => 0, 'behind' => null, 'lag_seconds' => null, 'partitions_unchecked' => 1], $this->state());
    }

    /**
     * Rejects: a range that is called finished at the end of its cursor
     * while a due row is left before the cursor.
     */
    public function test_a_range_resumed_from_a_cursor_checks_its_start(): void
    {
        $this->freeze();
        Retention::useLimitsForTesting(batch: 2);
        $this->seedDue(4, self::NOW - 7200);
        $this->endTimeShareAtSelect(1);
        self::assertSame(2, $this->retention()->run(), 'The first run stores a cursor after 2 rows.');
        Hooks::$callback = null;

        // A row that became due before the cursor.
        $older = $this->jobRow(['finished_at' => self::NOW - 9000]);

        self::assertSame(3, $this->retention()->run());
        self::assertFalse($this->exists($older), 'The range went back to its start.');
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->count());
        self::assertSame([false, 0], [$this->state()['behind'], $this->state()['partitions_unchecked']]);
    }

    /**
     * Real job completions (Laravel's pop() and deleteReserved(), each a
     * read and then a write in a DEFERRED transaction) during retention
     * runs, on the database that the runs use.
     *
     * On a SQLite file shared with the jobs table, SQLite fails that upgrade
     * at once while a run holds the write lock, so a finished job can stay
     * reserved. The `shared` lock mode holds the lock at most about 25% of
     * the time (measured: 0.23).
     *
     * Precondition: at least MIN_RESERVED (20) jobs were reserved while a
     * run was deleting. The test pushes 120 jobs and seeds 120,000 due rows
     * once, then repeats retention runs (each up to its 10 s share) until 20
     * jobs were reserved during the runs, or 150 s pass. It never reseeds
     * between runs, so no other writer holds the lock between them. A
     * missed precondition fails the test with the counts; it never skips.
     *
     * Statistical bound: a delete upgrade fails when it meets the held lock,
     * so each reserved job stays reserved with a probability p of about the
     * duty cycle, p ≤ 0.25, independently enough per job for a binomial
     * model. The test allows at most 60% of the N ≥ 20 jobs to stay
     * reserved: P(X > 0.6 N) for X ~ Binomial(20, 0.25) is 1.8 × 10⁻⁴
     * (Σ_{k=13..20} C(20, k) 0.25^k 0.75^(20−k)), and smaller for larger N
     * (1.0 × 10⁻⁵ at N = 30). Without the duty cycle (the mutation check)
     * nearly every upgrade fails, so few jobs are reserved and most of them
     * stay reserved. On a separate mirror file, MySQL and Postgres no job
     * stays reserved. Measured (5 runs in a row, one run each): 119 jobs
     * reserved during the run, 2, 4, 3, 3 and 4 stayed reserved. Mutation
     * check without the duty cycle: 0 and 1 jobs reserved in 150 s, so the
     * precondition fails.
     *
     * The hook drains the worker's output pipes at each batch: a worker
     * whose pipe is full blocks in write() and reserves nothing.
     *
     * Rejects: back-to-back write transactions on a shared SQLite file.
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_real_completions_during_a_run(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that it shares with the test: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $minimum = 20;
        $jobs = 120;
        // Real pauses.
        Retention::useLockModeForTesting(null);
        $point = 'retention.worker_job';
        Queue::connection('database')->push(new HookedJob($point));

        for ($i = 1; $i < $jobs; $i++) {
            Queue::connection('database')->push(new BasicJob);
        }

        $this->seedDue(120_000, Clock::now() - 7200);
        $mode = $this->retention()->lockMode(Container::getInstance()->make(PackageSession::class)->mirror());
        $hold = Barrier::forTest();
        $worker = WorkerProcess::start(['queue:work', 'database', '--sleep=0', '--max-jobs='.$jobs, '--max-time=300'], $this->testEnvironment(), $hold->holdEnvironment($point));
        $hold->await($point.'.reached', 60, $worker->abandonIfExited());

        $batches = 0;
        $problem = null;
        Hooks::$callback = function (string $reached) use ($hold, $point, $worker, &$batches, &$problem): void {
            if ($reached !== Retention::AFTER_SELECT) {
                return;
            }

            // Drain the worker's output pipes: a worker whose pipe is full
            // (the stack traces of its "database is locked" reports) blocks
            // in write() and reserves nothing.
            $worker->output();
            $worker->errorOutput();

            if (++$batches === 2) {
                try {
                    $hold->arrive($point.'.release');
                } catch (Throwable $error) {
                    $problem = $error;
                }
            }
        };

        // Reserved so far: deleted, or still reserved.
        $reserved = static fn (): int => $jobs - DB::table('jobs')->whereNull('reserved_at')->count();
        $during = 0;
        $runs = 0;
        $deleted = 0;
        $deadline = hrtime(true) + 150 * 1_000_000_000;

        try {
            while ($during < $minimum && hrtime(true) < $deadline && $this->mirror()->table('orrery_jobs')->where('status', 'completed')->where('finished_at', '<', Clock::toDecimal(Clock::now() - 3600))->exists()) {
                $before = $reserved();
                $deleted += $this->retention()->run();
                $during += $reserved() - $before;
                $runs++;
            }
        } finally {
            Hooks::$callback = null;
        }

        self::assertNull($problem);
        self::assertSame(0, $worker->waitForExit(300), $worker->describe());
        $stuck = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $counts = "{$mode} lock mode: {$runs} runs deleted {$deleted} rows; {$during} jobs were reserved during the runs, {$stuck} stayed reserved";

        self::assertGreaterThanOrEqual($minimum, $during, "Precondition not met within 150 s: at least {$minimum} jobs reserved during a run ({$counts}). Worker: ".$worker->describe());

        if ($mode === 'shared') {
            self::assertLessThanOrEqual(0.6, $stuck / $during, $counts);
        } else {
            self::assertSame(0, $stuck, 'No finished job stays reserved without a shared SQLite file: '.$counts);
        }

    }

    private function freeze(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('U.u', sprintf('%.6F', self::NOW)));
    }

    /**
     * Ends the run's time share at the $n-th candidate select, through the
     * monotonic clock (the deadline is 10 s from the start of the run).
     */
    private function endTimeShareAtSelect(int $n, ?\Closure $then = null): void
    {
        $selects = 0;
        Hooks::$callback = static function (string $point) use ($n, $then, &$selects): void {
            if ($point === Retention::AFTER_SELECT && ++$selects === $n) {
                Clock::advanceMonotonicForTesting(Retention::MAX_SECONDS + 1);

                if ($then !== null) {
                    $then();
                }
            }
        };
    }

    /**
     * $count completed rows of database:$queue that finished at $finished.
     */
    private function seedDue(int $count, float $finished, string $queue = 'default'): void
    {
        $rows = [];
        $at = Clock::toDecimal($finished);

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => $queue,
                'name' => 'App\\Jobs\\Retained',
                'type' => 'job',
                'status' => 'completed',
                'silenced' => false,
                'monitored' => false,
                'payload' => '{}',
                'payload_bytes' => 2,
                'pushed_at' => $at,
                'finished_at' => $at,
                'updated_at' => $at,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }
    }

    /**
     * @return array{a: int, b: int, c: int}
     */
    private function counts(): array
    {
        $counts = ['a' => 0, 'b' => 0, 'c' => 0];

        foreach (array_keys($counts) as $queue) {
            $counts[$queue] = $this->mirror()->table('orrery_jobs')->where('queue', $queue)->count();
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', Retention::STATE_KEY)->value('value');
        self::assertIsString($value, 'The run wrote retention_state.');

        $state = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        return $state;
    }
}
