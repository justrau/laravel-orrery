<?php

namespace Justrau\Orrery;

use ArrayAccess;
use Illuminate\Support\Arr;

/**
 * @implements ArrayAccess<string, mixed>
 */
class JobPayload implements ArrayAccess
{
    public string $value;

    /**
     * @var array<string, mixed>
     */
    public array $decoded;

    public function __construct(string $value)
    {
        $this->value = $value;
        $this->decoded = json_decode($value, true) ?: [];
    }

    public function id(): string
    {
        return $this->decoded['uuid'] ?? $this->decoded['id'];
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return Arr::get($this->decoded, 'tags', []);
    }

    public function isSilenced(): bool
    {
        return (bool) ($this->decoded['silenced'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): static
    {
        $this->decoded = array_merge($this->decoded, $values);
        $this->value = (string) json_encode($this->decoded, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    public function commandName(): ?string
    {
        return Arr::get($this->decoded, 'data.commandName');
    }

    public function displayName(): ?string
    {
        return Arr::get($this->decoded, 'displayName');
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->decoded);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->decoded[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->decoded[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->decoded[$offset]);
    }
}
