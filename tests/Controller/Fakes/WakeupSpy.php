<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller\Fakes;

/**
 * A batch option that counts its unserializations: a viewer read of the
 * batches must never wake it. Tests reset the
 * counter before they read.
 */
final class WakeupSpy
{
    public static int $woken = 0;

    public string $value = 'spy';

    public function __wakeup(): void
    {
        self::$woken++;
    }
}
