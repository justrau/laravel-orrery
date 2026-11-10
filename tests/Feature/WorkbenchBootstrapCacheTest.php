<?php

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\Support\WorkbenchCliTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Throwable;
use Workbench\App\Support\WorkbenchBootstrapCache;
use Workbench\App\Support\WorkbenchSkeleton;

/**
 * Hostile APP_*_CACHE values never reach a file outside the Workbench.
 *
 * Laravel reads and writes the package and services manifests before any service provider runs.
 * When the skeleton has a .env file, its environment loader also checks whether the config cache
 * exists before the testbench.yaml env list loads. The routes and events caches are used later
 * (WorkbenchBootstrapCache lists when). So the boundary is the launcher workbench/bin/testbench,
 * which pins all five first. Every variable gets a test-owned path outside the checkout, in
 * <outside>/cache, in two forms:
 *  - an existing file that records its own loading in a sentinel file and then throws, so a load
 *    fails the command and leaves evidence;
 *  - a missing file in an existing directory, so a write would create it.
 *
 * The launcher sequence covers every reader and writer that WorkbenchBootstrapCache lists: every
 * boot (includes or writes both manifests), `optimize:clear` (deletes all five), `package:discover`
 * (rewrites the packages manifest), `workbench:ownership` (HTTP requests, after the package
 * provider checked the routes cache), and `optimize` (writes and includes the config cache, writes
 * the events and routes caches). The foreign directory is compared after every command, because a
 * later command can delete what an earlier one wrote.
 *
 * The positive control is the owned directory workbench/storage/bootstrap-cache. The test changes
 * it only through Workbench commands: the Workbench's own `optimize:clear` empties it, and the
 * test then requires the files that each later command writes or deletes. When the sequence stops
 * before its last `optimize:clear` removed the config, routes and events caches, a `finally`
 * block clears them through the launcher, and deletes a file that is still left. A leftover
 * routes cache makes the package skip its routes in every later Workbench process. Only Workbench
 * processes use that directory, and the Workbench CLI tests hold the checkout lock. A running dev
 * server is not coordinated: workbench/fixtures.md says to stop it before these tests.
 *
 * The test observes nothing at the Testbench skeleton defaults
 * (vendor/orchestra/testbench-core/laravel/bootstrap/cache). Every ordinary package test reads
 * and writes those two manifests without the lock, so a change there says nothing about the
 * Workbench. It only reads the skeleton's vendor link, which ordinary tests never change: the
 * launcher makes that link, and no launcher command may create or remove it again
 * (WorkbenchSkeleton explains why). Two overlap cases are regression controls for the interference that a review
 * found: the launcher sequence runs beside a loop of ordinary package boots, and
 * beside a slice of the fast suite, in this checkout, and both sides must pass. They detect a
 * deleted or partly written manifest and an assertion on the shared defaults. They do not prove
 * that the two sides share no file: a valid replacement that is written atomically passes them.
 *
 * The fast suite is the one that the group contract of tests/CLAUDE.md defines: every test except the groups
 * scripts, workbench and docker. Two selection controls check the arguments of the child. The
 * overlap case runs only a slice of it (FAST_SUITE_SLICE): controller tests, which boot the
 * package application and serve its routes, its gates and its page through the shared skeleton,
 * and the console tests, which copy that skeleton for the install command. The other fast-suite
 * tests read the same skeleton files, and they run in `composer test`.
 *
 * The overlap of that case does not depend on the speed of the machine. The launcher sequence
 * starts after the first slice test finished. A hold fixture is the last test of the slice: it
 * boots the ordinary package application again and again until the sequence has ended, so the
 * slice cannot end first. Each launcher command must overlap a slice test that was in progress
 * during it, not a test that finished inside it: a short command beside one long slice test
 * overlaps it, and no test finishes inside the command.
 */
#[Group('workbench')]
final class WorkbenchBootstrapCacheTest extends WorkbenchCliTestCase
{
    private const SERVER_START_SECONDS = 30.0;

    /**
     * The time limit of the package boot loop, which must outlast the whole launcher sequence
     * (about 3 s on a quiet machine). Its process gets CHILD_TIMEOUT_SECONDS, which is longer,
     * so the loop's own limit ends it first with a message.
     */
    private const OVERLAP_CHILD_SECONDS = 540;

    /**
     * The longest wait for the first finished test of the fast-suite slice (its bootstrap).
     */
    private const FIRST_SUITE_TEST_SECONDS = 300.0;

    /**
     * The limit of the fast-suite child. The fast suite takes about 40 s on a quiet machine. With a
     * load average of 45 to 60 on 10 cores from other agents' runs (2026-11-03), it took more than
     * 120 s (the old limit): 234 s on Laravel 13 and 535 s on Laravel 12. The child grows with the
     * suite, so it gets its own limit: 900 s still stops a hung suite, but not a slow one.
     */
    private const FAST_SUITE_CHILD_SECONDS = 900;

    /**
     * The paths of the fast-suite slice that runs beside the launcher sequence, relative to the
     * repository. Each one boots the package application through the Testbench skeleton.
     */
    private const FAST_SUITE_SLICE = [
        'tests/Controller/AuthorizationTest.php',
        'tests/Controller/OperateAbilityTest.php',
        'tests/Controller/PageShellTest.php',
        'tests/Controller/ApiNotFoundTest.php',
        'tests/Controller/ListProjectionTest.php',
        'tests/Feature/Console',
    ];

    /**
     * The class of the hold fixture that ends the fast-suite slice (writeSliceHoldFixture()).
     */
    private const SLICE_HOLD_FIXTURE = 'WorkbenchSliceFixture\\SliceHoldFixtureTest';

    /**
     * The groups that the fast suite leaves out (tests/CLAUDE.md, "Groups and the skip rule").
     * Each one needs its own --exclude-group option: PHPUnit 12.5 reads a comma-separated value
     * as one group name. A command-line exclusion replaces the exclusions of phpunit.xml.dist,
     * so the child names all three.
     */
    private const FAST_SUITE_EXCLUDED_GROUPS = ['scripts', 'workbench', 'docker'];

    /**
     * The owned caches that `optimize` writes, and the launcher command that clears each one.
     */
    private const OPTIMIZE_CACHES = [
        'APP_CONFIG_CACHE' => 'config:clear',
        'APP_ROUTES_CACHE' => 'route:clear',
        'APP_EVENTS_CACHE' => 'event:clear',
    ];

    /**
     * The launcher commands, in order.
     */
    private const SEQUENCE = [
        ['migrate', '--force'],
        ['optimize:clear'],
        ['package:discover'],
        ['workbench:ownership'],
        ['optimize'],
        ['optimize:clear'],
    ];

    /**
     * @return array<string, array{bool}>
     */
    public static function foreignForms(): array
    {
        return [
            'existing foreign files' => [true],
            'missing foreign files' => [false],
        ];
    }

    #[DataProvider('foreignForms')]
    public function test_the_launcher_keeps_every_bootstrap_cache_in_the_workbench(bool $existing): void
    {
        $environment = $this->foreignCaches($existing) + ['DB_DATABASE' => $this->database()];
        $foreignBefore = self::tree($this->foreignDirectory());

        $sequence = $this->runLauncherSequence($environment);

        $this->assertLauncherSequence($sequence, $foreignBefore);
    }

    public function test_the_launcher_sequence_beside_ordinary_package_boots(): void
    {
        $environment = $this->foreignCaches(true) + ['DB_DATABASE' => $this->database()];
        $foreignBefore = self::tree($this->foreignDirectory());

        $control = $this->outsideDirectory.DIRECTORY_SEPARATOR.'boots';
        mkdir($control, 0700);
        $status = $control.DIRECTORY_SEPARATOR.'status';
        $stop = $control.DIRECTORY_SEPARATOR.'stop';

        $boots = $this->startPhp([$this->repository.'/tests/Support/bin/package-testbench-boots.php', $status, $stop, (string) self::OVERLAP_CHILD_SECONDS], []);

        try {
            $this->waitUntil(static fn (): bool => self::bootCount($status) >= 1, $boots, 'The package boot loop did not complete its first boot');
            $bootsBefore = self::bootCount($status);
            $sequence = $this->runLauncherSequence($environment);
            $bootsAfter = self::bootCount($status);
        } finally {
            touch($stop);
            $this->waitForExit($boots);
        }

        $this->assertSame(0, $boots->getExitCode(), "The ordinary package boots failed beside the Workbench:\n".$boots->getOutput().$boots->getErrorOutput());
        $this->assertGreaterThan($bootsBefore, $bootsAfter, 'No ordinary package boot completed while the launcher sequence ran, so the two did not overlap.');
        $this->assertLauncherSequence($sequence, $foreignBefore);
    }

    /**
     * Rejects: a launcher command that deletes, empties or partly writes a manifest of the shared
     * Testbench skeleton, or changes a file that an ordinary package test reads there, while
     * ordinary tests run; and a launcher command that no slice test was in progress beside. Still
     * passes: a command that overlaps only the hold fixture (it boots the same application as the
     * slice tests, but runs no controller or console test).
     */
    public function test_the_launcher_sequence_beside_a_slice_of_the_fast_suite(): void
    {
        $environment = $this->foreignCaches(true) + ['DB_DATABASE' => $this->database()];
        $foreignBefore = self::tree($this->foreignDirectory());

        // PHPUnit writes one "Test Preparation Started" and one "Test Finished" event per test to
        // this log while the suite runs, also for a skipped test.
        $events = $this->outsideDirectory.DIRECTORY_SEPARATOR.'suite-events.txt';
        $count = static fn (string $event): int => is_file($events) ? substr_count((string) file_get_contents($events), "\n{$event} (") : 0;
        $release = $this->outsideDirectory.DIRECTORY_SEPARATOR.'slice-release';
        $fixture = $this->writeSliceHoldFixture($release);

        $suite = $this->startPhp([...$this->fastSuiteSliceArguments(), $fixture, '--log-events-text', $events], [], self::FAST_SUITE_CHILD_SECONDS);

        // The number of slice tests in progress during each launcher command: the tests that
        // started before the command ended, less the tests that finished before it started.
        // The slice runs one test at a time, so each of them ran during the command.
        $during = [];
        $finishedAtStart = 0;
        $observe = static function (string $step, bool $after) use ($count, &$during, &$finishedAtStart): void {
            if ($after) {
                $during[$step] = $count('Test Preparation Started') - $finishedAtStart;
            } else {
                $finishedAtStart = $count('Test Finished');
            }
        };

        try {
            // The launcher starts only when the suite runs tests. On a loaded
            // machine the suite's bootstrap can take longer than the whole
            // launcher sequence, and then the two would not overlap.
            $this->waitUntil(static fn (): bool => $count('Test Finished') >= 1, $suite, 'The fast suite did not finish its first test', self::FIRST_SUITE_TEST_SECONDS);
            $sequence = $this->runLauncherSequence($environment, $observe);
        } finally {
            // The hold fixture ends the slice.
            touch($release);
            $this->waitForExit($suite);
        }

        $this->assertSame(0, $suite->getExitCode(), self::suiteFailure($suite));
        // A pass without issues. On in-memory SQLite the fast suite skips the
        // process tests (ProcessHarnessTest), so a pass with skipped tests is
        // the same result.
        $this->assertMatchesRegularExpression('/^(OK \(\d+ tests?, \d+ assertions?\)|OK, but some tests were skipped!\RTests: \d+, Assertions: \d+, Skipped: \d+\.)$/m', $suite->getOutput());
        $this->assertSame([], array_keys(array_filter($during, static fn (int $tests): bool => $tests < 1)), 'A launcher command ran while no fast-suite test was in progress, so the two did not overlap: '.json_encode($during));
        $this->assertStringContainsString('Test Finished ('.self::SLICE_HOLD_FIXTURE.'::test_boots_until_the_launcher_sequence_ends)', (string) file_get_contents($events), 'The hold fixture did not run.');
        $this->assertStringEndsWith('Test Finished ('.self::SLICE_HOLD_FIXTURE."::test_boots_until_the_launcher_sequence_ends)\n", self::lastTestEvent($events), 'The hold fixture was not the last test of the slice.');
        $this->assertLauncherSequence($sequence, $foreignBefore);
    }

    /**
     * The last "Test Finished" line of a PHPUnit event log, with its line end.
     */
    private static function lastTestEvent(string $events): string
    {
        preg_match_all('/^Test Finished \(.*\R/m', (string) file_get_contents($events), $matches);

        return (string) end($matches[0]);
    }

    /**
     * Write the hold fixture of the fast-suite slice into the outside directory, and return its
     * path. It is an ordinary package test (tests/TestCase.php). Until the file $release exists, it
     * boots the package application through the Testbench skeleton again and again, as
     * tests/Support/bin/package-testbench-boots.php does, and requires both manifests to exist and
     * to include cleanly after each boot. After the loop, it gives the container and the facades
     * back to its own application, so its teardown runs as in every other test.
     */
    private function writeSliceHoldFixture(string $release): string
    {
        $directory = $this->outsideDirectory.DIRECTORY_SEPARATOR.'slice-hold';
        mkdir($directory, 0700);
        [$namespace, $class] = explode('\\', self::SLICE_HOLD_FIXTURE);
        $path = $directory.DIRECTORY_SEPARATOR.$class.'.php';

        file_put_contents($path, implode("\n", [
            '<?php',
            '',
            "namespace {$namespace};",
            '',
            'use Illuminate\Container\Container;',
            'use Illuminate\Foundation\PackageManifest;',
            'use Illuminate\Support\Facades\Facade;',
            'use Justrau\Orrery\OrreryServiceProvider;',
            'use Justrau\Orrery\Tests\TestCase;',
            '',
            '// The hold fixture of WorkbenchBootstrapCacheTest: the last test of the fast-suite slice.',
            "final class {$class} extends TestCase",
            '{',
            '    public function test_boots_until_the_launcher_sequence_ends(): void',
            '    {',
            '        $release = '.var_export($release, true).';',
            '        $deadline = microtime(true) + '.self::OVERLAP_CHILD_SECONDS.';',
            '',
            '        try {',
            '            while (! is_file($release)) {',
            '                if (microtime(true) > $deadline) {',
            '                    self::fail("No release file after '.self::OVERLAP_CHILD_SECONDS.' seconds.");',
            '                }',
            '',
            '                $app = $this->createApplication();',
            '',
            '                foreach ([$app->make(PackageManifest::class)->manifestPath, $app->getCachedServicesPath()] as $manifest) {',
            '                    self::assertFileExists($manifest);',
            '                    self::assertIsArray(require $manifest);',
            '                }',
            '',
            '                self::assertTrue($app->providerIsLoaded(OrreryServiceProvider::class));',
            '                $app->flush();',
            '            }',
            '        } finally {',
            '            Container::setInstance($this->app);',
            '            Facade::clearResolvedInstances();',
            '            Facade::setFacadeApplication($this->app);',
            '        }',
            '',
            '        self::assertFileExists($release);',
            '    }',
            '}',
            '',
        ]));

        return $path;
    }

    public function test_the_fast_suite_child_leaves_out_the_scripts_workbench_and_docker_groups(): void
    {
        // One fixture test class in each excluded group, and one without a group. They are in the
        // test-owned outside directory, so no configured test suite runs them. Before the test groups existed,
        // the repository has no test in the scripts or docker group, so only these fixtures show
        // that the child leaves those two groups out.
        $fixtures = $this->writeSelectionFixtures();

        $this->assertSame(
            ['WorkbenchSelectionFixture\UngroupedSelectionFixtureTest::test_listed'],
            $this->listTests([...$this->fastSuiteArguments(), $fixtures]),
            'The fast-suite child selects a test of the scripts, workbench or docker group.',
        );

        // The control: each grouped fixture is selectable, so the exclusion removed it.
        $this->assertSame(
            [
                'WorkbenchSelectionFixture\DockerSelectionFixtureTest::test_listed',
                'WorkbenchSelectionFixture\ScriptsSelectionFixtureTest::test_listed',
                'WorkbenchSelectionFixture\WorkbenchSelectionFixtureTest::test_listed',
            ],
            $this->listTests([...$this->excludedGroupsArguments(), $fixtures]),
        );
    }

    public function test_the_fast_suite_child_selects_every_configured_test_outside_the_three_groups(): void
    {
        $fast = $this->listTests($this->fastSuiteArguments());
        $excluded = $this->listTests($this->excludedGroupsArguments());

        // A command-line exclusion replaces the exclusions of phpunit.xml.dist. Excluding a group
        // that no test has therefore lists every configured test.
        $every = $this->listTests([...self::phpunitArguments($this->repository), '--exclude-group', 'workbench-selection-no-such-group']);

        $this->assertNotSame([], $fast);
        $this->assertNotSame([], $excluded, 'The workbench group has tests, so this list cannot be empty.');
        $this->assertSame([], array_values(array_intersect($fast, $excluded)), 'The fast-suite child selects a test of the scripts, workbench or docker group.');
        $this->assertSame(array_values(array_diff($every, $excluded)), $fast);

        // When phpunit.xml.dist excludes groups by default, `composer test` must
        // select exactly these tests. Before that, the file excludes no group, and the
        // assertions above are the whole check.
        $configured = self::configuredExcludedGroups($this->repository.'/phpunit.xml.dist');

        if ($configured !== []) {
            $expected = self::FAST_SUITE_EXCLUDED_GROUPS;
            sort($expected);
            sort($configured);
            $this->assertSame($expected, $configured, 'phpunit.xml.dist does not exclude exactly the groups of the fast suite.');
            $this->assertSame($fast, $this->listTests(self::phpunitArguments($this->repository)), 'composer test and the fast-suite child select different tests.');
        }
    }

    public function test_the_web_server_of_serve_uses_only_the_owned_bootstrap_caches(): void
    {
        $environment = $this->foreignCaches(true) + ['DB_DATABASE' => $this->database()];
        $foreignBefore = self::tree($this->foreignDirectory());

        $this->assertSucceeded($this->testbench(['migrate', '--force'], $environment), 'migrate --force with foreign bootstrap caches');

        $port = self::freePort();
        $server = $this->startPhp([$this->repository.'/workbench/bin/testbench', 'serve', '--host=127.0.0.1', '--port='.$port, '--no-ansi', '--no-interaction'], $environment);
        $responses = [];

        try {
            $this->waitUntil(static fn (): bool => self::accepts($port), $server, sprintf('serve did not listen on port %d', $port), self::SERVER_START_SECONDS);

            foreach (['/orrery/api/workload', '/orrery/dashboard'] as $path) {
                $responses[$path] = self::get($port, $path);
            }
        } finally {
            // SIGTERM: the serve command stops its web server child, then exits.
            $server->stop(10);
        }

        // The web server child refuses with HTTP 500 when any of its five paths, or its package
        // manifest, is not the owned file. So HTTP 200 means that all six were owned.
        $statuses = array_map(static fn (array $response): int => $response['status'], $responses);
        $this->assertSame(['/orrery/api/workload' => 200, '/orrery/dashboard' => 200], $statuses, "The web server failed:\n".implode("\n", array_column($responses, 'body'))."\n".$server->getOutput().$server->getErrorOutput());
        $this->assertTrue(self::closes($port), 'The web server still listens on port '.$port.' after serve stopped.');
        $this->assertIsArray(require WorkbenchBootstrapCache::paths()['APP_SERVICES_CACHE']);
        $this->assertForeignCachesUntouched($foreignBefore);
    }

    public function test_a_direct_testbench_call_is_refused_after_its_early_writes(): void
    {
        // Every variable names a missing foreign file, so the direct call never uses a Testbench
        // skeleton default. Testbench and Laravel write both manifests before the Workbench
        // provider exists: the launcher is the boundary, and the refusal only makes a bypass
        // visible. WorkbenchBootstrapCacheRefusalTest covers the manifest path check.
        $environment = $this->foreignCaches(false) + ['DB_DATABASE' => $this->database()];
        $direct = $this->directTestbench(['about'], $environment);

        $this->assertNotSame(0, $direct->getExitCode(), "The direct call did not fail:\n".$direct->getOutput().$direct->getErrorOutput());
        $this->assertOutputContains('The Workbench bootstrap cache APP_SERVICES_CACHE resolves to ['.$environment['APP_SERVICES_CACHE'].']', $direct);
        $this->assertOutputContains('Start the Workbench CLI with `php workbench/bin/testbench`', $direct);
        $this->assertFileExists($environment['APP_PACKAGES_CACHE']);
        $this->assertFileExists($environment['APP_SERVICES_CACHE']);

        foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $variable) {
            $this->assertFileDoesNotExist($environment[$variable]);
        }
    }

    /**
     * Run every command of SEQUENCE through the launcher. After each one, record the foreign
     * directory and which owned files exist. $observe($step, $after) runs before and after each.
     *
     * @param  array<string, string>  $environment
     * @return array{runs: array<string, Process>, foreign: array<string, array<string, string>>, owned: array<string, array<string, bool>>, link: array<string, int|null>}
     */
    private function runLauncherSequence(array $environment, ?callable $observe = null): array
    {
        $sequence = ['runs' => [], 'foreign' => [], 'owned' => [], 'link' => []];

        try {
            foreach (self::SEQUENCE as $index => $arguments) {
                $step = ($index + 1).'. '.implode(' ', $arguments);

                if ($observe !== null) {
                    $observe($step, false);
                }

                $sequence['runs'][$step] = $this->testbench($arguments, $environment);

                if ($observe !== null) {
                    $observe($step, true);
                }

                // The child changed files behind PHP's stat cache.
                clearstatcache();
                $sequence['foreign'][$step] = self::tree($this->foreignDirectory());
                $sequence['owned'][$step] = array_map(static fn (string $path): bool => is_file($path), WorkbenchBootstrapCache::paths());
                $sequence['link'][$step] = self::skeletonVendorLink();
            }
        } finally {
            $this->clearOptimizeCaches($environment);
        }

        return $sequence;
    }

    /**
     * Remove the config, routes and events caches that `optimize` wrote when the sequence stopped
     * before its last `optimize:clear` removed them: first with the launcher's clear command, then
     * directly when the file is still there. After a complete sequence, no file is left, and this
     * runs no command.
     *
     * @param  array<string, string>  $environment
     */
    private function clearOptimizeCaches(array $environment): void
    {
        $owned = WorkbenchBootstrapCache::paths();

        foreach (self::OPTIMIZE_CACHES as $variable => $command) {
            if (! is_file($owned[$variable])) {
                continue;
            }

            try {
                $this->testbench([$command], $environment);
            } catch (Throwable) {
                // The direct removal below still runs, and the original failure stays visible.
            }

            // PHP caches the last stat() result, and the child deleted the file behind that cache.
            clearstatcache(true, $owned[$variable]);

            if (is_file($owned[$variable])) {
                unlink($owned[$variable]);
            }
        }
    }

    /**
     * PHPUnit with the checkout's configuration, and no test selection of its own.
     *
     * @return list<string>
     */
    private static function phpunitArguments(string $repository): array
    {
        return [$repository.'/vendor/bin/phpunit', '--configuration', $repository.'/phpunit.xml.dist', '--do-not-cache-result', '--colors=never'];
    }

    /**
     * The fast suite: PHPUnit without the scripts, workbench and docker groups.
     *
     * @return list<string>
     */
    private function fastSuiteArguments(): array
    {
        $arguments = self::phpunitArguments($this->repository);

        foreach (self::FAST_SUITE_EXCLUDED_GROUPS as $group) {
            $arguments[] = '--exclude-group';
            $arguments[] = $group;
        }

        return $arguments;
    }

    /**
     * The fast suite limited to the paths of FAST_SUITE_SLICE: the same configuration and group
     * exclusions.
     *
     * @return list<string>
     */
    private function fastSuiteSliceArguments(): array
    {
        $arguments = $this->fastSuiteArguments();

        foreach (self::FAST_SUITE_SLICE as $path) {
            $arguments[] = $this->repository.'/'.$path;
        }

        return $arguments;
    }

    /**
     * PHPUnit with only the scripts, workbench and docker groups. A command-line group also
     * overrides an exclusion of that group in phpunit.xml.dist.
     *
     * @return list<string>
     */
    private function excludedGroupsArguments(): array
    {
        $arguments = self::phpunitArguments($this->repository);

        foreach (self::FAST_SUITE_EXCLUDED_GROUPS as $group) {
            $arguments[] = '--group';
            $arguments[] = $group;
        }

        return $arguments;
    }

    /**
     * The sorted test names that PHPUnit selects with the arguments (`--list-tests`).
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function listTests(array $arguments): array
    {
        $listing = $this->runPhp([...$arguments, '--list-tests'], []);
        $this->assertSucceeded($listing, 'phpunit --list-tests');

        preg_match_all('/^ - (.+)$/m', $listing->getOutput(), $matches);
        $tests = $matches[1];
        sort($tests);

        return $tests;
    }

    /**
     * The groups that phpunit.xml.dist excludes by default.
     *
     * @return list<string>
     */
    private static function configuredExcludedGroups(string $configuration): array
    {
        $xml = simplexml_load_file($configuration);
        self::assertNotFalse($xml, 'Cannot read '.$configuration);

        $groups = [];

        foreach ($xml->groups->exclude->group ?? [] as $group) {
            $groups[] = trim((string) $group);
        }

        return $groups;
    }

    /**
     * Write one fixture test class per excluded group, and one without a group, into
     * <outside>/selection.
     */
    private function writeSelectionFixtures(): string
    {
        $directory = $this->outsideDirectory.DIRECTORY_SEPARATOR.'selection';
        mkdir($directory, 0700);

        $classes = [
            'ScriptsSelectionFixtureTest' => 'scripts',
            'WorkbenchSelectionFixtureTest' => 'workbench',
            'DockerSelectionFixtureTest' => 'docker',
            'UngroupedSelectionFixtureTest' => null,
        ];

        foreach ($classes as $class => $group) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.$class.'.php', implode("\n", [
                '<?php',
                '',
                'namespace WorkbenchSelectionFixture;',
                '',
                'use PHPUnit\Framework\TestCase;',
                '',
                '// A selection fixture of WorkbenchBootstrapCacheTest. PHPUnit only lists it.',
                $group === null ? '' : "#[\\PHPUnit\\Framework\\Attributes\\Group('{$group}')]",
                "final class {$class} extends TestCase",
                '{',
                '    public function test_listed(): void',
                '    {',
                '        $this->assertTrue(true);',
                '    }',
                '}',
                '',
            ]));
        }

        return $directory;
    }

    /**
     * @param  array{runs: array<string, Process>, foreign: array<string, array<string, string>>, owned: array<string, array<string, bool>>, link: array<string, int|null>}  $sequence
     * @param  array<string, string>  $foreignBefore
     */
    private function assertLauncherSequence(array $sequence, array $foreignBefore): void
    {
        // The property under test first: the foreign files were never loaded or changed.
        $this->assertForeignCachesUntouched($foreignBefore);

        foreach ($sequence['foreign'] as $step => $tree) {
            $this->assertSame($foreignBefore, $tree, 'The Workbench changed, created or deleted a foreign bootstrap cache during '.$step.'.');
        }

        foreach ($sequence['runs'] as $step => $run) {
            $this->assertSucceeded($run, $step.' with foreign bootstrap caches');
        }

        $report = self::probeReport($sequence['runs']['4. workbench:ownership']);
        $owned = WorkbenchBootstrapCache::paths();
        $this->assertSame($owned, $report['bootstrap_caches']);
        $this->assertSame($owned['APP_PACKAGES_CACHE'], $report['package_manifest']);
        $this->assertSame([200, 200], array_values($report['http']));

        // The positive control: the Workbench's own commands wrote and deleted the owned files.
        $none = array_fill_keys(array_keys($owned), false);
        $this->assertSame($none, $sequence['owned']['2. optimize:clear']);
        $this->assertSame(['APP_SERVICES_CACHE' => true, 'APP_PACKAGES_CACHE' => true] + $none, $sequence['owned']['3. package:discover']);
        $this->assertSame(array_fill_keys(array_keys($owned), true), $sequence['owned']['5. optimize']);
        $this->assertSame($none, $sequence['owned']['6. optimize:clear']);

        // The skeleton vendor link: the launcher made it before the first command, and no command
        // created or removed it again. When Testbench manages the link itself, it deletes the
        // shared default package manifest and rewrites the owned manifests after each run
        // (WorkbenchSkeleton). The test only reads the link, which ordinary tests never change.
        $first = $sequence['link']['1. migrate --force'];
        $this->assertNotNull($first, 'The skeleton vendor link was missing after the first launcher command.');
        $this->assertSame(array_fill_keys(array_keys($sequence['link']), $first), $sequence['link'], 'A launcher command created or removed the skeleton vendor link.');
        $this->assertTrue(WorkbenchSkeleton::vendorAccepted(self::skeletonDirectory().DIRECTORY_SEPARATOR.'vendor', $this->repository.'/vendor'));
    }

    /**
     * The inode of the Testbench skeleton's vendor link, or null when it is not a link.
     */
    private static function skeletonVendorLink(): ?int
    {
        $link = self::skeletonDirectory().DIRECTORY_SEPARATOR.'vendor';
        clearstatcache(true, $link);

        if (! is_link($link)) {
            return null;
        }

        $stat = lstat($link);

        return $stat === false ? null : $stat['ino'];
    }

    private static function skeletonDirectory(): string
    {
        return (string) realpath(__DIR__.'/../../vendor/orchestra/testbench-core/laravel');
    }

    /**
     * Point every variable at a test-owned path in <outside>/cache, with Laravel's own file names.
     * An existing file records its loading in <file>.loaded and throws.
     *
     * @return array<string, string> Variable => foreign path.
     */
    private function foreignCaches(bool $existing): array
    {
        $directory = $this->foreignDirectory();
        mkdir($directory, 0700);

        $paths = [];

        foreach (WorkbenchBootstrapCache::FILES as $variable => $file) {
            $paths[$variable] = $directory.DIRECTORY_SEPARATOR.$file;

            if ($existing) {
                file_put_contents($paths[$variable], implode("\n", [
                    '<?php',
                    '',
                    '// A foreign bootstrap cache of WorkbenchBootstrapCacheTest. Loading it is a failure.',
                    "file_put_contents(__FILE__.'.loaded', 'loaded');",
                    "throw new RuntimeException('The Workbench loaded the foreign bootstrap cache '.__FILE__);",
                    '',
                ]));
            }
        }

        return $paths;
    }

    private function foreignDirectory(): string
    {
        return $this->outsideDirectory.DIRECTORY_SEPARATOR.'cache';
    }

    private function database(): string
    {
        return $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
    }

    /**
     * @param  array<string, string>  $before
     */
    private function assertForeignCachesUntouched(array $before): void
    {
        $this->assertSame([], glob($this->foreignDirectory().'/*.loaded') ?: [], 'The Workbench loaded a foreign bootstrap cache.');
        $this->assertSame($before, self::tree($this->foreignDirectory()), 'The Workbench changed, created or deleted a foreign bootstrap cache.');
    }

    /**
     * Wait until the condition holds. Fail when the child exits first or the time runs out.
     */
    private function waitUntil(callable $condition, Process $child, string $what, float $seconds = 30.0): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $child->checkTimeout();

            if ($condition()) {
                return;
            }

            if (! $child->isRunning()) {
                self::fail($what.": the child exited first.\n".$child->getOutput().$child->getErrorOutput());
            }

            usleep(20_000);
        }

        self::fail(sprintf("%s within %d seconds.\n%s", $what, $seconds, $child->getOutput().$child->getErrorOutput()));
    }

    /**
     * Wait for a child to exit by itself. checkTimeout() stops it at its own timeout.
     */
    private function waitForExit(Process $child): void
    {
        while ($child->isRunning()) {
            $child->checkTimeout();
            usleep(20_000);
        }
    }

    /**
     * The failure message of the nested fast suite: the names of its failed
     * tests first, so that the tail of a log shows them, then its output.
     */
    private static function suiteFailure(Process $suite): string
    {
        $output = $suite->getOutput();
        preg_match_all('/^\d+\) (\S+)/m', $output, $matches);
        $names = array_values(array_unique($matches[1]));

        return sprintf(
            "The fast suite failed beside the Workbench (exit %s). Failed tests: %s\n%s%s",
            var_export($suite->getExitCode(), true),
            $names === [] ? '(none named; see the output)' : implode(', ', $names),
            $output,
            $suite->getErrorOutput(),
        );
    }

    private static function bootCount(string $status): int
    {
        return is_file($status) ? (int) file_get_contents($status) : 0;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, 'Cannot find a free port: '.$errorMessage);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function accepts(int $port): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 0.5);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Whether nothing listens on the port within 5 seconds.
     */
    private static function closes(int $port): bool
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            if (! self::accepts($port)) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /**
     * One plain HTTP/1.1 GET request.
     *
     * @return array{status: int, body: string}
     */
    private static function get(int $port, string $path): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 5);
        self::assertIsResource($socket, 'Cannot connect to the web server: '.$errorMessage);
        stream_set_timeout($socket, 30);

        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nAccept: application/json\r\nConnection: close\r\n\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        preg_match('#^HTTP/\S+ (\d{3})#', $response, $matches);

        return ['status' => (int) ($matches[1] ?? 0), 'body' => substr($response, 0, 2000)];
    }
}
