<?php

namespace Justrau\Orrery\Http\Lists;

use Justrau\Orrery\Storage\Clock;

/**
 * A keyset position of a list: base64url of the JSON
 * `{"v": 1, "k": [<order value>, <key>], "d": "after"|"before", "f": <filter hash>}`.
 *
 * - The order value is a decimal string from Clock::toDecimal(), or null
 *   (the null-order group of a batch's pending and running members).
 * - The key is the row's internal id (an integer), or the `job_uuid` of a
 *   tag source.
 * - `after` reads the rows after the key in the list's order, `before` the
 *   rows before it.
 * - `f` is the sha256 of the normalised filters, the status and the order
 *   (ListRequest::filterHash()); a cursor of another list is refused as
 *   `cursor_mismatch`.
 *
 * The cursor carries the internal id. It is not a secret: a crafted cursor
 * only moves the start of a page.
 */
final class Cursor
{
    public const AFTER = 'after';

    public const BEFORE = 'before';

    /** The longest encoded cursor that decode() reads. */
    private const MAX_LENGTH = 512;

    public function __construct(
        public readonly ?string $order,
        public readonly int|string $key,
        public readonly string $direction,
        public readonly string $filters,
    ) {}

    /**
     * The cursor of a row: its order value (a decimal column read as a
     * number or a string, or null) and its key.
     */
    public static function at(mixed $order, int|string $key, string $direction, string $filters): self
    {
        return new self($order === null ? null : Clock::toDecimal((float) $order), $key, $direction, $filters);
    }

    public function encode(): string
    {
        $json = (string) json_encode(['v' => 1, 'k' => [$this->order, $this->key], 'd' => $this->direction, 'f' => $this->filters], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidListRequest malformed (`invalid`), or of another list (`cursor_mismatch`)
     */
    public static function decode(mixed $value, string $field, string $filters, bool $uuidKey): self
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new InvalidListRequest($field, "{$field} is not a cursor of this list.");
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data)
            || ($data['v'] ?? null) !== 1
            || ! is_array($data['k'] ?? null)
            || ! array_is_list($data['k'])
            || count($data['k']) !== 2
            || ! in_array($data['d'] ?? null, [self::AFTER, self::BEFORE], true)
            || ! is_string($data['f'] ?? null)
            || count($data) !== 4) {
            throw new InvalidListRequest($field, "{$field} is not a cursor of this list.");
        }

        [$order, $key] = $data['k'];

        if ($order !== null && (! is_string($order) || preg_match('/^\d{1,12}\.\d{6}$/D', $order) !== 1)) {
            throw new InvalidListRequest($field, "{$field} is not a cursor of this list.");
        }

        $keyValid = $uuidKey
            ? is_string($key) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $key) === 1
            : is_int($key) && $key >= 0;

        if (! hash_equals($data['f'], $filters)) {
            throw new InvalidListRequest($field, "{$field} belongs to another list: its filters, status or order differ.", 'cursor_mismatch');
        }

        if (! $keyValid) {
            throw new InvalidListRequest($field, "{$field} is not a cursor of this list.");
        }

        return new self($order, $key, $data['d'], $data['f']);
    }
}
