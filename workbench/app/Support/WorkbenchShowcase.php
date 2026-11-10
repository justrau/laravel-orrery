<?php

namespace Workbench\App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The showcase mode of the Workbench (WORKBENCH_SHOWCASE=1): the small shop app of
 * ShowcaseSeeder, for the screenshots of the README and the docs. It is off unless the variable
 * is set, so the default mode, the strict-auth mode and their fixtures do not change.
 *
 * The mode runs in the production environment under an application name, as a real application
 * does, and lets every visitor view and operate the dashboard (there is no login).
 */
final class WorkbenchShowcase
{
    public const VARIABLE = 'WORKBENCH_SHOWCASE';

    public const ON = '1';

    public const APP_NAME = 'Acme Shop';

    public const ENVIRONMENT = 'production';

    /**
     * Whether the showcase mode is on. Any value other than unset, empty or `1` throws, so a
     * typo never falls back to another mode.
     */
    public static function enabled(): bool
    {
        return self::parse(ProcessEnvironment::get(self::VARIABLE));
    }

    public static function parse(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if ($value === self::ON) {
            return true;
        }

        throw new RuntimeException(sprintf('The Workbench refuses %s=[%s]: leave it unset, or set it to `%s`.', self::VARIABLE, $value, self::ON));
    }

    public static function apply(Repository $config): void
    {
        $config->set('app.name', self::APP_NAME);
        $config->set('app.debug', false);

        // One queue connection, as a small shop has: without the Workbench's second
        // connection on the same jobs table, the dashboard shows no shared-table note.
        $connections = (array) $config->get('queue.connections', []);
        unset($connections['database-secondary']);
        $config->set('queue.connections', $connections);
    }

    public static function defineGates(): void
    {
        Gate::define(WorkbenchAuth::VIEW, static fn ($user = null): bool => true);
        Gate::define(WorkbenchAuth::OPERATE, static fn ($user = null): bool => true);
    }
}
