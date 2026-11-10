<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Queue\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;

class ControlPlaneTest extends ControllerTest
{
    use WorkerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // The control plane applies only in worker processes.
        $this->actAsWorker();
    }

    public function test_global_pause_makes_pop_return_null_even_when_a_job_is_pending(): void
    {
        Queue::push(new BasicJob);
        $this->assertSame(1, DB::table('jobs')->count());

        app(SignalState::class)->pause('global', '');

        $popped = Queue::connection('database')->pop('default');

        $this->assertNull($popped, 'paused workers must skip reservation');
        $this->assertSame(1, DB::table('jobs')->count(), 'and leave the job pending in the queue');
    }

    /**
     * Rejects: an ignored queue pause (high runs), and a queue pause applied globally (default stays). A queue pause is Laravel's own: Laravel's worker skips the queue.
     */
    public function test_queue_scoped_pause_only_pauses_the_matching_queue(): void
    {
        Queue::push(new BasicJob, '', 'high');
        Queue::push(new BasicJob, '', 'default');

        app(SignalState::class)->pause('queue', 'database:high');
        $this->assertTrue(Queue::isPaused('database', 'high'));
        $this->assertFalse(Queue::isPaused('database', 'default'));

        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->setCache($this->app['cache']->driver());
        $worker->runNextJob('database', 'high,default', $this->workerOptions());

        $this->assertSame(['high'], DB::table('jobs')->pluck('queue')->all(), 'the worker did not take only the default job');
        $this->assertSame(1, DB::table('jobs')->whereNull('reserved_at')->where('queue', 'high')->count(), 'the job of high was reserved');
    }

    public function test_continue_lifts_the_pause(): void
    {
        Queue::push(new BasicJob);

        $signals = app(SignalState::class);
        $signals->pause('global', '');

        $this->assertNull(Queue::connection('database')->pop('default'));

        $signals->continue('global', '');

        $popped = Queue::connection('database')->pop('default');

        $this->assertNotNull($popped);
    }

    public function test_pause_state_persists_across_signalstate_instances(): void
    {
        (new SignalState)->pause('global', '');
        SignalState::flushCache();

        $this->assertTrue((new SignalState)->connectionPaused('database'));
    }

    public function test_tick_command_deletes_expired_terminate_signals(): void
    {
        $this->mirror()->table('orrery_signals')->insert([
            [
                'signal' => 'terminate',
                'scope' => 'global',
                'scope_value' => '',
                'issued_at' => microtime(true) - 7200,
                'expires_at' => Carbon::now()->subHours(1),
            ],
            [
                'signal' => 'pause',
                'scope' => 'global',
                'scope_value' => '',
                'issued_at' => microtime(true),
                'expires_at' => null,
            ],
        ]);

        $this->artisan(TickCommand::class)->assertSuccessful();

        $rows = $this->mirror()->table('orrery_signals')->orderBy('signal')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('pause', $rows[0]->signal);
    }

    public function test_api_post_pauses_globally(): void
    {
        $this->postJson('/orrery/api/control', ['action' => 'pause'])
            ->assertOk()
            ->assertJsonFragment(['action' => 'pause', 'scope' => 'global', 'ok' => true]);

        $this->assertSame(1, $this->mirror()->table('orrery_signals')->where('signal', 'pause')->count());
    }

    public function test_api_post_continue_lifts_pause(): void
    {
        app(SignalState::class)->pause('global', '');

        $this->postJson('/orrery/api/control', ['action' => 'continue'])
            ->assertOk();

        $this->assertSame(0, $this->mirror()->table('orrery_signals')->where('signal', 'pause')->count());
    }

    public function test_api_post_queue_scope_requires_connection_and_queue(): void
    {
        $this->postJson('/orrery/api/control', [
            'action' => 'pause',
            'scope' => 'queue',
            'connection' => 'database',
            // queue missing
        ])->assertStatus(422);
    }

    public function test_api_get_returns_active_signals(): void
    {
        app(SignalState::class)->pause('connection', 'database');

        $response = $this->getJson('/orrery/api/control')->assertOk();
        $data = $response->json();

        $this->assertCount(1, $data);
        $this->assertSame('pause', $data[0]['signal']);
        $this->assertSame('connection', $data[0]['scope']);
        $this->assertSame('database', $data[0]['scope_value']);
    }
}
