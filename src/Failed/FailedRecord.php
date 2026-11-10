<?php

namespace Justrau\Orrery\Failed;

/**
 * One failed-job record, read by its exact identity (`id` and `uuid`):
 * every column as the failed-record table stores it.
 */
final class FailedRecord
{
    /**
     * @param  array<string, mixed>  $columns
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly array $columns,
    ) {}
}
