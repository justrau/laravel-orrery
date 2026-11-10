<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Justrau\Orrery\Control\SignalCache;
use Justrau\Orrery\Control\StopLatch;
use Justrau\Orrery\Failed\OccurrenceSlot;
use Justrau\Orrery\Failed\StatementCounters;
use Justrau\Orrery\Lifecycle\RuntimeClock;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\WatchRegistry;
use Justrau\Orrery\Queue\OpenBulkCaptures;
use Justrau\Orrery\Queue\PendingBulkCaptures;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Tests\Feature\Support\Jobs\LifetimeReportJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use Justrau\Orrery\Workers\Heartbeats;
use PHPUnit\Framework\Attributes\Group;

/**
 * The lifetimes of the package's container bindings:
 * unit-of-work state is scoped, so each job of a worker and each request of
 * an Octane-like process gets new instances; process state is a singleton,
 * so the breaker, the watch registry, the degradation record and the
 * sessions are kept.
 */
#[Group('process')]
#[Group('shared-database')]
final class BindingLifetimeTest extends TestCase
{
    /** Unit-of-work state: a scoped binding. */
    public const SCOPED = [
        RuntimeClock::class,
        OpenBulkCaptures::class,
        OccurrenceSlot::class,
    ];

    /** Process state: a singleton. */
    public const PROCESS = [
        Watcher::class,
        WatchRegistry::class,
        CommitDeferral::class,
        Degradation::class,
        PackageSession::class,
        PackageTransaction::class,
        PendingBulkCaptures::class,
        StatementCounters::class,
        MonitoredTagCache::class,
        StopLatch::class,
        SignalCache::class,
        Heartbeats::class,
    ];

    /** @var list<object> the instances that the requests saw, so no object id is reused */
    private static array $kept = [];

    protected function defineRoutes($router): void
    {
        Route::get('/lifetime-probe', static function (): array {
            $ids = [];

            foreach ([...self::SCOPED, ...self::PROCESS] as $binding) {
                $instance = app($binding);
                self::$kept[] = $instance;
                $ids[$binding] = spl_object_id($instance);
            }

            return $ids;
        });
    }

    protected function tearDown(): void
    {
        self::$kept = [];

        parent::tearDown();
    }

    /**
     * A worker daemon runs two jobs. Each job sees new scoped instances (the
     * worker flushes them at the start of each loop), and its runtime map
     * has its own start (JobProcessing and the job share one map); the
     * process state is the same object in both jobs.
     *
     * Rejects: a binding with the wrong lifetime: a scoped item store or
     * runtime map bound as a singleton (the second job sees the first job's
     * instance), or a breaker, watch registry or degradation record bound as
     * scoped (the second job gets a new one).
     */
    public function test_worker_jobs_get_new_scoped_state_and_keep_process_state(): void
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $path = Barrier::forTest()->directory().'/lifetimes.jsonl';
        Queue::push(new LifetimeReportJob($path));
        Queue::push(new LifetimeReportJob($path));

        $worker = WorkerProcess::start(['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1'], $env);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        self::assertFileExists($path, 'The jobs wrote no report. '.$worker->describe());
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        self::assertCount(2, $lines, 'Each job wrote one line. '.$worker->describe());
        [$first, $second] = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);

        self::assertTrue($first['runtime_started'], 'The first job sees the start that its JobProcessing recorded.');
        self::assertTrue($second['runtime_started'], 'The second job sees the start that its JobProcessing recorded.');
        $this->assertLifetimes($first['ids'], $second['ids'], 'job');
    }

    /**
     * Two requests of one application, with Octane's flush of the scoped
     * instances between them.
     *
     * Rejects: a binding with the wrong lifetime, as in the worker test.
     */
    public function test_requests_after_a_scoped_flush_get_new_scoped_state_and_keep_process_state(): void
    {
        $first = $this->get('/lifetime-probe')->assertOk()->json();
        $this->app->forgetScopedInstances();
        $second = $this->get('/lifetime-probe')->assertOk()->json();

        $this->assertLifetimes($first, $second, 'request');
    }

    /**
     * Every class that keeps package state on an instance is a shared
     * binding (a singleton or scoped). An unbound class would be built anew
     * on each make(), and its state would be lost without an error. The
     * classes whose static methods act on their bound instance (a private
     * bound(): self) are found in src/; the others are listed.
     *
     * Rejects: a state owner that no module binds, for example a test seam
     * of SampleCounts that a non-shared instance never sees; and a process
     * owner bound as scoped.
     * Still passes: a class that keeps state but is neither listed nor uses
     * bound() (the inventory test then finds no static, and this test does
     * not know the class).
     */
    public function test_every_state_owner_is_a_shared_binding(): void
    {
        $owners = [
            ...self::SCOPED,
            ...self::PROCESS,
            \Justrau\Orrery\Console\ClearCommand::class,
            \Justrau\Orrery\Failed\IdReuseProofs::class,
            \Justrau\Orrery\Lifecycle\IncidentCleanup::class,
            \Justrau\Orrery\Maintenance\SiblingLocks::class,
            \Justrau\Orrery\Retry\Fence::class,
            \Justrau\Orrery\Retry\Phases\OperationRetentionPhase::class,
            \Justrau\Orrery\Runtime\ProcessState::class,
        ];
        $root = dirname(__DIR__, 3);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src')) as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            if (str_contains($code, 'private static function bound(): self')) {
                $owners[] = 'Justrau\\Orrery\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($root.'/src/'), -4));
            }
        }

        self::assertGreaterThan(count(self::SCOPED) + count(self::PROCESS) + 7, count($owners), 'The scan found the classes with bound().');

        foreach (array_unique($owners) as $owner) {
            self::assertTrue(class_exists($owner), "{$owner} exists.");
            self::assertTrue($this->app->isShared($owner), "{$owner} keeps package state, so a module binds it as a singleton or scoped.");

            if (in_array($owner, self::SCOPED, true)) {
                continue;
            }

            // A process owner survives a scoped flush: a scoped binding of it fails here.
            $before = $this->app->make($owner);
            $this->app->forgetScopedInstances();
            self::assertSame($before, $this->app->make($owner), "{$owner} is process state: a scoped flush keeps it.");
        }
    }

    /**
     * Every process binding of every module, resolved on the base
     * application when it boots, with no connection
     * opened and no package session built at boot.
     *
     * Rejects: a process singleton that is first resolved on its first use,
     * so on Octane a request's sandbox builds it and it lives for that
     * request only; and a boot that opens a database connection.
     * Still passes: a constructor that sends SQL through a connection that
     * it closes again (the manager would hold it, so this is unlikely).
     */
    public function test_process_state_is_resolved_at_boot(): void
    {
        $base = $this->app;
        $fresh = $this->createApplication();

        try {
            foreach (self::processBindings() as $binding) {
                self::assertTrue($fresh->resolved($binding), "{$binding} is resolved when the application boots.");
            }

            self::assertSame([], $fresh->make('db')->getConnections(), 'The boot opened no database connection.');
            Container::setInstance($fresh);
            self::assertSame([], PackageSession::sessionsForTesting(), 'The boot built no package session.');
        } finally {
            // The new application took the container and the facades: give them back.
            Container::setInstance($base);
            Facade::setFacadeApplication($base);
            Facade::clearResolvedInstances();
            $fresh->flush();
        }
    }

    /**
     * Two Octane requests: each runs in a sandbox, a clone of the base
     * application, and Octane flushes the sandbox and the base's scoped
     * instances between them. Each process binding is the same object in
     * both requests.
     *
     * Rejects: a process singleton resolved lazily, which each sandbox builds
     * for itself.
     */
    public function test_two_sandboxes_share_the_process_state(): void
    {
        $base = $this->app;
        $seen = [];

        try {
            foreach ([1, 2] as $request) {
                $base->forgetScopedInstances();
                $sandbox = clone $base;
                Container::setInstance($sandbox);

                foreach (self::processBindings() as $binding) {
                    $instance = Container::getInstance()->make($binding);
                    self::$kept[] = $instance;
                    $seen[$request][$binding] = spl_object_id($instance);
                }

                Container::setInstance($base);
                $sandbox->flush();
            }
        } finally {
            Container::setInstance($base);
        }

        self::assertSame($seen[1], $seen[2]);
    }

    /**
     * Octane gives each request its own copy of the configuration (the
     * sandbox binds a clone as `config`). A singleton that the base resolved
     * at boot reads the request's configuration when it uses it.
     *
     * Rejects: a boot-resolved singleton that keeps the configuration
     * repository of its constructor, so a request's change (here the
     * `orrery.waits` threshold of WaitTime) never applies.
     * Still passes: a class that copies a value out of the configuration in
     * its constructor (this test checks WaitTime only; the other boot-resolved
     * classes with a configuration read it the same way).
     */
    public function test_boot_resolved_state_reads_the_request_configuration(): void
    {
        $base = $this->app;
        Queue::push(new \Justrau\Orrery\Tests\Feature\Jobs\BasicJob);
        $waits = $base->make(\Justrau\Orrery\Monitoring\WaitTime::class);
        $sandbox = clone $base;
        $config = clone $base->make('config');
        $sandbox->instance('config', $config);
        Container::setInstance($sandbox);

        try {
            $config->set('orrery.waits', ['*' => 7]);
            $rows = $waits->measure();
        } finally {
            Container::setInstance($base);
        }

        self::assertNotSame([], $rows, 'WaitTime measured the queues.');

        foreach ($rows as $row) {
            self::assertSame(7, $row['threshold'], 'The request\'s configuration sets the threshold.');
        }
    }

    /**
     * The process bindings: PROCESS and every module's PROCESS_STATE.
     *
     * @return list<class-string>
     */
    private static function processBindings(): array
    {
        $bindings = self::PROCESS;

        foreach (\Justrau\Orrery\OrreryServiceProvider::MODULES as $module) {
            if (defined($module.'::PROCESS_STATE')) {
                $bindings = [...$bindings, ...constant($module.'::PROCESS_STATE')];
            }
        }

        return array_values(array_unique($bindings));
    }

    /**
     * @param  array<string, int>  $first
     * @param  array<string, int>  $second
     */
    private function assertLifetimes(array $first, array $second, string $unit): void
    {
        foreach (self::SCOPED as $binding) {
            self::assertNotSame($first[$binding], $second[$binding], "{$binding} is unit-of-work state: the second {$unit} gets a new instance.");
        }

        foreach (self::PROCESS as $binding) {
            self::assertSame($first[$binding], $second[$binding], "{$binding} is process state: the second {$unit} keeps the instance.");
        }
    }
}
