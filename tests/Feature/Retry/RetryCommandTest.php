<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Illuminate\Console\Signals;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Console\RetryCommand;
use Justrau\Orrery\Console\Terminal;
use Justrau\Orrery\Http\Bulk\BulkRun;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * orrery:retry: one uuid's current occurrence, or a bulk run
 * of a scope with its confirmation and exit codes.
 */
final class RetryCommandTest extends RetryTestCase
{
    /**
     * Rejects: a uuid retry outside the ledger or with another source, an
     * unknown uuid that exits 0, and a refusal that exits 0.
     */
    public function test_one_uuid_is_retried_with_the_cli_source(): void
    {
        [$uuid] = $this->failedJob();

        self::assertSame(0, Artisan::call('orrery:retry', ['uuid' => strtoupper($uuid)]), Artisan::output());
        self::assertSame(['pending', 'cli'], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->cycle_source]);
        self::assertSame('cli', $this->mirror()->table('orrery_operations')->where('job_uuid', $uuid)->value('actor'));

        self::assertSame(RetryCommand::REFUSED, Artisan::call('orrery:retry', ['uuid' => $uuid]), 'a pending row is not eligible');
        self::assertSame(1, Artisan::call('orrery:retry', ['uuid' => '00000000-0000-4000-8000-00000000dead']));
        self::assertSame(1, Artisan::call('orrery:retry'));
    }

    /**
     * Rejects: a bulk run that starts without a confirmation when no
     * terminal can give one, and a declined confirmation that acts.
     */
    public function test_a_run_needs_a_confirmation_or_force(): void
    {
        [$uuid] = $this->failedJob();
        $this->stdinIsTerminal(true);

        self::assertSame(1, Artisan::call('orrery:retry', ['--all' => true, '--connection' => 'database', '--no-interaction' => true]));
        self::assertStringContainsString('pass --force', Artisan::output());

        $this->artisan('orrery:retry', ['--all' => true, '--connection' => 'database'])
            ->expectsConfirmation('Retry these failed jobs?', 'no')
            ->assertExitCode(1);

        self::assertSame('failed', $this->rowOrFail($uuid)->status);
        self::assertSame(0, $this->mirror()->table('orrery_bulk_runs')->count(), 'nothing ran');

        $this->artisan('orrery:retry', ['--all' => true, '--connection' => 'database'])
            ->expectsConfirmation('Retry these failed jobs?', 'yes')
            ->assertExitCode(0);
        self::assertSame('pending', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a scope option given with an empty value that reads as absent: `--batch=` or
     * `--key=` with an unset shell variable would retry every failed job. Each is refused with exit 1 before
     * any run.
     */
    public function test_an_empty_scope_option_is_refused(): void
    {
        [$uuid] = $this->failedJob();

        foreach ([['--batch' => ''], ['--key' => ['']], ['--queue' => '', '--connection' => 'database'], ['--class' => '']] as $options) {
            self::assertSame(1, Artisan::call('orrery:retry', ['--all' => true, '--force' => true] + $options), json_encode($options));
            self::assertStringContainsString('is empty', Artisan::output());
        }

        self::assertSame(1, Artisan::call('orrery:retry', ['uuid' => '', '--all' => true, '--force' => true]), 'an empty uuid beside --all');
        self::assertStringContainsString('Give either a uuid or --all, not both.', Artisan::output());

        self::assertSame('failed', $this->rowOrFail($uuid)->status);
        self::assertSame(0, $this->mirror()->table('orrery_bulk_runs')->count(), 'nothing ran');
    }

    /**
     * Rejects: a confirmation that a piped stdin can answer (`yes | php artisan orrery:retry --all`): without a
     * terminal on stdin and without --force, nothing runs (exit 1), although the input is interactive.
     */
    public function test_a_run_refuses_a_piped_stdin_without_force(): void
    {
        [$uuid] = $this->failedJob();
        $this->stdinIsTerminal(false);

        self::assertSame(1, Artisan::call('orrery:retry', ['--all' => true, '--connection' => 'database']));
        self::assertStringContainsString('Nothing retried: confirm on a terminal, or pass --force.', Artisan::output());
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
        self::assertSame(0, $this->mirror()->table('orrery_bulk_runs')->count(), 'nothing ran');
    }

    /**
     * Binds the terminal check of the confirmation (STDIN of the test process may be a terminal or not).
     */
    private function stdinIsTerminal(bool $terminal): void
    {
        $this->app->instance(Terminal::class, new class($terminal) extends Terminal
        {
            public function __construct(private readonly bool $terminal) {}

            public function stdinIsTerminal(): bool
            {
                return $this->terminal;
            }
        });
    }

    /**
     * Rejects: a forced run that leaves members, that names another source
     * or no run on its operations, or exits non-zero when every member was
     * accepted.
     */
    public function test_a_forced_run_retries_every_member_in_one_run(): void
    {
        [$a] = $this->failedJob();
        [$b] = $this->failedJob();

        $exit = Artisan::call('orrery:retry', ['--all' => true, '--queue' => 'default', '--connection' => 'database', '--force' => true]);
        // Artisan::output() empties the buffer: it is read once.
        $output = Artisan::output();
        self::assertSame(0, $exit, $output);

        self::assertStringContainsString('accepted', $output);
        self::assertSame(['pending', 'pending'], [$this->rowOrFail($a)->status, $this->rowOrFail($b)->status]);
        $ref = $this->mirror()->table('orrery_bulk_runs')->value('ref');
        self::assertIsString($ref);
        self::assertStringContainsString($ref, $output);
        self::assertSame(['cli'], $this->mirror()->table('orrery_operations')->where('bulk_ref', $ref)->distinct()->pluck('source')->all());
        self::assertSame(2, (int) $this->mirror()->table('orrery_bulk_runs')->where('ref', $ref)->value('claimed'));
        self::assertSame('cli', $this->mirror()->table('orrery_bulk_runs')->where('ref', $ref)->value('actor'));
    }

    /**
     * Rejects: exit codes without their precedence: a refused member gives
     * 2, a held member 3 (also beside a refused one), a refused freeze 1.
     */
    public function test_the_exit_codes_follow_the_outcomes(): void
    {
        [$held] = $this->failedJob();
        [$refused] = $this->failedJob();
        $this->mirror()->table('orrery_jobs')->where('uuid', $held)->update(['status' => 'retrying', 'operation_id' => str_repeat('f', 32)]);
        $this->app->instance('queue.failer', new FileFailedJobProvider(sys_get_temp_dir().'/orrery-retry-command-no-failures'));

        $exit = Artisan::call('orrery:retry', ['--all' => true, '--connection' => 'database', '--force' => true]);
        $output = Artisan::output();
        self::assertSame(RetryCommand::UNCERTAIN, $exit, $output);
        self::assertStringContainsString('provider_read_only', $output);

        $this->mirror()->table('orrery_jobs')->where('uuid', $held)->update(['status' => 'failed', 'operation_id' => null]);
        self::assertSame(RetryCommand::REFUSED, Artisan::call('orrery:retry', ['--all' => true, '--connection' => 'database', '--force' => true]));
        self::assertSame('failed', $this->rowOrFail($refused)->status);

        // --key without a window: the freeze refuses.
        self::assertSame(1, Artisan::call('orrery:retry', ['--all' => true, '--key' => [str_repeat('a', 64)], '--force' => true]));
        self::assertStringContainsString('invalid', Artisan::output());
    }

    /**
     * Rejects: a SIGINT that kills the run in the middle of a member or is
     * ignored (the run would go on): the run stops after the current member
     * and exits 130.
     */
    public function test_sigint_stops_after_the_current_member(): void
    {
        if (! extension_loaded('pcntl') || ! function_exists('posix_kill')) {
            self::markTestSkipped('SIGINT needs the pcntl and posix extensions.');
        }

        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $this->onHook(static function (string $point): void {
            if ($point === BulkRun::BETWEEN_MEMBERS) {
                posix_kill(getmypid(), SIGINT);
            }
        });
        // Laravel turns signal traps off while unit tests run; this test needs the real trap.
        Signals::resolveAvailabilityUsing(static fn (): bool => true);

        try {
            $exit = Artisan::call('orrery:retry', ['--all' => true, '--connection' => 'database', '--force' => true]);
        } finally {
            Signals::resolveAvailabilityUsing(static fn (): bool => false);
        }

        self::assertSame(RetryCommand::INTERRUPTED, $exit, Artisan::output());

        // The second member had started when the signal arrived; nothing after it ran.
        $statuses = [$this->rowOrFail($a)->status, $this->rowOrFail($b)->status];
        sort($statuses);
        self::assertSame(['failed', 'pending'], $statuses);
        self::assertSame(1, DB::table('jobs')->count());
    }

    /**
     * The command gives trap() its signals as a closure, which Laravel
     * resolves only where signals are available. A bare SIGINT
     * is evaluated before trap() runs, and PHP without ext-pcntl has no such
     * constant, so the run would die after its freeze. This PHP has pcntl,
     * so the test checks the argument that trap() gets.
     *
     * Rejects: `trap(SIGINT, …)` or any other signal value that PHP
     * evaluates before trap() runs. Would still pass: a closure that itself
     * names SIGINT where it is undefined (Laravel calls it only where
     * signals are available).
     */
    public function test_the_signals_reach_trap_unevaluated(): void
    {
        $this->failedJob();
        $command = new class extends RetryCommand
        {
            /** @var list<mixed> */
            public array $trapped = [];

            public function trap($signals, $callback)
            {
                $this->trapped[] = $signals;

                parent::trap($signals, $callback);
            }
        };
        $this->app->instance(RetryCommand::class, $command);

        self::assertSame(0, Artisan::call('orrery:retry', ['--all' => true, '--connection' => 'database', '--force' => true]), Artisan::output());
        self::assertCount(1, $command->trapped);
        self::assertInstanceOf(\Closure::class, $command->trapped[0]);
        self::assertSame([SIGINT], ($command->trapped[0])());
    }

    /**
     * Rejects: scope options that the chosen kind does not use and that
     * the command drops in silence (review medium): --batch with a queue
     * filter would retry the whole batch, --key with --class every class of
     * the group. Each such option exits 1 before any freeze.
     *
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('unusedScopeOptions')]
    public function test_scope_options_that_the_kind_does_not_use_are_refused(array $options, string $option): void
    {
        [$uuid] = $this->failedJob();

        $exit = Artisan::call('orrery:retry', ['--all' => true, '--force' => true] + $options);
        $output = Artisan::output();

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('--'.$option, $output);
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
        self::assertSame(0, $this->mirror()->table('orrery_bulk_runs')->count());
        self::assertSame(0, $this->mirror()->table('orrery_operations')->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unusedScopeOptions(): array
    {
        $key = ['--key' => [str_repeat('a', 64)], '--from' => '1700000000', '--to' => '1700003600'];

        return [
            'batch with a connection and a queue' => [['--batch' => 'b', '--connection' => 'database', '--queue' => 'other'], 'connection'],
            'batch with a class' => [['--batch' => 'b', '--class' => 'App\\Jobs\\X'], 'class'],
            'batch with a tag' => [['--batch' => 'b', '--tag' => 't'], 'tag'],
            'batch with a window' => [['--batch' => 'b', '--from' => '1700000000'], 'from'],
            'key with a class' => [$key + ['--class' => 'App\\Jobs\\X'], 'class'],
            'key with a tag' => [$key + ['--tag' => 't'], 'tag'],
            'key with a batch' => [$key + ['--batch' => 'b'], 'batch'],
        ];
    }

    /**
     * Rejects: a held row that one uuid's retry reports as refused (exit 2)
     * while --all reports the same row as uncertain (exit 3).
     */
    public function test_one_uuid_on_a_held_row_exits_uncertain(): void
    {
        [$uuid] = $this->failedJob();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'retrying', 'operation_id' => str_repeat('f', 32)]);

        self::assertSame(RetryCommand::UNCERTAIN, Artisan::call('orrery:retry', ['uuid' => $uuid]), Artisan::output());
    }

    /**
     * Rejects: a CLI run that a tiny candidate budget stalls or ends early
     * (exit 0 with members left), and one that the redacted preview blocks
     * (an invalid redaction configuration must not stop the command). On an
     * 8-range list source (a queue: 4 retention ranges per status).
     */
    #[DataProvider('tinyBudgets')]
    public function test_a_tiny_budget_still_retries_every_member(int $budget): void
    {
        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $this->app['config']->set('orrery.dashboard.search_candidates', $budget);
        $this->app['config']->set('orrery.redact.patterns', ['not a pattern']);

        $exit = Artisan::call('orrery:retry', ['--all' => true, '--connection' => 'database', '--queue' => 'default', '--force' => true]);

        self::assertSame(0, $exit, Artisan::output());
        self::assertSame(['pending', 'pending'], [$this->rowOrFail($a)->status, $this->rowOrFail($b)->status]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function tinyBudgets(): array
    {
        return ['1 candidate' => [1], '8 candidates' => [8]];
    }

    /**
     * Rejects: a command that loops on, or exits 0 after, a page that is not
     * done and gives no new cursor (a stalled run).
     */
    public function test_a_page_without_progress_is_a_stall(): void
    {
        self::assertTrue(RetryCommand::stalled(['done' => false, 'next_after' => null], null));
        self::assertTrue(RetryCommand::stalled(['done' => false, 'next_after' => 'abc'], 'abc'));
        self::assertFalse(RetryCommand::stalled(['done' => false, 'next_after' => 'abd'], 'abc'));
        self::assertFalse(RetryCommand::stalled(['done' => true, 'next_after' => null], 'abc'));
    }
}
