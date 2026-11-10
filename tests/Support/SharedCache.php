<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

/**
 * A per-test `file` cache store that the test process and its worker
 * processes share. Laravel keeps its native queue pause
 * (`QueueManager::pause()`, `isPaused()`) in the default cache store, so a
 * process test of the native pause needs a store that every process reads.
 *
 * The harness pins `cache.default = array` (TestEnvironment::apply()). A
 * test opts in with TestCase::useSharedCache(): it creates the private
 * directory orrery-cache-<16 hex> (mode 0700) in the temporary directory,
 * the test process switches its default store to the `file` store STORE
 * there, and TestEnvironment::withSharedCache() hands the directory to each
 * child through VARIABLE. The child's identity line then names the store and
 * its path (IdentityRecord), so a child on another store is refused.
 *
 * TestCase::tearDown() removes the directories (removeAll()) only after the
 * workers are proved stopped.
 */
final class SharedCache
{
    public const VARIABLE = 'ORRERY_TEST_SHARED_CACHE';

    /** The name of the shared store in cache.stores. */
    public const STORE = 'orrery_shared';

    public const DIRECTORY_PATTERN = '/^orrery-cache-[0-9a-f]{16}$/D';

    /** @var array<string, true> the directories that create() made in this process */
    private static array $created = [];

    /**
     * Creates a new private directory and returns its canonical path.
     */
    public static function create(): string
    {
        $parent = realpath(sys_get_temp_dir());

        if ($parent === false || ! is_dir($parent)) {
            throw new HarnessRefusal('The temporary directory '.sys_get_temp_dir().' does not exist.');
        }

        $directory = $parent.'/orrery-cache-'.bin2hex(random_bytes(8));
        $umask = umask(0077);

        try {
            if (! @mkdir($directory, 0700)) {
                throw new HarnessRefusal("Cannot create the shared cache directory {$directory}.");
            }
        } finally {
            umask($umask);
        }

        self::$created[$directory] = true;

        return self::validate($directory);
    }

    /**
     * The canonical path of a valid shared cache directory: absolute,
     * canonical, named orrery-cache-<16 hex>, owned by this user and private.
     *
     * @throws HarnessRefusal
     */
    public static function validate(string $directory): string
    {
        if ($directory === '' || $directory[0] !== '/' || realpath($directory) !== $directory) {
            throw new HarnessRefusal("A shared cache directory must be an absolute, canonical path, not '{$directory}'.");
        }

        if (preg_match(self::DIRECTORY_PATTERN, basename($directory)) !== 1) {
            throw new HarnessRefusal("A shared cache directory must be named orrery-cache-<16 hex>: {$directory}.");
        }

        clearstatcache();
        $stat = @lstat($directory);

        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw new HarnessRefusal("The shared cache directory {$directory} does not exist, or it is not a directory.");
        }

        if ($stat['uid'] !== (function_exists('posix_geteuid') ? posix_geteuid() : (int) trim((string) shell_exec('id -u')))) {
            throw new HarnessRefusal("The shared cache directory {$directory} is not owned by this user.");
        }

        if (($stat['mode'] & 0077) !== 0) {
            throw new HarnessRefusal(sprintf('The shared cache directory %s has mode %o; it must be private (0700).', $directory, $stat['mode'] & 0777));
        }

        return $directory;
    }

    /**
     * The definition of the shared store in cache.stores.
     *
     * @return array{driver: string, path: string, lock_path: string}
     */
    public static function store(string $directory): array
    {
        return ['driver' => 'file', 'path' => $directory, 'lock_path' => $directory];
    }

    /**
     * Removes every directory that create() made in this process, with the
     * files of the store in it. A symlink inside is removed, never followed.
     */
    public static function removeAll(): void
    {
        foreach (array_keys(self::$created) as $directory) {
            self::removeTree($directory);
            unset(self::$created[$directory]);
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeTree($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
