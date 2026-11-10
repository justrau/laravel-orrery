<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use RuntimeException;

/**
 * A private copy of Testbench's application skeleton for one test.
 *
 * Testbench boots every test application from one shared skeleton under
 * vendor/orchestra/testbench-core/laravel. A test that publishes files into
 * the application, or edits bootstrap/providers.php, changes that shared
 * directory, and a concurrent suite of the same checkout sees the change or
 * deletes the files under it. A test that writes into the application boots
 * from a copy instead: override applicationBasePath() to return copy().
 *
 * copy() copies the regular files and directories, and keeps each symbolic
 * link as a link with the same target (the skeleton's vendor link), so it
 * never copies the vendor tree. remove() deletes the copy, and deletes a link
 * without the files under its target.
 */
final class ApplicationSkeleton
{
    public const DIRECTORY_PATTERN = '/^orrery-skeleton-[a-f0-9]{16}$/D';

    /**
     * Copies $source into a new private directory in the temporary directory,
     * and returns the path of the copy. $beforeFile (tests only) runs with
     * the source path of each regular file just before its copy.
     *
     * @param  (callable(string): void)|null  $beforeFile
     */
    public static function copy(string $source, ?callable $beforeFile = null): string
    {
        if (! is_dir($source) || is_link($source)) {
            throw new RuntimeException("Cannot copy the application skeleton {$source}: it is not a directory.");
        }

        $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orrery-skeleton-'.bin2hex(random_bytes(8));

        if (! mkdir($target, 0700)) {
            throw new RuntimeException("Cannot create the application skeleton copy {$target}.");
        }

        try {
            self::copyInto($source, $target, $beforeFile);
        } catch (\Throwable $failure) {
            self::remove($target);

            throw $failure;
        }

        return $target;
    }

    /**
     * Deletes a copy that copy() made. The guard checks only that the base
     * name matches orrery-skeleton-<16 hex> and that the path itself is not a
     * link. It does not prove that copy() made the path, or that its parent
     * is the temporary directory, so pass only a path that copy() returned.
     */
    public static function remove(string $copy): void
    {
        if (preg_match(self::DIRECTORY_PATTERN, basename($copy)) !== 1 || is_link($copy)) {
            throw new RuntimeException("Refusing to remove {$copy}: it is not an application skeleton copy.");
        }

        if (! file_exists($copy)) {
            return;
        }

        self::removeTree($copy);
    }

    /**
     * A hash of the paths, types, link targets and file contents under $root.
     * It never follows a link. The paths in $exclude (relative to $root) and
     * everything under them are left out.
     *
     * @param  list<string>  $exclude
     */
    public static function fingerprint(string $root, array $exclude = []): string
    {
        $entries = [];
        self::collect($root, '', $exclude, $entries);
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }

    /**
     * @param  (callable(string): void)|null  $beforeFile
     */
    private static function copyInto(string $source, string $target, ?callable $beforeFile): void
    {
        foreach (self::children($source) as $name) {
            $from = $source.DIRECTORY_SEPARATOR.$name;
            $to = $target.DIRECTORY_SEPARATOR.$name;

            if (is_link($from)) {
                $link = readlink($from);

                if ($link === false || ! symlink($link, $to)) {
                    throw new RuntimeException("Cannot copy the link {$from}.");
                }
            } elseif (is_dir($from)) {
                if (! mkdir($to, 0700)) {
                    throw new RuntimeException("Cannot create the directory {$to}.");
                }

                self::copyInto($from, $to, $beforeFile);
            } else {
                if ($beforeFile !== null) {
                    $beforeFile($from);
                }

                if (! @copy($from, $to) || ($mode = @fileperms($from)) === false || ! chmod($to, $mode & 0777)) {
                    // Another suite of the checkout can delete a runtime file of the
                    // shared skeleton (a compiled view, a log) while this copies it.
                    clearstatcache(true, $from);

                    if (! file_exists($from) && ! is_link($from)) {
                        @unlink($to);

                        continue;
                    }

                    throw new RuntimeException("Cannot copy the file {$from}.");
                }
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || ! is_dir($path)) {
            if (! unlink($path)) {
                throw new RuntimeException("Cannot remove {$path}.");
            }

            return;
        }

        foreach (self::children($path) as $name) {
            self::removeTree($path.DIRECTORY_SEPARATOR.$name);
        }

        if (! rmdir($path)) {
            throw new RuntimeException("Cannot remove the directory {$path}.");
        }
    }

    /**
     * @param  list<string>  $exclude
     * @param  list<string>  $entries
     */
    private static function collect(string $root, string $relative, array $exclude, array &$entries): void
    {
        $directory = $relative === '' ? $root : $root.DIRECTORY_SEPARATOR.$relative;

        foreach (self::children($directory) as $name) {
            $path = $relative === '' ? $name : $relative.'/'.$name;

            if (in_array($path, $exclude, true)) {
                continue;
            }

            $absolute = $root.DIRECTORY_SEPARATOR.$path;

            if (is_link($absolute)) {
                $entries[] = "link {$path} ".readlink($absolute);
            } elseif (is_dir($absolute)) {
                $entries[] = "dir {$path}";
                self::collect($root, $path, $exclude, $entries);
            } else {
                $entries[] = "file {$path} ".hash_file('sha256', $absolute);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function children(string $directory): array
    {
        $names = scandir($directory);

        if ($names === false) {
            throw new RuntimeException("Cannot read the directory {$directory}.");
        }

        return array_values(array_diff($names, ['.', '..']));
    }
}
