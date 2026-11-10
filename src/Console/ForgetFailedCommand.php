<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Storage\PackageSession;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * orrery:forget: every forget goes through
 * the operation ledger, never a direct delete.
 *
 * - `orrery:forget <uuid>` forgets the current occurrence of the job's
 *   mirror row (ForgetService::forget(), source `cli`, actor `cli`).
 * - `orrery:forget --all [--connection=] [--queue=]` forgets every record of
 *   the scope (ForgetAllRecords): an exact SQL walk bounded by the largest
 *   id at its start. `--queue` alone matches that queue name on every
 *   connection; `--connection` narrows it. The output gives the deleted,
 *   provider-only and skipped counts, the last by reason. It first prints
 *   the number of records (at most; the run stops at the id bound that the
 *   count read) and the scope and asks for a confirmation (`--force` skips
 *   it); without `--force` it forgets nothing when the input is not
 *   interactive or STDIN is not a terminal (a pipe could answer). A scope option given with an empty value is refused,
 *   never read as "every connection" or "every queue".
 *
 * The command exits 1 when the requested work did not happen: a result that
 * is not `deleted`, a refusal, a skipped record of --all, an empty scope
 * option or uuid beside --all, or a declined or missing confirmation. A uuid together
 * with --all is refused (exit 1) before any work.
 */
#[AsCommand(name: 'orrery:forget')]
class ForgetFailedCommand extends Command
{
    public const ACTOR = 'cli';

    /** @var string */
    protected $signature = 'orrery:forget
                            {id? : The uuid of the failed job}
                            {--all : Forget every failed record (optionally scoped by --connection and --queue)}
                            {--connection= : When used with --all, only the records of this queue connection}
                            {--queue= : When used with --all, only the records of this queue}
                            {--force : When used with --all, forget without asking (needed when STDIN is not a terminal)}';

    /** @var string */
    protected $description = 'Forget a failed queue job in the failed-job table and the Orrery mirror, through the operation ledger.';

    public function handle(ForgetService $forget, ForgetAllRecords $all, PackageSession $sessions): int
    {
        $given = $this->argument('id');
        $uuid = strtolower(trim((string) ($given ?? '')));

        if ($this->option('all')) {
            if ($given !== null) {
                // Also an empty uuid: `orrery:forget "$ID" --all` with ID unset.
                // --all deletes every record of its scope: a uuid beside it is a mistake, never ignored.
                $this->components->error('Give either a uuid or --all, not both.');

                return self::FAILURE;
            }

            return $this->forgetAll($all);
        }

        if ($uuid === '') {
            $this->components->error('No failed job uuid provided.');

            return self::FAILURE;
        }

        $occurrence = $sessions->mirror()->table(JobStore::TABLE)->where('uuid', $uuid)->value('occurrence_id');

        if (! is_numeric($occurrence)) {
            $this->components->error('No failed job matches the given uuid.');

            return self::FAILURE;
        }

        $result = $forget->forget($uuid, (int) $occurrence, self::ACTOR, 'cli');

        if ($result->result === ForgetResult::DELETED) {
            $this->components->info('Failed job deleted successfully!');

            return self::SUCCESS;
        }

        $this->components->warn("{$uuid}: {$result->result}".($result->code === null ? '' : " ({$result->code})").($result->message === null ? '' : ": {$result->message}"));

        return self::FAILURE;
    }

    private function forgetAll(ForgetAllRecords $all): int
    {
        $scope = [];

        foreach (['connection', 'queue'] as $name) {
            $value = $this->option($name);

            if ($value === '') {
                // An unset shell variable would widen the scope to every name.
                $this->components->error("--{$name} is empty: give a name, or leave the option out.");

                return self::FAILURE;
            }

            $scope[$name] = is_string($value) ? $value : null;
        }

        $preview = $all->preview($scope['connection'], $scope['queue']);

        if ($preview['result'] === ForgetAllRecords::REFUSED) {
            $this->components->error('Refused ('.$preview['code'].')'.($preview['message'] === null ? '.' : ': '.$preview['message']));

            return self::FAILURE;
        }

        $this->line("Failed records in the scope: at most {$preview['count']}.");
        $this->line('Scope: '.($scope['connection'] === null ? 'every connection' : 'connection '.$scope['connection']).', '.($scope['queue'] === null ? 'every queue' : 'queue '.$scope['queue']).'.');

        if (! $this->option('force')) {
            if (! $this->input->isInteractive() || ! $this->laravel->make(Terminal::class)->stdinIsTerminal()) {
                $this->components->error('Nothing forgotten: confirm on a terminal, or pass --force.');

                return self::FAILURE;
            }

            if (! $this->confirm('Forget these failed records for good?')) {
                $this->components->warn('Nothing forgotten.');

                return self::FAILURE;
            }
        }

        // The run stops at the bound that the shown count read.
        $report = $all->run($scope['connection'], $scope['queue'], $preview['bound']);

        if ($report['result'] === ForgetAllRecords::REFUSED) {
            $this->components->error('Refused ('.$report['code'].')'.($report['message'] === null ? '.' : ': '.$report['message']));

            return self::FAILURE;
        }

        $skipped = array_sum($report['skipped']);
        $this->components->info("Forgot {$report['deleted']} mirrored failed job(s) and {$report['provider_only']} failed job(s) without a mirror row.");

        if ($skipped > 0) {
            ksort($report['skipped']);
            $reasons = implode(', ', array_map(static fn (string $reason, int $count): string => "{$reason} {$count}", array_keys($report['skipped']), $report['skipped']));
            $this->components->warn("Skipped {$skipped} failed job(s): {$reasons}.");
        }

        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }
}
