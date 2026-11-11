<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Control;

use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\QueuePaused;
use Illuminate\Queue\Events\QueueResumed;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Control\Phases\SignalExpiryPhase;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Http\Controllers\HealthController;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\NativePause;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Tests\Controller\Fakes\FailingCacheStore;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\Fixtures\Control\WithoutInterruptionPollingProvider;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pause unification: a queue pause is Laravel's own
 * (QueueManager::pause()), the global and connection pauses stay signal
 * rows, the effective pause is an OR of the three scopes, and the workload
 * reports every cause in `pauses`.
 *
 * The test kinds: every test here is a new-behaviour
 * test, except test_without_interruption_polling_ignores_the_queue_pause,
 * which is a contract test of Laravel's documented limit.
 */
final class QueuePauseTest extends ControllerTest
{
    use WorkerFixtures;

    /** The connection of the application's SQLite file that holds the `database` cache store. */
    private const CACHE_CONNECTION = 'orrery_c7_cache';

    private ?string $cacheFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('orrery.waits', ['*' => 60]);
        // Laravel 13's default `failover` connection pops from `database`, so a queue pause also sets its
        // flag. The tests of one name drop it; the failover tests configure their own.
        $this->app['config']->set('queue.connections.failover', null);
    }

    protected function tearDown(): void
    {
        $file = $this->cacheFile;
        $this->cacheFile = null;

        try {
            parent::tearDown();
        } finally {
            if ($file !== null) {
                SqliteFiles::remove($file);
            }
        }
    }

    /**
     * Rejects: a package-only queue pause (a signal row that Laravel's worker never reads, so both jobs run, or the pause of the whole worker).
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_queue_pause_uses_laravels_pause(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('The worker needs its own process and a shared database: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $env = $this->useSharedCache();
        $high = Queue::push(new BasicJob, '', 'high');
        Queue::push(new BasicJob, '', 'default');

        self::assertSame(0, Artisan::call('orrery:pause', ['--connection' => 'database', '--queue' => 'high']));
        self::assertTrue(Queue::isPaused('database', 'high'), 'orrery:pause did not use Laravel\'s queue pause');

        $worker = WorkerProcess::start(['queue:work', 'database', '--queue=high,default', '--sleep=0', '--tries=1', '--stop-when-empty'], $env);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        self::assertSame(['high'], DB::table('jobs')->pluck('queue')->all(), 'the worker did not take only the default job. '.$worker->describe());
        $row = DB::table('jobs')->where('id', $high)->first();
        self::assertNotNull($row);
        self::assertNull($row->reserved_at, 'the paused queue\'s job was reserved');
    }

    /**
     * Rejects: a queue pause that a worker on a `failover` connection never
     * sees: Laravel's worker asks for the pause under the name
     * of its own connection (`failover`), while it pops from the first
     * connection of the list; and a continue that leaves that flag set.
     * The worker's own read (getPausedQueues() under the worker's
     * connection name, isPaused() per queue on Laravel 12) is asserted,
     * without a worker process.
     *
     * Known limit: it would pass a pause that also reaches a failover connection whose first connection is another one (only the matching one is configured).
     */
    public function test_a_queue_pause_reaches_the_failover_connections_of_its_connection(): void
    {
        $this->app['config']->set('queue.connections.orrery-failover', ['driver' => 'failover', 'connections' => ['database', 'sync']]);
        $this->app['config']->set('queue.connections.orrery-nested', ['driver' => 'failover', 'connections' => ['orrery-failover', 'sync']]);
        $this->app['config']->set('queue.connections.orrery-other', ['driver' => 'failover', 'connections' => ['sync', 'database']]);
        $manager = $this->app->make(QueueManager::class);

        self::assertSame(0, Artisan::call('orrery:pause', ['--connection' => 'database', '--queue' => 'emails']));

        self::assertSame(['emails'], $this->pausedForWorker($manager, 'orrery-failover', ['emails', 'default']), 'the failover worker does not see the pause');
        self::assertSame(['emails'], $this->pausedForWorker($manager, 'orrery-nested', ['emails']), 'the nested failover worker does not see the pause');
        self::assertSame([], $this->pausedForWorker($manager, 'orrery-other', ['emails']), 'a failover connection that pops from another connection is paused');

        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database', '--queue' => 'emails']));

        self::assertSame([], $this->pausedForWorker($manager, 'orrery-failover', ['emails']), 'the continue left the failover flag set');
        self::assertSame([], $this->pausedForWorker($manager, 'orrery-nested', ['emails']));
    }

    /**
     * Rejects: "Running" for a queue whose failover connection Laravel's own
     * queue:pause paused: the worker on that connection skips
     * the queue, which the dashboard shows under the first connection.
     */
    public function test_a_native_pause_of_a_failover_connection_shows_as_paused(): void
    {
        $this->app['config']->set('queue.connections.orrery-failover', ['driver' => 'failover', 'connections' => ['database', 'sync']]);
        $this->readyJob('high');

        self::assertSame(0, Artisan::call('queue:pause', ['queue' => 'orrery-failover:high']));

        $rows = $this->workload();
        self::assertSame([['scope' => 'queue', 'source' => 'queue:pause', 'issued_at' => null, 'issued_by' => null, 'possible' => false]], $rows['high']['pauses']);

        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database', '--queue' => 'high']));
        self::assertFalse(Queue::isPaused('orrery-failover', 'high'), 'the continue did not lift the failover flag');
        self::assertSame([], $this->workload()['high']['pauses']);
    }

    /**
     * Rejects: "Running" for a queue that Laravel's own queue:pause paused.
     */
    public function test_laravels_queue_pause_shows_as_paused_from_queue_pause(): void
    {
        $this->readyJob('high');
        $this->readyJob('default');

        self::assertSame(0, Artisan::call('queue:pause', ['queue' => 'database:high']));

        $rows = $this->workload();
        self::assertSame([['scope' => 'queue', 'source' => 'queue:pause', 'issued_at' => null, 'issued_by' => null, 'possible' => false]], $rows['high']['pauses']);
        self::assertSame([], $rows['default']['pauses']);
        self::assertFalse($rows['high']['paused_unknown']);
    }

    /**
     * Rejects: a silent "resumed" for a queue whose continue leaves Orrery's global pause in force.
     */
    public function test_queue_continue_under_a_global_pause_reports_the_global_pause(): void
    {
        $signals = new SignalState;
        $signals->pause('global', '', 'cli');
        $signals->pause('queue', 'database:emails', 'cli');

        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database', '--queue' => 'emails']));
        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

        self::assertStringContainsString('Lifted the pause of queue "database:emails". Queue "database:emails" is still paused by Orrery\'s global pause (orrery:continue).', $output);
        self::assertFalse(Queue::isPaused('database', 'emails'), 'the continue did not resume Laravel\'s queue pause');

        $signals->pause('queue', 'database:emails', 'cli');
        $this->postJson('/orrery/api/control', ['action' => 'continue', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'emails'])
            ->assertOk()
            ->assertJson(['removed' => 1, 'still_paused_by' => [['scope' => 'global', 'value' => '', 'source' => 'orrery']], 'still_paused_unknown' => false], true);
    }

    /**
     * Rejects: an annotation counted as a definite pause while Laravel's pause state is not read, and an annotation that suppresses the attention rule.
     */
    public function test_annotation_under_native_unknown_is_possibly_paused(): void
    {
        $this->useHostFileCache();
        $this->readyJob('high', 300);

        // An operator's pause writes Laravel's flag through the store, and the annotation.
        self::assertSame(0, Artisan::call('orrery:pause', ['--connection' => 'database', '--queue' => 'high']));
        self::assertSame(0, Artisan::call('queue:continue', ['queue' => 'database:high']));

        $row = $this->workload()['high'];

        self::assertTrue($row['native_unknown']);
        self::assertSame(NativePause::UNSAFE_HINT, $row['native_hint']);
        self::assertCount(1, $row['pauses']);
        self::assertSame(['scope' => 'queue', 'source' => 'orrery', 'issued_by' => 'cli', 'possible' => true], array_diff_key($row['pauses'][0], ['issued_at' => true]));
        self::assertTrue($row['over_limit'], 'the backlog is over its limit');
        self::assertFalse($row['paused_unknown'], 'the signals and the (skipped) native read did not fail');
    }

    /**
     * Rejects: an automatic native read through a host-file cache: a DatabaseStore read deletes the expired key.
     */
    public function test_native_pause_is_not_read_through_a_host_file_cache(): void
    {
        $this->useHostFileCache();
        $this->readyJob('high');
        // A pause() whose key is expired below.
        Queue::pause('database', 'high');
        $key = $this->cachePrefix().'illuminate:queue:paused:database:high';
        DB::connection(self::CACHE_CONNECTION)->table('cache')->where('key', $key)->update(['expiration' => time() - 10]);
        $this->mirror()->table('orrery_signals')->insert(['signal' => 'pause', 'scope' => 'queue', 'scope_value' => 'database:high', 'issued_at' => Clock::toDecimal(Clock::now()), 'expires_at' => null, 'issued_by' => 'cli']);

        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$statements): void {
            if ($event->connectionName === self::CACHE_CONNECTION) {
                $statements[] = $event->sql;
            }
        });
        $calls = 0;
        $this->app['events']->listen(RetrievingKey::class, static function () use (&$calls): void {
            $calls++;
        });

        $row = $this->workload()['high'];
        $this->runPhase();

        self::assertSame([], $statements, 'an automatic path read or wrote the application\'s cache file');
        self::assertSame(0, $calls, 'an automatic path read the cache store');
        self::assertSame(1, DB::connection(self::CACHE_CONNECTION)->table('cache')->where('key', $key)->count(), 'the expired key was deleted');
        self::assertTrue($row['native_unknown']);
        self::assertSame(NativePause::UNSAFE_HINT, $row['native_hint']);
        self::assertSame(1, $this->mirror()->table('orrery_signals')->where('scope', 'queue')->count(), 'signal_expiry deleted an annotation without a native read');

        // An operator's continue of that queue still works.
        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database', '--queue' => 'high']));
        self::assertSame(0, $this->mirror()->table('orrery_signals')->where('scope', 'queue')->count());
        self::assertSame(0, DB::connection(self::CACHE_CONNECTION)->table('cache')->where('key', $key)->count(), 'the operator\'s continue did not resume the queue');
    }

    /**
     * Rejects: the most-specific-wins rule (a continue of a narrower scope that overrides a broader pause), which only the narrower-continue cases can tell apart.
     */
    #[DataProvider('scopeCombinations')]
    public function test_pause_is_an_or_of_the_three_scopes(bool $global, bool $connection, bool $queue, bool $narrowerContinue): void
    {
        $this->actAsWorker();
        $signals = new SignalState;

        if ($queue) {
            $signals->pause('queue', 'database:high');
        }

        if ($connection) {
            $signals->pause('connection', 'database');
        }

        if ($global) {
            $signals->pause('global', '');
        }

        if ($narrowerContinue) {
            // Every scope narrower than the broadest pause.
            if ($global) {
                $signals->continue('connection', 'database');
            }

            if ($global || $connection) {
                $signals->continue('queue', 'database:high');
            }
        }

        $expected = $global || $connection || $queue;
        $pauses = $this->workload(['high'])['high']['pauses'];
        self::assertSame($expected, $pauses !== [], 'the workload: '.json_encode($pauses));

        Queue::push(new BasicJob, '', 'high');
        $this->runWorker('high');
        self::assertSame($expected ? 1 : 0, DB::table('jobs')->where('queue', 'high')->count(), $expected ? 'a paused queue ran its job' : 'a running queue kept its job');
    }

    /**
     * @return array<string, array{bool, bool, bool, bool}>
     */
    public static function scopeCombinations(): array
    {
        $cases = [];

        foreach ([false, true] as $global) {
            foreach ([false, true] as $connection) {
                foreach ([false, true] as $queue) {
                    $name = sprintf('global %d, connection %d, queue %d', $global, $connection, $queue);
                    $cases[$name] = [$global, $connection, $queue, false];

                    if ($global || $connection) {
                        $cases[$name.', narrower continue'] = [$global, $connection, $queue, true];
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * Rejects: a global pause or continue that deletes the narrower rows.
     */
    public function test_global_continue_keeps_a_connection_pause(): void
    {
        $signals = new SignalState;
        $signals->pause('connection', 'database');
        $signals->pause('global', '');
        $result = $signals->continue('global', '');

        self::assertSame(1, $result->removed);
        SignalState::flushCache();
        self::assertTrue($signals->connectionPaused('database'), 'the connection pause was lost');
        self::assertFalse($signals->connectionPaused('other'));
        $this->readyJob('high');
        self::assertSame(['connection'], array_column($this->workload()['high']['pauses'], 'scope'));
    }

    /**
     * Rejects: reporting Laravel's global pause as a queue pause, or not at all.
     */
    public function test_laravel_global_pause_is_reported(): void
    {
        $this->requirePauseAll();
        $this->readyJob('high');
        $this->readyJob('default');

        self::assertSame(0, Artisan::call('queue:pause', ['--all' => true]));

        foreach ($this->workload() as $name => $row) {
            self::assertSame([['scope' => 'global', 'source' => 'queue:pause --all', 'issued_at' => null, 'issued_by' => null, 'possible' => false]], $row['pauses'], "queue {$name}");
            self::assertTrue($row['queue_cause_unknown'], "queue {$name}");
        }

        $this->postJson('/orrery/api/control', ['action' => 'continue', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'high'])
            ->assertOk()
            ->assertJson(['still_paused_by' => [['scope' => 'global', 'value' => '', 'source' => 'queue:pause --all']]], true);

        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database', '--queue' => 'high']));
        self::assertStringContainsString('is still paused by Laravel\'s global pause (queue:continue --all).', (string) preg_replace('/\s+/', ' ', Artisan::output()));
    }

    /**
     * Rejects: reporting both causes as known while Laravel's global pause masks the queue's own flag, and dropping the own cause.
     */
    #[DataProvider('maskedCases')]
    public function test_masked_queue_cause_is_unknown(bool $ownPause, bool $ownLifted): void
    {
        $this->requirePauseAll();
        $this->readyJob('high');
        $signals = new SignalState;

        if ($ownPause) {
            $signals->pause('queue', 'database:high', 'cli');
        }

        self::assertSame(0, Artisan::call('queue:pause', ['--all' => true]));

        if ($ownLifted) {
            self::assertSame(0, Artisan::call('queue:continue', ['queue' => 'database:high']));
        }

        $row = $this->workload()['high'];
        $definite = array_values(array_filter($row['pauses'], static fn (array $cause): bool => ! $cause['possible']));
        self::assertSame([['scope' => 'global', 'source' => 'queue:pause --all', 'issued_at' => null, 'issued_by' => null, 'possible' => false]], $definite);
        self::assertTrue($row['queue_cause_unknown']);
        // The annotation is only possible while the own flag is masked.
        self::assertSame($ownPause ? [['queue', 'orrery', true]] : [], array_map(static fn (array $cause): array => [$cause['scope'], $cause['source'], $cause['possible']], array_values(array_filter($row['pauses'], static fn (array $cause): bool => $cause['possible']))));

        // The annotation survives the global pause ...
        $this->runPhase();
        self::assertSame($ownPause ? 1 : 0, $this->annotations());

        // ... and after it, the own flag decides.
        self::assertSame(0, Artisan::call('queue:continue', ['--all' => true]));
        $this->forgetMeasurement();
        $row = $this->workload()['high'];
        self::assertFalse($row['queue_cause_unknown']);
        $this->runPhase();

        if ($ownPause && ! $ownLifted) {
            self::assertSame([['queue', 'orrery', false]], array_map(static fn (array $cause): array => [$cause['scope'], $cause['source'], $cause['possible']], $row['pauses']), 'the own queue pause was lost');
            self::assertSame(1, $this->annotations());
        } else {
            self::assertSame([], $row['pauses']);
            self::assertSame(0, $this->annotations(), 'the stale annotation was not removed');
        }
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function maskedCases(): array
    {
        return [
            'global only' => [false, false],
            'global and an own queue pause' => [true, false],
            'own pause lifted under the global pause' => [true, true],
        ];
    }

    /**
     * Rejects: a test that would pass a queue pause that has an effect without interruption polling (pinned as the documented limit), and a global pause that stops working there.
     */
    #[Group('process')]
    #[Group('shared-database')]
    public function test_without_interruption_polling_ignores_the_queue_pause(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('The worker needs its own process and a shared database: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $env = $this->useSharedCache();
        $extra = ['ORRERY_TEST_PROVIDERS' => WithoutInterruptionPollingProvider::class, 'ORRERY_SIGNAL_CACHE_SECONDS' => '0'];
        Queue::push(new BasicJob, '', 'high');
        self::assertSame(0, Artisan::call('orrery:pause', ['--connection' => 'database', '--queue' => 'high']));

        $worker = WorkerProcess::start(['queue:work', 'database', '--queue=high', '--sleep=0', '--tries=1', '--stop-when-empty'], $env, $extra);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        self::assertSame(0, DB::table('jobs')->count(), 'the documented limit: without interruption polling the queue pause has no effect');

        self::assertSame(0, Artisan::call('orrery:pause'));
        Queue::push(new BasicJob, '', 'high');
        $worker = WorkerProcess::start(['queue:work', 'database', '--queue=high', '--sleep=0', '--tries=1', '--stop-when-empty'], $env, $extra);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(1, DB::table('jobs')->whereNull('reserved_at')->count(), 'the global pause did not stop the worker');
    }

    /**
     * Rejects: a second native check inside pop() (a cache read per poll).
     */
    public function test_worker_pop_reads_no_cache_for_the_pause(): void
    {
        $this->actAsWorker();
        Queue::push(new BasicJob, '', 'high');
        Queue::pause('database', 'high');
        $keys = [];
        $this->app['events']->listen(RetrievingKey::class, static function (RetrievingKey $event) use (&$keys): void {
            $keys[] = $event->key;
        });
        $this->app['events']->listen(RetrievingManyKeys::class, static function (RetrievingManyKeys $event) use (&$keys): void {
            array_push($keys, ...$event->keys);
        });

        // The package's pop() leaves the queue pause to Laravel's worker.
        self::assertNotNull(Queue::connection('database')->pop('high'));
        self::assertSame([], array_values(array_filter($keys, static fn (string $key): bool => str_contains($key, 'paused'))), 'pop() read a pause key: '.json_encode($keys));
    }

    /**
     * Rejects: false (an unknown native pause read as "running") when the cache store throws.
     */
    public function test_failed_native_read_is_unknown(): void
    {
        $this->readyJob('high');
        $this->mirror()->table('orrery_signals')->insert(['signal' => 'pause', 'scope' => 'queue', 'scope_value' => 'database:high', 'issued_at' => Clock::toDecimal(Clock::now()), 'expires_at' => null, 'issued_by' => 'cli']);
        $store = new FailingCacheStore;
        $store->failGet = true;
        $this->app['cache']->extend('orrery-failing', fn ($app) => $app['cache']->repository($store));
        $this->app['config']->set('cache.stores.orrery-failing', ['driver' => 'orrery-failing']);
        $this->app['config']->set('cache.default', 'orrery-failing');

        $row = $this->workload()['high'];

        self::assertTrue($row['paused_unknown']);
        self::assertSame([['queue', true]], array_map(static fn (array $cause): array => [$cause['scope'], $cause['possible']], $row['pauses']), 'the annotation is only a possible cause');
        self::assertNull($this->app->make(NativePause::class)->isPaused('database', 'high'));
    }

    /**
     * Rejects: an annotation that shows "Paused" forever after a native queue:continue.
     */
    public function test_stale_annotation_is_removed(): void
    {
        $this->readyJob('high');
        (new SignalState)->pause('queue', 'database:high', 'cli');
        self::assertSame(['orrery'], array_column($this->workload()['high']['pauses'], 'source'));

        self::assertSame(0, Artisan::call('queue:continue', ['queue' => 'database:high']));
        $this->forgetMeasurement();
        self::assertSame([], $this->workload()['high']['pauses'], 'the stale annotation still shows a pause');

        $this->runPhase();
        self::assertSame(0, $this->annotations(), 'signal_expiry kept the stale annotation');
    }

    /**
     * Rejects: a stale-annotation delete without the issued_at guard, which removes an annotation that a new pause refreshed after the phase read it.
     */
    public function test_signal_expiry_keeps_an_annotation_refreshed_after_its_read(): void
    {
        $this->annotation('database:high', Clock::now() - 60);
        $refreshed = Clock::toDecimal(Clock::now());
        $done = false;
        // The native read runs between the phase's annotation read and its delete: a pause refreshes the row then.
        $refresh = function () use (&$done, $refreshed): void {
            if (! $done) {
                $done = true;
                $this->mirror()->table('orrery_signals')->where('scope', 'queue')->update(['issued_at' => $refreshed]);
            }
        };
        $this->app['events']->listen(RetrievingKey::class, $refresh);
        $this->app['events']->listen(RetrievingManyKeys::class, $refresh);

        $this->runPhase();

        self::assertTrue($done, 'the phase read no native state');
        self::assertSame(1, $this->annotations(), 'the phase deleted an annotation that was refreshed after its read');
        $this->runPhase();
        self::assertSame(0, $this->annotations(), 'the next run did not delete the stale annotation');
    }

    /**
     * Rejects: an unbounded run (more than 500 annotations checked at once), and a run that always checks the oldest 500, so a stale annotation behind 500 live ones is never reached.
     */
    public function test_signal_expiry_checks_500_annotations_per_run_and_moves_on(): void
    {
        $rows = [];
        $at = Clock::now() - 600;

        for ($i = 0; $i < SignalExpiryPhase::ANNOTATIONS_PER_RUN; $i++) {
            Queue::pause('database', "live-{$i}");
            $rows[] = ['signal' => 'pause', 'scope' => 'queue', 'scope_value' => "database:live-{$i}", 'issued_at' => Clock::toDecimal($at), 'expires_at' => null, 'issued_by' => 'cli'];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            $this->mirror()->table('orrery_signals')->insert($chunk);
        }

        // The stale one is the newest, after the 500 live ones.
        $this->annotation('database:stale', Clock::now());

        $this->runPhase();
        self::assertSame(501, $this->annotations(), 'one run checked more than 500 annotations');
        $this->runPhase();
        self::assertSame(500, $this->annotations(), 'the second run did not reach the annotation after the first 500');
        self::assertSame(0, $this->mirror()->table('orrery_signals')->where('scope_value', 'database:stale')->count());
    }

    /**
     * Rejects: a pause refresh that reads its row before it writes it (updateOrCreate), so a stale-annotation delete between the read and the write leaves the refreshed pause without its row.
     */
    public function test_a_pause_refresh_survives_a_concurrent_annotation_delete(): void
    {
        if ($this->testEnvironment()->driver === 'sqlite') {
            self::markTestSkipped('SQLite serialises the writers at BEGIN IMMEDIATE: the concurrent delete waits for the refresh. Run it through bin/test mysql or pgsql.');
        }

        $at = Clock::now() - 60;
        $old = Clock::toDecimal($at);
        $this->annotation('database:high', $at);
        $deleted = null;
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$deleted, $old): void {
            if ($deleted === null && $event->connectionName === self::mirrorSession() && str_contains($event->sql, 'orrery_signals') && str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                // Another session: signal_expiry's guarded delete of the stale annotation.
                $deleted = $this->mirror()->table('orrery_signals')->where('scope_value', 'database:high')->where('issued_at', $old)->delete();
            }
        });

        (new SignalState)->pause('queue', 'database:high', 'ops');

        // The delete matched the stale row whenever it ran (a refresh that reads first lets it run in between).
        self::assertContains($deleted, [null, 1], 'the concurrent delete did not match the stale annotation');
        self::assertSame(['ops'], $this->mirror()->table('orrery_signals')->where('scope_value', 'database:high')->pluck('issued_by')->all(), 'the refreshed pause lost its row (deleted in between: '.json_encode($deleted).')');
    }

    /**
     * Rejects: a second read of Laravel's global pause in one /api/workload request (one probe
     * per measurement, also with two measured queues).
     */
    public function test_workload_reads_the_global_probe_once_per_request(): void
    {
        $this->requirePauseAll();
        $this->readyJob('high');
        $this->readyJob('default');
        $this->app['config']->set('orrery.dashboard.measure_cache_seconds', 0);
        $probes = 0;
        $count = static function (array $keys) use (&$probes): void {
            // Laravel reads its global key first in every getPausedQueues() and isPaused(): one read per probe.
            $probes += count(array_filter($keys, static fn (string $key): bool => str_ends_with($key, 'illuminate:queues:paused')));
        };
        $this->app['events']->listen(RetrievingKey::class, static fn (RetrievingKey $event) => $count([$event->key]));
        $this->app['events']->listen(RetrievingManyKeys::class, static fn (RetrievingManyKeys $event) => $count($event->keys));

        Queue::pauseAll();
        $rows = $this->workload();

        self::assertSame(1, $probes, 'the request read the global probe more than once');
        self::assertSame(['queue:pause --all'], array_column($rows['high']['pauses'], 'source'));
        self::assertSame(['queue:pause --all'], array_column($rows['default']['pauses'], 'source'));
    }

    /**
     * Rejects: Laravel's pause() or resume() inside the package transaction, where the application's QueuePaused and QueueResumed listeners and the cache write run under the package's lock, and a busy retry pauses twice.
     */
    public function test_the_native_pause_runs_outside_the_package_transaction(): void
    {
        $seen = [];
        $writes = [];
        // isOpen() also sees the SQLite package transaction (BEGIN IMMEDIATE on the PDO), which neither PDO nor Laravel counts.
        $state = static fn (): array => [PackageTransaction::isOpen(Container::getInstance()->make(PackageSession::class)->mirror()), Container::getInstance()->make(PackageSession::class)->mirror()->transactionLevel()];
        $this->app['events']->listen(QueuePaused::class, static function () use (&$seen, $state): void {
            $seen[] = ['paused', ...$state()];
        });
        $this->app['events']->listen(QueueResumed::class, static function () use (&$seen, $state): void {
            $seen[] = ['resumed', ...$state()];
        });
        // The cache write itself, on every Laravel line.
        $this->app['events']->listen(KeyWritten::class, static function (KeyWritten $event) use (&$writes, $state): void {
            if (str_starts_with($event->key, 'illuminate:queue:paused:')) {
                $writes[] = ['written', ...$state()];
            }
        });
        $this->app['events']->listen(KeyForgotten::class, static function (KeyForgotten $event) use (&$writes, $state): void {
            if (str_starts_with($event->key, 'illuminate:queue:paused:')) {
                $writes[] = ['forgotten', ...$state()];
            }
        });

        (new SignalState)->pause('queue', 'database:high', 'cli');
        (new SignalState)->continue('queue', 'database:high');

        self::assertSame([['written', false, 0], ['forgotten', false, 0]], $writes, 'a native pause write ran inside a package transaction');
        // Laravel dispatches QueuePaused and QueueResumed after the write.
        self::assertSame([['paused', false, 0], ['resumed', false, 0]], $seen, 'a native event ran inside a package transaction');
    }

    /**
     * Rejects: no hint for a per-server cache store (a queue pause then never reaches the other servers' workers), and a hint for a shared store.
     */
    public function test_health_hints_a_cache_store_local_to_each_server(): void
    {
        $hint = fn (): mixed => $this->getJson('/orrery/api/health')->assertOk()->json('queue_pause_cache_local');

        $this->app['config']->set('cache.default', 'array');
        self::assertSame(HealthController::QUEUE_PAUSE_CACHE_ARRAY, $hint());
        self::assertStringContainsString('so a queue pause has no effect on any worker', (string) $hint());

        $this->app['config']->set('cache.stores.local_files', ['driver' => 'file', 'path' => sys_get_temp_dir()]);
        $this->app['config']->set('cache.default', 'local_files');
        self::assertSame(HealthController::QUEUE_PAUSE_CACHE_LOCAL, $hint());
        self::assertStringContainsString("may not reach every server's workers", (string) $hint());

        $this->app['config']->set('cache.stores.shared', ['driver' => 'redis', 'connection' => 'cache']);
        $this->app['config']->set('cache.default', 'shared');
        self::assertNull($hint());
    }

    /**
     * The workload rows by queue name. $queues are measured even without a job.
     *
     * @param  list<string>  $queues
     * @return array<string, array<string, mixed>>
     */
    private function workload(array $queues = []): array
    {
        if ($queues !== []) {
            $this->app['config']->set('orrery.waits', array_fill_keys(array_map(static fn (string $queue): string => "database:{$queue}", $queues), 60) + ['*' => 60]);
        }

        $rows = [];

        foreach ((array) $this->getJson('/orrery/api/workload')->assertOk()->json() as $row) {
            self::assertIsArray($row);
            $rows[(string) $row['name']] = $row;
        }

        return $rows;
    }

    private function readyJob(string $queue, int $age = 10): void
    {
        $at = time() - $age;

        DB::connection(TestEnvironment::PRIMARY)->table('jobs')->insert([
            'queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $at, 'created_at' => $at,
        ]);
    }

    /**
     * One run of Laravel's worker in this process, with its cache, so it skips a natively paused queue.
     */
    private function runWorker(string $queues): void
    {
        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->setCache($this->app['cache']->driver());
        $worker->runNextJob('database', $queues, $this->workerOptions());
    }

    private function runPhase(): void
    {
        $phase = $this->app->make(SignalExpiryPhase::class);
        $phase->run(new TickBudget(Clock::monotonic() + 30));
    }

    private function annotation(string $value, float $issuedAt): void
    {
        $this->mirror()->table('orrery_signals')->insert(['signal' => 'pause', 'scope' => 'queue', 'scope_value' => $value, 'issued_at' => Clock::toDecimal($issuedAt), 'expires_at' => null, 'issued_by' => 'cli']);
    }

    private function annotations(): int
    {
        return $this->mirror()->table('orrery_signals')->where('signal', 'pause')->where('scope', 'queue')->count();
    }

    /**
     * Ends the shared measurement now, as its lifetime would: a native CLI command does not forget it.
     */
    private function forgetMeasurement(): void
    {
        $this->app['config']->set('orrery.dashboard.measure_cache_seconds', 0);
    }

    /**
     * Rejects: a supported Laravel whose manager has pauseAll() without getPausedQueues(), or the reverse (a contract test on every matrix leg): NativePause reads the global pause only inside getPausedQueues(), so a global pause without the batch read would never be read.
     */
    public function test_the_global_pause_comes_with_the_batch_read(): void
    {
        self::assertSame(
            method_exists(QueueManager::class, 'pauseAll'),
            method_exists(QueueManager::class, 'getPausedQueues'),
            'Laravel '.$this->app->version().': pauseAll() and getPausedQueues() exist together (every supported 13.x) or not at all (12.x)',
        );
    }

    /**
     * The queues of $queues that a worker on $connection sees as paused,
     * read the way that worker reads them: getPausedQueues() where the
     * manager has it (Laravel 13), else isPaused() per queue (Laravel 12).
     *
     * @param  list<string>  $queues
     * @return list<string>
     */
    private function pausedForWorker(QueueManager $manager, string $connection, array $queues): array
    {
        if (method_exists($manager, 'getPausedQueues')) {
            return array_values((array) $manager->getPausedQueues($connection, $queues));
        }

        return array_values(array_filter($queues, static fn (string $queue): bool => $manager->isPaused($connection, $queue)));
    }

    private function requirePauseAll(): void
    {
        if (! method_exists(QueueManager::class, 'pauseAll')) {
            self::markTestSkipped('This Laravel has no global queue pause (QueueManager::pauseAll(), Laravel 13 only).');
        }
    }

    private function cachePrefix(): string
    {
        return (string) $this->app['config']->get('cache.prefix', '');
    }

    /**
     * The default cache store is a `database` store on an application SQLite
     * file (WAL, a busy timeout), so HostFiles::cacheIsSafe() is false.
     */
    private function useHostFileCache(): void
    {
        $this->cacheFile = SqliteFiles::create();
        $config = $this->app['config'];
        $config->set('database.connections.'.self::CACHE_CONNECTION, ['driver' => 'sqlite', 'url' => null, 'database' => $this->cacheFile, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000, 'journal_mode' => 'wal']);
        $schema = DB::connection(self::CACHE_CONNECTION)->getSchemaBuilder();
        $schema->create('cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        $schema->create('cache_locks', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        $config->set('cache.stores.app_database', ['driver' => 'database', 'connection' => self::CACHE_CONNECTION, 'table' => 'cache', 'lock_connection' => self::CACHE_CONNECTION, 'lock_table' => 'cache_locks']);
        $config->set('cache.default', 'app_database');
        $this->app->forgetInstance('cache.store');
        PackageSession::releaseAll();
    }
}
