<?php

namespace Justrau\Orrery\Http\Projection;

/**
 * One entry of a ParsedNode: an array entry (its key) or an object property
 * (its logical name, visibility and declaring class). $key is the array key
 * or the logical property name; a key that is not valid UTF-8 or is too
 * long is a marker array, never its bytes.
 */
final class ParsedEntry
{
    public const PUBLIC = 'public';

    public const PROTECTED = 'protected';

    public const PRIVATE = 'private';

    /**
     * @param  int|string|array<string, int|string>  $key
     */
    public function __construct(
        public readonly int|string|array $key,
        public readonly mixed $value,
        public readonly ?string $visibility = null,
        public readonly ?string $declaredIn = null,
    ) {
    }

    /**
     * @param  int|string|array<string, int|string>  $key
     */
    public function with(int|string|array $key, mixed $value): self
    {
        return new self($key, $value, $this->visibility, $this->declaredIn);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $property): array
    {
        if (! $property) {
            return ['key' => $this->key, 'value' => $this->value];
        }

        return ['name' => $this->key, 'visibility' => $this->visibility]
            + ($this->declaredIn === null ? [] : ['declared_in' => $this->declaredIn])
            + ['value' => $this->value];
    }
}
