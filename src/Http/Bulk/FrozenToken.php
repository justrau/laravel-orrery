<?php

namespace Justrau\Orrery\Http\Bulk;

use JsonException;
use Justrau\Orrery\Retry\Operations;

/**
 * The signed freeze of a bulk run: an opaque
 * token that the client echoes verbatim.
 *
 * token = base64url(the canonical JSON text of {action, exact, frozen_at,
 * max_occurrence, ref, source, source_hash, total}, keys sorted) + "." +
 * base64url(HMAC-SHA256 of that JSON text). The HMAC key is
 * hash_hmac('sha256', DOMAIN, app.key): a domain label of its own, so no
 * other HMAC of the package (the failure-group key) shares it.
 *
 * verify() compares the signature with hash_equals() before it decodes
 * anything, then decodes the signed JSON text and reads every field from it:
 * exactly the known keys, each of its type, the source valid and its
 * source_hash equal to the sha256 of its canonical JSON. Anything else is
 * `frozen_invalid`. The server never rebuilds the fields from a parsed
 * copy: a JSON round trip on the client (1762600000.0 to 1762600000) would
 * break a signature over parsed values.
 */
final class FrozenToken
{
    public const DOMAIN = 'orrery-bulk-freeze';

    /** The fields of the signed JSON, sorted. */
    public const KEYS = ['action', 'exact', 'frozen_at', 'max_occurrence', 'ref', 'source', 'source_hash', 'total'];

    /** The longest token that verify() reads: a groups source of 200 keys is about 20 KB. */
    public const MAX_LENGTH = 65_536;

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param  array{count: int, at_least: bool}  $total
     */
    private function __construct(
        public readonly string $ref,
        public readonly string $action,
        public readonly BulkSource $source,
        public readonly string $sourceHash,
        public readonly int $maxOccurrence,
        public readonly float $frozenAt,
        public readonly array $total,
        public readonly bool $exact,
    ) {}

    /**
     * @param  array{count: int, at_least: bool}  $total
     */
    public static function freeze(string $ref, string $action, BulkSource $source, int $maxOccurrence, float $frozenAt, array $total, bool $exact): self
    {
        return new self($ref, $action, $source, $source->hash(), $maxOccurrence, $frozenAt, $total, $exact);
    }

    /**
     * The signed fields, sorted.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        $total = ['at_least' => $this->total['at_least'], 'count' => $this->total['count']];

        return [
            'action' => $this->action,
            'exact' => $this->exact,
            'frozen_at' => $this->frozenAt,
            'max_occurrence' => $this->maxOccurrence,
            'ref' => $this->ref,
            'source' => $this->source->toArray(),
            'source_hash' => $this->sourceHash,
            'total' => $total,
        ];
    }

    public function encode(string $appKey): string
    {
        $json = json_encode($this->fields(), self::JSON);

        return self::base64url($json).'.'.self::base64url(hash_hmac('sha256', $json, self::key($appKey), true));
    }

    /**
     * @throws BulkRefusal `frozen_invalid`
     */
    public static function verify(mixed $token, string $appKey): self
    {
        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token) !== 1) {
            throw self::invalid();
        }

        [$body, $signature] = explode('.', $token, 2);
        $json = self::decode($body);

        // Only the one canonical encoding: another base64 form of the same bytes (unused low bits of the last
        // character) is not the token this server gave out.
        if ($json === null || self::base64url($json) !== $body) {
            throw self::invalid();
        }

        if (! hash_equals(self::base64url(hash_hmac('sha256', $json, self::key($appKey), true)), $signature)) {
            throw self::invalid();
        }

        try {
            $fields = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalid();
        }

        if (! is_array($fields) || array_is_list($fields)) {
            throw self::invalid();
        }

        $keys = array_keys($fields);
        sort($keys);

        if ($keys !== self::KEYS) {
            throw self::invalid();
        }

        $total = $fields['total'];

        if (! Operations::validRef($fields['ref'])
            || ! in_array($fields['action'], [BulkRun::RETRY, BulkRun::FORGET], true)
            || ! is_string($fields['source_hash']) || preg_match('/^[0-9a-f]{64}$/D', $fields['source_hash']) !== 1
            || ! is_int($fields['max_occurrence']) || $fields['max_occurrence'] < 0
            || ! (is_int($fields['frozen_at']) || is_float($fields['frozen_at'])) || ! is_finite((float) $fields['frozen_at'])
            || ! is_bool($fields['exact'])
            || ! is_array($total) || count($total) !== 2
            || ! is_int($total['count'] ?? null) || $total['count'] < 0
            || ! is_bool($total['at_least'] ?? null)) {
            throw self::invalid();
        }

        try {
            $source = BulkSource::from($fields['source']);
        } catch (BulkRefusal) {
            throw self::invalid();
        }

        if (! hash_equals($source->hash(), $fields['source_hash'])) {
            throw self::invalid();
        }

        return new self(
            $fields['ref'],
            $fields['action'],
            $source,
            $fields['source_hash'],
            $fields['max_occurrence'],
            (float) $fields['frozen_at'],
            ['count' => $total['count'], 'at_least' => $total['at_least']],
            $fields['exact'],
        );
    }

    /**
     * The HMAC key: hash_hmac('sha256', DOMAIN, app.key).
     */
    public static function key(string $appKey): string
    {
        return hash_hmac('sha256', self::DOMAIN, $appKey);
    }

    private static function invalid(): BulkRefusal
    {
        return new BulkRefusal(BulkErrors::FROZEN_INVALID, 'The frozen selection is not one that this server signed: start a new one.');
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return is_string($bytes) ? $bytes : null;
    }
}
