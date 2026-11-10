<?php

namespace Justrau\Orrery\Http\Bulk;

use Justrau\Orrery\Failures\FailureScope;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Failures\InvalidFailureRequest;
use Justrau\Orrery\Observation\TagNormalizer;

/**
 * The source of a bulk run: the predicate that a freeze
 * signs.
 *
 * - `groups`: `{kind, queue, from, to, keys}`: the failure groups of these
 *   keys (1 to 200, through the stored failure_key) in the scope of
 *   FailureScope (an optional `connection:queue`, the window of failed_at,
 *   at most 7 days).
 * - `list`: `{kind, connection, queue, class, tag, from, to}`: the failed
 *   tab's filters (each optional; `queue` needs `connection`; `from` and
 *   `to` on failed_at, half-open).
 * - `batch`: `{kind, batch_id}`: the failed members of a batch.
 *
 * Every text is compared byte for byte: valid UTF-8 without a NUL byte, never
 * trimmed. A tag is normalised as stored (TagNormalizer). A key that the
 * kind does not know, or a value of another type, is refused (`invalid`,
 * with the field).
 *
 * The canonical form is the source with every key, its keys sorted, the
 * times as floats; its JSON (without escaped slashes or unicode, with a zero
 * fraction kept) is what the token signs and the audit row stores, and
 * source_hash is its sha256.
 */
final class BulkSource
{
    public const GROUPS = 'groups';

    public const LIST = 'list';

    public const BATCH = 'batch';

    public const BATCH_MAX = 255;

    /** The most group keys of a groups source (its own limit; the explicit-list endpoints are gone). */
    public const MAX_KEYS = 200;

    /** The longest texts, in characters: the widths of their orrery_jobs columns. */
    public const MAX_CHARACTERS = ['connection' => 191, 'queue' => 255, 'class' => 255, 'tag' => 255, 'batch_id' => 255];

    public const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private const KEYS = [
        self::GROUPS => ['kind', 'queue', 'from', 'to', 'keys'],
        self::LIST => ['kind', 'connection', 'queue', 'class', 'tag', 'from', 'to'],
        self::BATCH => ['kind', 'batch_id'],
    ];

    private const EPOCH = '/^\d{1,12}(\.\d{1,6})?$/D';

    /**
     * @param  list<string>  $keys
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?FailureScope $scope = null,
        public readonly array $keys = [],
        public readonly ?string $connection = null,
        public readonly ?string $queue = null,
        public readonly ?string $class = null,
        public readonly ?string $tag = null,
        public readonly ?float $from = null,
        public readonly ?float $to = null,
        public readonly ?string $batchId = null,
    ) {
    }

    /**
     * @throws BulkRefusal `invalid`, with the field
     */
    public static function from(mixed $source): self
    {
        if (! is_array($source) || (array_is_list($source) && $source !== [])) {
            throw BulkRefusal::invalid('source', 'source is an object with a kind: groups, list or batch.');
        }

        $kind = $source['kind'] ?? null;

        if (! is_string($kind) || ! isset(self::KEYS[$kind])) {
            throw BulkRefusal::invalid('source.kind', 'source.kind is groups, list or batch.');
        }

        foreach (array_keys($source) as $key) {
            if (! in_array($key, self::KEYS[$kind], true)) {
                throw BulkRefusal::invalid('source.'.$key, "source.{$key} is not a field of a {$kind} source.");
            }
        }

        return match ($kind) {
            self::GROUPS => self::groups($source),
            self::LIST => self::list($source),
            default => self::batch($source),
        };
    }

    /**
     * The canonical form: every key of the kind, sorted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $source = match ($this->kind) {
            self::GROUPS => [
                'kind' => self::GROUPS,
                'queue' => $this->scope?->queueKey(),
                'from' => $this->scope?->from,
                'to' => $this->scope?->to,
                'keys' => $this->keys,
            ],
            self::LIST => [
                'kind' => self::LIST,
                'connection' => $this->connection,
                'queue' => $this->queue,
                'class' => $this->class,
                'tag' => $this->tag,
                'from' => $this->from,
                'to' => $this->to,
            ],
            default => ['kind' => self::BATCH, 'batch_id' => $this->batchId],
        };

        ksort($source);

        return $source;
    }

    public function json(): string
    {
        return json_encode($this->toArray(), self::JSON);
    }

    public function hash(): string
    {
        return hash('sha256', $this->json());
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private static function groups(array $source): self
    {
        try {
            $scope = FailureScope::from($source['queue'] ?? null, $source['from'] ?? null, $source['to'] ?? null);
        } catch (InvalidFailureRequest $error) {
            throw BulkRefusal::invalid('source', $error->getMessage());
        }

        if (($scope->connection !== null && mb_strlen($scope->connection) > self::MAX_CHARACTERS['connection'])
            || ($scope->queue !== null && mb_strlen($scope->queue) > self::MAX_CHARACTERS['queue'])) {
            throw BulkRefusal::invalid('source.queue', 'source.queue is a connection of at most 191 characters and a queue of at most 255.');
        }

        $keys = $source['keys'] ?? null;

        if (! is_array($keys) || ! array_is_list($keys) || $keys === [] || count($keys) > self::MAX_KEYS) {
            throw BulkRefusal::invalid('source.keys', 'source.keys is a list of 1 to 200 group keys.');
        }

        foreach ($keys as $key) {
            if (! GroupKey::valid($key)) {
                throw BulkRefusal::invalid('source.keys', 'Each group key is 64 lowercase hexadecimal characters.');
            }
        }

        /** @var list<string> $keys */
        return new self(self::GROUPS, scope: $scope, keys: array_values(array_unique($keys)));
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private static function list(array $source): self
    {
        $connection = self::exact($source, 'connection');
        $queue = self::exact($source, 'queue');
        $class = self::exact($source, 'class');

        if ($queue !== null && $connection === null) {
            throw BulkRefusal::invalid('source.queue', 'source.queue needs source.connection.');
        }

        $tag = null;

        if (($source['tag'] ?? null) !== null) {
            if (is_string($source['tag']) && mb_strlen($source['tag']) > self::MAX_CHARACTERS['tag']) {
                throw BulkRefusal::invalid('source.tag', 'source.tag has at most 255 characters.');
            }

            $tag = is_string($source['tag']) ? TagNormalizer::normalize($source['tag']) : null;

            if ($tag === null) {
                throw BulkRefusal::invalid('source.tag', 'source.tag is a non-empty tag.');
            }
        }

        $from = self::epoch($source, 'from');
        $to = self::epoch($source, 'to');

        if ($from !== null && $to !== null && ! ($from < $to)) {
            throw BulkRefusal::invalid('source.from', 'source.from must be before source.to.');
        }

        return new self(self::LIST, connection: $connection, queue: $queue, class: $class, tag: $tag, from: $from, to: $to);
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private static function batch(array $source): self
    {
        $batch = self::exact($source, 'batch_id');

        if ($batch === null) {
            throw BulkRefusal::invalid('source.batch_id', 'source.batch_id is a batch id of 1 to 255 characters.');
        }

        return new self(self::BATCH, batchId: $batch);
    }

    /**
     * A byte-exact text, or null when absent, null or empty.
     *
     * @param  array<array-key, mixed>  $source
     */
    private static function exact(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            throw BulkRefusal::invalid('source.'.$key, "source.{$key} is valid UTF-8 text without a NUL byte.");
        }

        $max = self::MAX_CHARACTERS[$key] ?? 255;

        if (mb_strlen($value) > $max) {
            throw BulkRefusal::invalid('source.'.$key, "source.{$key} has at most {$max} characters.");
        }

        return $value;
    }

    /**
     * Epoch seconds (a number, or a numeric string), or null when absent.
     *
     * @param  array<array-key, mixed>  $source
     */
    private static function epoch(array $source, string $key): ?float
    {
        $value = $source[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0) {
            return (float) $value;
        }

        if (is_string($value) && preg_match(self::EPOCH, $value) === 1) {
            return (float) $value;
        }

        throw BulkRefusal::invalid('source.'.$key, "source.{$key} is epoch seconds, for example 1790000000 or 1790000000.25.");
    }
}
