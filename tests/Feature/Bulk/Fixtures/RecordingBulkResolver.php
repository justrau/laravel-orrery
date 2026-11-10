<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Bulk\Fixtures;

use Justrau\Orrery\Observation\DeferredWork;
use Justrau\Orrery\Queue\BulkResolver;
use RuntimeException;

/**
 * A BulkResolver that records each observation of a bulk insert (the
 * hook's deferred() call). With $throw, it throws before it builds
 * anything.
 */
class RecordingBulkResolver extends BulkResolver
{
    /** @var list<list<string>> the payloads of each observe() call */
    public array $observed = [];

    public bool $throw = false;

    public function deferred(string $connection, string $queue, array $payloads, int $floor, array $delays = []): ?DeferredWork
    {
        $this->observed[] = $payloads;

        if ($this->throw) {
            throw new RuntimeException('bulk observe failed');
        }

        return parent::deferred($connection, $queue, $payloads, $floor, $delays);
    }
}
