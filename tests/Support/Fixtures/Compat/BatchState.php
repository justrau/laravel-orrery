<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Compat;

/**
 * A backed enum batch option: its JSON is "ready",
 * and the token scrubber must keep that.
 */
enum BatchState: string
{
    case Ready = 'ready';
}
