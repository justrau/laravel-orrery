<?php

namespace Justrau\Orrery\Actions;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Lifecycle\History;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;

/**
 * Run a delayed job now: the service of `POST /api/jobs/{uuid}/run-now`.
 * Call it inside an operator action (PackageSession::asOperator(), D17).
 *
 * - The mirror row must be `pending` with a resolved job_id.
 * - `:t` is floor(PHP time()) of the request (Carbon, as Laravel's
 *   DatabaseQueue reads the time): the clock that the workers compare
 *   available_at with, never a database clock.
 * - One transaction on the queue connection locks the host row by id,
 *   checks that its payload's uuid is the job's, and runs `UPDATE jobs SET
 *   available_at = :t WHERE id = ? AND reserved_at IS NULL AND available_at
 *   > :t`.
 * - Then one mirror transaction sets only `available_at` and `updated_at`,
 *   guarded by uuid, status `pending` and job_id, and adds a `run_now`
 *   history entry (source `dashboard`). It changes no `releases`, job_id or
 *   status, so no stuck signal can fire. A mirror write that fails is
 *   reported; the job is available anyway, and its reservation brings the
 *   row up to date.
 *
 * Results: `available`; `not_delayed` (available_at is not in the future);
 * `not_pending` (the mirror row is not pending, or the host row is
 * reserved, or gone while the mirror row is no longer pending); `gone` (the
 * host row is gone, or holds another job, while the mirror row is still
 * pending); `job_id_unknown` (an unresolved bulk row); `not_found` (no
 * mirror row). A queue connection inside a transaction is refused
 * (`in_transaction`); an SQLITE_BUSY answer runs the transaction again
 * (WaitingJobs::retryBusy()). An error of the queue connection reaches the
 * caller.
 */
final class DelayedJobs
{
    public const AVAILABLE = 'available';

    public const NOT_DELAYED = 'not_delayed';

    public const HISTORY_EVENT = 'run_now';

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
        private readonly History $history,
        private readonly WaitingJobs $waiting,
    ) {
    }

    /**
     * @return array{result: string, connection: string|null, queue: string|null}
     *
     * @throws ActionRefused
     */
    public function runNow(string $uuid): array
    {
        $row = $this->mirrorRow($uuid);

        if ($row === null) {
            return ['result' => WaitingJobs::NOT_FOUND, 'connection' => null, 'queue' => null];
        }

        $facts = ['connection' => (string) $row->connection, 'queue' => (string) $row->queue];

        if ($row->status !== 'pending') {
            return ['result' => WaitingJobs::NOT_PENDING] + $facts;
        }

        if ($row->job_id === null) {
            return ['result' => WaitingJobs::JOB_ID_UNKNOWN] + $facts;
        }

        $jobId = (int) $row->job_id;
        $time = Carbon::now()->getTimestamp();
        $queue = $this->waiting->queue($facts['connection']);
        $database = $queue->getDatabase();
        WaitingJobs::refuseInTransaction($database, $facts['connection']);
        $table = WaitingJobs::tableOf($queue);
        $result = WaitingJobs::retryBusy($database, static fn (): string => $database->transaction(static function (Connection $database) use ($table, $jobId, $uuid, $time): string {
            $job = $database->table($table)->where('id', $jobId)->lockForUpdate()->first(['id', 'payload', 'reserved_at', 'available_at']);

            if ($job === null || PayloadIdentity::uuidOf($job->payload ?? null) !== $uuid) {
                // The row is gone, or its id now holds another job.
                return WaitingJobs::GONE;
            }

            if ($job->reserved_at !== null) {
                return WaitingJobs::NOT_PENDING;
            }

            if ((int) $job->available_at <= $time) {
                return self::NOT_DELAYED;
            }

            $moved = $database->table($table)
                ->where('id', $jobId)
                ->whereNull('reserved_at')
                ->where('available_at', '>', $time)
                ->update(['available_at' => $time]);

            return $moved === 1 ? self::AVAILABLE : WaitingJobs::NOT_PENDING;
        }));

        if ($result === WaitingJobs::GONE) {
            $current = $this->mirrorRow($uuid);

            return ['result' => $current !== null && $current->status === 'pending' ? WaitingJobs::GONE : WaitingJobs::NOT_PENDING] + $facts;
        }

        if ($result === self::AVAILABLE) {
            Watcher::instance()->run('run_now:mirror', fn () => $this->updateMirror($uuid, $jobId, $time));
        }

        return ['result' => $result] + $facts;
    }

    private function updateMirror(string $uuid, int $jobId, int $time): void
    {
        $this->transactions->run($this->sessions->mirror(), function (Connection $tx) use ($uuid, $jobId, $time): void {
            $cycle = $tx->table(JobStore::TABLE)->where('uuid', $uuid)->where('status', 'pending')->where('job_id', $jobId)->lockForUpdate()->value('cycle');

            if ($cycle === null) {
                return;
            }

            $stamp = Clock::toDecimal((float) $time);
            $tx->table(JobStore::TABLE)
                ->where('uuid', $uuid)
                ->where('status', 'pending')
                ->where('job_id', $jobId)
                ->update(['available_at' => $stamp, 'updated_at' => Clock::toDecimal(Clock::now())]);

            $this->history->insert($tx, $uuid, (int) $cycle, null, self::HISTORY_EVENT, [
                'occurred_at' => Clock::toDecimal(Clock::now()),
                'source' => 'dashboard',
            ], null);
        });
    }

    private function mirrorRow(string $uuid): ?\stdClass
    {
        /** @var \stdClass|null */
        return $this->sessions->bounded($this->sessions->mirror(), static fn (Connection $session): ?\stdClass => $session->table(JobStore::TABLE)->where('uuid', $uuid)->first(['uuid', 'connection', 'queue', 'status', 'job_id']));
    }
}
