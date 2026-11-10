<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Bulk\Fixtures;

use Closure;
use Justrau\Orrery\Queue\BulkResolver;
use RuntimeException;

/**
 * A BulkResolver that records each observe() call. With $throw, observe()
 * throws before it builds anything.
 */
class RecordingBulkResolver extends BulkResolver
{
    /** @var list<list<string>> the payloads of each observe() call */
    public array $observed = [];

    public bool $throw = false;

    public function observe(string $connection, string $queue, array $payloads, int $floor, array $delays = []): ?Closure
    {
        $this->observed[] = $payloads;

        if ($this->throw) {
            throw new RuntimeException('bulk observe failed');
        }

        return parent::observe($connection, $queue, $payloads, $floor, $delays);
    }
}
