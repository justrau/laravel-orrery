<?php

namespace Justrau\Orrery\Http\Lists;

use Justrau\Orrery\Monitoring\StuckSignals;

/**
 * The validated parameters of `GET /api/jobs/stuck`.
 *
 * | Parameter | Rule |
 * | --- | --- |
 * | signal | required: reservation_expired, outcome_not_observed, repeated_releases or attempts_not_capped |
 * | connection, queue | exact and byte-exact; queue needs connection |
 * | class | the exact job name, byte-exact |
 * | cursor | opaque (Cursor), of the same signal and filters |
 * | per_page | 1 to 100, default 50 |
 *
 * The order is the signal's own (StuckList::ORDER). A value that breaks a
 * rule, a query array where a string is expected, or a malformed cursor
 * throws InvalidListRequest with the field. Byte-exact text must be valid
 * UTF-8 without a NUL byte. Other parameters are ignored.
 */
final class StuckRequest
{
    private function __construct(
        public readonly string $signal,
        public readonly ?string $connection,
        public readonly ?string $queue,
        public readonly ?string $class,
        public readonly int $perPage,
        public readonly ?Cursor $cursor,
    ) {
    }

    /**
     * @param  array<array-key, mixed>  $query  the request's query values
     *
     * @throws InvalidListRequest
     */
    public static function from(array $query): self
    {
        $signal = $query['signal'] ?? null;

        if (! is_string($signal) || ! in_array($signal, StuckSignals::SIGNALS, true)) {
            throw new InvalidListRequest('signal', 'signal is one of '.implode(', ', StuckSignals::SIGNALS).'.');
        }

        $connection = self::exact($query, 'connection');
        $queue = self::exact($query, 'queue');
        $class = self::exact($query, 'class');

        if ($queue !== null && $connection === null) {
            throw new InvalidListRequest('queue', 'queue needs connection.');
        }

        $perPage = ListRequest::DEFAULT_PER_PAGE;
        $rawPerPage = $query['per_page'] ?? null;

        if ($rawPerPage !== null && $rawPerPage !== '') {
            if (! is_string($rawPerPage) || preg_match('/^\d{1,3}$/D', $rawPerPage) !== 1 || (int) $rawPerPage < 1 || (int) $rawPerPage > ListRequest::MAX_PER_PAGE) {
                throw new InvalidListRequest('per_page', 'per_page is a whole number from 1 to 100.');
            }

            $perPage = (int) $rawPerPage;
        }

        $request = new self($signal, $connection, $queue, $class, $perPage, null);
        $cursor = array_key_exists('cursor', $query) ? Cursor::decode($query['cursor'], 'cursor', $request->filterHash(), false) : null;

        // Every stuck order value is a time (StuckList): a null order value cannot take the keyset condition.
        if ($cursor !== null && $cursor->order === null) {
            throw new InvalidListRequest('cursor', 'cursor is not a cursor of this list.');
        }

        return new self($signal, $connection, $queue, $class, $perPage, $cursor);
    }

    /**
     * The same request on its first page (a `before` cursor that reached the start).
     */
    public function withoutCursor(): self
    {
        return new self($this->signal, $this->connection, $this->queue, $this->class, $this->perPage, null);
    }

    /**
     * The sha256 of the signal and the filters: the `f` of every cursor of
     * this list. A cursor of another signal or filter is `cursor_mismatch`.
     */
    public function filterHash(): string
    {
        return hash('sha256', (string) json_encode([
            'stuck' => $this->signal,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'class' => $this->class,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * A byte-exact query string (never trimmed): valid UTF-8 without a NUL byte.
     *
     * @param  array<array-key, mixed>  $query
     *
     * @throws InvalidListRequest
     */
    private static function exact(array $query, string $key): ?string
    {
        if (! array_key_exists($key, $query) || $query[$key] === null || $query[$key] === '') {
            return null;
        }

        $value = $query[$key];

        if (! is_string($value)) {
            throw new InvalidListRequest($key, "{$key} is a single value.");
        }

        if (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidListRequest($key, "{$key} is valid UTF-8 text without a NUL byte.");
        }

        return $value;
    }
}
