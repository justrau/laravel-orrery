<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * The package classes that the signature audit inspects.
 */
final class PackageClasses
{
    /** The PSR-4 prefix of src/ in composer.json. */
    public const PREFIX = 'Justrau\\Orrery\\';

    /**
     * Every class under src/ that has a class in the Illuminate\ namespace among its parents.
     * It walks the PHP files of src/ and maps each path to a class name by the PSR-4 prefix
     * Justrau\Orrery\ => src/, so it never depends on Composer's class map.
     *
     * @return list<class-string>
     */
    public static function extendingIlluminate(): array
    {
        $classes = [];

        foreach (self::declaredInSource() as $class) {
            if (self::hasIlluminateParent($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Every class that a PHP file of src/ declares by the PSR-4 rule. Interfaces,
     * traits and enums are not classes here.
     *
     * @return list<class-string>
     */
    public static function declaredInSource(): array
    {
        $source = self::sourceDirectory();
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        );
        $classes = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($source) + 1, -strlen('.php'));
            $class = self::PREFIX.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (class_exists($class) && ! enum_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    public static function sourceDirectory(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src';
    }

    /**
     * @param  class-string  $class
     */
    private static function hasIlluminateParent(string $class): bool
    {
        $parent = (new ReflectionClass($class))->getParentClass();

        while ($parent !== false) {
            if (str_starts_with($parent->getName(), 'Illuminate\\')) {
                return true;
            }

            $parent = $parent->getParentClass();
        }

        return false;
    }
}
