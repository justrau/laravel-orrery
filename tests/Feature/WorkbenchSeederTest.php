<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Http\Controllers\JobClassesController;
use Justrau\Orrery\Http\Controllers\QueueHistoryController;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\OrreryServiceProvider;
use Justrau\Orrery\Listeners\RecordWorkerHeartbeat;
use Justrau\Orrery\Monitoring\NativePause;
use Justrau\Orrery\Storage\HostFiles;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Workbench\App\Jobs\BatchMemberJob;
use Workbench\App\Jobs\CampaignJob;
use Workbench\App\Jobs\FailingJob;
use Workbench\App\Jobs\QuickJob;
use Workbench\App\Providers\WorkbenchServiceProvider;
use Workbench\App\Support\FixtureManifest;
use Workbench\App\Support\WorkbenchConfiguration;
use Workbench\Database\Seeders\DatabaseSeeder;

use function Orchestra\Testbench\default_migration_path;

/**
 * Runs the Workbench seeder with the Workbench configuration on a fresh temporary SQLite file,
 * twice, and checks the counts of workbench/fixtures.md.
 *
 * It extends Testbench directly, not the package TestCase: the Workbench is SQLite-only, and it
 * needs the file-backed connection that WorkbenchConfiguration defines. It registers only the
 * package provider. WorkbenchServiceProvider stays out, as in every PHPUnit application.
 */
#[Group('workbench')]
final class WorkbenchSeederTest extends TestCase
{
    // The name that WorkbenchServiceProvider passes: the Workbench fence migration names it.
    private const CONNECTION = 'sqlite';

    private string $directory;

    private string $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orrery-workbench-seeder-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->database = $this->directory.DIRECTORY_SEPARATOR.'database.sqlite';
        touch($this->database);

        // A Workbench build is a fresh process. Reset the static state that a fresh process
        // does not carry, so the heartbeat and signal counts match fixtures.md.
        RecordWorkerHeartbeat::resetThrottle();
        SignalState::flushCache();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::removeTree($this->directory);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            self::removeTree($path.DIRECTORY_SEPARATOR.$name);
        }

        rmdir($path);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OrreryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        WorkbenchConfiguration::apply($app['config'], self::CONNECTION, $this->database, $this->directory.DIRECTORY_SEPARATOR.'storage');

        // WorkbenchServiceProvider sets databasePath() to the directory of the database file,
        // which has no migrations directory. Do the same, so migrate:fresh never runs files that
        // another test published into database/migrations of Testbench's shared skeleton.
        $app->useDatabasePath($this->directory);

        // The Workbench build loads Testbench's default Laravel migrations (workbench.install)
        // and the Workbench's own migrations (the fence table on its queue database)
        // next to the package migrations, which the package provider registers.
        $app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(default_migration_path());
            $migrator->path(dirname(__DIR__, 2).'/workbench/database/migrations');
        });
    }

    /**
     * @return array<string, array{int}>
     */
    public static function runs(): array
    {
        return [
            'first fresh database' => [1],
            'second fresh database' => [2],
        ];
    }

    #[DataProvider('runs')]
    public function test_the_seeder_produces_the_fixture_manifest_on_a_fresh_database(int $run): void
    {
        $this->seedAndVerify((string) $run);
    }

    /**
     * The clock stops one second before a minute boundary while the seeder runs, and passes the
     * boundary when `orrery:tick` starts (step 17). So the tick counts the five minutes before
     * the new minute, and the seeder's moved clock must place the throttled job's completion
     * (step 13) inside them.
     *
     * Rejects: an anchor of the moved clock older than the tick's five counted minutes (the
     * completion is then neither counted nor read from the mirror rows, and `database:throttled`
     * drops out of the measured queues), and an at() that restores the real time instead of the
     * caller's pinned clock.
     */
    public function test_a_seeding_that_crosses_a_minute_boundary_before_the_tick_keeps_every_measured_queue(): void
    {
        // The boundary of the current minute: every pinned time stays at or before the real time,
        // which the package's database-clock reads (the backlog's ready jobs) use.
        $boundary = intdiv(time(), 60) * 60;
        Carbon::setTestNow(Carbon::createFromTimestamp($boundary - 1));

        // Artisan::call() raises CommandStarting only through the kernel's rerouted Symfony
        // events, as `artisan` does; a new Artisan application takes the rerouting.
        $kernel = $this->app->make(ConsoleKernel::class);
        $this->assertInstanceOf(\Illuminate\Foundation\Console\Kernel::class, $kernel);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);

        Event::listen(CommandStarting::class, static function (CommandStarting $event) use ($boundary): void {
            if ($event->command === 'orrery:tick') {
                Carbon::setTestNow(Carbon::createFromTimestamp($boundary + 1));
            }
        });

        try {
            $this->seedAndVerify('across a minute boundary');
            $this->assertSame($boundary, $this->counted(), 'The tick did not run in the minute after the boundary.');
            // Step 16 runs after the moved clock of steps 13 to 15: at the pinned time, not the real time.
            $this->assertSame(
                (float) ($boundary - 1),
                (float) $this->mirrorTable('orrery_jobs')->where('status', 'retrying')->value('updated_at'),
                'A step after the moved clock ran at the real time instead of the caller\'s pinned clock.',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function seedAndVerify(string $run): void
    {
        $this->assertFalse($this->app->providerIsLoaded(WorkbenchServiceProvider::class));
        $this->assertSame(0, filesize($this->database), 'The run must start on an empty file.');

        // The same command and options as the `migrate-fresh` build step in testbench.yaml.
        $status = Artisan::call('migrate:fresh', [
            '--seed' => true,
            '--seeder' => DatabaseSeeder::class,
        ]);

        $this->assertSame(0, $status, Artisan::output());
        $this->assertSame(realpath($this->database), realpath((string) DB::connection()->getDatabaseName()));

        $manifest = FixtureManifest::load();

        $this->assertNotEmpty($manifest->checks);
        // The package tables are in the sibling mirror file, attached as
        // `orrery`, as workbench/check.php reads them.
        $pdo = new \PDO('sqlite:'.realpath($this->database), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('ATTACH DATABASE '.$pdo->quote(\Workbench\App\Support\WorkbenchDatabase::sibling((string) realpath($this->database))).' AS orrery');
        $this->assertSame([], $manifest->verify($pdo), "Run {$run}: the fixture manifest does not hold.");

        $this->assertFailedJobsAreMirrored();
        $this->assertTheBatchRecordsOneFailedMember();
        $this->assertMetricDetailScreensHaveTheSeededJobs();
        $this->assertListsHaveASecondPage();
    }

    /**
     * A package table of the sibling mirror file.
     */
    private function mirrorTable(string $table): \Illuminate\Database\Query\Builder
    {
        return app(\Justrau\Orrery\Storage\PackageSession::class)->mirror()->table($table);
    }

    private function assertFailedJobsAreMirrored(): void
    {
        $uuids = DB::table('failed_jobs')->pluck('uuid')->all();

        // 59 FailingJob, 2 batch members, 210 FlakyApiJob, 3 CampaignJob, 1 TimedOutJob,
        // 2 SecretsJob and 1 LongValuesJob: the retried FailingJob completed in cycle 2, and its
        // publication deleted its failed_jobs record. The FailingJob
        // that the awaiting operation holds keeps its record, and its row is `retrying`.
        $this->assertCount(278, $uuids);
        $this->assertSame(
            277,
            $this->mirrorTable('orrery_jobs')->whereIn('uuid', $uuids)->where('status', 'failed')->count(),
        );
        $this->assertSame(
            1,
            $this->mirrorTable('orrery_jobs')->whereIn('uuid', $uuids)->where('status', 'retrying')->count(),
        );
    }

    private function assertTheBatchRecordsOneFailedMember(): void
    {
        $batch = DB::table('job_batches')->where('name', DatabaseSeeder::BATCH_NAME)->sole();

        $this->assertSame(DatabaseSeeder::BATCH_NAME, $batch->name);
        $this->assertSame(5, (int) $batch->total_jobs);
        $this->assertSame(1, (int) $batch->failed_jobs);

        $failedIds = json_decode((string) $batch->failed_job_ids, true);

        $this->assertIsArray($failedIds);
        $this->assertCount(1, $failedIds);
        $this->assertTrue(DB::table('failed_jobs')->where('uuid', $failedIds[0])->exists());
        $this->assertSame(
            BatchMemberJob::class,
            $this->mirrorTable('orrery_jobs')->where('uuid', $failedIds[0])->where('status', 'failed')->value('name'),
        );
    }

    /**
     * The Job classes view and the queue history read the samples:
     * the seeded completions of the last hour, stored or scanned from the mirror rows, under the
     * primary alias of the shared jobs table. (The legacy metrics read them the same way.)
     */
    private function assertMetricDetailScreensHaveTheSeededJobs(): void
    {
        $this->assertSame(0, $this->mirrorTable('orrery_samples')->whereIn('scope', ['queue', 'job'])->where('completed', '>', 0)->where('minute', '>=', $this->counted())->count(), 'The tick counted no minute at or after M: those are read from the mirror rows.');

        $classes = array_column((array) $this->app->call([$this->app->make(JobClassesController::class), 'index'], ['request' => Request::create('/api/classes?minutes=60')])->getData(true)['classes'], 'completed', 'name');
        $this->assertSame(297, $classes[CampaignJob::class] ?? null);
        $this->assertSame(51, $classes[QuickJob::class] ?? null, '48 on database:default and 3 on reports');
        $this->assertSame(3, $classes[FailingJob::class] ?? null, 'the retried job of step 7 and the two of the bulk run completed');

        // The queues with completions: the campaign jobs, the partial batch's two completed
        // members, the throttled job that took the limiter's slot and the two jobs of the bulk run
        // (step 16b) add theirs.
        foreach (['bulk-retry' => 2, 'campaigns' => 297, 'partial' => 2, 'reports' => 3, 'throttled' => 1] as $queue => $completed) {
            $history = $this->app->call([$this->app->make(QueueHistoryController::class), 'show'], ['request' => Request::create('/api/queues/database/'.$queue.'/history?minutes=60'), 'connection' => 'database', 'queue' => $queue])->getData(true);
            $this->assertSame($completed, array_sum(array_map('intval', array_column($history['buckets'], 'completed'))), "database:{$queue}");
        }
    }

    private function counted(): int
    {
        return (int) $this->mirrorTable('orrery_settings')->where('key', 'samples_counted_through')->value('value');
    }

    private function assertListsHaveASecondPage(): void
    {
        $jobs = $this->app->make(JobList::class);
        $count = static fn (string $tab): int => $jobs->tabCount($tab, 1_000_000)['count'];

        // The Jobs list shows 50 rows per page.
        $this->assertGreaterThan(50, $count('completed'));
        $this->assertGreaterThan(50, $count('failed'));
        $this->assertSame(3, $count('silenced'));
        // 20 pending and 2 reserved rows.
        $this->assertSame([20, 2], [$count('pending'), $count('running')]);
    }

    /**
     * The dashboard reads Laravel's own pause state in the Workbench. A `database` cache store on
     * the Workbench's SQLite file is a host file (D17), so every queue would show "Pause state
     * unknown" in the sidebar and the Overview, and the attention badge would read "?".
     *
     * Rejects: a Workbench default cache store on the app's SQLite file (every native pause
     * unknown), and a store that the queue manager cannot read a pause from (a flag set by
     * Queue::pause() not seen).
     */
    public function test_the_workbench_cache_store_lets_the_dashboard_read_laravels_pause_state(): void
    {
        /** @var object $queue */
        $queue = $this->app->make('queue');

        if (! method_exists($queue, 'pause')) {
            $this->markTestSkipped('This Laravel release has no queue pause.');
        }

        $this->assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());

        $this->assertNull($this->app->make(HostFiles::class)->unsafeCacheFile(null));

        $queue->pause('database', 'default');

        $states = $this->app->make(NativePause::class)->read([['database', 'default'], ['database', 'idle']]);

        $this->assertSame(['queue' => true, 'global' => false, 'unknown' => null], $states[NativePause::key('database', 'default')]);
        $this->assertSame(['queue' => false, 'global' => false, 'unknown' => null], $states[NativePause::key('database', 'idle')]);
    }
}
