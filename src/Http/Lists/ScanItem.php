<?php

namespace Justrau\Orrery\Http\Lists;

use stdClass;

/**
 * One row of a list scan (Scan): its order value (a decimal from
 * Clock::toDecimal(), or null in a null-order group), its key (the id, or
 * the job_uuid of a tag source), the mirror row's SCAN_COLUMNS (null when a
 * tag row has no job row), and whether the row has the tag of a search text.
 */
final class ScanItem
{
    public function __construct(
        public readonly ?string $o,
        public readonly int|string $k,
        public readonly ?stdClass $job,
        public bool $qtag = false,
    ) {
    }
}
