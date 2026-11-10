<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Registration\Modules\RuntimeModule;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\ProcessState;
use Justrau\Orrery\Runtime\SharedBreaker;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\MirrorOutage;
use Justrau\Orrery\Tests\TestCase;
use PDOException;
use Throwable;

/**
 * The breaker across requests. Under PHP-FPM each request builds
 * a new application, so a breaker kept only in the watcher singleton closes
 * at every request, and each request pays the connect timeout of an
 * unreachable mirror again. The shared breaker keeps the end of an open
 * breaker in a small file, keyed by the mirror's endpoint identity.
 */
final class SharedBreakerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(1_789_999_000, 'UTC'));
        $this->directory = sys_get_temp_dir().'/orrery-shared-breaker-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->app['config']->set('orrery.observer.shared_breaker', true);
        $this->app['config']->set('orrery.observer.shared_breaker_path', $this->directory);
        $this->newApplicationState();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        @chmod($this->directory, 0700);

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * Rejects: a breaker kept only in the watcher singleton, so the next
     * request (a new application) tries the unreachable mirror again; and a
     * shared end that never expires.
     */
    public function test_a_breaker_opened_in_one_request_holds_in_the_next(): void
    {
        $this->loseTheMirror();
        self::assertTrue(Watcher::instance()->breakerOpen());
        self::assertCount(1, glob($this->directory.'/*') ?: [], 'the open breaker wrote no shared file');

        // The next request: a new application with a new watcher.
        Carbon::setTestNow(Carbon::now()->addSeconds(20));
        $this->newApplicationState();

        $calls = 0;
        self::assertSame('skipped', Watcher::instance()->run('test:next', static function () use (&$calls): string {
            $calls++;

            return 'ran';
        }, 'skipped'));
        self::assertSame(0, $calls, 'the next request tried the mirror while the shared breaker was open');
        self::assertTrue(Watcher::instance()->breakerOpen());

        // It holds only for the rest of the window: 10 s (both clocks move).
        Watcher::advanceMonotonicForTesting(10);
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        self::assertFalse(Watcher::instance()->breakerOpen(), 'the adopted breaker outlived the window of the first request');

        // That watcher claimed the probe when the window ended. A request
        // after the claim does not adopt the breaker: it probes itself.
        Carbon::setTestNow(Carbon::now()->addSeconds(SharedBreaker::PROBE_SECONDS + 1));
        $this->newApplicationState();
        self::assertSame('ran', Watcher::instance()->run('test:after', static fn (): string => 'ran', 'skipped'));
    }

    /**
     * Rejects: trusting a stored end beyond the breaker window (a wall clock
     * that jumped back, a damaged file), so the observations stop for longer
     * than breaker_seconds.
     */
    public function test_a_stored_end_beyond_the_window_or_damaged_is_ignored(): void
    {
        $this->loseTheMirror();
        $files = glob($this->directory.'/*') ?: [];
        self::assertCount(1, $files);

        foreach ([(Carbon::now()->getTimestamp() + 3600).' '.Carbon::now()->getTimestamp(), (string) (Carbon::now()->getTimestamp() + 10), 'not a time', ''] as $content) {
            file_put_contents($files[0], $content);
            $this->newApplicationState();
            self::assertFalse(Watcher::instance()->breakerOpen(), "the stored end '{$content}' opened the breaker");
        }
    }

    /**
     * Rejects: a shared file that is on in the console by default (workers
     * and commands keep their breaker in memory), or a store that throws
     * into the host when its directory cannot be written.
     */
    public function test_the_store_is_off_in_the_console_by_default_and_never_throws(): void
    {
        $this->app['config']->set('orrery.observer.shared_breaker', null);
        $this->newApplicationState();
        $this->loseTheMirror();
        self::assertTrue(Watcher::instance()->breakerOpen());
        self::assertSame([], glob($this->directory.'/*') ?: [], 'a console process wrote the shared file');

        $this->app['config']->set('orrery.observer.shared_breaker', true);
        $this->app['config']->set('orrery.observer.shared_breaker_path', $this->directory.'/missing/deeper');
        $this->newApplicationState();
        $this->loseTheMirror();
        self::assertTrue(Watcher::instance()->breakerOpen(), 'an unwritable store kept the breaker closed');

        $this->newApplicationState();
        self::assertFalse(Watcher::instance()->breakerOpen());
    }

    /**
     * Rejects: a production default (`shared_breaker` null) that
     * is off under a web SAPI, where every request builds a new application
     * and needs the file, or on in the console, where workers and commands
     * keep their breaker in memory. It would still pass a rule that reads a
     * boolean string ("true") as unset; the configuration holds a boolean.
     * FrankenPHP's SAPI is `frankenphp` in classic and in worker mode, and
     * its worker threads each hold their own application, so the file is on.
     */
    public function test_the_default_of_the_shared_file_follows_the_sapi(): void
    {
        foreach (['fpm-fcgi' => true, 'apache2handler' => true, 'cli-server' => true, 'cgi-fcgi' => true, 'frankenphp' => true, 'cli' => false, 'phpdbg' => false] as $sapi => $on) {
            self::assertSame($on, RuntimeModule::sharedBreakerEnabled(null, $sapi), $sapi);
        }

        self::assertTrue(RuntimeModule::sharedBreakerEnabled(true, 'cli'));
        self::assertFalse(RuntimeModule::sharedBreakerEnabled(false, 'fpm-fcgi'));
    }

    /**
     * Rejects: a flush that writes while the breaker is open, so a request
     * that already lost the mirror pays one more connect timeout in its
     * terminating flush; and a breaker close that does not flush.
     */
    public function test_the_flush_skips_while_the_breaker_is_open(): void
    {
        $flushes = $this->countFlushes();
        $this->loseTheMirror();
        self::assertNotNull(Degradation::record());

        Degradation::flush();
        self::assertSame(0, $flushes->count, 'the flush wrote while the breaker was open');
        self::assertSame(0, $this->mirror()->table('orrery_health_events')->count());

        Watcher::advanceMonotonicForTesting(30);
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        self::assertFalse(Watcher::instance()->breakerOpen());
        self::assertSame(1, $flushes->count, 'the close of the breaker did not flush');
        self::assertSame(1, $this->mirror()->table('orrery_health_events')->count());
    }

    /**
     * Rejects: a degradation flush whose write fails with a mirror dependency error and leaves the breaker
     * closed: the close of an expired breaker flushes, the flush pays one connect timeout, and the
     * work after it pays a second one. The failed flush opens and shares the breaker, so the work is skipped.
     * Would still pass: a flush that opens the breaker but does not share it while the store is off.
     */
    public function test_a_flush_that_loses_the_mirror_opens_and_shares_the_breaker(): void
    {
        $this->loseTheMirror();
        $this->failHealthWrites();

        Watcher::advanceMonotonicForTesting(30);
        Carbon::setTestNow(Carbon::now()->addSeconds(30));

        $calls = 0;
        self::assertSame('skipped', Watcher::instance()->run('test:after_close', static function () use (&$calls): string {
            $calls++;

            return 'ran';
        }, 'skipped'));

        self::assertSame(0, $calls, 'the work tried the mirror after the flush had lost it');
        self::assertTrue(Watcher::instance()->dependencyBreakerOpen());
        self::assertNotNull($this->otherProcess()->remaining(30), 'the breaker of the failed flush was not shared');
    }

    /**
     * Rejects: a terminating flush without a breaker whose write loses the mirror and keeps the breaker closed:
     * under PHP-FPM the next request connects again; the failed flush shares the breaker. A
     * flush that fails with another error (here: no table) opens nothing.
     */
    #[FreshSchema]
    public function test_a_terminating_flush_that_loses_the_mirror_shares_the_breaker(): void
    {
        // The rename outage in every mode: a missing table is a data error. A separate mirror's
        // default outage is a lost server, a dependency error that rightly opens the breaker.
        $outage = MirrorOutage::begin($this->app, $this->testEnvironment(), MirrorOutage::RENAME);

        try {
            Degradation::error('test:recorded', 'a recorded error');
            Degradation::flush(terminating: true);
        } finally {
            $outage->end();
        }

        self::assertFalse(Watcher::instance()->breakerOpen(), 'a data error opened the breaker');

        $this->failHealthWrites();
        Degradation::flush(terminating: true);

        self::assertTrue(Watcher::instance()->dependencyBreakerOpen());
        $this->newApplicationState();
        self::assertTrue(Watcher::instance()->breakerOpen(), 'the next request did not adopt the breaker of the failed flush');
    }

    /**
     * Rejects: a breaker of lock timeouts that stays local when a flush then loses the mirror:
     * the open breaker does not open again, so nothing shares it, and the next request connects again. The
     * dependency error marks it a dependency breaker and shares it.
     */
    public function test_a_flush_that_loses_the_mirror_shares_an_open_lock_breaker(): void
    {
        $this->lockTimeout();
        $this->lockTimeout();
        self::assertTrue(Watcher::instance()->breakerOpen());
        self::assertFalse(Watcher::instance()->dependencyBreakerOpen());
        self::assertSame([], glob($this->directory.'/*') ?: [], 'a breaker of lock timeouts is not shared');

        $this->failHealthWrites();
        Degradation::error('test:recorded', 'a recorded error');
        Degradation::flush(terminating: true);

        self::assertTrue(Watcher::instance()->dependencyBreakerOpen());
        self::assertNotNull($this->otherProcess()->remaining(30), 'the breaker was not shared');
    }

    /**
     * Rejects: two terminating flushes, so a failed write is tried twice at
     * the end of one request.
     */
    #[FreshSchema]
    public function test_terminating_flushes_once(): void
    {
        $outage = $this->mirrorOutage();
        Degradation::error('test:recorded', 'a recorded error');
        $flushes = $this->countFlushes();

        $this->app->terminate();
        $outage->end();

        self::assertSame(1, $flushes->count, 'terminating tried the failing flush more than once');
        self::assertNotNull(Degradation::record(), 'the failed flush lost the record');
    }

    /**
     * Rejects: a store that only one mirror can use (a fixed file name), so
     * the breaker of one mirror stops the observations of another.
     */
    public function test_two_mirrors_have_two_files(): void
    {
        $this->loseTheMirror();
        $original = $this->app['config']->get('orrery.database');
        $this->app['config']->set('database.connections.orrery_second_mirror', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->app['config']->set('orrery.database', 'orrery_second_mirror');

        try {
            // The same store (a long-lived process): its key follows the mirror.
            $this->app->forgetInstance(Watcher::class);
            $this->app->forgetInstance(Degradation::class);
            self::assertFalse(Watcher::instance()->breakerOpen(), "the first mirror's breaker stopped the second mirror");

            $this->loseTheMirror();
            self::assertCount(2, glob($this->directory.'/*') ?: [], 'two mirrors did not get two files');
        } finally {
            // The fresh schema's rollback reads orrery.database: it must undo the migration where it ran.
            $this->app['config']->set('orrery.database', $original);
        }
    }

    /**
     * Rejects: a long-lived web worker (Octane, a non-console SAPI) that
     * reads the store only once, so it never sees a breaker that another
     * process opened later; and a read on every watched work.
     */
    public function test_a_long_lived_watcher_reads_the_store_once_per_second(): void
    {
        $watcher = Watcher::instance();
        self::assertFalse($watcher->breakerOpen());

        // Another process opens the breaker.
        $other = new SharedBreaker($this->directory, fn (): string => $this->app->make(PackageSession::class)->mirrorKey());
        self::assertTrue($other->open(30.0));

        self::assertFalse($watcher->breakerOpen(), 'the watcher read the store again within a second');
        Watcher::advanceMonotonicForTesting(1);
        self::assertTrue($watcher->breakerOpen(), 'the watcher never read the store again');
    }

    /**
     * Rejects: a store whose failed write is silent (a read-only directory:
     * the breaker then never reaches the next requests), a report per
     * opening, and a failed write that leaves its temporary file.
     */
    public function test_a_read_only_directory_is_reported_once(): void
    {
        $reported = $this->recordReports();
        chmod($this->directory, 0500);

        if (is_writable($this->directory)) {
            self::markTestSkipped('The directory stays writable (the tests run as root).');
        }

        $this->loseTheMirror();
        self::assertTrue(Watcher::instance()->breakerOpen(), 'an unwritable store kept the breaker closed');
        // Past the report limit of one operation: once per process, not once per minute.
        Watcher::advanceMonotonicForTesting(Watcher::REPORT_INTERVAL_SECONDS + 1);
        $this->loseTheMirror();

        $shared = array_filter($reported->items, static fn (Throwable $e): bool => $e instanceof WatchedOperationFailed && $e->operation === 'shared_breaker');
        self::assertCount(1, $shared, 'the unwritable store was not reported exactly once');
        self::assertSame([], glob($this->directory.'/*') ?: [], 'the failed write left a file');
    }

    /**
     * Rejects: an expired end that every request takes as closed at once (all
     * of them wait the connect timeout together), a claim that outlives a
     * probe that reached the mirror, and claims long after the failure.
     */
    public function test_one_request_probes_an_expired_breaker(): void
    {
        $this->loseTheMirror();
        Carbon::setTestNow(Carbon::now()->addSeconds(31));

        $this->newApplicationState();
        $probe = Watcher::instance();
        self::assertFalse($probe->breakerOpen(), 'the first request after the end did not probe');

        $this->newApplicationState();
        self::assertTrue(Watcher::instance()->breakerOpen(), 'a second request probed while the first one did');

        $sessions = $this->app->make(PackageSession::class);
        self::assertSame(1, $probe->run('test:probe', static fn (): mixed => (int) $sessions->mirror()->scalar('select 1')));
        self::assertSame([], glob($this->directory.'/*') ?: [], 'the probe that reached the mirror kept the file');

        $this->newApplicationState();
        self::assertFalse(Watcher::instance()->breakerOpen());

        // A failure long ago: the expired end gives no claim.
        $this->loseTheMirror();
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->newApplicationState();
        self::assertFalse(Watcher::instance()->breakerOpen());
        $this->newApplicationState();
        self::assertFalse(Watcher::instance()->breakerOpen(), 'an old failure still holds the requests off');
    }

    /**
     * Rejects: a probe claim that a degradation flush took (the flush reads
     * the store first) and that outlives the flush's successful write, so
     * the other requests keep skipping a mirror that answers until the
     * claim ends, and the next one probes again. It would still
     * pass a flush that removes the file after a failed write; the second
     * part keeps the file then.
     */
    public function test_a_flush_that_reaches_the_mirror_ends_its_probe_claim(): void
    {
        $this->loseTheMirror();
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->newApplicationState();
        Degradation::error('test:recorded', 'a recorded error');

        Degradation::flush(terminating: true);

        self::assertSame(1, $this->mirror()->table('orrery_health_events')->count(), 'the probing flush did not write');
        self::assertSame([], glob($this->directory.'/*') ?: [], 'the flush that reached the mirror kept its probe claim');

        $this->loseTheMirror();
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->newApplicationState();
        $this->failHealthWrites();
        Degradation::error('test:recorded', 'a recorded error');
        Degradation::flush(terminating: true);
        self::assertCount(1, glob($this->directory.'/orrery-breaker-*') ?: [], 'a failed flush removed the file');
    }

    /**
     * Rejects: a breaker of lock timeouts that is shared (two slow writes of
     * one request then stop every request), that stops the terminating
     * flush (the record of the process would be lost), or that lets a
     * heartbeat flush write into the blocked table (each flush then waits
     * its lock timeout; the close of the breaker flushes anyway).
     */
    public function test_a_breaker_of_lock_timeouts_is_not_shared_and_flushes(): void
    {
        $flushes = $this->countFlushes();
        $this->lockTimeout();
        $this->lockTimeout();
        self::assertTrue(Watcher::instance()->breakerOpen(), 'two lock timeouts kept the breaker closed');
        self::assertSame([], glob($this->directory.'/*') ?: [], 'a breaker of lock timeouts was shared');

        Degradation::flush();
        self::assertSame(0, $flushes->count, 'a heartbeat flush wrote while a breaker was open');

        Degradation::flush(terminating: true);
        self::assertSame(1, $flushes->count, 'a breaker of lock timeouts stopped the terminating flush');
    }

    /**
     * Rejects: a watcher that closes its expired breaker without a look at
     * the store, so it flushes into a mirror that another process found
     * unreachable meanwhile (the flush waits the connect timeout).
     */
    public function test_an_expired_breaker_reads_the_store_before_it_closes(): void
    {
        $this->loseTheMirror();
        $flushes = $this->countFlushes();

        // Another process loses the mirror 20 s later: its end is 30 s after that.
        Carbon::setTestNow(Carbon::now()->addSeconds(20));
        self::assertTrue($this->otherProcess()->open(30.0));

        Watcher::advanceMonotonicForTesting(30);
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        self::assertTrue(Watcher::instance()->breakerOpen(), 'the expired breaker closed while the store held a later end');
        self::assertSame(0, $flushes->count, 'the close flushed into the unreachable mirror');
    }

    /**
     * Rejects: a flush that trusts the read of the store from less than a
     * second ago, so it writes into a mirror that another process has just
     * found unreachable.
     */
    public function test_the_flush_reads_the_store_again_before_it_writes(): void
    {
        Degradation::error('test:recorded', 'a recorded error');
        $flushes = $this->countFlushes();
        self::assertFalse(Watcher::instance()->breakerOpen());

        self::assertTrue($this->otherProcess()->open(30.0));
        Log::spy();
        Degradation::flush(terminating: true);

        self::assertSame(0, $flushes->count, 'the flush wrote although the store held an open breaker');
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * Rejects: a probe claim that the other processes ignore when the
     * breaker window is shorter than the claim (PROBE_SECONDS), so every
     * request probes the unreachable mirror at once.
     */
    public function test_a_probe_claim_holds_with_a_short_breaker_window(): void
    {
        self::assertGreaterThan(3.0, SharedBreaker::PROBE_SECONDS);
        $this->app['config']->set('orrery.observer.breaker_seconds', 3);
        $this->newApplicationState();
        $this->loseTheMirror();
        Carbon::setTestNow(Carbon::now()->addSeconds(4));

        $this->newApplicationState();
        self::assertFalse(Watcher::instance()->breakerOpen(), 'the first request after the end did not probe');

        $this->newApplicationState();
        self::assertTrue(Watcher::instance()->breakerOpen(), 'a second request probed while the first one did');
    }

    /**
     * Rejects: a failed rename that leaves its temporary file (a directory
     * fills with them, one per opening), or that throws into the host.
     */
    public function test_a_failed_rename_leaves_no_temporary_file(): void
    {
        $reported = $this->recordReports();
        $path = $this->directory.'/orrery-breaker-'.substr($this->app->make(PackageSession::class)->mirrorKey(), 0, 40);
        mkdir($path);

        try {
            $this->loseTheMirror();
            self::assertTrue(Watcher::instance()->breakerOpen(), 'the failed write kept the breaker closed');
            self::assertSame([], glob($this->directory.'/*.tmp') ?: [], 'the failed rename left its temporary file');
            self::assertCount(1, array_filter($reported->items, static fn (Throwable $e): bool => $e instanceof WatchedOperationFailed && $e->operation === 'shared_breaker'));
        } finally {
            @rmdir($path);
        }
    }

    /**
     * Rejects: a probe whose success removes the file although another
     * process wrote a newer end into it meanwhile (its probe failed), so
     * the next requests try the unreachable mirror again.
     */
    public function test_a_probe_keeps_a_newer_end_of_another_process(): void
    {
        $this->loseTheMirror();
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->newApplicationState();
        $probe = Watcher::instance();
        self::assertFalse($probe->breakerOpen(), 'the request did not claim the probe');

        self::assertTrue($this->otherProcess()->open(30.0));
        $sessions = $this->app->make(PackageSession::class);
        self::assertSame(1, $probe->run('test:probe', static fn (): mixed => (int) $sessions->mirror()->scalar('select 1')));

        self::assertCount(1, glob($this->directory.'/orrery-breaker-*') ?: [], 'the probe removed the newer end');
        $this->newApplicationState();
        self::assertTrue(Watcher::instance()->breakerOpen());
    }

    /**
     * Rejects: a terminating flush that skips its write without a trace (the
     * record of a web request or of orrery:tick is lost unseen), and a log
     * line per skipped flush.
     */
    public function test_a_skipped_terminating_flush_is_logged_once(): void
    {
        $this->loseTheMirror();
        Log::spy();

        Degradation::flush(terminating: true);
        Degradation::flush(terminating: true);
        Degradation::flush();

        Log::shouldHaveReceived('warning')->once();
        self::assertNotNull(Degradation::record());
    }

    /**
     * Rejects: a skip log that is never armed again, so in a long-lived
     * process (Octane) the skipped terminating flush of a later outage,
     * after a flush that reached the mirror, is silent. It would still pass
     * a log line per skipped flush of one outage; the first test pins once.
     */
    public function test_a_later_outage_logs_its_skipped_flush_again(): void
    {
        $this->loseTheMirror();
        Log::spy();
        Degradation::flush(terminating: true);

        Watcher::advanceMonotonicForTesting(31);
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        // The close of the breaker flushes the record into the mirror.
        self::assertFalse(Watcher::instance()->breakerOpen());

        $this->loseTheMirror();
        Degradation::flush(terminating: true);

        Log::shouldHaveReceived('warning')->twice();
    }

    /**
     * The store as another process sees it.
     */
    private function otherProcess(): SharedBreaker
    {
        return new SharedBreaker($this->directory, fn (): string => $this->app->make(PackageSession::class)->mirrorKey());
    }

    /**
     * A dependency error of the mirror session in watched work.
     */
    private function loseTheMirror(): void
    {
        $name = $this->app->make(PackageSession::class)->mirrorSessionName();
        Watcher::instance()->run('test:lost', static function () use ($name): never {
            throw new QueryException($name, 'select 1', [], new class extends PDOException
            {
                public function __construct()
                {
                    parent::__construct('SQLSTATE[08006]: connection failure');
                    $this->code = '08006';
                    $this->errorInfo = ['08006', 7, 'connection failure'];
                }
            });
        });
    }

    /**
     * Every later write of the health events on the mirror session fails with a dependency error of that
     * session, as an unreachable server makes it fail.
     */
    private function failHealthWrites(): void
    {
        $sessions = $this->app->make(PackageSession::class);
        $name = $sessions->mirrorSessionName();
        $sessions->mirror()->beforeExecuting(static function (string $query) use ($name): void {
            // Only the flush's reads and writes: the teardown's schema statements still run.
            if (! str_contains($query, 'orrery_health_events') || preg_match('/^\s*(insert|update|select|delete)\b/i', $query) !== 1) {
                return;
            }

            throw new QueryException($name, $query, [], new class extends PDOException
            {
                public function __construct()
                {
                    parent::__construct('SQLSTATE[08006]: connection failure');
                    $this->code = '08006';
                    $this->errorInfo = ['08006', 7, 'connection failure'];
                }
            });
        });
    }

    /**
     * A lock timeout of the mirror session in watched work, in the form of
     * the mirror's driver.
     */
    private function lockTimeout(): void
    {
        $sessions = $this->app->make(PackageSession::class);
        $name = $sessions->mirrorSessionName();
        $info = match ($sessions->sessionDriver($name)) {
            'mysql', 'mariadb' => ['HY000', 1205, 'Lock wait timeout exceeded'],
            'pgsql' => ['55P03', 7, 'canceling statement due to lock timeout'],
            default => ['HY000', 5, 'database is locked'],
        };
        Watcher::instance()->run('test:locked', static function () use ($name, $info): never {
            $error = new PDOException('SQLSTATE['.$info[0].']: '.$info[2]);
            $error->errorInfo = $info;

            throw new QueryException($name, 'update orrery_jobs set status = ?', ['x'], $error);
        });
    }

    /**
     * Records Laravel's reports.
     */
    private function recordReports(): object
    {
        $reported = new class
        {
            /** @var list<Throwable> */
            public array $items = [];
        };

        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler
        {
            public function __construct(private object $reported) {}

            public function report(Throwable $e): void
            {
                $this->reported->items[] = $e;
            }

            public function shouldReport(Throwable $e): bool
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                throw $e;
            }

            public function renderForConsole($output, Throwable $e): void {}
        });

        return $reported;
    }

    /**
     * Counts the flushes that reach their write (the hook point before it).
     */
    private function countFlushes(): object
    {
        $counter = new class
        {
            public int $count = 0;
        };

        Hooks::$callback = static function (string $point) use ($counter): void {
            if ($point === Degradation::BEFORE_FLUSH) {
                $counter->count++;
            }
        };

        return $counter;
    }

    /**
     * The runtime singletons of a new application, as the next request
     * builds them.
     */
    private function newApplicationState(): void
    {
        foreach ([Watcher::class, Degradation::class, ProcessState::class, SharedBreaker::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }
}
