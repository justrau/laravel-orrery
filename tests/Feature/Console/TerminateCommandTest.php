<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Tests\TestCase;

/**
 * The message of `orrery:terminate`. A worker
 * that started before the signal sets its stop latch and `shouldQuit`, and
 * ends after its current job (Control\StopLatch). It sends itself SIGTERM
 * only when no Worker instance is bound, so the message does not promise a
 * SIGTERM.
 */
final class TerminateCommandTest extends TestCase
{
    /**
     * Rejects: the old text "Workers will SIGTERM themselves on next poll".
     */
    public function test_the_message_says_that_workers_exit_after_their_current_job(): void
    {
        $this->assertSame(0, Artisan::call('orrery:terminate'));

        // The console component wraps long lines: compare the words.
        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());
        $this->assertStringContainsString('Termination signal issued for all workers (global).', $output);
        $this->assertStringContainsString('finishes its current job and then exits', $output);
        $this->assertStringNotContainsString('SIGTERM', $output);
    }

    public function test_the_message_names_the_scope_of_a_queue_terminate(): void
    {
        $this->artisan('orrery:terminate', ['--connection' => 'database', '--queue' => 'emails'])
            ->expectsOutputToContain('Termination signal issued for queue "database:emails".')
            ->assertExitCode(0);
    }
}
