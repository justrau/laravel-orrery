<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Console\Terminal;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Tests\Feature\Retry\RetryTestCase;

/**
 * orrery:forget: a uuid goes through
 * ForgetService::forget() with the row's current occurrence, and --all
 * through ForgetAllRecords. Kind: new behaviour (the old command paged the
 * provider and adopted provider-only records through receipts).
 */
final class ForgetFailedCommandTest extends RetryTestCase
{
    /**
     * Rejects: a command that deletes the rows itself, or keeps the mirror
     * row: the forget is one `done` operation of the ledger with the actor
     * and source `cli`.
     */
    public function test_forget_removes_the_provider_record_and_the_mirror_row(): void
    {
        [$uuid, $occurrence] = $this->failedJob();

        $this->artisan('orrery:forget', ['id' => $uuid])->assertExitCode(0);

        self::assertNull($this->providerRecord($uuid));
        self::assertNull($this->row($uuid));
        $operation = $this->onlyOperation();
        self::assertSame([Operations::FORGET, Operations::DONE, $uuid, $occurrence, 'cli', 'cli'], [$operation->action, $operation->state, $operation->job_uuid, (int) $operation->occurrence_id, $operation->actor, $operation->source]);
    }

    /**
     * Rejects: a command that runs --all when a uuid is also given,
     * so a stray flag forgets every failed record without a
     * confirmation. Would still pass: a refusal whose text does not name the
     * two inputs (the test checks the exit code and the records only).
     */
    public function test_a_uuid_with_all_is_refused(): void
    {
        [$uuid] = $this->failedJob();
        [$other] = $this->failedJob();

        $this->artisan('orrery:forget', ['id' => $uuid, '--all' => true])->assertExitCode(1);

        self::assertNotNull($this->providerRecord($uuid));
        self::assertNotNull($this->providerRecord($other));
        self::assertSame(0, $this->mirror()->table('orrery_operations')->count(), 'nothing was forgotten');
    }

    /**
     * Rejects: deleting `failed_tags` by tag instead of uuid.
     */
    public function test_forgetting_one_job_keeps_other_jobs_failed_tags(): void
    {
        [$uuid] = $this->failedJob($this->job(['App\\Jobs\\Foo', 'shared']));
        [$other] = $this->failedJob($this->job(['App\\Jobs\\Foo', 'shared']));

        $this->artisan('orrery:forget', ['id' => $uuid])->assertExitCode(0);

        self::assertSame([], $this->failedTags($uuid));
        self::assertSame(['App\\Jobs\\Foo', 'shared'], $this->failedTags($other), 'the other job keeps its failed tags');
        self::assertSame('failed', $this->rowOrFail($other)->status);
    }

    /**
     * Rejects: an --all that loops over ids and forget(): a provider-only
     * record (no mirror row) is deleted by its exact id and uuid, counted
     * separately, and every mirrored record goes with its row.
     */
    public function test_forget_all_deletes_mirrored_and_provider_only_records(): void
    {
        $uuids = [];

        for ($i = 0; $i < 3; $i++) {
            [$uuids[]] = $this->failedJob();
        }

        // A record whose mirror row retention removed: provider-only.
        $this->trimMirrorRow($uuids[1]);
        self::assertSame(3, DB::table('failed_jobs')->count());

        $this->artisan('orrery:forget', ['--all' => true, '--force' => true])
            ->expectsOutputToContain('Forgot 2 mirrored failed job(s) and 1 failed job(s) without a mirror row.')
            ->assertExitCode(0);

        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, $this->mirrorCount());
    }

    /**
     * Rejects: a queue filter that forgets the records of other queues.
     */
    public function test_forget_all_with_a_queue_only_forgets_that_queue(): void
    {
        [$keep] = $this->failedJob();
        [$drop] = $this->failedJob();
        DB::table('failed_jobs')->where('uuid', $drop)->update(['queue' => 'reports']);
        $this->mirror()->table('orrery_jobs')->where('uuid', $drop)->update(['queue' => 'reports']);

        $this->artisan('orrery:forget', ['--all' => true, '--queue' => 'reports', '--force' => true])->assertExitCode(0);

        self::assertNotNull($this->providerRecord($keep));
        self::assertSame('failed', $this->rowOrFail($keep)->status);
        self::assertNull($this->providerRecord($drop));
        self::assertNull($this->row($drop));
    }

    /**
     * Rejects: a command that reports success for a forget that did not
     * happen: an unbound occurrence is refused with its code and message
     * (exit 1), a skipped record of --all exits 1 with its reason, and an
     * unknown uuid exits 1.
     */
    public function test_a_result_that_is_not_deleted_is_printed(): void
    {
        [$uuid] = $this->failedJob();
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['provider_id' => null]);

        $this->artisan('orrery:forget', ['id' => $uuid])
            ->expectsOutputToContain('refused (occurrence_unprovable)')
            ->assertExitCode(1);

        $this->artisan('orrery:forget', ['--all' => true, '--force' => true])
            ->expectsOutputToContain('Skipped 1 failed job(s): occurrence_unprovable 1.')
            ->assertExitCode(1);

        $this->artisan('orrery:forget', ['id' => '00000000-0000-4000-8000-000000000000'])->assertExitCode(1);
        self::assertNotNull($this->providerRecord($uuid));
    }

    /**
     * Rejects: an `orrery:forget --all` that deletes at once: it prints the number of records and
     * the scope, asks, and a declined confirmation, or a run without a terminal and without --force, deletes
     * nothing (exit 1). Would still pass: a count that is wrong in a scope this test does not use.
     */
    public function test_forget_all_needs_a_confirmation_or_force(): void
    {
        [$first] = $this->failedJob();
        [$second] = $this->failedJob();
        $this->stdinIsTerminal(true);

        $this->artisan('orrery:forget', ['--all' => true, '--no-interaction' => true])
            ->expectsOutputToContain('pass --force')
            ->assertExitCode(1);

        $this->artisan('orrery:forget', ['--all' => true, '--queue' => 'default'])
            ->expectsOutputToContain('Failed records in the scope: at most 2.')
            ->expectsOutputToContain('Scope: every connection, queue default.')
            ->expectsConfirmation('Forget these failed records for good?', 'no')
            ->assertExitCode(1);

        self::assertNotNull($this->providerRecord($first));
        self::assertSame(['failed', 'failed'], [$this->rowOrFail($first)->status, $this->rowOrFail($second)->status]);

        $this->artisan('orrery:forget', ['--all' => true])
            ->expectsConfirmation('Forget these failed records for good?', 'yes')
            ->assertExitCode(0);

        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    /**
     * Rejects: a scope option given with an empty value that reads as absent: `--queue=` with an
     * unset shell variable would forget every queue. It is refused with exit 1 before any work.
     */
    public function test_an_empty_scope_option_is_refused(): void
    {
        [$uuid] = $this->failedJob();

        foreach (['--queue', '--connection'] as $option) {
            $this->artisan('orrery:forget', ['--all' => true, $option => '', '--force' => true])
                ->expectsOutputToContain("{$option} is empty")
                ->assertExitCode(1);
        }

        // An empty uuid beside --all (`orrery:forget "$ID" --all` with ID unset) is refused too.
        $this->artisan('orrery:forget', ['id' => '', '--all' => true, '--force' => true])
            ->expectsOutputToContain('Give either a uuid or --all, not both.')
            ->assertExitCode(1);

        self::assertNotNull($this->providerRecord($uuid));
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
    }

    /**
     * Rejects: a confirmation that a piped stdin can answer (`yes | php artisan orrery:forget --all`): without a
     * terminal on stdin and without --force, nothing is forgotten (exit 1), although the input is interactive.
     * Would still pass: a rule that reads another stream than STDIN (the decision function is tested below).
     */
    public function test_forget_all_refuses_a_piped_stdin_without_force(): void
    {
        [$uuid] = $this->failedJob();
        $this->stdinIsTerminal(false);

        $this->artisan('orrery:forget', ['--all' => true])
            ->expectsOutputToContain('Nothing forgotten: confirm on a terminal, or pass --force.')
            ->assertExitCode(1);

        self::assertNotNull($this->providerRecord($uuid));
    }

    /**
     * Rejects: a terminal check that takes a pipe or a memory stream for a terminal. Would still pass: a check
     * that takes a closed stream for no terminal (also the right answer).
     */
    public function test_the_terminal_check_refuses_a_pipe(): void
    {
        $pipe = popen('echo yes', 'r');
        self::assertIsResource($pipe);

        try {
            self::assertFalse(Terminal::isTerminal($pipe));
            self::assertFalse(Terminal::isTerminal(fopen('php://memory', 'r')));
            self::assertFalse(Terminal::isTerminal(null));
        } finally {
            pclose($pipe);
        }
    }

    /**
     * Rejects: a preview that does not bind the run: a record that fails after the
     * confirmation is past the id bound that the preview took, so the run keeps it and the shown "at most"
     * count holds. Would still pass: a bound that the run ignores when the failer changed (refused instead).
     */
    public function test_the_preview_binds_the_run(): void
    {
        [$shown] = $this->failedJob();
        $all = $this->app->make(ForgetAllRecords::class);
        $preview = $all->preview();
        self::assertSame(1, $preview['count']);

        [$later] = $this->failedJob();
        $report = $all->run(null, null, $preview['bound']);

        self::assertSame([1, 0], [$report['deleted'], $report['provider_only']]);
        self::assertNull($this->providerRecord($shown));
        self::assertNotNull($this->providerRecord($later), 'the run reached a record that failed after the preview');
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
}
