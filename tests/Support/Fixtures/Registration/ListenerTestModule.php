<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Registration;

use Illuminate\Contracts\Foundation\Application;
use Justrau\Orrery\Registration\Module;
use RuntimeException;

/**
 * A test module whose listener throws or returns a value,
 * added through Modules::addForTesting(). The test sets $event and $mode
 * before the provider registers.
 */
final class ListenerTestModule implements Module
{
    /** The event that the module listens to, or null for none. */
    public static ?string $event = null;

    /** throw, or return: the listener returns $value */
    public static string $mode = 'throw';

    public static mixed $value = null;

    public static int $calls = 0;

    public function register(Application $app): void
    {
    }

    public function boot(Application $app): void
    {
    }

    public function listeners(): array
    {
        return self::$event === null ? [] : [self::$event => [self::class]];
    }

    public function handle(mixed $event): mixed
    {
        self::$calls++;

        if (self::$mode === 'throw') {
            throw new RuntimeException('the module listener fails');
        }

        return self::$value;
    }

    public static function resetForTesting(): void
    {
        self::$calls = 0;
    }
}
