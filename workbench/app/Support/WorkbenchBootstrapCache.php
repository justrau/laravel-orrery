<?php

namespace Workbench\App\Support;

use RuntimeException;

/**
 * The owned locations of Laravel's five bootstrap cache files, and the boundary that pins them.
 *
 * Laravel takes each path from an environment variable (Application::normalizeCachePath()). It
 * uses an absolute value unchanged and resolves a relative value against the application base
 * path, which is Testbench's skeleton in vendor/orchestra/testbench-core/laravel. When each value
 * is consumed in the Workbench application:
 *  - APP_PACKAGES_CACHE: Testbench resolves the package manifest while it creates the application
 *    (PackageManifest::swap()), before it loads the testbench.yaml env list and before it turns off
 *    getenv() reads. So getenv(), $_SERVER and $_ENV all count here. Provider registration then
 *    includes the file, or builds and writes it when it is missing. `package:discover` rewrites it,
 *    and `clear-compiled` (part of `optimize:clear`) deletes it.
 *  - APP_SERVICES_CACHE: ProviderRepository includes the services manifest, and writes it when it
 *    is missing or the provider list changed, before any configured provider registers.
 *    `clear-compiled` deletes it.
 *  - APP_CONFIG_CACHE: Laravel's .env loader checks whether the file exists, and skips the .env
 *    file when it does. That check runs before the testbench.yaml env list loads, and only when
 *    the skeleton has a .env file. Testbench's configuration loader never includes the file.
 *    `config:cache` writes and includes it, and `config:clear` deletes it.
 *  - APP_ROUTES_CACHE: the package provider checks at boot whether the file exists, and then
 *    skips its routes. The Testbench application registers no route service provider, so nothing
 *    includes the file. `route:cache` writes it, and `route:clear` deletes it.
 *  - APP_EVENTS_CACHE: the Testbench application registers no event service provider, so only
 *    `event:cache` (writes it) and `event:clear` (deletes it) use it.
 * After Testbench turns off getenv() reads, Laravel reads the other four from $_SERVER and $_ENV.
 *
 * Testbench also creates a preliminary application to link the vendor directory. It has no
 * Workbench provider and no testbench.yaml env list, but it builds both manifests. So a service
 * provider is too late for them. The boundary is workbench/bin/testbench: pin() runs there before
 * any Testbench code, and sets all five absolute paths in putenv(), $_ENV and $_SERVER.
 *
 * The web server child of `serve` (php -S) inherits the pinned values in its process environment.
 * The package manifest path reaches it through getenv(). The built-in server puts no process
 * environment in $_SERVER or $_ENV, so the other four come from the testbench.yaml env list. Those
 * entries are relative to the skeleton, and resolve to the same owned files.
 *
 * The Workbench provider calls refuseForeign() and refuses to run when one path resolves
 * elsewhere, for example after a direct `vendor/bin/testbench` call. The refusal comes after the
 * early reads and writes, so it cannot undo them: it only makes the bypass fail loudly. The
 * check trusts the checkout: isOwned() describes the symbolic links that it does not refuse.
 *
 * This class has no Laravel dependency on purpose: the launcher uses it before Laravel loads.
 */
final class WorkbenchBootstrapCache
{
    /**
     * Variable => the Laravel application method that resolves it.
     */
    public const RESOLVERS = [
        'APP_SERVICES_CACHE' => 'getCachedServicesPath',
        'APP_PACKAGES_CACHE' => 'getCachedPackagesPath',
        'APP_CONFIG_CACHE' => 'getCachedConfigPath',
        'APP_ROUTES_CACHE' => 'getCachedRoutesPath',
        'APP_EVENTS_CACHE' => 'getCachedEventsPath',
    ];

    /**
     * Variable => file name, the same names that Laravel uses in bootstrap/cache.
     */
    public const FILES = [
        'APP_SERVICES_CACHE' => 'services.php',
        'APP_PACKAGES_CACHE' => 'packages.php',
        'APP_CONFIG_CACHE' => 'config.php',
        'APP_ROUTES_CACHE' => 'routes-v7.php',
        'APP_EVENTS_CACHE' => 'events.php',
    ];

    /**
     * The owned directory: <repo>/workbench/storage/bootstrap-cache (ignored by git).
     */
    public static function directory(): string
    {
        return implode(DIRECTORY_SEPARATOR, [dirname(__DIR__, 2), 'storage', 'bootstrap-cache']);
    }

    /**
     * @param  string|null  $directory  The owned directory; only tests pass another one.
     * @return array<string, string> Variable => owned absolute path.
     */
    public static function paths(?string $directory = null): array
    {
        $directory ??= self::directory();
        $paths = [];

        foreach (self::FILES as $variable => $file) {
            $paths[$variable] = $directory.DIRECTORY_SEPARATOR.$file;
        }

        return $paths;
    }

    /**
     * Create the owned directory when it is missing.
     */
    public static function ensureDirectory(): void
    {
        $directory = self::directory();

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the Workbench bootstrap cache directory [%s].', $directory));
        }
    }

    /**
     * Point all five variables at the owned files, whatever the environment says. Laravel's Env
     * repository reads $_SERVER and $_ENV (and getenv() until Testbench turns it off), and child
     * processes inherit the putenv() values.
     */
    public static function pin(): void
    {
        self::ensureDirectory();

        foreach (self::paths() as $variable => $path) {
            putenv($variable.'='.$path);
            $_ENV[$variable] = $path;
            $_SERVER[$variable] = $path;
        }
    }

    /**
     * Throw unless every resolved path, and the path that the package manifest uses, is the owned
     * file. The Workbench provider calls this with the five resolver results of the application.
     *
     * @param  array<string, string>  $resolved  Variable => the path that Laravel resolved now.
     * @param  string  $packageManifest  The path that the package manifest captured when Testbench
     *                                   created the application (PackageManifest::$manifestPath).
     * @param  string|null  $directory  The owned directory; only tests pass another one.
     *
     * @throws RuntimeException Naming the first variable whose path is not the owned file.
     */
    public static function refuseForeign(array $resolved, string $packageManifest, ?string $directory = null): void
    {
        $checks = [];

        foreach (array_keys(self::FILES) as $variable) {
            $checks[] = [$variable, $resolved[$variable] ?? ''];
        }

        $checks[] = ['APP_PACKAGES_CACHE', $packageManifest];

        foreach ($checks as [$variable, $path]) {
            if (! self::isOwned($variable, $path, $directory)) {
                throw new RuntimeException(sprintf(
                    'The Workbench bootstrap cache %s resolves to [%s], not to the owned [%s]. Start the Workbench CLI with `php workbench/bin/testbench`, which pins the five APP_*_CACHE paths before Laravel reads them.',
                    $variable,
                    $path,
                    self::paths($directory)[$variable],
                ));
            }
        }
    }

    /**
     * Whether a path that Laravel resolved for the variable is the owned file.
     *
     * The file name must match, and the real path of its parent directory must be the real path of
     * the owned directory. So the relative testbench.yaml values of the web server child match, and
     * a path in another directory does not. A missing directory never matches.
     *
     * The check resolves the parent directory, not the final entry, and it trusts the checkout. A
     * symbolic link at workbench/storage, at workbench/storage/bootstrap-cache, or as one cache file
     * inside that directory passes, and Laravel then reads and writes where the link points. The
     * boundary is against inherited environment values. Only the developer writes inside the
     * checkout, so links there are not part of it. The SQLite guard is stricter (WorkbenchDatabase
     * refuses links), because DB_DATABASE comes from the environment.
     *
     * @param  string|null  $directory  The owned directory; only tests pass another one.
     */
    public static function isOwned(string $variable, string $resolved, ?string $directory = null): bool
    {
        if (! isset(self::FILES[$variable]) || basename($resolved) !== self::FILES[$variable]) {
            return false;
        }

        $owned = realpath($directory ?? self::directory());
        $actual = realpath(dirname($resolved));

        return $owned !== false && $actual === $owned;
    }
}
