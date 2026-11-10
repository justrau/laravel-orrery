<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Control;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Control\StopLatch;
use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\TestCase;

/**
 * Pause: the global and connection rows stop a
 * worker, a queue pause is Laravel's own (QueuePauseTest), and the control
 * plane applies only in worker processes.
 */
final class PauseTest extends TestCase
{
    use WorkerFixtures;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->name() === 'test_swap_off_partial_pause_of_a_multi_queue_worker') {
            $app['config']->set('orrery.swap_connector', false);
        }
    }

    /**
     * Rejects: a Looping stop for a queue pause (Laravel's worker skips that queue itself), and a connection pause that does not stop a foreign queue's loop.
     */
    public function test_swap_off_partial_pause_of_a_multi_queue_worker(): void
    {
        self::assertNotInstanceOf(DatabaseQueue::class, Queue::connection('database'), 'the connector swap is on');
        $this->actAsWorker();
        $signals = new SignalState;

        $signals->pause('queue', 'database:alpha');
        $signals->pause('queue', 'database:beta');
        self::assertNull(Event::until(new Looping('database', 'alpha,beta')), 'a queue pause stopped the whole loop');

        $signals->pause('connection', 'database');
        self::assertFalse(Event::until(new Looping('database', 'alpha,beta')), 'the connection pause covers every subscribed queue');

        $signals->continue('connection', 'database');
        self::assertNull(Event::until(new Looping('database', 'alpha,beta')));

        // A process that is not a worker is never paused.
        $signals->pause('global', '');
        $this->actAs(ProcessContext::CLI);
        self::assertNull(Event::until(new Looping('database', 'alpha,beta')));
    }

    /**
     * Rejects: a paused pop() in a web request.
     */
    public function test_pop_outside_a_worker_ignores_the_control_plane(): void
    {
        $kills = 0;
        StopLatch::useKillerForTesting(static function () use (&$kills): void {
            $kills++;
        });
        $this->actAs(ProcessContext::WEB);
        $signals = new SignalState;
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        $signals->pause('global', '');
        $signals->terminate('global', '');

        self::assertNotNull(Queue::connection('database')->pop('default'), 'a web request was paused or terminated');
        self::assertFalse(StopLatch::isSet(), 'a web request set the stop latch');
        self::assertSame(0, $kills, 'a web request sent itself SIGTERM');
        self::assertNotNull(Queue::connection('database')->pop('default'));

        // The same pop() in a worker process is paused. The terminate row goes
        // first: a worker would stop this PHPUnit process.
        $this->mirror()->table('orrery_signals')->where('signal', 'terminate')->delete();
        SignalState::flushCache();
        $this->actAsWorker();
        Queue::push(new BasicJob);
        self::assertNull(Queue::connection('database')->pop('default'));
        self::assertSame(1, DB::table('jobs')->whereNull('reserved_at')->count());
    }

    /**
     * Rejects: a pause refresh that deletes the rows in their own committed statement before it writes the new pause, so another session sees no pause for a moment.
     */
    public function test_a_pause_refresh_never_commits_an_unpaused_state(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('The committed state needs a second session on the same database: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $signals = new SignalState;
        $signals->pause('global', '');
        $signals->pause('queue', 'database:default');
        self::assertSame(2, $this->committedPauses());

        // Each statement of the package session on orrery_signals: what does
        // another session (a polling worker) see now?
        $seen = [];
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$seen): void {
            if ($event->connectionName === self::mirrorSession() && str_contains($event->sql, 'orrery_signals') && ! str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                $seen[] = $this->committedPauses();
            }
        });

        // The idempotent refresh of the global pause; it keeps the narrower queue pause.
        $signals->pause('global', '');

        self::assertNotSame([], $seen, 'the refresh wrote nothing on the package session');
        self::assertNotContains(0, $seen, 'another session saw no pause during the refresh: '.json_encode($seen));
        self::assertNotContains(1, $seen, 'another session saw one of the two pauses gone during the refresh: '.json_encode($seen));
        self::assertSame(2, $this->committedPauses());
        self::assertTrue($signals->connectionPaused('database'));
    }

    /**
     * Rejects: a reader that still honours a continue row (none is written; an older row overrides nothing).
     */
    public function test_a_continue_row_lifts_no_pause(): void
    {
        $now = microtime(true);
        $row = static fn (string $signal, float $issuedAt): array => [
            'signal' => $signal, 'scope' => 'connection', 'scope_value' => 'database',
            'issued_at' => sprintf('%.6F', $issuedAt), 'expires_at' => null,
        ];

        $this->mirror()->table('orrery_signals')->insert([$row('pause', $now - 20), $row('continue', $now - 10)]);
        SignalState::flushCache();
        self::assertTrue((new SignalState)->connectionPaused('database'), 'a newer continue row lifted the pause');
    }

    /**
     * The pause rows that another session sees (the committed state).
     */
    private function committedPauses(): int
    {
        return $this->mirror()->table('orrery_signals')->where('signal', 'pause')->count();
    }

    /**
     * Rejects: a swap-on Looping listener that pauses the whole worker (pop() decides per queue).
     */
    public function test_swap_on_looping_never_pauses(): void
    {
        self::assertInstanceOf(DatabaseQueue::class, Queue::connection('database'));
        $this->actAsWorker();
        (new SignalState)->pause('global', '');

        self::assertNull(Event::until(new Looping('database', 'default')));
    }
}
