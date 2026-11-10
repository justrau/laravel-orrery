<?php

namespace Justrau\Orrery\Failures;

/**
 * The scope of a failure-group read or operation: an
 * optional queue (`connection:queue`) and the half-open window [from, to)
 * of failed_at, in epoch seconds.
 *
 * Validation: from and to are finite numbers of 0 or more, from < to, and
 * to - from is at most 7 days. There is no check against the retention
 * floor: members older than the retention are simply absent. The queue is
 * split at its first colon; both parts must be non-empty. The queue is
 * valid UTF-8 without a NUL byte or a backslash.
 */
final class FailureScope
{
    /** The longest window: 7 days. */
    public const MAX_SECONDS = 604_800;

    private const EPOCH = '/^\d{1,12}(\.\d{1,6})?$/D';

    private function __construct(
        public readonly ?string $connection,
        public readonly ?string $queue,
        public readonly float $from,
        public readonly float $to,
    ) {}

    /**
     * @throws InvalidFailureRequest
     */
    public static function from(mixed $queue, mixed $from, mixed $to): self
    {
        $start = self::time($from, 'from');
        $end = self::time($to, 'to');

        if (! ($start < $end)) {
            throw new InvalidFailureRequest('from must be before to.');
        }

        if ($end - $start > self::MAX_SECONDS) {
            throw new InvalidFailureRequest('The window from..to is at most 7 days (604800 seconds).');
        }

        [$connection, $name] = self::queue($queue);

        return new self($connection, $name, $start, $end);
    }

    /**
     * The queue as the request writes it (`connection:queue`), or null for all queues.
     */
    public function queueKey(): ?string
    {
        return $this->connection === null ? null : $this->connection.':'.$this->queue;
    }

    /**
     * Whether a failure of this connection and queue at this time is inside the scope.
     */
    public function contains(string $connection, string $queue, float $failedAt): bool
    {
        if ($this->connection !== null && ($connection !== $this->connection || $queue !== $this->queue)) {
            return false;
        }

        return $failedAt >= $this->from && $failedAt < $this->to;
    }

    public function seconds(): float
    {
        return $this->to - $this->from;
    }

    /**
     * @return array{queue: string|null, from: float, to: float}
     */
    public function toArray(): array
    {
        return ['queue' => $this->queueKey(), 'from' => $this->from, 'to' => $this->to];
    }

    private static function time(mixed $value, string $name): float
    {
        if (is_int($value) || is_float($value)) {
            $time = (float) $value;

            if (! is_finite($time) || $time < 0) {
                throw new InvalidFailureRequest("{$name} is epoch seconds: a finite number of 0 or more.");
            }

            return $time;
        }

        if (is_string($value) && preg_match(self::EPOCH, $value) === 1) {
            return (float) $value;
        }

        throw new InvalidFailureRequest("{$name} is epoch seconds, for example 1790000000 or 1790000000.25.");
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function queue(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }

        if (! is_string($value)) {
            throw new InvalidFailureRequest('queue is connection:queue.');
        }

        // The keyed reads write the queue into the SQL as a connection-escaped literal: valid UTF-8 without a NUL
        // byte, and without a backslash, which PDO's SQL scanner on Postgres can misread before PHP 8.4.
        if (str_contains($value, "\0") || str_contains($value, '\\') || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidFailureRequest('queue is valid UTF-8 text without a NUL byte or a backslash.');
        }

        $parts = explode(':', $value, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidFailureRequest('queue is connection:queue.');
        }

        return [$parts[0], $parts[1]];
    }
}
