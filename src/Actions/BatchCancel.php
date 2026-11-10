<?php

namespace Justrau\Orrery\Actions;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Database\Repositories\DatabaseBatchRepository;

/**
 * Cancel a batch: the service of `POST /api/batches/{id}/cancel`. Call it
 * inside an operator action (PackageSession::asOperator(), D17).
 *
 * One guarded update of the batch table on DatabaseBatchRepository::session(),
 * with a byte-exact id (MySQL also `CAST(id AS BINARY)`): `UPDATE <batching
 * table> SET cancelled_at = :t, finished_at = :t WHERE id = :id AND
 * cancelled_at IS NULL AND finished_at IS NULL`, where `:t` is PHP's time()
 * (Carbon), as Laravel's Batch::cancel() writes it.
 *
 * Laravel's `BatchCanceled` event is not dispatched: building a Batch needs
 * unserialize() of its options, which the package's data-only rule refuses.
 * Jobs that check the batch (the SkipIfBatchCancelled middleware or
 * `batch()->cancelled()`) skip their work; other jobs still run, and running
 * jobs finish.
 *
 * Results: CANCELLED (one row changed); else the batch is read again:
 * ALREADY_CANCELLED (cancelled_at is set), ALREADY_FINISHED, NOT_FOUND;
 * UNSUPPORTED (the bound repository is not exactly Laravel's database
 * repository, so the batches may live elsewhere) and STATE_UNKNOWN (the
 * batch table cannot be read: DatabaseBatchRepository::unknownReason())
 * change nothing. A database error reaches the caller.
 */
final class BatchCancel
{
    public const CANCELLED = 'cancelled';

    public const ALREADY_CANCELLED = 'already_cancelled';

    public const ALREADY_FINISHED = 'already_finished';

    public const NOT_FOUND = 'not_found';

    public const UNSUPPORTED = DatabaseBatchRepository::UNSUPPORTED_REASON;

    public const STATE_UNKNOWN = 'batch_state_unknown';

    public function __construct(private readonly DatabaseBatchRepository $batches)
    {
    }

    /**
     * @return array{result: string, cancelled_at: int|null}
     */
    public function cancel(string $id): array
    {
        $reason = $this->batches->unknownReason();

        if ($reason === DatabaseBatchRepository::UNSUPPORTED_REASON) {
            return ['result' => self::UNSUPPORTED, 'cancelled_at' => null];
        }

        if ($reason !== null) {
            return ['result' => self::STATE_UNKNOWN, 'cancelled_at' => null];
        }

        $time = Carbon::now()->getTimestamp();
        $session = $this->batches->session();
        $query = $session->table($this->batches->table())
            ->where('id', $id)
            ->whereNull('cancelled_at')
            ->whereNull('finished_at');

        if (in_array($session->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereRaw('CAST(`id` AS BINARY) = CAST(? AS BINARY)', [$id]);
        }

        if ($query->update(['cancelled_at' => $time, 'finished_at' => $time]) === 1) {
            return ['result' => self::CANCELLED, 'cancelled_at' => $time];
        }

        $row = $this->batches->find($id);

        if ($row === null) {
            return ['result' => self::NOT_FOUND, 'cancelled_at' => null];
        }

        return $row->cancelled_at !== null
            ? ['result' => self::ALREADY_CANCELLED, 'cancelled_at' => (int) $row->cancelled_at]
            : ['result' => $row->finished_at !== null ? self::ALREADY_FINISHED : self::STATE_UNKNOWN, 'cancelled_at' => null];
    }
}
