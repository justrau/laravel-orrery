<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Control;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Control\StopLatch;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\MirrorSession;
use Justrau\Orrery\Tests\TestCase;
use PDOException;

/**
 * Stale signals: when the mirror is unreachable, the last
 * successfully read signals stay in effect for
 * `orrery.control.stale_signals_seconds`; then the worker fails open, logs
 * and records it in the health events.
 */
final class StaleSignalsTest extends TestCase
{
    use WorkerFixtures;

    /**
     * Rejects: failing open at the first read error.
     */
    public function test_last_read_signals_stay_for_the_stale_window_then_fail_open(): void
    {
        $this->app['config']->set('orrery.control.stale_signals_seconds', 900);
        $this->actAsWorker();
        Queue::push(new BasicJob);

        $signals = new SignalState;
        $signals->pause('global', '');
        self::assertTrue($signals->connectionPaused('database'), 'the first read did not see the pause');

        // The mirror becomes unreachable: every signal read fails from now on.
        Watcher::failOperationsForTesting(['control:signals']);

        // Past the one-second cache, the read fails: the last read stays in effect.
        Watcher::advanceMonotonicForTesting(2);
        self::assertTrue($signals->connectionPaused('database'), 'the worker failed open at the first read error');
        self::assertNull(Queue::connection('database')->pop('default'), 'the stale pause did not hold pop()');
        self::assertNotSame('control:signals_stale', Degradation::record()['last_error_operation'] ?? null);

        // Still inside the window.
        Watcher::advanceMonotonicForTesting(890);
        self::assertTrue($signals->connectionPaused('database'), 'the stale window ended early');

        // After the window, the worker fails open and records it.
        Watcher::advanceMonotonicForTesting(10);
        self::assertFalse($signals->connectionPaused('database'), 'the worker did not fail open after the stale window');
        self::assertSame('control:signals_stale', Degradation::record()['last_error_operation'] ?? null, 'the fail-open is not in the degradation record');
        self::assertNotNull(Queue::connection('database')->pop('default'));

        // The next successful read applies the stored signals again.
        Watcher::failOperationsForTesting([]);
        Watcher::advanceMonotonicForTesting(2);
        self::assertTrue($signals->connectionPaused('database'));
    }

    /**
     * Rejects: a control check inside one watched run, which the open breaker skips, so pop() reserves while the stale pause is still in effect.
     */
    public function test_stale_signals_hold_pop_while_the_breaker_is_open(): void
    {
        $this->actAsWorker();
        Queue::push(new BasicJob);
        (new SignalState)->pause('global', '');
        self::assertNull(Queue::connection('database')->pop('default'), 'the first read did not see the pause');

        $watcher = $this->openBreaker();

        // Past the one-second cache, inside the breaker window and the stale window.
        Watcher::advanceMonotonicForTesting(2);
        self::assertTrue($watcher->breakerOpen());
        self::assertNull(Queue::connection('database')->pop('default'), 'the open breaker lifted the stale pause');
        self::assertSame(1, DB::table('jobs')->whereNull('reserved_at')->count());
    }

    /**
     * Rejects: a queue name that the open breaker replaces with '' (popShouldSkip($connection, '')), so a stale queue-scoped terminate no longer matches its queue (it replaces the removed queue-pause cases).
     */
    public function test_a_stale_queue_terminate_holds_pop_while_the_breaker_is_open(): void
    {
        $kills = 0;
        StopLatch::useKillerForTesting(static function () use (&$kills): void {
            $kills++;
        });
        $this->actAsWorker();
        Queue::push(new BasicJob);
        (new SignalState)->terminate('queue', 'database:default');
        // The first read sees the terminate, which does not apply to another queue.
        self::assertNull(Queue::connection('database')->pop('other'));
        self::assertFalse(StopLatch::isSet());

        $watcher = $this->openBreaker();
        Watcher::advanceMonotonicForTesting(2);
        self::assertTrue($watcher->breakerOpen());

        self::assertNull(Queue::connection('database')->pop('default'), 'the open breaker lost the queue terminate');
        self::assertTrue(StopLatch::isSet(), 'the queue terminate did not set the stop latch');
        self::assertSame(1, DB::table('jobs')->whereNull('reserved_at')->count());
    }

    /**
     * A dependency error of the package storage opens the breaker.
     */
    private function openBreaker(): Watcher
    {
        $watcher = $this->app->make(Watcher::class);
        $watcher->run('test:lost', static function (): never {
            throw new QueryException(MirrorSession::name(), 'select 1', [], new class extends PDOException
            {
                public function __construct()
                {
                    parent::__construct('SQLSTATE[08006]: connection failure');
                    $this->code = '08006';
                    $this->errorInfo = ['08006', 7, 'connection failure'];
                }
            });
        });
        self::assertTrue($watcher->breakerOpen());

        return $watcher;
    }
}
