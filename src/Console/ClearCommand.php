<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Justrau\Orrery\Actions\ActionRefused;
use Justrau\Orrery\Actions\ClearResult;
use Justrau\Orrery\Actions\OperatorAudit;
use Justrau\Orrery\Actions\WaitingJobs;
use Justrau\Orrery\Storage\PackageSession;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * orrery:clear: deletes only the waiting jobs of a database queue, in
 * batches, with their mirror rows on every alias of the connection. The
 * work is Actions\WaitingJobs::clear() (its docblock holds the rules), with
 * no time budget; this command only adapts the input and the output, and
 * records the action (Actions\OperatorAudit, actor `cli`).
 *
 * Exit 1: an empty argument, a connection that is not a database queue, a
 * failed mirror delete (reconciliation marks those rows unknown later; the
 * operator asked for the mirror rows too), or a failed probe (that batch
 * and the later ones stay queued). An error of the queue connection is not
 * caught, so it also exits 1. Undecodable payloads and count mismatches are
 * warnings (exit 0).
 */
#[AsCommand(name: 'orrery:clear')]
class ClearCommand extends Command
{
    use ConfirmableTrait;

    /** The attempts of one batch transaction when SQLite answers SQLITE_BUSY (WaitingJobs::BUSY_ATTEMPTS). */
    public const BUSY_ATTEMPTS = WaitingJobs::BUSY_ATTEMPTS;

    /** @var string */
    protected $signature = 'orrery:clear
                            {connection? : The queue connection to clear (defaults to queue.default)}
                            {--queue= : The queue name (defaults to the connection\'s configured queue)}
                            {--force : Force the operation to run when in production}';

    /** @var string */
    protected $description = 'Delete the waiting (unreserved) jobs of a database queue and their mirror rows. Unlike queue:clear, reserved jobs stay.';

    public function handle(WaitingJobs $jobs, OperatorAudit $audit): int
    {
        // An operator action (D17): its writes may reach a host SQLite file.
        return PackageSession::asOperator(fn (): int => $this->handleInAction($jobs, $audit));
    }

    /**
     * @param  int|null  $size  the batch size; null restores WaitingJobs::BATCH
     */
    public static function useBatchForTesting(?int $size): void
    {
        WaitingJobs::useBatchForTesting($size);
    }

    private function handleInAction(WaitingJobs $jobs, OperatorAudit $audit): int
    {
        foreach (['connection' => $this->argument('connection'), '--queue' => $this->option('queue')] as $name => $value) {
            if ($value === '') {
                // An empty value is refused, never read as the default.
                $this->components->error("{$name} is empty: give a name, or leave it out.");

                return self::FAILURE;
            }
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $config = $this->laravel->make('config');
        $connection = (string) ($this->argument('connection') ?: $config->get('queue.default'));
        $queueName = (string) ($this->option('queue') ?: $config->get("queue.connections.{$connection}.queue", 'default'));

        try {
            $result = $jobs->clear($connection, $queueName);
        } catch (ActionRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $audit->record('clear', ['connection' => $connection, 'queue' => $queueName], 'cli', $result->complete ? 'done' : (string) $result->reason, $result->toArray());

        return $this->report($result, $connection, $queueName);
    }

    private function report(ClearResult $result, string $connection, string $queueName): int
    {
        $this->components->info(sprintf(
            'Cleared %d waiting job(s) and %d mirror row(s), %d of them unresolved bulk rows, for [%s][%s].',
            $result->deleted,
            $result->mirrorRows,
            $result->unresolvedRows,
            $connection,
            $queueName,
        ));
        $this->components->info('Reserved jobs were kept: unlike queue:clear, orrery:clear deletes only jobs that no worker has reserved.');

        if ($result->countMismatches > 0) {
            $this->components->warn(sprintf('In %d batch(es), the queue deleted another number of jobs than it locked. Only the jobs that are gone were counted, and only their mirror rows were deleted.', $result->countMismatches));
        }

        if ($result->undecodable > 0) {
            $this->components->warn(sprintf('%d payload(s) could not be decoded, so their unresolved bulk mirror rows, if any, were kept.', $result->undecodable));
        }

        if ($result->mirrorFailed) {
            // The operator asked for the mirror rows too, so a failed mirror delete is a failed
            // command, although the jobs are gone: a script must not see success.
            $this->components->error('Some mirror rows could not be deleted. Reconciliation marks them unknown later.');
        }

        $probeFailed = $result->reason === ClearResult::PROBE_FAILED;

        if ($probeFailed) {
            // The batch whose probe failed kept its jobs: a later clear can check their unresolved bulk rows.
            $this->components->error('The unresolved bulk mirror rows could not be checked, so Orrery stopped before it deleted the next batch of waiting jobs. Those jobs are still queued. Run orrery:clear again.');
        }

        return $result->mirrorFailed || $probeFailed ? self::FAILURE : self::SUCCESS;
    }
}
