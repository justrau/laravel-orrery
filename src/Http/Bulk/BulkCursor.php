<?php

namespace Justrau\Orrery\Http\Bulk;

use Justrau\Orrery\Storage\Clock;

/**
 * The position of a bulk run ("Order and pages"): base64url of
 * the JSON `{"v": 1, "r": <ref>, "g": <segment>, "k": [<order value>, <id>]}`.
 *
 * - `g` is the segment: the index of the group key in the request (a groups
 *   source), else 0.
 * - `k` is the keyset position inside the segment: the order value (a
 *   decimal string from Clock::toDecimal()) and the row id of the last
 *   examined row. The run goes on after it, newest first.
 * - `r` binds the cursor to its run: a cursor of another run is `invalid`.
 *
 * The cursor carries internal ids. It is not a secret: a crafted cursor only
 * moves the start of a page (and the forget slots bound what a run can
 * delete).
 */
final class BulkCursor
{
    private const MAX_LENGTH = 512;

    public function __construct(
        public readonly string $ref,
        public readonly int $segment,
        public readonly string $order,
        public readonly int $id,
    ) {}

    public static function at(string $ref, int $segment, mixed $order, int $id): self
    {
        return new self($ref, $segment, Clock::toDecimal((float) $order), $id);
    }

    public function encode(): string
    {
        $json = (string) json_encode(['v' => 1, 'r' => $this->ref, 'g' => $this->segment, 'k' => [$this->order, $this->id]], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * null for an absent cursor (the start of the run).
     *
     * @throws BulkRefusal `invalid` on the field `after`
     */
    public static function decode(mixed $value, string $ref, int $segments): ?self
    {
        if ($value === null) {
            return null;
        }

        $invalid = BulkRefusal::invalid('after', 'after is a cursor that an earlier page of this run returned (next_after), or null.');

        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw $invalid;
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data)
            || count($data) !== 4
            || ($data['v'] ?? null) !== 1
            || ! is_string($data['r'] ?? null) || ! hash_equals($ref, $data['r'])
            || ! is_int($data['g'] ?? null) || $data['g'] < 0 || $data['g'] >= $segments
            || ! is_array($data['k'] ?? null) || ! array_is_list($data['k']) || count($data['k']) !== 2
            || ! is_string($data['k'][0]) || preg_match('/^\d{1,12}\.\d{6}$/D', $data['k'][0]) !== 1
            || ! is_int($data['k'][1]) || $data['k'][1] < 0) {
            throw $invalid;
        }

        return new self($ref, $data['g'], $data['k'][0], $data['k'][1]);
    }
}
