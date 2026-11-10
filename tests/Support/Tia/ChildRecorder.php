<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Tia;

/**
 * The recorder of a child process during bin/tia record. Plain PHP without
 * the autoloader: it runs before anything else is loaded.
 *
 * Every PHP child of a recording PHPUnit process starts it: Recorder adds a
 * scan directory to PHP_INI_SCAN_DIR whose ini file sets auto_prepend_file to
 * the recording's prepend.php, which calls startFromEnvironment() with the
 * recording directory. (A child started with `php -n` reads no ini file and
 * records nothing; in this repository only bin/lib/testdb.php runs that way.)
 * The worker CLI (tests/Support/bin/artisan) also calls it, for a PHP binary
 * that ignores the scan directory.
 *
 * The child belongs to one test class: the one named by ORRERY_TIA_CHILD
 * (WorkerProcess hands it on for each worker), else the class that runs in
 * the parent when the child starts (the parent's "context" file). It writes
 * "<prefix>-<pid>.start" at once and "<prefix>-<pid>.json" with the files
 * that it included at shutdown and at each flush() (the worker CLI flushes
 * before a hold point, because a held worker can be killed). A child includes
 * every file that it runs, so its included files are a superset of its code:
 * no Xdebug is needed, and the children run at their normal speed. A start
 * file without its JSON file is a killed child: MapBuilder makes its class
 * incomplete, unless its script includes no other file. The worker CLI also
 * flushes on WorkerStopping and JobTimedOut, which Laravel's worker
 * dispatches before its timeout kills it.
 */
final class ChildRecorder
{
    public const VARIABLE = 'ORRERY_TIA_CHILD';

    public const DIRECT = 'ORRERY_TIA_DIRECT';

    /** The repository root; Recorder passes its own (fixture recordings use another). */
    public const ROOT = 'ORRERY_TIA_ROOT';

    private static ?string $prefix = null;

    private static bool $direct = false;

    private static string $root = '';

    /** @var array<string, string|null> */
    private static array $paths = [];

    /**
     * Starts recording this process: for the prefix of ORRERY_TIA_CHILD, else
     * for the current context of the recording in $directory. Without either
     * it does nothing.
     */
    public static function startFromEnvironment(?string $directory = null, ?string $root = null): void
    {
        if (self::$prefix !== null) {
            return;
        }

        $prefix = getenv(self::VARIABLE);

        if (! is_string($prefix) || $prefix === '') {
            $context = $directory === null ? false : @file_get_contents($directory.'/context');

            if (! is_string($context) || $context === '') {
                return;
            }

            $prefix = $directory.'/children/'.$context;
        }

        self::$prefix = $prefix;
        self::$direct = getenv(self::DIRECT) === '1';
        self::$root = $root ?? self::root();

        // The start file names the main script, so a killed child still says what it was.
        @file_put_contents($prefix.'-'.getmypid().'.start', self::script());

        // The processes that this one starts are not the direct child.
        putenv(self::DIRECT.'=0');
        $_ENV[self::DIRECT] = '0';
        $_SERVER[self::DIRECT] = '0';

        register_shutdown_function(static function (): void {
            self::flush();
        });
    }

    /**
     * Writes the files that this process included so far (the file holds the
     * union, so a later flush replaces it).
     */
    public static function flush(): void
    {
        if (self::$prefix === null) {
            return;
        }

        $file = self::$prefix.'-'.getmypid().'.json';
        $json = json_encode([
            'pid' => getmypid(),
            'direct' => self::$direct,
            'script' => self::script(),
            'files' => self::relevant(get_included_files(), self::$root, self::$paths),
        ], JSON_UNESCAPED_SLASHES);

        if (is_string($json) && @file_put_contents($file.'.tmp', $json) !== false) {
            @rename($file.'.tmp', $file);
        }
    }

    public static function active(): bool
    {
        return self::$prefix !== null;
    }

    /**
     * The real path of the main script ('' for php -r and standard input).
     */
    private static function script(): string
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';

        return is_string($script) && $script !== '' ? (realpath($script) ?: $script) : '';
    }

    /**
     * The paths relative to $root of the files that matter for the map: not
     * vendor/, not the recorder itself, and a compiled Blade view as its
     * source view.
     *
     * @param  iterable<string>  $paths
     * @param  array<string, string|null>  $cache
     * @return list<string>
     */
    public static function relevant(iterable $paths, string $root, array &$cache): array
    {
        $relevant = [];

        foreach ($paths as $path) {
            if (! array_key_exists($path, $cache)) {
                $cache[$path] = self::relative($path, $root);
            }

            if ($cache[$path] !== null) {
                $relevant[$cache[$path]] = true;
            }
        }

        return array_keys($relevant);
    }

    public static function root(): string
    {
        $root = getenv(self::ROOT);

        if (! is_string($root) || $root === '') {
            $root = dirname(__DIR__, 3);
        }

        return realpath($root) ?: $root;
    }

    /**
     * Limits Xdebug's coverage to the top-level entries of $root other than
     * vendor/ and the dot directories: the vendor code is most of what runs,
     * and the map never holds it. A compiled Blade view outside the root is
     * then not covered, but its include still counts.
     */
    public static function filter(string $root): void
    {
        $include = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== 'vendor' && ! str_starts_with($entry, '.')) {
                $include[] = $root.'/'.$entry.(is_dir($root.'/'.$entry) ? '/' : '');
            }
        }

        xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, $include);
    }

    public static function coverageAvailable(): bool
    {
        return function_exists('xdebug_info') && function_exists('xdebug_start_code_coverage') && in_array('coverage', (array) xdebug_info('mode'), true);
    }

    private static function relative(string $path, string $root): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $real = realpath($path) ?: $path;

        // A compiled Blade view ends with the path of its source.
        if (str_contains($real, '/storage/framework/views/')) {
            $handle = @fopen($real, 'rb');

            if ($handle === false) {
                return null;
            }

            $size = (int) (fstat($handle)['size'] ?? 0);
            fseek($handle, max(0, $size - 1024));
            $tail = (string) stream_get_contents($handle);
            fclose($handle);

            if (preg_match('~/\*\*PATH (.+?) ENDPATH\*\*/~', $tail, $match) !== 1) {
                return null;
            }

            $real = realpath($match[1]) ?: $match[1];
        }

        if (! str_starts_with($real, $root.'/')) {
            return null;
        }

        $relative = substr($real, strlen($root) + 1);

        if (str_starts_with($relative, 'vendor/') || str_starts_with($relative, 'tests/Support/Tia/') || str_starts_with($relative, '.tia/')) {
            return null;
        }

        return $relative;
    }
}
