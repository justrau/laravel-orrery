<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller\Fakes;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/**
 * An array cache store whose reads or writes can fail, for the
 * MeasurementCache and NativePause error paths.
 */
final class FailingCacheStore extends ArrayStore
{
    public bool $failGet = false;

    public bool $failPut = false;

    /** The number of store round trips: get() and many() calls from outside. */
    public int $reads = 0;

    /**
     * @param  string  $key
     */
    public function get($key): mixed
    {
        $this->reads++;

        if ($this->failGet) {
            throw new RuntimeException('The cache store cannot be read.');
        }

        return parent::get($key);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $this->reads++;

        if ($this->failGet) {
            throw new RuntimeException('The cache store cannot be read.');
        }

        // ArrayStore::many() calls get() per key: one store round trip counts once.
        $reads = $this->reads;
        $values = parent::many($keys);
        $this->reads = $reads;

        return $values;
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     */
    public function put($key, $value, $seconds): bool
    {
        if ($this->failPut) {
            throw new RuntimeException('The cache store cannot be written.');
        }

        return parent::put($key, $value, $seconds);
    }
}
