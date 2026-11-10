<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * The static analysis configuration.
 *
 * PHPStan runs at level 8 with Larastan over config/, database/, routes/ and
 * src/, for PHP 8.3, without a baseline and without suppressions. The test
 * reads phpstan.neon.dist (its NEON is the YAML subset that Symfony Yaml
 * parses) and the analysed files. It never runs PHPStan.
 */
#[Group('scripts')]
final class StaticAnalysisConfigTest extends TestCase
{
    private const PATHS = ['config', 'database', 'routes', 'src'];

    /**
     * Rejects: a baseline, an ignoreErrors entry, a @phpstan-ignore comment in an
     * analysed file, a level below 8, a missing Larastan include, other paths,
     * and any other key (an excludePaths entry, another include, a service).
     * Still passes: a suppression written in another form, for example a
     * `@phpstan-var` that lies about a type, or a Larastan include that PHPStan
     * cannot load (the test does not run PHPStan).
     */
    public function test_phpstan_configuration_is_level_eight_without_a_baseline(): void
    {
        $root = dirname(__DIR__, 2);
        $raw = (string) file_get_contents($root.'/phpstan.neon.dist');
        $config = Yaml::parse($raw);

        self::assertIsArray($config);
        $parameters = $config['parameters'] ?? [];
        self::assertIsArray($parameters);

        self::assertSame(8, $parameters['level'] ?? null, 'The PHPStan level');
        self::assertContains(
            'vendor/larastan/larastan/extension.neon',
            $config['includes'] ?? [],
            'The Larastan extension include',
        );
        self::assertSame(self::PATHS, $parameters['paths'] ?? null, 'The analysed paths');
        self::assertSame(80300, $parameters['phpVersion'] ?? null, 'The PHP version');

        // Every other key could narrow the analysis (excludePaths, a lower
        // strictness switch, a service that drops errors), so the keys are exact.
        self::assertSame(['includes', 'parameters'], array_keys($config), 'The top-level keys');
        self::assertSame(['vendor/larastan/larastan/extension.neon'], $config['includes'], 'The includes');
        self::assertSame(['phpVersion', 'paths', 'level', 'tmpDir', 'configDirectories'], array_keys($parameters), 'The parameter keys');
        self::assertArrayNotHasKey('ignoreErrors', $parameters, 'An ignoreErrors entry');
        self::assertStringNotContainsStringIgnoringCase('baseline', $raw, 'A baseline include');
        self::assertStringNotContainsString('ignoreErrors', $raw, 'An ignoreErrors entry');

        $suppressed = [];

        foreach (self::PATHS as $path) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$path));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                if (str_contains((string) file_get_contents($file->getPathname()), '@phpstan-ignore')) {
                    $suppressed[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        self::assertSame([], $suppressed, 'Files with a @phpstan-ignore comment');
    }

    /**
     * Rejects: a phpstan.neon or phpstan.dist.neon in the checkout, which
     * PHPStan loads instead of phpstan.neon.dist when no -c is given (so the
     * checks above would read a file that `composer lint` does not use), and
     * an inline type assertion of a literal-string (`@var literal-string`,
     * `@phpstan-var`, `@psalm-var`, `@phpstan-assert`) outside the one in
     * Storage\TrustedSql.
     * Still passes: a `-c` option added to the lint script (the previous
     * test pins the script), and an assertion through a type alias.
     */
    public function test_phpstan_reads_only_the_dist_file_and_only_trusted_sql_asserts_a_literal_string(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['phpstan.neon', 'phpstan.dist.neon'] as $override) {
            self::assertFileDoesNotExist($root.'/'.$override, "PHPStan would read {$override} instead of phpstan.neon.dist");
        }

        $assertions = [];

        foreach (self::PATHS as $path) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$path)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $count = self::literalStringAssertions((string) file_get_contents($file->getPathname()));

                if ($count > 0) {
                    $assertions[substr($file->getPathname(), strlen($root) + 1)] = $count;
                }
            }
        }

        self::assertSame(['src/Storage/TrustedSql.php' => 1], $assertions, 'Inline literal-string assertions: only Storage\TrustedSql may assert one');
    }

    /**
     * Rejects: an assertion scan that misses a form, or that counts a
     * checked `@param` or `@return` literal-string (PHPStan proves those at
     * every call site and return).
     */
    public function test_the_literal_string_scan_finds_every_assertion_form(): void
    {
        $source = <<<'PHP'
            /** @var literal-string $a */
            /** @phpstan-var literal-string $b */
            /** @psalm-var   non-empty-literal-string $c */
            /** @phpstan-assert literal-string $d */
            /** @var array<int, literal-string> $e */
             * the one `@var literal-string` below is true (prose, not a tag)
            /**
             * @param  literal-string  $f
             * @return literal-string
             */
            PHP;

        self::assertSame(5, self::literalStringAssertions($source));
    }

    private static function literalStringAssertions(string $source): int
    {
        return (int) preg_match_all('/(?:\/\*\*|^\s*\*)\s*@(?:phpstan-|psalm-)?(?:var|assert(?:-if-true|-if-false)?)\s+[^\n]*literal-string/m', $source);
    }

    /**
     * Rejects: no tmpDir (PHPStan then shares one result cache in the system
     * temporary directory with every other checkout, and parallel runs corrupt
     * it), an absolute or %tmpDir%-based path, a path that leaves the checkout
     * with "..", and a cache directory that git does not ignore.
     * Still passes: a tmpDir that PHPStan cannot create (PHPStan is not run).
     */
    public function test_the_result_cache_is_a_gitignored_directory_of_this_checkout(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Yaml::parse((string) file_get_contents($root.'/phpstan.neon.dist'));
        self::assertIsArray($config);
        $tmpDir = $config['parameters']['tmpDir'] ?? null;

        self::assertIsString($tmpDir, 'phpstan.neon.dist sets no tmpDir');
        // PHPStan resolves a relative path against the directory of the configuration file.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)*$/D', $tmpDir, 'tmpDir must be a relative path inside the checkout');
        self::assertNotContains('..', explode('/', $tmpDir), 'tmpDir leaves the checkout');
        self::assertStringNotContainsString('%', $tmpDir, 'tmpDir must not depend on a PHPStan parameter');

        $ignored = array_map('trim', file($root.'/.gitignore') ?: []);
        self::assertContains('/'.explode('/', $tmpDir)[0].'/', $ignored, 'git does not ignore the cache directory');
    }

    /**
     * Rejects: a lint script that starts PHPStan directly. Larastan then boots the
     * Workbench without the pinned bootstrap cache paths, and the Workbench
     * provider refuses to run, so PHPStan analyses nothing.
     * Still passes: a launcher that exists but does not pin the paths (the
     * launcher is not run here).
     */
    public function test_lint_starts_phpstan_through_the_workbench_launcher(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertSame('@php workbench/bin/phpstan analyse', $composer['scripts']['lint'] ?? null);
        self::assertFileExists($root.'/workbench/bin/phpstan');
    }
}
