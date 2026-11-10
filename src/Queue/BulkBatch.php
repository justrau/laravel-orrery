<?php

namespace Justrau\Orrery\Queue;

use Justrau\Orrery\Lifecycle\Observation;

/**
 * The bulkPush observations of one bulk insert: its queue
 * connection and queue, and its floor.
 *
 * @internal BulkResolver
 */
final class BulkBatch
{
    /** @var list<Observation> */
    private array $observations = [];

    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $floor,
    ) {
    }

    public function add(Observation $observation): void
    {
        $this->observations[] = $observation;
    }

    /**
     * @return list<Observation>
     */
    public function observations(): array
    {
        return $this->observations;
    }

    public function count(): int
    {
        return count($this->observations);
    }

    public static function key(Observation $observation): string
    {
        return $observation->uuid."\0".($observation->generation ?? '');
    }
}
