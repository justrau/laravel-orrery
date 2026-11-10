<?php

namespace Workbench\App\Support;

use Justrau\Orrery\Storage\PackageSession;
use RuntimeException;

/**
 * Resolves the one SQLite file that the Workbench app may use, and refuses every other path.
 *
 * The Workbench owns only regular files named database.sqlite inside <repo>/workbench/database.
 * The Workbench provider calls resolve() in register(), before any command runs, so a bad path
 * stops `create-sqlite-db`, `migrate:fresh`, `serve` and `queue:work` before they touch a file.
 * workbench/check.php calls it too, so every consumer uses the same canonical path.
 *
 * This class has no Laravel dependency on purpose: check.php uses it without booting an app.
 */
final class WorkbenchDatabase
{
    /**
     * The file name that the Workbench build step `create-sqlite-db` creates in databasePath().
     */
    public const FILE_NAME = 'database.sqlite';

    /**
     * The package's sibling mirror file of a resolved Workbench database path:
     * the default connection is this SQLite file and orrery.database is empty, so Orrery
     * keeps its tables there. The name is the one that the package derives
     * (PackageSession::siblingNameOf(), a pure function, so check.php needs no application);
     * the file is in the same directory, so the guard of resolve() covers it.
     */
    public static function sibling(string $database): string
    {
        return dirname($database).DIRECTORY_SEPARATOR.PackageSession::siblingNameOf(basename($database));
    }

    /**
     * The environment variable that can select another file inside the owned directory.
     */
    public const ENV = 'DB_DATABASE';

    /**
     * The owned directory: <repo>/workbench/database, computed from this file's location.
     */
    public static function directory(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database';
    }

    /**
     * The DB_DATABASE value of the process environment, or null when it is not set.
     *
     * The provider and check.php both read this value, so they resolve the same path. Testbench
     * disables putenv(), so a DB_DATABASE line in a .env file or in testbench.yaml never reaches
     * getenv() and cannot make the two disagree.
     */
    public static function configured(): ?string
    {
        $value = getenv(self::ENV);

        return is_string($value) ? $value : null;
    }

    /**
     * Resolve the configured SQLite path to its canonical form, or throw.
     *
     * Rules, in order:
     *  1. The owned directory exists, is a real directory, and is not a symbolic link.
     *  2. The configured path (DB_DATABASE, or <dir>/database.sqlite when unset or empty) is absolute
     *     and its file name is database.sqlite.
     *  3. If a directory entry exists at the path in any form (lstat succeeds, which includes a
     *     dangling symbolic link), it must be a regular file and not a symbolic link, and its
     *     realpath must be inside the owned directory.
     *  4. Only a genuinely absent entry takes the new-file branch. Then the realpath of its parent
     *     directory must be the owned directory or a directory inside it.
     *
     * @param  string|null  $configured  The DB_DATABASE value; null or '' selects the default file.
     * @param  string|null  $directory  The owned directory; only tests pass another one.
     * @return string The canonical absolute path. Every consumer must use this exact value.
     *
     * @throws RuntimeException When the path is not a Workbench-owned SQLite file.
     */
    public static function resolve(?string $configured, ?string $directory = null): string
    {
        $root = self::ownedDirectory($directory ?? self::directory());

        $path = ($configured === null || $configured === '')
            ? $root.DIRECTORY_SEPARATOR.self::FILE_NAME
            : $configured;

        if (! self::isAbsolute($path)) {
            throw self::refuse($path, $root, 'the path is not absolute');
        }

        if (basename($path) !== self::FILE_NAME) {
            throw self::refuse($path, $root, 'the file name is not '.self::FILE_NAME.', which is the only name create-sqlite-db creates');
        }

        // lstat() does not follow a final symbolic link, so it also sees a dangling link.
        // file_exists() follows the link and reports a dangling link as absent, which would
        // send it to the new-file branch and let the build write through it.
        clearstatcache(true, $path);

        if (is_link($path)) {
            throw self::refuse($path, $root, 'the path is a symbolic link');
        }

        if (@lstat($path) !== false) {
            if (! is_file($path)) {
                throw self::refuse($path, $root, 'the path exists but is not a regular file');
            }

            $real = realpath($path);

            if ($real === false || ! self::isInside($real, $root)) {
                throw self::refuse($path, $root, 'the file resolves to '.($real === false ? 'nothing' : $real).', which is outside the owned directory');
            }

            return $real;
        }

        $parent = realpath(dirname($path));

        if ($parent === false) {
            throw self::refuse($path, $root, 'the parent directory does not exist');
        }

        if ($parent !== $root && ! self::isInside($parent, $root)) {
            throw self::refuse($path, $root, 'the parent directory resolves to '.$parent.', which is outside the owned directory');
        }

        return $parent.DIRECTORY_SEPARATOR.self::FILE_NAME;
    }

    private static function ownedDirectory(string $directory): string
    {
        if (is_link($directory)) {
            throw new RuntimeException(sprintf('The Workbench database directory [%s] is a symbolic link. It must be a real directory.', $directory));
        }

        $real = realpath($directory);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException(sprintf('The Workbench database directory [%s] does not exist.', $directory));
        }

        return $real;
    }

    private static function isInside(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    private static function refuse(string $path, string $root, string $reason): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Refusing the Workbench SQLite path [%s]: %s. The Workbench uses only a regular file named %s inside [%s]. Unset %s to use [%s].',
            $path,
            $reason,
            self::FILE_NAME,
            $root,
            self::ENV,
            $root.DIRECTORY_SEPARATOR.self::FILE_NAME,
        ));
    }
}
