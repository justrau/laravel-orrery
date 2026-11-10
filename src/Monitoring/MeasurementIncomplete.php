<?php

namespace Justrau\Orrery\Monitoring;

use RuntimeException;

/**
 * The long-wait phase measured some queue connections only as unknown:
 * their backlog could not be read, or their queue object
 * could not be resolved. The phase alerts the readable rows first and then
 * throws this, so the tick records the phase as failed.
 */
final class MeasurementIncomplete extends RuntimeException
{
    /**
     * @param  list<string>  $targets  the primary connection names of the unknown rows
     */
    public function __construct(public readonly array $targets)
    {
        parent::__construct('The backlog of these queue connections could not be measured: '.implode(', ', $targets).'.');
    }
}
