<?php

namespace Justrau\Orrery\Http\Lists;

use Justrau\Orrery\Observation\TagNormalizer;

/**
 * The validated parameters of `GET /api/jobs`.
 *
 * | Parameter | Rule |
 * | --- | --- |
 * | status | required: pending, running, completed, silenced, failed, retrying, unknown, or all (only with tag) |
 * | connection, queue | exact and byte-exact; queue needs connection |
 * | class | the exact job name, byte-exact |
 * | tag | an exact tag, normalised as stored (TagNormalizer) |
 * | batch | a batch id of at most 255 characters, not with status all |
 * | from, to | epoch seconds on the order column, half-open, from < to |
 * | q | a full uuid (any case), or 3 to 100 characters: a name substring (case-insensitive) or, when that tag exists, the exact tag |
 * | order | desc (default) or asc |
 * | cursor, newer_than | opaque (Cursor) |
 * | per_page | 1 to 100, default 50 |
 *
 * A value that breaks a rule, a query array where a string is expected,
 * or a malformed cursor throws InvalidListRequest with the field. Byte-exact
 * text must be valid UTF-8 without a NUL byte. Unknown parameters are
 * ignored.
 */
final class ListRequest
{
    public const STATUSES = ['pending', 'running', 'completed', 'silenced', 'failed', 'retrying', 'unknown', 'all'];

    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

    public const Q_MIN = 3;

    public const Q_MAX = 100;

    public const BATCH_MAX = 255;

    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

    private const EPOCH = '/^\d{1,12}(\.\d{1,6})?$/D';

    private function __construct(
        public readonly string $status,
        public readonly ?string $connection,
        public readonly ?string $queue,
        public readonly ?string $class,
        public readonly ?string $tag,
        public readonly ?string $batch,
        public readonly ?float $from,
        public readonly ?float $to,
        public readonly ?string $uuid,
        public readonly ?string $q,
        public readonly string $order,
        public readonly int $perPage,
        public readonly ?Cursor $cursor,
        public readonly ?Cursor $newerThan,
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the request's query values
     *
     * @throws InvalidListRequest
     */
    public static function from(array $query): self
    {
        $status = self::text($query, 'status');

        if ($status === null || ! in_array($status, self::STATUSES, true)) {
            throw new InvalidListRequest('status', 'status is one of '.implode(', ', self::STATUSES).'.');
        }

        $connection = self::exact($query, 'connection');
        $queue = self::exact($query, 'queue');
        $class = self::exact($query, 'class');

        if ($queue !== null && $connection === null) {
            throw new InvalidListRequest('queue', 'queue needs connection.');
        }

        $tag = null;
        $rawTag = self::text($query, 'tag');

        if ($rawTag !== null) {
            $tag = TagNormalizer::normalize($rawTag);

            if ($tag === null) {
                throw new InvalidListRequest('tag', 'tag is a non-empty tag.');
            }
        }

        if ($status === 'all' && $tag === null) {
            throw new InvalidListRequest('status', 'status all needs a tag.');
        }

        $batch = self::exact($query, 'batch');

        if ($batch !== null && (mb_strlen($batch) > self::BATCH_MAX)) {
            throw new InvalidListRequest('batch', 'batch is a batch id of at most 255 characters.');
        }

        if ($batch !== null && $status === 'all') {
            throw new InvalidListRequest('batch', 'batch needs a status other than all.');
        }

        $from = self::epoch($query, 'from');
        $to = self::epoch($query, 'to');

        if ($from !== null && $to !== null && ! ($from < $to)) {
            throw new InvalidListRequest('from', 'from must be before to.');
        }

        [$uuid, $q] = self::search($query);

        $order = self::text($query, 'order') ?? 'desc';

        if (! in_array($order, ['desc', 'asc'], true)) {
            throw new InvalidListRequest('order', 'order is desc or asc.');
        }

        $perPage = self::DEFAULT_PER_PAGE;
        $rawPerPage = self::text($query, 'per_page');

        if ($rawPerPage !== null) {
            if (preg_match('/^\d{1,3}$/D', $rawPerPage) !== 1 || (int) $rawPerPage < 1 || (int) $rawPerPage > self::MAX_PER_PAGE) {
                throw new InvalidListRequest('per_page', 'per_page is a whole number from 1 to 100.');
            }

            $perPage = (int) $rawPerPage;
        }

        $request = new self($status, $connection, $queue, $class, $tag, $batch, $from, $to, $uuid, $q, $order, $perPage, null, null);
        $hash = $request->filterHash();
        $uuidKey = $request->tag !== null && $request->uuid === null;
        $cursor = array_key_exists('cursor', $query) ? Cursor::decode($query['cursor'], 'cursor', $hash, $uuidKey) : null;
        $newer = array_key_exists('newer_than', $query) ? Cursor::decode($query['newer_than'], 'newer_than', $hash, $uuidKey) : null;
        // A null order value belongs only to the null-order group of a batch's pending and running members
        // (JobList::plan()); a uuid list reads no keyset. Elsewhere the keyset condition cannot take it.
        $nullOrder = $uuid !== null || ($tag === null && $batch !== null && in_array($status, ['pending', 'running'], true));

        foreach (['cursor' => $cursor, 'newer_than' => $newer] as $field => $given) {
            if ($given !== null && $given->order === null && ! $nullOrder) {
                throw new InvalidListRequest($field, "{$field} is not a cursor of this list.");
            }
        }

        return new self($status, $connection, $queue, $class, $tag, $batch, $from, $to, $uuid, $q, $order, $perPage, $cursor, $newer);
    }

    /**
     * The same request on its first page (a `before` cursor that reached the start).
     */
    public function withoutCursor(): self
    {
        return new self($this->status, $this->connection, $this->queue, $this->class, $this->tag, $this->batch, $this->from, $this->to, $this->uuid, $this->q, $this->order, $this->perPage, null, $this->newerThan);
    }

    /**
     * The sha256 of the normalised filters, the status and the order: the
     * `f` of every cursor of this list.
     */
    public function filterHash(): string
    {
        return hash('sha256', (string) json_encode([
            'status' => $this->status,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'class' => $this->class,
            'tag' => $this->tag,
            'batch' => $this->batch,
            'from' => $this->from,
            'to' => $this->to,
            'uuid' => $this->uuid,
            'q' => $this->q,
            'order' => $this->order,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A trimmed query string, or null when absent or empty.
     *
     * @param  array<array-key, mixed>  $query
     *
     * @throws InvalidListRequest
     */
    private static function text(array $query, string $key): ?string
    {
        if (! array_key_exists($key, $query) || $query[$key] === null) {
            return null;
        }

        if (! is_string($query[$key])) {
            throw new InvalidListRequest($key, "{$key} is a single value.");
        }

        $value = trim($query[$key]);

        return $value === '' ? null : $value;
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

    /**
     * @param  array<array-key, mixed>  $query
     *
     * @throws InvalidListRequest
     */
    private static function epoch(array $query, string $key): ?float
    {
        $value = self::text($query, $key);

        if ($value === null) {
            return null;
        }

        if (preg_match(self::EPOCH, $value) !== 1) {
            throw new InvalidListRequest($key, "{$key} is epoch seconds, for example 1790000000 or 1790000000.25.");
        }

        return (float) $value;
    }

    /**
     * `q`: [uuid, null] for a full uuid (lower case), [null, text] for a
     * search text, or [null, null].
     *
     * @param  array<array-key, mixed>  $query
     * @return array{0: string|null, 1: string|null}
     *
     * @throws InvalidListRequest
     */
    private static function search(array $query): array
    {
        $q = self::text($query, 'q');

        if ($q === null) {
            return [null, null];
        }

        if (preg_match(self::UUID, $q) === 1) {
            return [strtolower($q), null];
        }

        if (! mb_check_encoding($q, 'UTF-8') || str_contains($q, "\0")) {
            throw new InvalidListRequest('q', 'q is valid UTF-8 text without a NUL byte.');
        }

        $length = mb_strlen($q);

        if ($length < self::Q_MIN || $length > self::Q_MAX) {
            throw new InvalidListRequest('q', 'q is a full uuid, or 3 to 100 characters.');
        }

        return [null, $q];
    }
}
