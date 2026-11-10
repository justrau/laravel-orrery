<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * The result of a continue: the command and
 * POST /api/control tell the lifted pause apart from the causes that still
 * pause the target. A continue lifts the pause of exactly its scope and
 * never a broader one. The first test is the rewrite of the probe
 * OperatorViewProbeTest::test_continue_under_global_pause_reports_no_match_but_resumes.
 */
final class ContinueCommandTest extends ControllerTest
{
    /**
     * Rejects: a message from the deleted-row count alone ("nothing changed" with no word about the global pause that still holds the queue).
     */
    public function test_continue_under_a_global_pause_reports_the_global_pause(): void
    {
        $signals = new SignalState;
        $signals->pause('global', '', 'cli');

        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database', '--queue' => 'emails']));
        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

        self::assertStringContainsString('No pause of queue "database:emails" was set; nothing changed. Queue "database:emails" is still paused by Orrery\'s global pause (orrery:continue).', $output);

        SignalState::flushCache();
        self::assertTrue($signals->connectionPaused('database'), 'the queue continue lifted the global pause');
    }

    /**
     * Rejects: a queue pause or continue that reports only a failure when its package record fails after
     * Laravel's part happened: the operator read "failed" while the queue was paused. The command
     * still exits 1, and its message says that Laravel's pause is set (or lifted).
     * Still passes: a message that names the state without the scope value.
     */
    #[FreshSchema]
    public function test_a_failed_record_after_the_native_change_says_what_happened(): void
    {
        $this->mirrorOutage();

        self::assertSame(1, Artisan::call('orrery:pause', ['--connection' => 'database', '--queue' => 'emails']));
        self::assertStringContainsString("Laravel's queue pause of database:emails is set, so the queue is paused", (string) preg_replace('/\s+/', ' ', Artisan::output()));
        self::assertTrue(Queue::isPaused('database', 'emails'));

        self::assertSame(1, Artisan::call('orrery:continue', ['--connection' => 'database', '--queue' => 'emails']));
        self::assertStringContainsString("Laravel's queue pause of database:emails was lifted", (string) preg_replace('/\s+/', ' ', Artisan::output()));
        self::assertFalse(Queue::isPaused('database', 'emails'));
    }

    /**
     * Rejects: one message for every case (a lift, and a continue that changes nothing, read alike), and a connection continue that also lifts its queue pauses.
     */
    public function test_continue_reports_a_lift_and_a_no_op(): void
    {
        $signals = new SignalState;
        $signals->pause('connection', 'database', 'cli');
        $signals->pause('queue', 'database:emails', 'cli');

        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database']));
        self::assertStringContainsString('Lifted the pause of connection "database".', (string) preg_replace('/\s+/', ' ', Artisan::output()));

        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database']));
        self::assertStringContainsString('No pause of connection "database" was set; nothing changed.', (string) preg_replace('/\s+/', ' ', Artisan::output()));
        self::assertSame(['queue'], $this->mirror()->table('orrery_signals')->pluck('scope')->all(), 'the connection continue removed the narrower queue pause');
        self::assertTrue(Queue::isPaused('database', 'emails'));
    }

    /**
     * Rejects: a list that names only the most specific broader cause.
     */
    public function test_a_queue_continue_lists_every_broader_cause(): void
    {
        $signals = new SignalState;
        $signals->pause('global', '', 'cli');
        $signals->pause('connection', 'database', 'cli');
        $signals->pause('queue', 'database:emails', 'cli');

        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database', '--queue' => 'emails']));
        self::assertStringContainsString(
            'Lifted the pause of queue "database:emails". Queue "database:emails" is still paused by Orrery\'s global pause (orrery:continue) and Orrery\'s pause of connection "database" (orrery:continue --connection=database).',
            (string) preg_replace('/\s+/', ' ', Artisan::output()),
        );
    }

    /**
     * Rejects: an API response without the result of the continue.
     */
    public function test_the_api_returns_the_lift_and_the_causes(): void
    {
        $signals = new SignalState;
        $signals->pause('connection', 'database', 'cli');
        $signals->pause('queue', 'database:emails', 'cli');

        $this->postJson('/orrery/api/control', ['action' => 'continue', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'emails'])
            ->assertOk()
            ->assertExactJson([
                'action' => 'continue',
                'scope' => 'queue',
                'value' => 'database:emails',
                'ok' => true,
                'removed' => 1,
                'still_paused_by' => [['scope' => 'connection', 'value' => 'database', 'source' => 'orrery']],
                'still_paused_unknown' => false,
                'lift_unknown' => false,
            ]);

        // A global and a connection continue read Laravel's global pause too: known, not unknown.
        $this->postJson('/orrery/api/control', ['action' => 'continue'])
            ->assertOk()
            ->assertJson(['removed' => 0, 'still_paused_by' => [], 'still_paused_unknown' => false]);
        $this->postJson('/orrery/api/control', ['action' => 'continue', 'scope' => 'connection', 'connection' => 'database'])
            ->assertOk()
            ->assertJson(['removed' => 1, 'still_paused_by' => [], 'still_paused_unknown' => false]);
        self::assertSame(0, $this->mirror()->table('orrery_signals')->count());
    }

    /**
     * Rejects: "nothing changed" for a queue continue under Laravel's global pause, although resume() deleted the queue's own flag, which the global pause hid.
     */
    public function test_a_masked_own_pause_is_not_reported_as_nothing_changed(): void
    {
        if (! method_exists(QueueManager::class, 'pauseAll')) {
            self::markTestSkipped('This Laravel has no global queue pause (QueueManager::pauseAll(), Laravel 13 only).');
        }

        Queue::pause('database', 'emails');
        Queue::pauseAll();

        self::assertSame(0, Artisan::call('orrery:continue', ['--connection' => 'database', '--queue' => 'emails']));
        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());
        self::assertStringContainsString('Resumed Laravel\'s pause of queue "database:emails", if it was set (its state is hidden by Laravel\'s global pause).', $output);
        self::assertStringNotContainsString('nothing changed', $output);
        self::assertStringContainsString('is still paused by Laravel\'s global pause (queue:continue --all).', $output);

        Queue::resumeAll();
        self::assertFalse(Queue::isPaused('database', 'emails'), 'the own flag was not resumed');

        Queue::pause('database', 'emails');
        Queue::pauseAll();
        $this->postJson('/orrery/api/control', ['action' => 'continue', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'emails'])
            ->assertOk()
            ->assertJson(['removed' => 0, 'lift_unknown' => true]);

        // Without the global pause the own flag is known: a definite lift, no lift_unknown.
        Queue::resumeAll();
        Queue::pause('database', 'emails');
        $this->postJson('/orrery/api/control', ['action' => 'continue', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'emails'])
            ->assertOk()
            ->assertJson(['removed' => 1, 'lift_unknown' => false]);
    }
}
