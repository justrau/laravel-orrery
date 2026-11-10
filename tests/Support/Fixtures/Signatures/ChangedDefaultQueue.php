<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Signatures;

use Illuminate\Queue\DatabaseQueue;

/**
 * The mutation fixture of the signature audit: it changes only
 * the default of `later()`'s `$queue` (the parent's is null). The parameter
 * stays optional, so PHP loads the class and only the audit can see the
 * change.
 */
class ChangedDefaultQueue extends DatabaseQueue
{
    /**
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     */
    public function later($delay, $job, $data = '', $queue = 'changed')
    {
        return parent::later($delay, $job, $data, $queue);
    }
}
