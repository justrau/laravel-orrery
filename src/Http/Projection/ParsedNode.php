<?php

namespace Justrau\Orrery\Http\Projection;

use JsonSerializable;

/**
 * A structured value of the data-only parser (SerializedValueParser): a PHP
 * array, an object, or a string that held a nested serialized value. The
 * redactor (Redactor::tree()) walks it and gives plain JSON data; the JSON
 * shapes:
 *
 * - array: {"array": [{"key": <int|string>, "value": …}, …]}
 * - object: {"class": "<class>", "properties": [{"name": "<logical name>",
 *   "visibility": "public" | "protected" | "private", "declared_in":
 *   "<class>" (private only), "value": …}, …]}
 * - serialized: {"serialized": …}, the value that a string held.
 */
final class ParsedNode implements JsonSerializable
{
    public const ARRAY = 'array';

    public const OBJECT = 'object';

    public const SERIALIZED = 'serialized';

    /**
     * @param  list<ParsedEntry>  $entries  the entries of an array, the properties of an object
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $class,
        public readonly array $entries,
        public readonly mixed $value,
    ) {
    }

    /**
     * @param  list<ParsedEntry>  $entries
     */
    public static function array(array $entries): self
    {
        return new self(self::ARRAY, null, $entries, null);
    }

    /**
     * @param  list<ParsedEntry>  $properties
     */
    public static function object(string $class, array $properties): self
    {
        return new self(self::OBJECT, $class, $properties, null);
    }

    public static function serialized(mixed $value): self
    {
        return new self(self::SERIALIZED, null, [], $value);
    }

    /**
     * The JSON shape without redaction (tests and diagnostics only; a
     * response always goes through Redactor::tree()).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return match ($this->kind) {
            self::ARRAY => ['array' => array_map(static fn (ParsedEntry $entry): array => $entry->toArray(false), $this->entries)],
            self::OBJECT => ['class' => $this->class, 'properties' => array_map(static fn (ParsedEntry $entry): array => $entry->toArray(true), $this->entries)],
            default => ['serialized' => $this->value],
        };
    }
}
