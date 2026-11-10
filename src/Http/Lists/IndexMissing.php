<?php

namespace Justrau\Orrery\Http\Lists;

use Justrau\Orrery\Database\Schema\DeferredIndexes;
use RuntimeException;

/**
 * A list whose deferred index (PackageSchema::DEFERRED_INDEXES)
 * is missing: HTTP 503 `index_missing` with the build command as the
 * hint. The list never reads without its index.
 */
final class IndexMissing extends RuntimeException
{
    public function __construct(public readonly string $index)
    {
        parent::__construct("The orrery_jobs index {$index} that this list reads is missing. Run ".DeferredIndexes::COMMAND.'.');
    }
}
