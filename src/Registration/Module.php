<?php

namespace Justrau\Orrery\Registration;

use Illuminate\Contracts\Foundation\Application;

/**
 * A registration module (one of nine).
 *
 * Every class in src/Registration/Modules/ implements this interface and has
 * one line in OrreryServiceProvider::MODULES. The service provider calls the
 * modules in that order (Modules::all()), before its own legacy
 * registrations.
 */
interface Module
{
    /**
     * Bindings, singletons and tick phase tags.
     */
    public function register(Application $app): void;

    /**
     * Connection callbacks, commands and schedule entries. The provider
     * registers this module's listeners() just before it calls boot().
     */
    public function boot(Application $app): void;

    /**
     * @return array<class-string, list<class-string>> event => listeners
     */
    public function listeners(): array;

    /**
     * Resets the static state that the module owns: only the allow-listed
     * statics (StaticStateInventoryTest), because every other state is a
     * container binding and a new application has new ones.
     * The test reset hook calls it before and after every test.
     */
    public static function resetForTesting(): void;
}
