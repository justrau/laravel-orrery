<?php

namespace Justrau\Orrery\Failed;

/**
 * The occurrence slot of the id binding: `uuid →
 * occurrence_id` of the failures whose fail or failWithoutRow write created
 * an occurrence in this unit of work.
 *
 * The package's failure listener (ObserveJobFailed, registered at boot) runs
 * before WorkCommand's logger, so it puts the entry first; the package's
 * provider (CapturingUuidFailedJobProvider::log()) takes it once, after its
 * insert. A write that is rejected, only accounted or dropped puts nothing.
 *
 * Unit-of-work state: a scoped binding, so the worker's flush at the start
 * of each loop (and the end of a request) clears it. It holds at most
 * LIMIT entries; the oldest goes first.
 */
final class OccurrenceSlot
{
    public const LIMIT = 1000;

    /** @var array<string, int> */
    private array $entries = [];

    public function put(string $uuid, int $occurrenceId): void
    {
        unset($this->entries[$uuid]);
        $this->entries[$uuid] = $occurrenceId;

        while (count($this->entries) > self::LIMIT) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }

    /**
     * The occurrence of $uuid, once: the entry goes.
     */
    public function take(string $uuid): ?int
    {
        $occurrence = $this->entries[$uuid] ?? null;
        unset($this->entries[$uuid]);

        return $occurrence;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
