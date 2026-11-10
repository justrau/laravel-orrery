<?php

namespace Justrau\Orrery\Retry;

/**
 * The result of one step of an operation (OperationHandler::step()):
 * - decided(): the step moved the operation (a terminal state, or the next
 *   state with its own next_check_at); the runner changes nothing;
 * - undecided(): no decision; `checks + 1` and the backoff;
 * - blocked(): a connect error, a connect timeout or a lock timeout on the
 *   named database connection; the runner defers that connection, gives the
 *   operation `checks + 1` and the backoff, and stops the run;
 * - awaiting(): the step needs an access to a host SQLite file that the
 *   runner may not make (D17, D18); `sqlite_reads_not_allowed` also counts a
 *   check and backs off, `sqlite_host_file` waits for an operator;
 * - later(): the operation is not due yet; next_check_at without a count.
 */
final class OperationStep
{
    public const DECIDED = 'decided';

    public const UNDECIDED = 'undecided';

    public const BLOCKED = 'blocked';

    public const AWAITING = 'awaiting';

    public const LATER = 'later';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $errorCode = null,
        public readonly ?string $error = null,
        public readonly ?string $connection = null,
        public readonly ?string $reason = null,
        public readonly ?float $nextCheckAt = null,
    ) {}

    public static function decided(): self
    {
        return new self(self::DECIDED);
    }

    public static function undecided(?string $errorCode = null, ?string $error = null): self
    {
        return new self(self::UNDECIDED, $errorCode, $error);
    }

    public static function blocked(string $connection, string $errorCode, ?string $error = null): self
    {
        return new self(self::BLOCKED, $errorCode, $error, $connection);
    }

    public static function awaiting(string $reason): self
    {
        return new self(self::AWAITING, reason: $reason);
    }

    public static function later(float $nextCheckAt): self
    {
        return new self(self::LATER, nextCheckAt: $nextCheckAt);
    }
}
