<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks\Jobs;

/**
 * A backed queue name whose value differs from its case name.
 */
enum QueueName: string
{
    case Emails = 'mail-out';
}
