<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Control;

use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Control\Phases\SignalExpiryPhase;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Database\Models\Signal;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\TestClock;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * Terminate in one time domain, the clock of the mirror's database server.
 * A host clock is frozen with Carbon::setTestNow() at the
 * database time plus an offset, so a drift between the test host and a
 * database container (a Docker VM clock) does not change the result. The issuer, the worker and the tick
 * host are the same process here, so each step sets the clock of the host
 * that it plays.
 */
final class TerminateClockTest extends TestCase
{
    use WorkerFixtures;

    /** @var list<string> */
    private array $clockReads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if (str_contains($event->sql, DatabaseClock::ALIAS)) {
                $this->clockReads[] = (string) $event->connectionName;
            }
        });

        $this->hostClock(0);
        $this->clockReads = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: the local comparison (the issuer's clock, 120 s ahead, makes a signal look newer than a replacement worker).
     */
    public function test_worker_started_after_the_signal_ignores_it_when_the_issuer_clock_is_ahead(): void
    {
        $this->hostClock(+120);
        (new SignalState)->terminate('global', '');
        $this->hostClock(0);

        // The replacement worker started 30 s after the signal, on a correct clock.
        $startedAt = Clock::now() + 30;

        self::assertNull((new SignalState)->terminateIssuedAfter('database', 'default', $startedAt), 'a worker that started after the signal stops');
    }

    /**
     * Rejects: the local comparison (the issuer's clock, 120 s behind, makes a signal look older than a running worker).
     */
    public function test_worker_started_before_the_signal_stops_when_the_issuer_clock_is_behind(): void
    {
        $this->hostClock(-120);
        (new SignalState)->terminate('global', '');
        $this->hostClock(0);

        self::assertNotNull((new SignalState)->terminateIssuedAfter('database', 'default', Clock::now() - 60), 'a worker that started before the signal ignores it');
    }

    /**
     * Rejects: a local `expires_at` (an issuer 40 minutes behind writes a signal that expired already).
     */
    public function test_issuer_behind_by_more_than_the_ttl_still_stops_old_workers(): void
    {
        $this->hostClock(-2400);
        (new SignalState)->terminate('global', '');
        $this->hostClock(0);

        $stored = Carbon::parse((string) $this->mirror()->table('orrery_signals')->where('signal', 'terminate')->value('expires_at'), date_default_timezone_get())->getTimestamp();
        self::assertEqualsWithDelta(DatabaseClock::now(Container::getInstance()->make(PackageSession::class)->mirror()) + 1800, $stored, 5, 'expires_at is not the database time plus the 30-minute TTL');

        self::assertNotNull((new SignalState)->terminateIssuedAfter('database', 'default', Clock::now() - 60), 'an old worker ignored a live signal');
    }

    /**
     * Rejects: a worker that judges with its own clock (60 s ahead: a worker that started before the signal reads its start as later).
     */
    public function test_a_worker_clock_ahead_is_calibrated(): void
    {
        (new SignalState)->terminate('global', '');
        $this->clockReads = [];

        // The worker started 30 s before the signal; its clock runs 60 s ahead, so it read its start as 30 s after.
        $this->hostClock(+60);
        $startedAt = Clock::now() - 30;

        self::assertNotNull((new SignalState)->terminateIssuedAfter('database', 'default', $startedAt), 'the worker compared its own clock with the database clock');
        self::assertCount(1, $this->clockReads, 'the worker did not calibrate once');
    }

    /**
     * Rejects: a worker that judges the expiry with its own clock (40 minutes ahead, it reads a live signal as expired).
     */
    public function test_a_worker_clock_ahead_judges_the_expiry_on_the_database_clock(): void
    {
        (new SignalState)->terminate('global', '');

        // The worker's clock runs 40 minutes ahead; it started 60 s before now by that clock.
        $this->hostClock(+2400);

        self::assertNotNull((new SignalState)->terminateIssuedAfter('database', 'default', Clock::now() - 60), 'the worker read a live signal as expired');
    }

    /**
     * Rejects: a calibration for a terminate row that expired long ago and was never deleted (one extra statement per new worker, forever), and a skip that also covers a row that expired a moment ago.
     */
    public function test_a_long_expired_terminate_row_needs_no_calibration(): void
    {
        $database = DatabaseClock::now(Container::getInstance()->make(PackageSession::class)->mirror());
        $this->mirror()->table('orrery_signals')->insert([
            'signal' => 'terminate',
            'scope' => 'global',
            'scope_value' => '',
            'issued_at' => Clock::toDecimal($database - 7200),
            'expires_at' => SignalState::dateTime($database - SignalState::TERMINATE_TTL_MINUTES * 60 - 60),
        ]);
        $this->clockReads = [];

        self::assertNull((new SignalState)->terminateIssuedAfter('database', 'default', Clock::now() - 60));
        self::assertSame([], $this->clockReads, 'a worker calibrated for a terminate row that expired more than one TTL ago');

        // A row that expired a minute ago may still be live on a calibrated clock: the worker calibrates.
        $this->mirror()->table('orrery_signals')->update(['expires_at' => SignalState::dateTime($database - 60)]);
        SignalState::flushCache();
        self::assertNull((new SignalState)->terminateIssuedAfter('database', 'default', Clock::now() - 60));
        self::assertCount(1, $this->clockReads);
    }

    /**
     * Rejects: a local delete (a tick host 40 minutes ahead deletes a signal that lives for 30 more minutes).
     */
    public function test_expiry_phase_ahead_keeps_a_live_signal(): void
    {
        (new SignalState)->terminate('global', '');
        $database = DatabaseClock::now(Container::getInstance()->make(PackageSession::class)->mirror());
        $this->mirror()->table('orrery_signals')->insert([
            'signal' => 'terminate',
            'scope' => 'connection',
            'scope_value' => 'database',
            'issued_at' => Clock::toDecimal($database - 3600),
            'expires_at' => SignalState::dateTime($database - 60),
        ]);

        $this->hostClock(+2400);
        $this->clockReads = [];
        app(SignalExpiryPhase::class)->run(new TickBudget(Watcher::monotonic() + 30));

        self::assertSame(['global'], $this->mirror()->table('orrery_signals')->pluck('scope')->all(), 'the phase deleted a live signal, or kept an expired one');
        self::assertCount(1, $this->clockReads, 'the phase did not read the database clock once');

        // Without a row that has an expiry, the phase reads no clock.
        $this->mirror()->table('orrery_signals')->delete();
        $this->clockReads = [];
        app(SignalExpiryPhase::class)->run(new TickBudget(Watcher::monotonic() + 30));
        self::assertSame([], $this->clockReads);
    }

    /**
     * Rejects: an expiry decided on wall-clock strings of a time zone with
     * daylight saving time: in the hour that repeats when the clocks go
     * back, the tick deletes a terminate signal that lives for 25 more
     * minutes.
     */
    public function test_expiry_phase_keeps_a_live_signal_in_the_repeated_hour(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vilnius');

        try {
            // 2026-10-25 00:50 UTC is 03:50 EEST, ten minutes before the clocks go back to 03:00 EET.
            TestClock::shift(TestClock::EVERY, 1_792_889_400 - microtime(true));
            (new SignalState)->terminate('global', '');

            // 00:55 UTC (03:55 EEST): the signal lives until 01:20 UTC (03:20 EET).
            TestClock::shift(TestClock::EVERY, 1_792_889_700 - microtime(true));
            app(SignalExpiryPhase::class)->run(new TickBudget(Watcher::monotonic() + 30));
            self::assertSame(['global'], $this->mirror()->table('orrery_signals')->pluck('scope')->all(), 'the phase deleted a live signal');
            $expires = Signal::query()->value('expires_at');
            self::assertInstanceOf(\DateTimeInterface::class, $expires);
            // The shifted server clock is about the target (the shift and the read take time); an hour off is the bug.
            self::assertEqualsWithDelta(1_792_891_200, $expires->getTimestamp(), 5, 'the stored expiry reads back as another time');

            // 01:21 UTC: it expired.
            TestClock::shift(TestClock::EVERY, 1_792_891_260 - microtime(true));
            app(SignalExpiryPhase::class)->run(new TickBudget(Watcher::monotonic() + 30));
            self::assertSame([], $this->mirror()->table('orrery_signals')->pluck('scope')->all(), 'the phase kept an expired signal');
        } finally {
            date_default_timezone_set($zone);
        }
    }

    /**
     * Rejects: a worker that never stops when the calibration fails, and a calibration that is silently missing (no health record, or no retry).
     */
    public function test_failed_calibration_is_recorded_and_retried(): void
    {
        $this->app['config']->set('orrery.control.signal_cache_seconds', 0);
        (new SignalState)->terminate('global', '');
        $signals = new SignalState;

        Watcher::failOperationsForTesting(['control:clock']);
        self::assertNotNull($signals->terminateIssuedAfter('database', 'default', Clock::now() - 60), 'the worker ignored the terminate without a calibration');
        self::assertArrayHasKey(SignalState::CLOCK_UNCALIBRATED, (array) (Degradation::record()['errors'] ?? []), 'the failed calibration is not in the health record');

        // The next signal read with an applicable terminate row tries again.
        Watcher::failOperationsForTesting([]);
        $this->clockReads = [];
        self::assertNotNull($signals->terminateIssuedAfter('database', 'default', Clock::now() - 60));
        self::assertCount(1, $this->clockReads, 'the worker did not try the calibration again');

        // The calibration is kept for the process.
        self::assertNotNull($signals->terminateIssuedAfter('database', 'default', Clock::now() - 60));
        self::assertCount(1, $this->clockReads, 'the worker read the clock again after a calibration');
    }

    /**
     * Rejects: an eager clock read at the first signal read, or for a terminate row of another queue or connection.
     */
    public function test_no_clock_read_without_a_terminate_row(): void
    {
        $this->app['config']->set('orrery.control.signal_cache_seconds', 0);
        $this->actAsWorker(startedAt: Clock::now() - 60, controlStartedAt: Clock::now() - 60);
        $signals = new SignalState;
        $signals->pause('queue', 'database:other');
        $this->terminateRow('connection', 'redis');
        $this->terminateRow('queue', 'database:other');

        self::assertNull($signals->terminateIssuedAfter('database', 'default', Clock::now() - 60));
        self::assertFalse($signals->connectionPaused('database'));
        self::assertSame([], $this->clockReads, 'a worker without an applicable terminate row read the database clock');

        // An applicable row: one read, on the package storage session.
        $this->terminateRow('queue', 'database:default');
        self::assertNotNull($signals->terminateIssuedAfter('database', 'default', Clock::now() - 60));
        self::assertSame([(string) Container::getInstance()->make(PackageSession::class)->mirror()->getName()], $this->clockReads);
    }

    /**
     * Rejects: a terminate that falls back to the issuer's clock when the database clock cannot be read.
     */
    public function test_a_failed_clock_read_writes_no_terminate(): void
    {
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event): void {
            if (str_contains($event->sql, DatabaseClock::ALIAS)) {
                throw new RuntimeException('the database clock is not readable');
            }
        });

        try {
            (new SignalState)->terminate('global', '');
            self::fail('the terminate succeeded without the database clock');
        } catch (RuntimeException $error) {
            self::assertSame('the database clock is not readable', $error->getMessage());
        }

        self::assertSame(0, $this->mirror()->table('orrery_signals')->count(), 'a terminate was written without the database clock');
    }

    /**
     * Control: each engine's expression gives the real time.
     */
    public function test_the_database_clock_reads_the_server_time(): void
    {
        // A loose sanity bound: a database container's clock may drift from the host.
        $now = DatabaseClock::now(Container::getInstance()->make(PackageSession::class)->mirror());

        self::assertEqualsWithDelta(microtime(true), $now, 600, 'the database clock is not epoch seconds');
    }

    private function terminateRow(string $scope, string $value): void
    {
        $reads = $this->clockReads;
        $database = DatabaseClock::now(Container::getInstance()->make(PackageSession::class)->mirror());
        $this->clockReads = $reads;
        $this->mirror()->table('orrery_signals')->insert([
            'signal' => 'terminate',
            'scope' => $scope,
            'scope_value' => $value,
            'issued_at' => Clock::toDecimal($database),
            'expires_at' => SignalState::dateTime($database + 1800),
        ]);
    }

    /**
     * Freezes this host's clock $offset seconds from the database clock (0: a correct host clock).
     */
    private function hostClock(int $offset): void
    {
        // The test's own clock read is not a read of the code under test.
        $reads = $this->clockReads;
        Carbon::setTestNow(Carbon::createFromTimestamp(DatabaseClock::now(Container::getInstance()->make(PackageSession::class)->mirror()) + $offset, date_default_timezone_get()));
        $this->clockReads = $reads;
    }
}
