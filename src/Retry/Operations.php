<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Runtime\SafeError;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\EscapedValue;
use Justrau\Orrery\Storage\TrustedSql;
use stdClass;
use Throwable;

/**
 * The operation ledger `orrery_operations` on the mirror:
 * the single authority of retry and forget, and the audit log of operator
 * actions.
 *
 * The states and their transitions (each one guarded, one winner):
 *
 * | From | To | By |
 * | --- | --- | --- |
 * | — | claimed | insertClaimed(), inside the claim transaction |
 * | claimed | published | markPublished(): the publisher after COMMIT, or a resolution that reads `published` |
 * | claimed, published | done | markDone(): inside the finalise or adoption transaction |
 * | claimed | aborting | markAborting(): the first aborter |
 * | aborting | aborted | markAborted(): inside the abort's mirror transaction |
 *
 * `done` and `aborted` are terminal: no statement here changes them, and
 * each terminal transition clears the awaiting fields. Every method takes
 * the mirror session of the caller's transaction and never begins or ends
 * one. A transition returns whether this call won it.
 *
 * Times: `deadline` is in the queue database's clock (retry) or the mirror
 * database's clock (forget); `record_deadline` in the failed-record
 * database's clock; `next_check_at` and `awaiting_since` in the mirror
 * database's clock. The callers read those clocks (DatabaseClock).
 * `created_at` and `updated_at` are the package's own time (Clock::now()).
 */
final class Operations
{
    public const TABLE = 'orrery_operations';

    public const RETRY = 'retry';

    public const FORGET = 'forget';

    public const CLAIMED = 'claimed';

    public const PUBLISHED = 'published';

    public const ABORTING = 'aborting';

    public const DONE = 'done';

    public const ABORTED = 'aborted';

    /** The states that the runner checks. */
    public const ACTIVE = [self::CLAIMED, self::PUBLISHED, self::ABORTING];

    public const TERMINAL = [self::DONE, self::ABORTED];

    /** The modes: the delete inside the publication, the delete before it (with restore), no failed record. */
    public const IN_TX = 'in_tx';

    public const BEFORE = 'before';

    public const NONE = 'none';

    public const SOURCES = ['dashboard', 'batch', 'bulk', 'cli'];

    /** The awaiting reasons (D17, D18). */
    public const SQLITE_HOST_FILE = 'sqlite_host_file';

    public const SQLITE_READS_NOT_ALLOWED = 'sqlite_reads_not_allowed';

    /** The most bytes of `error`. */
    public const MAX_ERROR_BYTES = 2000;

    /** The columns that a claim must give; the others are nullable or have defaults. */
    private const REQUIRED = ['id', 'action', 'mode', 'job_uuid', 'occurrence_id', 'queue_connection', 'queue', 'queue_fingerprint', 'deadline', 'next_check_at', 'source'];

    /**
     * A new operation id: 32 random hex characters (the retry's orreryGen
     * and the fence key).
     */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Whether a value is a bulk run ref (or an operation id): 32 lowercase
     * hexadecimal characters.
     */
    public static function validRef(mixed $ref): bool
    {
        return is_string($ref) && preg_match('/^[0-9a-f]{32}$/D', $ref) === 1;
    }

    /**
     * The claim count of a bulk run, inside the caller's claim
     * transaction: `claimed + 1` on the run's audit row; with $slot only
     * while `claimed < confirmed_total` (a forget's slot of its confirmed
     * total). False when no row changed: the claim must roll back.
     */
    public static function countBulkClaim(Connection $mirror, string $ref, bool $slot): bool
    {
        $query = $mirror->table(PackageSchema::BULK_RUNS)->where('ref', $ref);

        if ($slot) {
            $query->whereNotNull('confirmed_total')->whereColumn('claimed', '<', 'confirmed_total');
        }

        return $query->update(['claimed' => TrustedSql::expression('claimed + 1')]) === 1;
    }

    /**
     * Inserts a `claimed` operation. Times are floats; the ledger stores
     * them as decimals.
     *
     * @param  array<string, mixed>  $columns
     */
    public function insertClaimed(Connection $mirror, array $columns): void
    {
        foreach (self::REQUIRED as $column) {
            if (! array_key_exists($column, $columns)) {
                throw new InvalidArgumentException("orrery: an operation needs {$column}.");
            }
        }

        if (! in_array($columns['action'], [self::RETRY, self::FORGET], true)
            || ! in_array($columns['mode'], [self::IN_TX, self::BEFORE, self::NONE], true)
            || ! in_array($columns['source'], self::SOURCES, true)
            || ! self::validRef($columns['id'])
            || (array_key_exists('bulk_ref', $columns) && ! self::validRef($columns['bulk_ref']))) {
            throw new InvalidArgumentException('orrery: an operation has an unknown action, mode or source, or an id that is not 32 hex characters.');
        }

        $now = Clock::toDecimal(Clock::now());
        $row = array_merge($columns, ['state' => self::CLAIMED, 'checks' => 0, 'created_at' => $now, 'updated_at' => $now]);

        foreach (['deadline', 'next_check_at', 'record_deadline'] as $time) {
            if (isset($row[$time]) && (is_float($row[$time]) || is_int($row[$time]))) {
                $row[$time] = Clock::toDecimal((float) $row[$time]);
            }
        }

        $mirror->table(self::TABLE)->insert($row);
    }

    public function find(Connection $mirror, string $id, bool $lock = false): ?stdClass
    {
        $query = $mirror->table(self::TABLE)->where('id', $id);

        if ($lock && $mirror->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * claimed → published, with the published job id.
     */
    public function markPublished(Connection $mirror, string $id, int $jobId): bool
    {
        return $this->transition($mirror, $id, [self::CLAIMED], ['state' => self::PUBLISHED, 'result_job_id' => $jobId]);
    }

    /**
     * claimed or published → done; $errorCode for a finalise miss (row_moved).
     */
    public function markDone(Connection $mirror, string $id, ?int $jobId = null, ?string $errorCode = null): bool
    {
        $set = ['state' => self::DONE, 'awaiting_since' => null, 'awaiting_reason' => null];

        if ($jobId !== null) {
            $set['result_job_id'] = $jobId;
        }

        if ($errorCode !== null) {
            $set['error_code'] = $errorCode;
        }

        return $this->transition($mirror, $id, [self::CLAIMED, self::PUBLISHED], $set);
    }

    /**
     * claimed → aborting, with the abort's code. The loser reads the state
     * (find()) and continues the abort when it reads `aborting`.
     */
    public function markAborting(Connection $mirror, string $id, string $errorCode, ?string $error = null): bool
    {
        return $this->transition($mirror, $id, [self::CLAIMED], ['state' => self::ABORTING, 'error_code' => $errorCode, 'error' => self::limit($error)]);
    }

    /**
     * aborting → aborted. The abort's code stays.
     */
    public function markAborted(Connection $mirror, string $id): bool
    {
        return $this->transition($mirror, $id, [self::ABORTING], ['state' => self::ABORTED, 'awaiting_since' => null, 'awaiting_reason' => null]);
    }

    /**
     * Schedules the next check of an active operation. $countCheck adds one
     * to `checks` (an undecided check); $errorCode and $error replace the
     * stored ones when given.
     */
    public function reschedule(Connection $mirror, string $id, float $nextCheckAt, bool $countCheck, ?string $errorCode = null, ?string $error = null): bool
    {
        $set = ['next_check_at' => Clock::toDecimal($nextCheckAt)];

        if ($countCheck) {
            $set['checks'] = TrustedSql::expression('checks + 1');
        }

        if ($errorCode !== null) {
            $set['error_code'] = $errorCode;
            $set['error'] = self::limit($error);
        }

        return $this->transition($mirror, $id, self::ACTIVE, $set);
    }

    /**
     * Marks an active operation as awaiting an operator (D17, D18). The
     * first `awaiting_since` stays; the reason is the current one.
     */
    public function await(Connection $mirror, string $id, string $reason, float $mirrorNow): bool
    {
        if (! in_array($reason, [self::SQLITE_HOST_FILE, self::SQLITE_READS_NOT_ALLOWED], true)) {
            throw new InvalidArgumentException("orrery: [{$reason}] is not an awaiting reason.");
        }

        return $this->transition($mirror, $id, self::ACTIVE, [
            'awaiting_since' => TrustedSql::expression('COALESCE(awaiting_since, %s)', EscapedValue::of($mirror, Clock::toDecimal($mirrorNow))),
            'awaiting_reason' => $reason,
        ]);
    }

    /**
     * Clears the awaiting fields when the waiting step can proceed. The
     * abort's `error_code` stays.
     */
    public function clearAwaiting(Connection $mirror, string $id): bool
    {
        return $this->transition($mirror, $id, self::ACTIVE, ['awaiting_since' => null, 'awaiting_reason' => null]);
    }

    /**
     * The stored error text: SafeError::summary(), at most
     * MAX_ERROR_BYTES bytes.
     */
    public static function errorText(string $operation, Throwable|string $problem): string
    {
        return (string) self::limit(SafeError::summary($operation, $problem));
    }

    private static function limit(?string $text): ?string
    {
        return $text === null ? null : mb_strcut($text, 0, self::MAX_ERROR_BYTES, 'UTF-8');
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $set
     */
    private function transition(Connection $mirror, string $id, array $from, array $set): bool
    {
        $set['updated_at'] = Clock::toDecimal(Clock::now());

        return $mirror->table(self::TABLE)->where('id', $id)->whereIn('state', $from)->update($set) === 1;
    }
}
