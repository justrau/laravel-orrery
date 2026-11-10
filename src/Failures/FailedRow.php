<?php

namespace Justrau\Orrery\Failures;

/**
 * One mirror row as the failure-group scan projects it: no payload, no
 * trace, and the message as the scan read it, unredacted: the scanned head
 * (FailureScan::HEAD_CHARACTERS + 1 characters), or the full message when
 * the scan read it again. A response shows it only through
 * FailureScan::displayMessages() and JobProjection::message(), which
 * redacts the whole stored message and then cuts its first line to 500
 * characters.
 */
final class FailedRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly ?int $occurrenceId,
        public readonly string $status,
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $name,
        public readonly ?string $exceptionClass,
        public readonly ?string $message,
        public readonly ?float $failedAt,
        public readonly ?float $retriedAt,
        public readonly int $attempts,
        public readonly string $key,
    ) {
    }

    public function queueKey(): string
    {
        return $this->connection.':'.$this->queue;
    }
}
