<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * dist/THIRD_PARTY_NOTICES.txt, which `pnpm run build` writes from the npm
 * packages of the bundle (the plugin thirdPartyNotices() of vite.config.js),
 * and which bin/check-assets proves the build reproduces. pnpm keeps each
 * package under node_modules/.pnpm/, so a plugin that finds no package
 * there writes a file without entries, and the build still passes.
 *
 * The test reads files only (group scripts: `composer test:all` runs it).
 */
#[Group('scripts')]
final class ThirdPartyNoticesTest extends TestCase
{
    /** The npm packages whose code dist/app.js bundles: Vue's runtime and Vue Router. */
    private const BUNDLED = ['@vue/reactivity', '@vue/runtime-core', '@vue/runtime-dom', '@vue/shared', 'vue-router'];

    /**
     * Rejects: a notices file that misses a bundled package (a plugin that
     * does not find the packages in pnpm's node_modules/.pnpm/ layout, or
     * that skips scoped names), one with a version other than the locked
     * one, a package without its license text, and a package that the
     * bundle no longer holds (axios, the Inter font).
     * Still passes: a new runtime dependency that both the bundle and the
     * notices hold, until BUNDLED names it.
     */
    public function test_the_notices_list_every_bundled_package_with_its_locked_version_and_license(): void
    {
        $root = dirname(__DIR__, 2);
        $notices = (string) file_get_contents($root.'/dist/THIRD_PARTY_NOTICES.txt');
        $lock = (string) file_get_contents($root.'/pnpm-lock.yaml');
        self::assertNotSame('', $notices, 'dist/THIRD_PARTY_NOTICES.txt is missing or empty.');

        $rule = str_repeat('=', 72);
        preg_match_all('/^'.$rule.'\n(\S+) (\S+) \(([^)]+)\)\n'.$rule.'\n\n(.*?)(?=\n'.$rule.'\n|\z)/ms', $notices, $sections, PREG_SET_ORDER);
        $listed = [];

        foreach ($sections as [, $name, $version, $license, $text]) {
            $listed[] = $name;
            self::assertSame(1, preg_match('/^  \'?'.preg_quote($name, '/').'@'.preg_quote($version, '/').'\'?:$/m', $lock), "{$name} {$version} is not the version in pnpm-lock.yaml.");
            self::assertSame('MIT', $license, "{$name} names another license.");
            self::assertStringContainsString('Permission is hereby granted', $text, "{$name} has no license text.");
        }

        self::assertSame(self::BUNDLED, $listed);
    }
}
