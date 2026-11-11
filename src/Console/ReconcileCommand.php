<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The operator path of reconciliation (D18): one full sweep of the
 * active rows of each database queue connection (or of --connection), without
 * --connection one sweep of the active rows of the queue connections that are
 * no longer configured (against every configured queue table), and
 * passes of the failed rows until they reach the end of the rows or
 * FAILED_SECONDS end (then it says so and exits 0), as an operator action. On a SQLite
 * file that allows no automatic reads (a rollback journal, no busy timeout,
 * an EXCLUSIVE locking mode, or orrery.sqlite_automatic_access off), the
 * tick does not reconcile against it, and this command does. It reads the
 * application's tables and writes only the mirror. A database error of the
 * mirror reaches the console kernel, so the command exits non-zero. A
 * failed read of the application's tables stops that sweep at its batch,
 * names what was not checked, and gives exit 1.
 */
#[AsCommand(name: 'orrery:reconcile')]
class ReconcileCommand extends Command
{
    use RefusesWhileDisabled;

    /** The batches of one sweep at most (1,000 rows each). */
    public const MAX_BATCHES = 10000;

    /** The time limit of the sweep of the rows of removed queue connections, in seconds. */
    public const REMOVED_SECONDS = 60.0;

    /** The time limit of the pass of the failed rows, in seconds. */
    public const FAILED_SECONDS = 60.0;

    /** @var string */
    protected $signature = 'orrery:reconcile {--connection= : Only this database queue connection}';

    /** @var string */
    protected $description = 'Check the mirror rows of the waiting, reserved and failed jobs against the queue and failed-job tables, also on a SQLite file that the tick does not read.';

    public function handle(Reconciliation $reconciliation, PackageSession $sessions): int
    {
        $only = $this->option('connection');
        $connections = $reconciliation->connections();

        if (is_string($only) && $only !== '') {
            if (! in_array($only, $connections, true)) {
                $this->components->error("The queue connection [{$only}] is not a database queue connection.");

                return self::FAILURE;
            }

            $connections = [$only];
        }

        return $sessions->operator(function () use ($reconciliation, $connections): int {
            $status = self::SUCCESS;

            foreach ($connections as $connection) {
                $batches = 0;

                do {
                    $checked = $reconciliation->reconcileActive($connection);
                    $batches++;
                } while ($checked && $batches < self::MAX_BATCHES && $this->sweeping($connection));

                if (! $checked) {
                    // A stored cursor is no progress: the failed batch stops the sweep.
                    $this->components->error("The waiting and reserved jobs of [{$connection}] were not checked: a read of its queue table failed (see the health events).");
                    $status = self::FAILURE;

                    continue;
                }

                $this->components->info("Reconciled the waiting and reserved jobs of [{$connection}].");
            }

            if ($this->option('connection') === null) {
                $status = $this->reconcileRemoved($reconciliation) === self::SUCCESS ? $status : self::FAILURE;
            }

            // Passes of the failed rows until one reaches the end of the rows (its cursor
            // clears) or the time share ends: one pass stops at its row and scan caps.
            $budget = new TickBudget(Clock::monotonic() + self::FAILED_SECONDS);

            do {
                $checked = $reconciliation->reconcileFailed($budget);
            } while ($checked && $this->failedPassOpen() && ! $budget->exhausted());

            if (! $checked) {
                $this->components->error('The failed jobs were not all checked: a read of the failed-job or queue table failed (see the health events).');
                $status = self::FAILURE;
            } elseif ($this->failedPassOpen()) {
                $this->components->warn('The failed jobs were checked only in part: the time share of the command ended. The tick continues the check; run orrery:reconcile again to continue now.');
            } else {
                $this->components->info('Reconciled the failed jobs.');
            }

            return $status;
        });
    }

    /**
     * One sweep of the active rows of the queue connections that are no
     * longer configured, from their first row (Reconciliation::
     * reconcileRemoved()), within REMOVED_SECONDS. A table whose read failed
     * is named, and the command exits 1; the rows that it could hold keep
     * their state.
     */
    private function reconcileRemoved(Reconciliation $reconciliation): int
    {
        Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_settings')->where('key', Reconciliation::REMOVED_CURSOR)->delete();
        $budget = new TickBudget(Clock::monotonic() + self::REMOVED_SECONDS);
        $skipped = [];

        do {
            $result = $reconciliation->reconcileRemoved($budget);
            $skipped = array_values(array_unique([...$skipped, ...$result['skipped']]));
        } while (! $result['wrapped'] && ! $result['stopped'] && $result['checked'] > 0);

        if ($skipped !== []) {
            $this->components->error('The waiting and reserved jobs of removed queue connections were not all checked: '.implode(', ', $skipped).' could not be read (see the health events).');

            return self::FAILURE;
        }

        if (! $result['wrapped']) {
            $this->components->warn('The waiting and reserved jobs of removed queue connections were checked only in part: the time share of the command ended. Run orrery:reconcile again to continue.');

            return self::SUCCESS;
        }

        $this->components->info('Reconciled the waiting and reserved jobs of removed queue connections.');

        return self::SUCCESS;
    }

    /**
     * Whether the pass of the failed rows stopped before their end: its
     * cursor is stored.
     */
    private function failedPassOpen(): bool
    {
        return Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_settings')->where('key', Reconciliation::FAILED_CURSOR)->exists();
    }

    /**
     * Whether the sweep of a connection is still in progress: its cursor is
     * stored.
     */
    private function sweeping(string $connection): bool
    {
        return Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_settings')->where('key', Reconciliation::CURSOR_PREFIX.$connection)->exists();
    }
}
