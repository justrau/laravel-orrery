<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Registration;

use Illuminate\Contracts\Foundation\Application;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Storage\PackageSession;

/**
 * A test module, added through Modules::addForTesting() (ModulesTest adds it
 * before StorageModule).
 *
 * It logs its register() and boot() calls, and whether StorageModule had
 * registered Sessions at that moment. It is also its own
 * listener of $event, so a test can see where module listeners run.
 */
class EarlyTestModule implements Module
{
    /** @var list<string> */
    public static array $log = [];

    /** @var array<class-string, int> the resetForTesting() calls per module class */
    public static array $resets = [];

    /** The event that the module listens to, or null for none. */
    public static ?string $event = null;

    public function register(Application $app): void
    {
        self::$log[] = static::label().'.register sessions='.($app->bound(PackageSession::class) ? 'bound' : 'unbound');
    }

    public function boot(Application $app): void
    {
        self::$log[] = static::label().'.boot';
    }

    public function listeners(): array
    {
        return self::$event === null ? [] : [self::$event => [static::class]];
    }

    public function handle(mixed $event): void
    {
        self::$log[] = static::label().'.listener';
    }

    public static function resetForTesting(): void
    {
        self::$resets[static::class] = (self::$resets[static::class] ?? 0) + 1;
    }

    protected static function label(): string
    {
        return 'Early';
    }
}
