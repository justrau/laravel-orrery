<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

/**
 * Owned SQLite files for the tests.
 *
 * A test SQLite file lives in a private directory named orrery-sqlite-<id>,
 * together with its mirror file <file>.mirror. bin/test --sqlite-file creates
 * such a directory for a run, and create() creates one for in-process use.
 *
 * validate() accepts a file only when:
 * - the path is absolute and canonical (no symlink in any component);
 * - the file is a regular file, not a symlink, owned by this user;
 * - its directory is named orrery-sqlite-*, is owned by this user, and is not
 *   accessible to anybody else;
 * - with a separate mirror, <file>.mirror passes the same checks.
 */
final class SqliteFiles
{
    public const DIRECTORY_PATTERN = '/^orrery-sqlite-[A-Za-z0-9_]+$/D';

    public const PRIMARY = 'primary.sqlite';

    /**
     * Creates a new owned directory with both files, and returns the
     * canonical path of the primary file.
     */
    public static function create(?string $parent = null): string
    {
        $parent ??= sys_get_temp_dir();
        $real = realpath($parent);

        if ($real === false || ! is_dir($real)) {
            throw new HarnessRefusal("Cannot create a SQLite test directory in {$parent}: it does not exist.");
        }

        $umask = umask(0077);

        try {
            $directory = $real.'/orrery-sqlite-'.bin2hex(random_bytes(8));

            if (! mkdir($directory, 0700)) {
                throw new HarnessRefusal("Cannot create {$directory}.");
            }

            foreach ([self::PRIMARY, self::PRIMARY.'.mirror'] as $name) {
                if (file_put_contents($directory.'/'.$name, '') !== 0) {
                    throw new HarnessRefusal("Cannot create {$directory}/{$name}.");
                }
            }
        } finally {
            umask($umask);
        }

        return self::validate($directory.'/'.self::PRIMARY, true);
    }

    /**
     * Removes a directory that create() made: both files, the SQLite
     * sidecars (-journal, -wal, -shm), the creation lock file of a package
     * sibling file (<sibling>.create.lock) and the directory. Nothing else.
     */
    public static function remove(string $file): void
    {
        $file = self::validate($file, false);
        $directory = dirname($file);

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_link($path) || ! is_file($path) || preg_match('/^[A-Za-z0-9_.]+\.sqlite(\.mirror)?(-journal|-wal|-shm|\.create\.lock)?$/D', $entry) !== 1) {
                throw new HarnessRefusal("Refusing to remove {$directory}: it holds {$entry}, which is not a SQLite test file.");
            }
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($directory.'/'.$entry);
            }
        }

        rmdir($directory);
    }

    /**
     * Returns the canonical path of a valid test SQLite file.
     *
     * @throws HarnessRefusal
     */
    public static function validate(string $file, bool $withMirror): string
    {
        if ($file === '' || $file[0] !== '/') {
            throw new HarnessRefusal("ORRERY_TEST_SQLITE_FILE must be an absolute path, not '{$file}'.");
        }

        if (str_ends_with($file, '.mirror')) {
            throw new HarnessRefusal("ORRERY_TEST_SQLITE_FILE must name the primary file, not a .mirror file: {$file}.");
        }

        self::assertOwnedFile($file);

        $directory = dirname($file);

        if (preg_match(self::DIRECTORY_PATTERN, basename($directory)) !== 1) {
            throw new HarnessRefusal("The SQLite test file {$file} must be in a directory named orrery-sqlite-*.");
        }

        clearstatcache();
        $stat = @lstat($directory);

        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw new HarnessRefusal("The directory of the SQLite test file {$file} is not a directory.");
        }

        if ($stat['uid'] !== self::uid()) {
            throw new HarnessRefusal("The directory {$directory} is not owned by this user.");
        }

        if (($stat['mode'] & 0077) !== 0) {
            throw new HarnessRefusal(sprintf('The directory %s has mode %o; it must be private (0700).', $directory, $stat['mode'] & 0777));
        }

        if ($withMirror) {
            self::assertOwnedFile($file.'.mirror');
        }

        return $file;
    }

    private static function assertOwnedFile(string $file): void
    {
        clearstatcache();

        if (is_link($file)) {
            throw new HarnessRefusal("The SQLite test file {$file} is a symlink.");
        }

        $stat = @lstat($file);

        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            throw new HarnessRefusal("The SQLite test file {$file} does not exist or is not a regular file.");
        }

        if (realpath($file) !== $file) {
            throw new HarnessRefusal("The SQLite test file {$file} is not a canonical path (realpath: ".var_export(realpath($file), true).').');
        }

        if ($stat['uid'] !== self::uid()) {
            throw new HarnessRefusal("The SQLite test file {$file} is not owned by this user.");
        }
    }

    private static function uid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : (int) trim((string) shell_exec('id -u'));
    }
}
