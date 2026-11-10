<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Notifications;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store whose every read and write throws, as a store whose server
 * is down (StuckNotificationsTest).
 */
final class ThrowingStore implements Store
{
    public function get($key): mixed
    {
        throw new RuntimeException('The cache store is down.');
    }

    public function many(array $keys): array
    {
        throw new RuntimeException('The cache store is down.');
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException('The cache store is down.');
    }

    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException('The cache store is down.');
    }

    public function increment($key, $value = 1): int|bool
    {
        throw new RuntimeException('The cache store is down.');
    }

    public function decrement($key, $value = 1): int|bool
    {
        throw new RuntimeException('The cache store is down.');
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException('The cache store is down.');
    }

    public function forget($key): bool
    {
        throw new RuntimeException('The cache store is down.');
    }

    public function flush(): bool
    {
        throw new RuntimeException('The cache store is down.');
    }

    /**
     * Laravel 13's Store contract; Laravel 12 has no such method, and an extra one is allowed.
     */
    public function touch($key, $seconds): bool
    {
        throw new RuntimeException('The cache store is down.');
    }

    public function getPrefix(): string
    {
        return '';
    }
}
