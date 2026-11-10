<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The JavaScript unit tests.
 *
 * Every exported function of the SPA's pure helper modules
 * (resources/js/support/*.js, resources/js/shell/data.js and
 * every resources/js/screens/<screen>/*.js, so a new screen directory is
 * covered without a change here) is imported from its
 * own module and called in the test file of that module under tests/js/. A
 * function whose test waits for the task that rewrites it is listed in
 * tests/js/pending-owners.txt. The test reads files only; it never runs Node.
 */
#[Group('scripts')]
final class JsTestCoverageTest extends TestCase
{
    private const PENDING = 'tests/js/pending-owners.txt';

    /**
     * Rejects: an exported helper function that no test of its module calls,
     * a test that only imports the function, a function that is tested only
     * in the test file of another module with the same function name, and a
     * call of a same-named function that the test imports from another module.
     * Still passes: a test that calls the function and asserts nothing, and a
     * call inside a comment (the test does not parse JavaScript).
     */
    public function test_every_exported_helper_function_is_called_in_its_module_test(): void
    {
        $pending = $this->pending();
        $missing = [];

        foreach ($this->modules() as $module) {
            foreach ($this->exportedFunctions($module) as $function) {
                if (isset($pending[$module][$function])) {
                    continue;
                }

                if (! $this->isTested($module, $function)) {
                    $missing[] = "{$module} {$function} (expected an import from its module and a call in tests/js/".self::testFileOf($module).')';
                }
            }
        }

        self::assertSame([], $missing, "Exported helper functions without a test:\n".implode("\n", $missing));
    }

    /**
     * Rejects: a pending line for a function that is not exported (a typo or a
     * removed function), a line without an owner task, and a pending function
     * that its module test already calls (the line must go once it is tested).
     * Still passes: a pending list that is not yet empty; the phase end gate
     * checks that.
     */
    public function test_every_pending_owner_line_names_an_untested_exported_function(): void
    {
        $problems = [];

        foreach ($this->pendingLines() as [$number, $line]) {
            if (preg_match('#^(\S+\.js)\s+([A-Za-z_$][\w$]*)\s+(H\d+)$#', $line, $match) !== 1) {
                $problems[] = "line {$number}: expected '<module> <function> <task>', got '{$line}'";

                continue;
            }

            [, $module, $function] = $match;

            if (! in_array($module, $this->modules(), true)) {
                $problems[] = "line {$number}: {$module} is not a helper module";

                continue;
            }

            if (! in_array($function, $this->exportedFunctions($module), true)) {
                $problems[] = "line {$number}: {$module} exports no function {$function}";

                continue;
            }

            if ($this->isTested($module, $function)) {
                $problems[] = "line {$number}: {$function} is tested now; remove the line";
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Rejects: a module list that misses a helper module (so its functions
     * escape the check), a fixed list of screen directories that misses a
     * new one, a parser that finds no exported function, and a relative
     * import without .js.
     */
    public function test_the_module_list_covers_every_helper_module(): void
    {
        $modules = $this->modules();

        self::assertContains('support/time.js', $modules);
        self::assertContains('shell/data.js', $modules);
        self::assertContains('screens/failed/failures.js', $modules);
        self::assertContains('screens/overview/overview.js', $modules);
        self::assertContains('screens/queues/queues.js', $modules);
        foreach (['support/lists.js', 'support/bulk.js', 'support/redirects.js', 'support/wording.js'] as $foundation) {
            self::assertContains($foundation, $modules, 'the D8 foundation modules');
        }
        self::assertNotContains('shell/store.js', $modules, 'the store holds Vue state, not pure helpers');

        // Every module of every screen directory, found without the module list's glob.
        $screens = self::root().'/resources/js/screens';
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($screens, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'js') {
                $found[] = 'screens/'.substr($file->getPathname(), strlen($screens) + 1);
            }
        }
        self::assertGreaterThanOrEqual(10, count($found));
        foreach ($found as $module) {
            self::assertContains($module, $modules, 'every screen module, also of a screen directory that a fixed list would miss');
        }

        self::assertContains('toSeconds', $this->exportedFunctions('support/time.js'));
        self::assertNotContains('POLL_SECONDS', $this->exportedFunctions('screens/queues/queues.js'), 'a constant is not a function');

        foreach ($modules as $module) {
            $source = (string) file_get_contents(self::root().'/resources/js/'.$module);
            self::assertDoesNotMatchRegularExpression(
                "/^import\\s[^;]*from\\s+'\\.{1,2}\\/[^']*(?<!\\.js)';/m",
                $source,
                "{$module} has a relative import without .js, which Node ESM cannot load",
            );
        }
    }

    /**
     * Rejects: a parser that sees only `export function`, so a function
     * exported in another form escapes the check; and a parser that counts an
     * exported constant as a function.
     * Still passes: a function re-exported from another module
     * (`export {a} from './b.js'`), which the helper modules do not use.
     */
    public function test_the_parser_finds_every_form_of_exported_function(): void
    {
        $source = <<<'JS'
            export function plain(a) { return a; }
            export async function later() {}
            export function* steps() {}
            export default function main() {}
            export const expression = function (value) { return value; };
            export const arrow = (value) => value;
            export const single = value => value;
            export const asyncArrow = async () => 1;
            export const LIMIT = 5;
            export const LIST = [1, 2];
            function listedA() {}
            const listedB = () => 1;
            const notAFunction = 3;
            export {listedA, listedB as renamed, notAFunction};
            JS;

        $found = self::exportedFunctionsOf($source);
        sort($found);

        self::assertSame(
            ['arrow', 'asyncArrow', 'expression', 'later', 'listedA', 'main', 'plain', 'renamed', 'single', 'steps'],
            $found,
        );
        self::assertSame(['default'], self::exportedFunctionsOf('export default function () {}'));
    }

    /**
     * Rejects: a test script without the fixed time zone or locale (a
     * German LC_ALL makes the number tests fail), a script that runs another
     * directory, and an assets workflow that never runs the tests or runs
     * them before pnpm install.
     * Still passes: a workflow step that runs pnpm test and ignores its exit
     * status (the test reads the YAML text only).
     */
    public function test_pnpm_test_runs_node_test_with_a_fixed_zone_and_the_workflow_runs_it(): void
    {
        $package = json_decode((string) file_get_contents(self::root().'/package.json'), true);
        self::assertIsArray($package);
        $script = $package['scripts']['test'] ?? null;
        self::assertIsString($script);
        self::assertStringContainsString('TZ=UTC', $script);
        self::assertStringContainsString('LANG=en_US.UTF-8', $script);
        self::assertStringContainsString('LC_ALL=en_US.UTF-8', $script);
        self::assertStringContainsString("node --test 'tests/js/*.test.js'", $script);

        $workflow = (string) file_get_contents(self::root().'/.github/workflows/assets.yml');
        $install = strpos($workflow, 'run: pnpm install --frozen-lockfile');
        $test = strpos($workflow, 'run: pnpm test');
        self::assertNotFalse($install, 'the assets workflow does not run pnpm install --frozen-lockfile');
        self::assertNotFalse($test, 'the assets workflow does not run pnpm test');
        self::assertGreaterThan($install, $test, 'pnpm test runs before pnpm install');
    }

    /**
     * Whether the module's test file imports the function from the module
     * itself (by name, under an alias, as the default export, or through a
     * namespace import) and calls it.
     */
    private function isTested(string $module, string $function): bool
    {
        $testFile = self::root().'/tests/js/'.self::testFileOf($module);

        if (! is_file($testFile)) {
            return false;
        }

        $source = (string) file_get_contents($testFile);
        $body = (string) preg_replace('/^import\s[^;]*;/m', '', $source);
        $callees = [];

        foreach ($this->imports($source, dirname($testFile)) as $import) {
            if ($import['module'] !== $module) {
                continue;
            }

            if ($import['imported'] === '*') {
                $callees[] = preg_quote($import['local'], '/').'\s*\.\s*'.preg_quote($function, '/');
            } elseif ($import['imported'] === $function) {
                $callees[] = preg_quote($import['local'], '/');
            }
        }

        foreach ($callees as $callee) {
            if (preg_match('/(?<![\w$.])'.$callee.'\s*\(/', $body) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The imports of a test file whose source is a helper module.
     *
     * @return list<array{local: string, imported: string, module: string}> `imported` is the exported name, `default`, or `*` for a namespace
     */
    private function imports(string $source, string $directory): array
    {
        preg_match_all("/^import\\s+([^;]*?)\\s+from\\s+'([^']+)'\\s*;/m", $source, $statements, PREG_SET_ORDER);
        $prefix = realpath(self::root()).'/resources/js/';
        $imports = [];

        foreach ($statements as [, $clause, $path]) {
            $resolved = realpath($directory.'/'.$path);

            if ($resolved === false || ! str_starts_with($resolved, $prefix)) {
                continue;
            }

            $module = substr($resolved, strlen($prefix));

            if (preg_match('/\*\s+as\s+([\w$]+)/', $clause, $namespace) === 1) {
                $imports[] = ['local' => $namespace[1], 'imported' => '*', 'module' => $module];
            }

            if (preg_match('/^\s*([\w$]+)\s*(?:,|$)/', $clause, $default) === 1) {
                $imports[] = ['local' => $default[1], 'imported' => 'default', 'module' => $module];
            }

            if (preg_match('/\{([^}]*)\}/', $clause, $named) === 1) {
                foreach (explode(',', $named[1]) as $entry) {
                    $entry = trim($entry);

                    if (preg_match('/^([\w$]+)(?:\s+as\s+([\w$]+))?$/', $entry, $parts) === 1) {
                        $imports[] = ['local' => $parts[2] ?? $parts[1], 'imported' => $parts[1], 'module' => $module];
                    }
                }
            }
        }

        return $imports;
    }

    /**
     * @return list<string> module paths relative to resources/js
     */
    private function modules(): array
    {
        $js = self::root().'/resources/js/';
        $files = array_merge(
            glob($js.'support/*.js') ?: [],
            [$js.'shell/data.js'],
            glob($js.'screens/*/*.js') ?: [],
        );

        $modules = array_map(static fn (string $file): string => substr($file, strlen($js)), $files);
        sort($modules);

        return $modules;
    }

    private static function testFileOf(string $module): string
    {
        if ($module === 'shell/data.js') {
            return 'shell-data.test.js';
        }

        return basename($module, '.js').'.test.js';
    }

    /**
     * @return list<string>
     */
    private function exportedFunctions(string $module): array
    {
        return self::exportedFunctionsOf((string) file_get_contents(self::root().'/resources/js/'.$module));
    }

    /**
     * The exported function names of a module source: `export function`,
     * `export default function` (its name, else `default`), an exported
     * function expression or arrow function, and the functions of an
     * `export {a, b as c}` list.
     *
     * @return list<string>
     */
    private static function exportedFunctionsOf(string $source): array
    {
        $functionValue = '(?:async\s+)?(?:function\b|\([^)]*\)\s*=>|[\w$]+\s*=>)';
        $names = [];

        preg_match_all('/^export\s+(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*)/m', $source, $matches);
        array_push($names, ...$matches[1]);

        preg_match_all('/^export\s+default\s+(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*)?/m', $source, $defaults, PREG_SET_ORDER);
        foreach ($defaults as $default) {
            $names[] = ($default[1] ?? '') !== '' ? $default[1] : 'default';
        }

        preg_match_all('/^export\s+(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*'.$functionValue.'/m', $source, $values);
        array_push($names, ...$values[1]);

        preg_match_all('/^export\s*\{([^}]*)\}\s*;?\s*$/m', $source, $lists);
        foreach ($lists[1] as $list) {
            foreach (explode(',', $list) as $entry) {
                if (preg_match('/^\s*([\w$]+)(?:\s+as\s+([\w$]+))?\s*$/', $entry, $parts) !== 1) {
                    continue;
                }

                $local = preg_quote($parts[1], '/');
                $declared = '/^(?:async\s+)?function\s*\*?\s*'.$local.'\b|^(?:const|let|var)\s+'.$local.'\s*=\s*'.$functionValue.'/m';

                if (preg_match($declared, $source) === 1) {
                    $names[] = $parts[2] ?? $parts[1];
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, array<string, true>> module => function => true
     */
    private function pending(): array
    {
        $pending = [];

        foreach ($this->pendingLines() as [, $line]) {
            $parts = preg_split('/\s+/', $line) ?: [];

            if (count($parts) >= 2) {
                $pending[$parts[0]][$parts[1]] = true;
            }
        }

        return $pending;
    }

    /**
     * @return list<array{int, string}> the line number and the line, without comments and empty lines
     */
    private function pendingLines(): array
    {
        $path = self::root().'/'.self::PENDING;
        self::assertFileExists($path);
        $lines = [];

        foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#')) {
                $lines[] = [$index + 1, $line];
            }
        }

        return $lines;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
