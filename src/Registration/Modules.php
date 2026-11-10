<?php

namespace Justrau\Orrery\Registration;

use Illuminate\Contracts\Foundation\Application;
use Justrau\Orrery\OrreryServiceProvider;
use Justrau\Orrery\Runtime\Watcher;
use LogicException;
use Throwable;

/**
 * The registration modules, in the order of OrreryServiceProvider::MODULES.
 *
 * The list is explicit: the boot reads no directory. Tests can insert their
 * own modules before a listed module, or at the end.
 */
final class Modules
{
    /** @var list<array{class-string<Module>, class-string<Module>|null}> each test module and its anchor */
    private static array $extra = [];

    /**
     * A new instance of every module, in registration order.
     *
     * @return list<Module>
     */
    public static function all(): array
    {
        return array_map(static fn (string $class): Module => new $class, self::classes());
    }

    /**
     * Calls resetForTesting() of every module, and removes the test modules.
     */
    public static function resetAllForTesting(): void
    {
        foreach (self::classes() as $class) {
            $class::resetForTesting();
        }

        self::$extra = [];
    }

    /**
     * Adds a test module. It registers just before $before, a listed module,
     * or after every listed module when $before is null. Two test modules
     * with the same anchor keep the order of their calls.
     */
    public static function addForTesting(string $module, ?string $before = null): void
    {
        self::assertModule($module);

        if ($before !== null && ! in_array($before, OrreryServiceProvider::MODULES, true)) {
            throw new LogicException("{$before} is not in OrreryServiceProvider::MODULES, so a test module cannot register before it.");
        }

        /** @var class-string<Module>|null $before */
        self::$extra[] = [$module, $before];
    }

    /**
     * Resolves process state on the base application, in a module's boot().
     * An Octane sandbox is a clone of
     * the base application: it shares the instances that the base resolved
     * before the clone, and a singleton that a sandbox resolves first lives
     * for one request only. The constructors open no connection and send no
     * SQL (BindingLifetimeTest). A class that cannot be built here is
     * reported, and built on its first use instead: the boot of the host
     * never fails.
     *
     * @param  list<class-string>  $bindings
     */
    public static function resolveAtBoot(Application $app, array $bindings): void
    {
        foreach ($bindings as $binding) {
            try {
                $app->make($binding);
            } catch (Throwable $error) {
                // Resolved on its first use; the failure is reported, never silent.
                try {
                    Watcher::instance()->report('boot:resolve:'.$binding, $error);
                } catch (Throwable) {
                    try {
                        report($error);
                    } catch (Throwable) {
                        // Nothing is left to report to.
                    }
                }
            }
        }
    }

    /**
     * @return list<class-string<Module>>
     */
    private static function classes(): array
    {
        $classes = [];

        foreach (OrreryServiceProvider::MODULES as $listed) {
            foreach (self::$extra as [$module, $before]) {
                if ($before === $listed) {
                    $classes[] = $module;
                }
            }

            $classes[] = $listed;
        }

        foreach (self::$extra as [$module, $before]) {
            if ($before === null) {
                $classes[] = $module;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @phpstan-assert class-string<Module> $class
     */
    private static function assertModule(string $class): void
    {
        if (! is_subclass_of($class, Module::class)) {
            throw new LogicException("{$class} is not a registration module: it must implement ".Module::class.'.');
        }
    }
}
