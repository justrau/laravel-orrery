<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Container\Container;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The package session name that an error of the mirror carries
 * (PackageSession::mirrorSessionName()), for the tests that open the mirror
 * breaker with a made-up dependency error: only the mirror's session opens
 * it, and the mirror is another session in the separate mode and on a
 * SQLite file (the sibling).
 */
final class MirrorSession
{
    public static function name(): string
    {
        return Container::getInstance()->make(PackageSession::class)->mirrorSessionName();
    }
}
