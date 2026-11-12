<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use OrreryMatrix\Leg;
use OrreryMatrix\LegResult;
use OrreryMatrix\LockRules;
use OrreryMatrix\Variant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * bin/matrix with fixtures: it never runs Composer or PHPUnit.
 *
 * Each test builds a small git checkout as the snapshot source, with its own
 * composer.json, phpunit.xml.dist, tests/known-failures.md and
 * tests/unconditional.txt (fixture copies, not the repository's files). The
 * test mode of bin/matrix (ORRERY_MATRIX_TEST=1) replaces each leg's bin/test
 * with Fixtures/matrix/fake-bin-test.php, and --composer points at
 * Fixtures/matrix/fake-composer.php. Both record every call. The PHP binary
 * of each leg is a small script that reports <version>.99 to bin/matrix's
 * version probe and runs everything else with this PHP.
 *
 * It is in the group scripts: `composer test` skips it, `composer test:all`
 * runs it.
 */
#[Group('scripts')]
final class MatrixRunnerTest extends TestCase
{
    use RunsScripts;

    private const HARNESS = [
        'Fx\\Tests\\HarnessTest::test_identity',
        'Fx\\Tests\\HarnessTest::test_refusal#a named set',
        'Fx\\Tests\\HarnessTest::test_refusal#0',
    ];

    private const PROCESS = ['Fx\\Tests\\ProcessTest::test_worker', 'Fx\\Tests\\ProcessTest::test_barrier', 'Fx\\Tests\\ProcessTest::test_deadline'];

    private const PROCESS_CONTROL = ['Fx\\Tests\\ProcessTest::test_barrier', 'Fx\\Tests\\ProcessTest::test_deadline'];

    /** The documented engine skip: test_deadline needs no shared database, so it runs in memory too. */
    private const SHARED_DATABASE = ['Fx\\Tests\\ProcessTest::test_worker', 'Fx\\Tests\\ProcessTest::test_barrier'];

    private const SUITE = ['Fx\\Tests\\FeatureTest::test_a', 'Fx\\Tests\\FeatureTest::test_b', 'Fx\\Tests\\MetricsTest::test_prefixed'];

    private string $work;

    private string $source;

    private string $out;

    private string $scenarioFile;

    private string $calls;

    /** @var array<string, mixed> */
    private array $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = $this->makeTempDir('orrery-matrix-');
        $this->source = $this->work.'/source';
        $this->out = $this->work.'/out';
        $this->scenarioFile = $this->work.'/scenario.json';
        $this->calls = $this->work.'/calls.jsonl';
        mkdir($this->out);
        mkdir($this->work.'/php');

        foreach (['8.3', '8.4', '8.5'] as $version) {
            $path = $this->work.'/php/php'.$version;
            file_put_contents($path, "#!/bin/sh\n# bin/matrix probes the version with -n -r.\nif [ \"\$1\" = \"-n\" ] && [ \"\$2\" = \"-r\" ]; then\n    printf '{\"version\":\"{$version}.99\",\"binary\":\"%s\"}' \"\$0\"\n    exit 0\nfi\nexec ".escapeshellarg(PHP_BINARY)." \"\$@\"\n");
            chmod($path, 0755);
        }

        $this->makeSource();
        $this->scenario = [
            'calls' => $this->calls,
            'groups' => [
                'harness' => self::HARNESS,
                'process' => self::PROCESS,
                'process-control' => self::PROCESS_CONTROL,
                'shared-database' => self::SHARED_DATABASE,
                'default' => self::SUITE,
                'scripts' => ['Fx\\Tests\\ScriptTest::test_script'],
                'workbench' => ['Fx\\Tests\\WorkbenchIsolationTest::test_guard'],
                'docker' => ['Fx\\Tests\\DockerTest::test_cold_run'],
            ],
            'runs' => [],
            'locks' => [
                '*' => self::lock('v12.69.2', 'v10.11.0', '11.5.56'),
                'php8.3-laravel13' => self::lock('v13.33.0', 'v11.2.0', '12.5.35'),
                'php8.4-laravel13' => self::lock('v13.33.0', 'v11.2.0', '12.5.35'),
                'php8.5-laravel13' => self::lock('v13.33.0', 'v11.2.0', '12.5.35'),
                'php8.3-laravel12-lowest' => self::lock('v12.0.1', 'v10.0.0', '11.5.3'),
                'php8.3-laravel13-lowest' => self::lock('v13.0.0', 'v11.0.0', '12.5.8'),
                // --ci runs in place: the working directory is the source checkout.
                'source' => self::lock('v13.33.0', 'v11.2.0', '12.5.35'),
            ],
            'composer_exit' => [],
        ];
    }

    protected function tearDown(): void
    {
        $this->cleanUpScripts();
        parent::tearDown();
    }

    public function test_a_leg_runs_each_variant_and_engine_and_records_versions_identity_and_groups(): void
    {
        // The prefix variant has a listed known failure (F10 in the fixture manifest).
        $this->scenario['runs']['*/same-prefix.*.suite*'] = ['fail' => [['id' => 'Fx\\Tests\\MetricsTest::test_prefixed', 'type' => 'error', 'text' => 'SQLSTATE[HY000]: INSERT INTO metric_counters failed']]];

        $run = $this->matrix(['--php=8.4', '--laravel=12', '--db=sqlite,mysql', '--mirror=same', '--no-lowest']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $leg = $this->onlyLeg($run, 'php8.4-laravel12');
        self::assertSame('pass-listed', $leg['verdict']);
        self::assertSame(['php' => '8.4.99', 'framework' => 'v12.69.2', 'testbench' => 'v10.11.0', 'testbench_core' => 'v10.14.1', 'phpunit' => '11.5.56'], $leg['versions']);
        // One base name per unit that uses a server: same/none and same/prefix.
        self::assertCount(2, $leg['databases']);

        foreach (['sn', 'sp'] as $index => $suffix) {
            self::assertMatchesRegularExpression('/^orrery_m_[0-9a-f]{6}_p84l12_'.$suffix.'$/', $leg['databases'][$index]);
        }

        $names = array_column($leg['runs'], 'run');
        // The variant leg runs its file runs fresh and its server runs in reuse.
        self::assertSame([
            'same-none.sqlite.suite', 'same-none.sqlite.groups', 'same-none.sqlite-file.suite', 'same-none.sqlite-file.groups', 'same-none.mysql.suite.reuse', 'same-none.mysql.groups.reuse',
            'same-prefix.sqlite.suite', 'same-prefix.sqlite.groups', 'same-prefix.sqlite-file.suite', 'same-prefix.sqlite-file.groups', 'same-prefix.mysql.suite.reuse', 'same-prefix.mysql.groups.reuse',
            'scripts', 'docker',
        ], $names);

        $runs = array_combine($names, $leg['runs']);
        // In memory only the two shared-database members skip; test_deadline runs.
        self::assertSame(['members' => 3, 'exempt' => 2, 'executed' => 1, 'skipped' => 2, 'missing' => 0], $runs['same-none.sqlite.groups']['check']['groups']['process']);
        self::assertSame(['members' => 2, 'exempt' => 1, 'executed' => 1, 'skipped' => 1, 'missing' => 0], $runs['same-none.sqlite.groups']['check']['groups']['process-control']);
        self::assertSame(['shared-database'], $runs['same-none.sqlite.groups']['check']['engine_skip']);
        self::assertSame(['members' => 3, 'exempt' => 0, 'executed' => 3, 'skipped' => 0, 'missing' => 0], $runs['same-none.sqlite-file.groups']['check']['groups']['process']);
        self::assertSame([], $runs['same-none.sqlite-file.groups']['check']['engine_skip']);
        self::assertSame(['members' => 3, 'exempt' => 0, 'executed' => 3, 'skipped' => 0, 'missing' => 0], $runs['same-none.mysql.groups.reuse']['check']['groups']['harness']);
        self::assertSame('sqlite-memory', $runs['same-none.sqlite.groups']['identity']['evidence']);
        self::assertSame('sqlite-file', $runs['same-none.sqlite-file.groups']['identity']['evidence']);
        self::assertSame('endpoint-token', $runs['same-prefix.mysql.groups.reuse']['identity']['evidence']);
        self::assertSame('pfx_', $runs['same-prefix.mysql.groups.reuse']['identity']['prefix']);
        self::assertSame('pass-listed', $runs['same-prefix.mysql.suite.reuse']['verdict']);
        self::assertSame(['members' => 1, 'exempt' => 0, 'executed' => 1, 'skipped' => 0, 'missing' => 0], $runs['docker']['check']['groups']['docker']);

        // What each bin/test call got.
        $binTest = $this->callsOf('bin/test');
        self::assertCount(15, $binTest, 'the test list plus 14 runs');

        foreach ($binTest as $call) {
            self::assertSame('8.4.99', $call['env']['ORRERY_EXPECT_PHP'] ?? null, $call['run']);
            self::assertSame($call['engine'], $call['env']['ORRERY_EXPECT_DRIVER'] ?? null, $call['run']);
            self::assertSame($this->work.'/php/php8.4', $call['php'], $call['run']);
            self::assertArrayNotHasKey('COMPOSER_NO_BLOCKING', $call['env'], $call['run']);
            self::assertSame('off', $call['env']['XDEBUG_MODE'] ?? null);
            self::assertStringEndsWith('/php8.4-laravel12', (string) $call['cwd']);
        }

        $byRun = array_column($binTest, null, 'run');
        self::assertSame(['--group', 'harness', '--group', 'process', '--group', 'process-control', '--exclude-group', 'docker'], array_slice($byRun['php8.4-laravel12/same-none.mysql.groups.reuse']['phpunit'], 0, 8));
        self::assertSame(['--exclude-group', 'docker', '--exclude-group', 'scripts', '--exclude-group', 'workbench', '--exclude-group', 'harness', '--exclude-group', 'process', '--exclude-group', 'process-control'], array_slice($byRun['php8.4-laravel12/same-none.sqlite.suite']['phpunit'], 0, 12));
        self::assertSame(['--sqlite-file', '--schema=fresh', '--timeout=4800'], $byRun['php8.4-laravel12/same-none.sqlite-file.groups']['wrapper']);
        self::assertSame(['--schema=fresh', '--timeout=4800'], $byRun['php8.4-laravel12/same-none.sqlite.suite']['wrapper']);
        // The file suite selects what the in-memory suite selects, on a SQLite file.
        self::assertSame(['--sqlite-file', '--schema=fresh', '--timeout=4800'], $byRun['php8.4-laravel12/same-none.sqlite-file.suite']['wrapper']);
        self::assertSame(
            array_slice($byRun['php8.4-laravel12/same-none.sqlite.suite']['phpunit'], 0, 12),
            array_slice($byRun['php8.4-laravel12/same-none.sqlite-file.suite']['phpunit'], 0, 12),
        );
        self::assertSame('pfx_', $byRun['php8.4-laravel12/same-prefix.sqlite-file.suite']['env']['ORRERY_TEST_PREFIX'] ?? null);

        // Every MySQL run owns the leg's name only for its own duration.
        foreach (['same-none.mysql.suite.reuse', 'same-none.mysql.groups.reuse', 'same-prefix.mysql.suite.reuse', 'same-prefix.mysql.groups.reuse'] as $name) {
            self::assertSame(['--drop-database', '--schema=reuse', '--timeout=4800'], $byRun['php8.4-laravel12/'.$name]['wrapper'], $name);
        }

        self::assertSame($leg['databases'][0], $byRun['php8.4-laravel12/same-none.mysql.suite.reuse']['env']['ORRERY_TEST_DATABASE'] ?? null);
        self::assertSame($leg['databases'][0], $byRun['php8.4-laravel12/same-none.mysql.groups.reuse']['env']['ORRERY_TEST_DATABASE'] ?? null);
        self::assertSame($leg['databases'][1], $byRun['php8.4-laravel12/same-prefix.mysql.suite.reuse']['env']['ORRERY_TEST_DATABASE'] ?? null);
        self::assertSame($leg['databases'][1], $runs['same-prefix.mysql.suite.reuse']['database']);
        self::assertNull($runs['same-none.sqlite.suite']['database']);
        self::assertSame('pfx_', $byRun['php8.4-laravel12/same-prefix.sqlite.suite']['env']['ORRERY_TEST_PREFIX'] ?? null);
        self::assertArrayNotHasKey('ORRERY_TEST_PREFIX', $byRun['php8.4-laravel12/same-none.sqlite.suite']['env']);
        self::assertSame('1', $byRun['php8.4-laravel12/docker']['env']['ORRERY_DOCKER_TESTS'] ?? null);
        self::assertArrayNotHasKey('ORRERY_DOCKER_TESTS', $byRun['php8.4-laravel12/scripts']['env']);
        self::assertSame(['--group', 'scripts', '--group', 'workbench', '--exclude-group', 'docker'], array_slice($byRun['php8.4-laravel12/scripts']['phpunit'], 0, 6));

        $composer = $this->callsOf('composer');
        self::assertSame(['require', '--dev', 'orchestra/testbench:^10.0', '--no-update'], array_slice($composer[0]['arguments'], 0, 4));
        self::assertSame('update', $composer[1]['command']);
        self::assertNotContains('--prefer-lowest', $composer[1]['arguments']);

        $summary = (string) file_get_contents($run['dir'].'/summary.md');
        self::assertStringContainsString('| php8.4-laravel12 | ', $summary);
        self::assertStringContainsString('PASS with 1 listed known failure', $summary);
        self::assertStringContainsString('| Leg | Snapshot | PHP | laravel/framework | orchestra/testbench | PHPUnit | Tests | Assertions | Exit codes | Duration | Result |', $run['stdout']);
    }

    /**
     * Rejects: a variant leg that runs a server run of any variant fresh (the old cost), that
     * runs its file runs in reuse, that keeps a separate reuse block (it repeats the variants),
     * and a failed reuse server run that does not fail the leg. It still passes a plan that runs
     * the variants' server runs in reuse on other legs too: the plan test checks the legs.
     */
    public function test_the_variant_leg_runs_its_server_runs_in_reuse_and_its_file_runs_fresh(): void
    {
        $this->scenario['runs']['*/separate-prefix.pgsql.suite.reuse'] = ['fail' => [['id' => 'Fx\\Tests\\FeatureTest::test_a', 'type' => 'failure', 'text' => 'Failed asserting that 2 is identical to 1.']]];

        $run = $this->matrix(['--php=8.4', '--laravel=12', '--db=sqlite,pgsql', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        $leg = $this->onlyLeg($run, 'php8.4-laravel12');
        self::assertSame('fail', $leg['verdict'], 'a failure of a reuse run does not fail the leg');
        $expected = [];

        foreach (['same-none', 'separate-none', 'same-prefix', 'separate-prefix'] as $variant) {
            $expected = [...$expected, "{$variant}.sqlite.suite", "{$variant}.sqlite.groups", "{$variant}.sqlite-file.suite", "{$variant}.sqlite-file.groups", "{$variant}.pgsql.suite.reuse", "{$variant}.pgsql.groups.reuse"];
        }

        self::assertSame([...$expected, 'scripts', 'docker'], array_column($leg['runs'], 'run'));
        self::assertCount(4, $leg['databases'], 'one base name per variant, no reuse block');

        foreach ($this->callsOf('bin/test') as $call) {
            if (str_ends_with($call['run'], '/list')) {
                continue;
            }

            $mode = $call['engine'] === 'pgsql' ? '--schema=reuse' : '--schema=fresh';
            self::assertSame([$mode, '--timeout=4800'], array_slice($call['wrapper'], -2), $call['run']);
        }

        $byRun = array_column($this->callsOf('bin/test'), null, 'run');
        self::assertSame(['--sqlite-file', '--schema=fresh', '--timeout=4800'], $byRun['php8.4-laravel12/separate-prefix.sqlite-file.suite']['wrapper']);
        self::assertSame(['--drop-database', '--schema=reuse', '--timeout=4800'], $byRun['php8.4-laravel12/separate-prefix.pgsql.groups.reuse']['wrapper']);
        self::assertSame('separate', $byRun['php8.4-laravel12/separate-prefix.pgsql.groups.reuse']['env']['ORRERY_TEST_MIRROR'] ?? null);
        self::assertSame('pfx_', $byRun['php8.4-laravel12/separate-prefix.pgsql.groups.reuse']['env']['ORRERY_TEST_PREFIX'] ?? null);
        self::assertStringContainsString('separate/prefix pgsql suite (schema reuse)', (string) file_get_contents($run['dir'].'/summary.md'));
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: array<string, string>}>
     */
    public static function schemaModes(): iterable
    {
        // php8.4-laravel13 is a latest leg without a reason for the fresh mode; php8.5-laravel13
        // keeps it (the latest Laravel 13 must run fresh somewhere).
        yield 'the default' => [[], ['php8.4-laravel13' => 'reuse', 'php8.5-laravel13' => 'fresh']];
        yield '--all-fresh' => [['--all-fresh'], ['php8.4-laravel13' => 'fresh', 'php8.5-laravel13' => 'fresh']];
    }

    /**
     * Rejects: a matrix that runs every leg fresh (the old cost), one that also runs the
     * php8.5-laravel13 leg or in-memory SQLite in reuse, one that runs only the suites in reuse
     * but the required groups fresh (or the reverse), and an --all-fresh that leaves a reuse run.
     *
     * @param  list<string>  $extra
     * @param  array<string, string>  $modes
     */
    #[DataProvider('schemaModes')]
    public function test_latest_legs_run_the_file_and_server_runs_in_reuse_except_the_fresh_legs(array $extra, array $modes): void
    {
        $run = $this->matrix(['--php=8.4,8.5', '--laravel=13', '--db=sqlite,mysql', '--no-lowest', ...$extra]);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);

        foreach ($modes as $id => $mode) {
            $suffix = $mode === 'reuse' ? '.reuse' : '';
            self::assertSame([
                'same-none.sqlite.suite', 'same-none.sqlite.groups',
                'same-none.sqlite-file.suite'.$suffix, 'same-none.sqlite-file.groups'.$suffix,
                'same-none.mysql.suite'.$suffix, 'same-none.mysql.groups'.$suffix,
                'scripts', 'docker',
            ], array_column($this->onlyLeg($run, $id)['runs'], 'run'), $id);
        }

        foreach ($this->callsOf('bin/test') as $call) {
            if (str_ends_with($call['run'], '/list')) {
                continue;
            }

            [$id] = explode('/', $call['run']);
            $memory = $call['engine'] === 'sqlite' && ! in_array('--sqlite-file', $call['wrapper'], true);
            $expected = $memory ? 'fresh' : $modes[$id];
            self::assertContains('--schema='.$expected, $call['wrapper'], $call['run']);
        }
    }

    /**
     * Rejects: a --variants option that runs other variants than it names, that drops runs of the
     * named variant (the CI split of the variant leg must run everything that the variant ran), or
     * that passes another mirror or prefix to bin/test.
     */
    public function test_variants_run_only_the_named_variants_with_all_their_runs(): void
    {
        $run = $this->matrix(['--php=8.4', '--laravel=12', '--db=sqlite,mysql', '--no-lowest', '--variants=separate/prefix']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $leg = $this->onlyLeg($run, 'php8.4-laravel12');
        self::assertSame([
            'separate-prefix.sqlite.suite', 'separate-prefix.sqlite.groups', 'separate-prefix.sqlite-file.suite', 'separate-prefix.sqlite-file.groups', 'separate-prefix.mysql.suite.reuse', 'separate-prefix.mysql.groups.reuse',
            'scripts', 'docker',
        ], array_column($leg['runs'], 'run'));

        foreach ($this->callsOf('bin/test') as $call) {
            if (str_contains($call['run'], 'separate-prefix.')) {
                self::assertSame('separate', $call['env']['ORRERY_TEST_MIRROR'] ?? null, $call['run']);
                self::assertSame('pfx_', $call['env']['ORRERY_TEST_PREFIX'] ?? null, $call['run']);
            }
        }
    }

    public function test_the_snapshot_holds_tracked_and_new_untracked_files_but_no_ignored_file(): void
    {
        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db=sqlite', '--no-lowest']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $snapshot = json_decode((string) file_get_contents($run['dir'].'/snapshot.json'), true);
        $copy = $run['dir'].'/php8.4-laravel13';

        self::assertFileExists($copy.'/tracked.txt');
        self::assertFileExists($copy.'/untracked.txt');
        self::assertFileDoesNotExist($copy.'/ignored.txt');
        self::assertFileDoesNotExist($copy.'/.matrix/old/stale.txt');
        self::assertTrue(is_link($copy.'/link.txt'));
        self::assertSame('tracked.txt', readlink($copy.'/link.txt'));
        self::assertTrue(is_executable($copy.'/bin/tool'));
        self::assertArrayNotHasKey('deleted.txt', $snapshot['entries'], 'a file that the working tree deleted is not in the snapshot');
        self::assertSame($this->headOf($this->source), $snapshot['head']);
        self::assertTrue($snapshot['dirty']);
        self::assertSame($snapshot['hash'], $this->onlyLeg($run, 'php8.4-laravel13')['snapshot']);
    }

    public function test_only_the_composer_calls_of_a_lowest_leg_get_composer_no_blocking(): void
    {
        // The helper exports COMPOSER_NO_BLOCKING=1 to bin/matrix itself.
        $run = $this->matrix(['--php=8.3', '--laravel=12', '--db=sqlite']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertSame(['php8.3-laravel12', 'php8.3-laravel12-lowest'], array_column($run['summary']['legs'], 'leg'));

        $composer = $this->callsOf('composer');
        self::assertCount(4, $composer);

        foreach ($composer as $call) {
            $lowest = $call['leg'] === 'php8.3-laravel12-lowest';
            self::assertSame($lowest ? '1' : null, $call['COMPOSER_NO_BLOCKING'], "{$call['leg']} {$call['command']}");

            if ($call['command'] === 'update') {
                self::assertSame($lowest, in_array('--prefer-lowest', $call['arguments'], true));
                self::assertSame($lowest, in_array('--prefer-stable', $call['arguments'], true));
            }
        }

        foreach ($this->callsOf('bin/test') as $call) {
            self::assertArrayNotHasKey('COMPOSER_NO_BLOCKING', $call['env'], $call['run']);
        }

        self::assertSame('v12.0.1', $run['summary']['legs'][1]['versions']['framework']);
    }

    public function test_a_lowest_leg_that_resolved_a_later_framework_fails_although_its_runs_pass(): void
    {
        $this->scenario['locks']['php8.3-laravel12-lowest'] = self::lock('v12.0.2', 'v10.0.0', '11.5.3');

        $run = $this->matrix(['--php=8.3', '--laravel=12', '--db=sqlite']);

        self::assertSame(1, $run['exit'], $run['stdout'].$run['stderr']);
        [$latest, $lowest] = $run['summary']['legs'];
        self::assertSame('pass', $latest['verdict']);
        self::assertSame('fail', $lowest['verdict']);
        self::assertSame(['the lowest leg resolved laravel/framework v12.0.2, not the floor 12.0.1 that composer.json declares'], $lowest['reasons']);
        self::assertCount(5, $lowest['runs']);

        foreach ($lowest['runs'] as $leg) {
            self::assertSame('pass', $leg['verdict'], $leg['run']);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int, 2: list<string>}>
     */
    public static function lowestLocks(): iterable
    {
        yield 'the floor' => [self::lock('v12.0.1', 'v10.0.0', '11.5.3'), 12, []];
        yield 'the floor without the v' => [self::lock('12.0.1', 'v10.0.0', '11.5.3'), 12, []];
        yield 'the Laravel 13 floor of ^13.0' => [self::lock('v13.0.0', 'v11.0.0', '12.5.8'), 13, []];
        yield 'a later framework version' => [self::lock('v12.0.2', 'v10.0.0', '11.5.3'), 12, ['the lowest leg resolved laravel/framework v12.0.2, not the floor 12.0.1 that composer.json declares']];
        yield 'a dev-main package' => [self::lock('v12.0.1', 'v10.0.0', '11.5.3', [['name' => 'guzzlehttp/guzzle', 'version' => 'dev-main']]), 12, ['the lowest leg resolved guzzlehttp/guzzle dev-main, which is not a stable release (dev)']];
        yield 'a numeric development branch' => [self::lock('v12.0.1', 'v10.0.0', '11.5.3', [['name' => 'guzzlehttp/guzzle', 'version' => '7.8.x-dev']]), 12, ['the lowest leg resolved guzzlehttp/guzzle 7.8.x-dev, which is not a stable release (dev)']];
        yield 'a stable release with a branch alias' => [self::lock('v12.0.1', 'v10.0.0', '11.5.3', [['name' => 'guzzlehttp/psr7', 'version' => '1.9.1', 'extra' => ['branch-alias' => ['dev-master' => '1.9-dev']]]]), 12, []];
        yield 'a release candidate' => [self::lock('v12.0.1', 'v10.0.0', '11.5.3', [['name' => 'symfony/console', 'version' => 'v7.2.0-RC1']]), 12, ['the lowest leg resolved symfony/console v7.2.0-RC1, which is not a stable release (RC)']];
    }

    /**
     * @param  array<string, mixed>  $lock
     * @param  list<string>  $reasons
     */
    #[DataProvider('lowestLocks')]
    public function test_the_lowest_leg_rules(array $lock, int $major, array $reasons): void
    {
        require_once self::repoRoot().'/bin/lib/matrix.php';

        $composerJson = ['require' => ['php' => '^8.3', 'illuminate/support' => '^12.0.1|^13.0', 'illuminate/queue' => '^12.0.1|^13.0']];

        self::assertSame($reasons, LockRules::checkLowest($lock, $composerJson, $major));
    }

    /**
     * Rejects: a leg verdict that is "pass" for a leg without a run and without a reason (a leg
     * that something stopped before its first run).
     */
    public function test_a_leg_without_a_run_never_passes(): void
    {
        require_once self::repoRoot().'/bin/lib/matrix.php';

        $result = new LegResult(new Leg('8.4', 13, false, [Variant::base()], ['sqlite']), []);

        self::assertSame([], $result->reasons);
        self::assertFalse($result->interrupted);
        self::assertSame('fail', $result->verdict());
    }

    public function test_versions_compare_without_the_v_and_the_fourth_part(): void
    {
        require_once self::repoRoot().'/bin/lib/matrix.php';

        self::assertSame('12.0.1', LockRules::normalize('v12.0.1'));
        self::assertSame('12.0.1', LockRules::normalize('12.0.1'));
        self::assertSame('12.0.1', LockRules::normalize('12.0.1.0'));
        self::assertSame('13.0.0', LockRules::normalize('v13.0'));
        self::assertSame('dev-main', LockRules::normalize('dev-main'));
        self::assertSame('12.0.1', LockRules::floor(['require' => ['illuminate/support' => '^12.0.1|^13.0']], 12));
        self::assertSame('13.0.0', LockRules::floor(['require' => ['illuminate/support' => '^12.0.1 || ^13.0']], 13));
        self::assertSame('12.1.0', LockRules::floor(['require' => ['illuminate/support' => '^12.0.1', 'illuminate/queue' => '^12.1']], 12));
        self::assertNull(LockRules::floor(['require' => ['illuminate/support' => '^12.0.1']], 13));
    }

    /**
     * Rejects: a floor that reads only the illuminate/* constraints, which the
     * package no longer requires (it requires laravel/framework).
     */
    public function test_the_floor_reads_the_laravel_framework_constraint(): void
    {
        require_once self::repoRoot().'/bin/lib/matrix.php';

        self::assertSame('12.69.0', LockRules::floor(['require' => ['laravel/framework' => '^12.69.0|^13.30.0']], 12));
        self::assertSame('13.30.0', LockRules::floor(['require' => ['laravel/framework' => '^12.69.0|^13.30.0']], 13));

        $composerJson = json_decode((string) file_get_contents(self::repoRoot().'/composer.json'), true);
        self::assertIsArray($composerJson);
        self::assertNotNull(LockRules::floor($composerJson, 12));
        self::assertNotNull(LockRules::floor($composerJson, 13));
    }

    public function test_a_failing_leg_does_not_stop_the_later_legs_and_the_run_exits_1(): void
    {
        $this->scenario['runs']['php8.3-laravel13/same-none.sqlite.suite'] = ['fail' => [['id' => 'Fx\\Tests\\FeatureTest::test_a', 'type' => 'failure', 'text' => 'Failed asserting that false is true.']]];

        $run = $this->matrix(['--php=8.3,8.4', '--laravel=13', '--db=sqlite', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        self::assertSame(['fail', 'pass'], array_column($run['summary']['legs'], 'verdict'));
        self::assertStringContainsString('unlisted failure on sqlite/same/none: Fx\\Tests\\FeatureTest::test_a: Failed asserting that false is true.', $run['stdout']);

        $runs = array_column(array_filter($this->callsOf('bin/test'), static fn (array $call): bool => str_starts_with($call['run'], 'php8.4-laravel13/')), 'run');
        self::assertSame(['php8.4-laravel13/list', 'php8.4-laravel13/same-none.sqlite.suite', 'php8.4-laravel13/same-none.sqlite.groups', 'php8.4-laravel13/same-none.sqlite-file.suite.reuse', 'php8.4-laravel13/same-none.sqlite-file.groups.reuse', 'php8.4-laravel13/scripts'], $runs);
        self::assertStringContainsString('Result: 2 legs, 1 passed (0 with listed known failures), 1 failed.', $run['stdout']);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function wrongIdentities(): iterable
    {
        yield 'another PHP in the identity' => ['*/same-none.sqlite.groups', ['identity' => ['php' => '8.3.30']], 'identity php: expected "8.4.99", got "8.3.30"'];
        yield 'another driver' => ['*/same-none.sqlite-file.groups.reuse', ['identity' => ['primary.driver' => 'mysql']], 'identity primary.driver: expected "sqlite", got "mysql"'];
        yield 'another database' => ['*/same-none.mysql.groups.reuse', ['identity' => ['primary.database' => 'orrery_other']], 'identity primary.database: expected "orrery_m_'];
        yield 'in-memory evidence on a file run' => ['*/same-none.sqlite-file.groups.reuse', ['identity' => ['primary.evidence.kind' => 'sqlite-memory']], 'identity primary.evidence.kind: expected "sqlite-file", got "sqlite-memory"'];
        yield 'another PHP in the Runtime line' => ['*/same-none.mysql.suite.reuse', ['runtime' => '8.4.1'], "PHPUnit ran on PHP 8.4.1, not on the leg's PHP 8.4.99"];
        yield 'no identity line' => ['*/same-none.mysql.groups.reuse', ['identity' => false], 'the identity log has 0 ORRERY-IDENTITY lines, expected 1'];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('wrongIdentities')]
    public function test_a_run_with_a_wrong_identity_fails_its_leg(string $pattern, array $change, string $reason): void
    {
        $this->scenario['runs'][$pattern] = $change;

        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db=sqlite,mysql', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        $leg = $this->onlyLeg($run, 'php8.4-laravel13');
        self::assertSame('fail', $leg['verdict']);
        $failed = array_values(array_filter($leg['runs'], static fn (array $run): bool => $run['verdict'] === 'fail'));
        self::assertCount(1, $failed);
        self::assertTrue(fnmatch($pattern, 'php8.4-laravel13/'.$failed[0]['run']));
        self::assertStringContainsString($reason, implode("\n", $failed[0]['reasons']));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function brokenJunitFiles(): iterable
    {
        yield 'no JUnit file' => [['junit' => false], 'no JUnit file at '];
        yield 'a malformed JUnit file' => [['junit' => '<testsuites><testcase'], 'is malformed'];
        yield 'zero executed tests' => [['skip' => self::SUITE], 'the JUnit file has no executed test'];
        yield 'a bootstrap refusal' => [['junit' => false, 'exit' => 78], 'PHPUnit exit status 78: tests/bootstrap.php refused'];
        yield 'a stale JUnit file' => [['junit_age' => 600], 'before the run started'];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('brokenJunitFiles')]
    public function test_a_run_without_usable_results_fails_its_leg(array $change, string $reason): void
    {
        $this->scenario['runs']['*/same-none.sqlite.suite'] = $change;

        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db=sqlite', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        $runs = array_column($this->onlyLeg($run, 'php8.4-laravel13')['runs'], null, 'run');
        self::assertSame('fail', $runs['same-none.sqlite.suite']['verdict']);
        self::assertStringContainsString($reason, implode("\n", $runs['same-none.sqlite.suite']['check']['reasons']));
        self::assertSame('pass', $runs['same-none.sqlite.groups']['verdict']);
    }

    public function test_a_skipped_process_test_fails_on_file_sqlite_but_is_the_engine_skip_in_memory(): void
    {
        $this->scenario['runs']['*/same-none.sqlite-file.groups.reuse'] = ['skip' => ['Fx\\Tests\\ProcessTest::test_worker']];

        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db=sqlite', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        $runs = array_column($this->onlyLeg($run, 'php8.4-laravel13')['runs'], null, 'run');
        self::assertSame('pass', $runs['same-none.sqlite.groups']['verdict'], 'the in-memory run skips the two shared-database tests');
        self::assertSame(2, $runs['same-none.sqlite.groups']['check']['groups']['process']['skipped']);
        self::assertSame('fail', $runs['same-none.sqlite-file.groups.reuse']['verdict']);
        self::assertSame(["a test of the required group 'process' was skipped: Fx\\Tests\\ProcessTest::test_worker"], $runs['same-none.sqlite-file.groups.reuse']['check']['reasons']);
    }

    /**
     * Rejects: a docker run that selects the alt tests, which always skip there without bin/test --alt.
     */
    public function test_the_docker_run_excludes_the_alt_group_but_a_skipped_docker_test_still_fails(): void
    {
        $alt = 'Fx\\Tests\\ReachableTargetTest::test_second_server';
        $this->scenario['groups']['docker'][] = $alt;
        $this->scenario['groups']['alt'] = [$alt];

        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db=mysql', '--no-lowest']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $runs = array_column($this->onlyLeg($run, 'php8.4-laravel13')['runs'], null, 'run');
        self::assertSame('pass', $runs['docker']['verdict']);
        self::assertSame(['--group', 'docker', '--exclude-group', 'alt'], array_slice(array_column($this->callsOf('bin/test'), null, 'run')['php8.4-laravel13/docker']['phpunit'], 0, 4));
        self::assertSame(1, $runs['docker']['check']['counts']['tests'], 'the alt test did not run');
        self::assertSame(0, $runs['docker']['check']['counts']['skipped']);
        self::assertSame(['members' => 1, 'exempt' => 0, 'executed' => 1, 'skipped' => 0, 'missing' => 0], $runs['docker']['check']['groups']['docker']);

        // A docker test outside the alt group must still run.
        self::removeTree($this->out);
        mkdir($this->out);
        $this->scenario['runs']['*/docker'] = ['skip' => ['Fx\\Tests\\DockerTest::test_cold_run']];

        $skipped = $this->matrix(['--php=8.4', '--laravel=13', '--db=mysql', '--no-lowest']);

        self::assertSame(1, $skipped['exit'], $skipped['stdout']);
        $runs = array_column($this->onlyLeg($skipped, 'php8.4-laravel13')['runs'], null, 'run');
        self::assertSame('fail', $runs['docker']['verdict']);
        self::assertContains("a test of the required group 'docker' was skipped: Fx\\Tests\\DockerTest::test_cold_run", $runs['docker']['check']['reasons']);
    }

    /**
     * Rejects: a matrix that runs the suite only in memory and on the servers (a test that runs
     * only on a SQLite file never runs), a file suite that the check classifies as another engine,
     * and a file suite that lets the shared-database members skip (--engine-skip).
     */
    public function test_each_variant_runs_the_suite_on_a_sqlite_file_classified_as_sqlite_without_an_engine_skip(): void
    {
        // A file-only failure, as of a test that skips in memory and on the servers.
        $this->scenario['runs']['*/same-none.sqlite-file.suite'] = ['fail' => [['id' => 'Fx\\Tests\\FeatureTest::test_b', 'type' => 'error', 'text' => 'SQLSTATE[HY000]: General error: 5 database is locked']]];

        $run = $this->matrix(['--php=8.4', '--laravel=12', '--db=sqlite', '--mirror=same,separate', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        $leg = $this->onlyLeg($run, 'php8.4-laravel12');
        self::assertSame('fail', $leg['verdict']);
        $runs = array_column($leg['runs'], null, 'run');

        foreach (['same-none', 'separate-none'] as $variant) {
            self::assertArrayHasKey($variant.'.sqlite-file.suite', $runs);
            $check = json_decode((string) file_get_contents($run['dir'].'/logs/php8.4-laravel12/'.$variant.'.sqlite-file.suite.check.json'), true);
            self::assertSame('sqlite/'.str_replace('-', '/', $variant), $check['engine']);
            self::assertSame([], $check['engine_skip'], $variant);
            self::assertSame(count(self::SUITE), $check['counts']['tests'], $variant);
            self::assertSame(0, $check['counts']['skipped'], $variant);
        }

        self::assertSame('fail', $runs['same-none.sqlite-file.suite']['verdict']);
        self::assertSame('pass', $runs['separate-none.sqlite-file.suite']['verdict']);
        self::assertSame('pass', $runs['same-none.sqlite.suite']['verdict']);
        self::assertStringContainsString('unlisted failure on sqlite/same/none: Fx\\Tests\\FeatureTest::test_b: SQLSTATE[HY000]: General error: 5 database is locked', $run['stdout']);

        // The file suite gets no identity log: only the groups runs check the identity line.
        $call = array_column($this->callsOf('bin/test'), null, 'run')['php8.4-laravel12/same-none.sqlite-file.suite'];
        self::assertSame('sqlite', $call['engine']);
        self::assertSame(['--sqlite-file', '--schema=fresh', '--timeout=4800'], $call['wrapper']);
        self::assertArrayNotHasKey('ORRERY_IDENTITY_LOG', $call['env']);
        self::assertNotContains('--engine-skip', $call['phpunit']);

        $summary = (string) file_get_contents($run['dir'].'/summary.md');
        self::assertMatchesRegularExpression('/^\| php8\.4-laravel12 \| same\/none sqlite \(file\) suite \| sqlite\/same\/none \| - \| 3 \| [0-9]+ \| 0 \| 0 \| [1-9][0-9]* \| .+ \| FAIL \|$/m', $summary);
        $json = json_decode((string) file_get_contents($run['dir'].'/summary.json'), true);
        self::assertContains('same-none.sqlite-file.suite', array_column($json['legs'][0]['runs'], 'run'));
    }

    public function test_an_in_memory_skip_of_a_process_control_outside_shared_database_fails(): void
    {
        // test_deadline needs no shared database, so the engine skip does not cover it.
        $this->scenario['runs']['*/same-none.sqlite.groups'] = ['skip' => ['Fx\\Tests\\ProcessTest::test_deadline']];

        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db=sqlite', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        $runs = array_column($this->onlyLeg($run, 'php8.4-laravel13')['runs'], null, 'run');
        self::assertSame('fail', $runs['same-none.sqlite.groups']['verdict']);
        self::assertSame([
            "a test of the required group 'process' was skipped: Fx\\Tests\\ProcessTest::test_deadline (only the members of shared-database may skip in this run)",
            "a test of the required group 'process-control' was skipped: Fx\\Tests\\ProcessTest::test_deadline (only the members of shared-database may skip in this run)",
        ], $runs['same-none.sqlite.groups']['check']['reasons']);
        self::assertSame(['members' => 3, 'exempt' => 2, 'executed' => 0, 'skipped' => 3, 'missing' => 0], $runs['same-none.sqlite.groups']['check']['groups']['process']);
        self::assertSame('pass', $runs['same-none.sqlite-file.groups.reuse']['verdict']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function serverOnlySelections(): iterable
    {
        yield 'MySQL only' => ['mysql'];
        yield 'Postgres only' => ['pgsql'];
    }

    #[DataProvider('serverOnlySelections')]
    public function test_every_leg_runs_the_process_groups_on_file_sqlite_also_with_only_a_server_engine(string $engine): void
    {
        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db='.$engine, '--no-lowest']);

        self::assertSame(0, $run['exit'], $run['stdout']);
        $leg = $this->onlyLeg($run, 'php8.4-laravel13');
        self::assertSame(['same-none.sqlite-file.suite.reuse', 'same-none.sqlite-file.groups.reuse', "same-none.{$engine}.suite.reuse", "same-none.{$engine}.groups.reuse", 'scripts', 'docker'], array_column($leg['runs'], 'run'));
        $file = array_column($leg['runs'], null, 'run')['same-none.sqlite-file.groups.reuse'];
        self::assertSame(['members' => 3, 'exempt' => 0, 'executed' => 3, 'skipped' => 0, 'missing' => 0], $file['check']['groups']['process']);
        self::assertSame('sqlite-file', $file['identity']['evidence']);
    }

    public function test_an_interrupted_matrix_lets_the_running_bin_test_finish_its_cleanup_and_starts_nothing_more(): void
    {
        $this->scenario['runs']['*/same-none.mysql.suite.reuse'] = ['hold' => true];
        $env = $this->matrixEnv([]);
        $matrix = $this->startProcess($this->matrixCommand(['--php=8.4', '--laravel=13', '--db=mysql', '--no-lowest']), $env);
        $this->waitUntil(fn (): bool => file_exists($this->calls.'.held') || ! $matrix->isRunning(), 60.0, 'the held run did not start');
        self::assertTrue($matrix->isRunning(), 'bin/matrix ended before the held run: '.$matrix->getOutput().$matrix->getErrorOutput());

        $matrix->signal(15);
        $matrix->wait();

        self::assertSame(143, $matrix->getExitCode(), $matrix->getOutput().$matrix->getErrorOutput());
        $binTest = $this->callsOf('bin/test');
        $held = array_values(array_filter($binTest, static fn (array $call): bool => $call['run'] === 'php8.4-laravel13/same-none.mysql.suite.reuse'));
        // The held run had --drop-database, got SIGTERM from bin/matrix, and
        // ended by itself: bin/test's cleanup drops the databases then.
        self::assertSame(['--drop-database', '--schema=reuse', '--timeout=4800'], $held[0]['wrapper']);
        self::assertSame('SIGTERM', $held[1]['signal'] ?? null, 'the running bin/test did not get SIGTERM');
        self::assertSame('php8.4-laravel13/same-none.mysql.suite.reuse', end($binTest)['run'], 'bin/matrix started a run after the interruption');

        $summary = json_decode((string) file_get_contents($this->onlyRunDir().'/summary.json'), true);
        self::assertTrue($summary['interrupted']);
        self::assertSame('fail', $summary['legs'][0]['verdict']);
        self::assertContains('interrupted before same/none mysql groups harness,process,process-control (schema reuse)', $summary['legs'][0]['reasons']);
        self::assertContains('interrupted before group docker', $summary['legs'][0]['reasons']);
    }

    public function test_a_failed_leg_still_gives_every_server_run_its_own_cleanup(): void
    {
        $this->scenario['runs']['php8.3-laravel13/same-none.pgsql.suite.reuse'] = ['fail' => [['id' => 'Fx\\Tests\\FeatureTest::test_a', 'type' => 'error', 'text' => 'SQLSTATE[08006]: connection lost']]];

        $run = $this->matrix(['--php=8.3,8.4', '--laravel=13', '--db=mysql,pgsql', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        self::assertSame(['fail', 'pass'], array_column($run['summary']['legs'], 'verdict'));
        $servers = array_values(array_filter($this->callsOf('bin/test'), static fn (array $call): bool => in_array($call['engine'], ['mysql', 'pgsql'], true)));
        self::assertCount(8, $servers, 'two legs with two runs on each server engine');

        foreach ($servers as $call) {
            self::assertSame(['--drop-database', '--schema=reuse', '--timeout=4800'], $call['wrapper'], $call['run']);
            self::assertMatchesRegularExpression('/^orrery_m_[0-9a-f]{6}_p8[34]l13_sn$/', $call['env']['ORRERY_TEST_DATABASE'] ?? '');
        }
    }

    public function test_a_leg_without_process_tests_fails(): void
    {
        $this->scenario['groups']['process'] = [];
        $this->scenario['groups']['process-control'] = [];

        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db=sqlite', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        $runs = array_column($this->onlyLeg($run, 'php8.4-laravel13')['runs'], null, 'run');

        foreach (['same-none.sqlite.groups', 'same-none.sqlite-file.groups.reuse'] as $name) {
            self::assertSame('fail', $runs[$name]['verdict']);
            self::assertSame([
                "the required group 'process' has no tests, so it ran zero tests",
                "the required group 'process-control' has no tests, so it ran zero tests",
            ], $runs[$name]['check']['reasons'], $name);
        }

        self::assertSame('pass', $runs['same-none.sqlite.suite']['verdict']);
    }

    public function test_an_unconditional_failure_fails_its_leg_although_the_manifest_lists_it(): void
    {
        $this->scenario['runs']['*/same-none.sqlite.groups'] = ['fail' => [['id' => 'Fx\\Tests\\HarnessTest::test_identity', 'type' => 'failure', 'text' => 'identity drift on the primary connection']]];
        $this->scenario['runs']['*/scripts'] = ['fail' => [['id' => 'Fx\\Tests\\WorkbenchIsolationTest::test_guard', 'type' => 'error', 'text' => 'isolation broken: a guard let the path through']]];

        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db=sqlite', '--no-lowest']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        $runs = array_column($this->onlyLeg($run, 'php8.4-laravel13')['runs'], null, 'run');
        self::assertSame('fail', $runs['same-none.sqlite.groups']['verdict']);
        self::assertSame([['id' => 'Fx\\Tests\\HarnessTest::test_identity', 'entry' => 'group harness', 'manifest_line' => 12, 'message' => 'identity drift on the primary connection']], $runs['same-none.sqlite.groups']['check']['unconditional']);
        self::assertSame('fail', $runs['scripts']['verdict']);
        self::assertSame('Fx\\Tests\\WorkbenchIsolationTest', $runs['scripts']['check']['unconditional'][0]['entry']);
        self::assertSame(13, $runs['scripts']['check']['unconditional'][0]['manifest_line']);
    }

    public function test_the_ci_mode_runs_one_leg_in_place_with_the_provisioned_databases(): void
    {
        $token = str_repeat('ab', 16);
        $run = $this->matrix(['--ci', '--php=8.4', '--laravel=13', '--db=sqlite,mysql,pgsql', '--no-lowest'], [
            'CI' => 'true',
            'ORRERY_TEST_DATABASE' => 'orrery_ci_123_4',
            'ORRERY_TEST_TOKEN' => $token,
        ]);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertDirectoryDoesNotExist($run['dir'].'/php8.4-laravel13', 'CI runs in place');
        self::assertDirectoryDoesNotExist($run['dir'].'/snapshot');
        self::assertSame(['orrery_ci_123_4'], $this->onlyLeg($run, 'php8.4-laravel13')['databases']);
        self::assertNotContains('docker', array_column($this->onlyLeg($run, 'php8.4-laravel13')['runs'], 'run'));

        foreach ($this->callsOf('composer') as $call) {
            self::assertSame($this->source, $call['cwd']);
        }

        foreach ($this->callsOf('bin/test') as $call) {
            self::assertSame($this->source, $call['cwd']);

            // CI signs off with the fresh mode on every engine.
            if (! str_ends_with($call['run'], '/list')) {
                self::assertContains('--schema=fresh', $call['wrapper'], $call['run']);
            }

            if ($call['engine'] === 'sqlite') {
                self::assertArrayNotHasKey('ORRERY_TEST_TOKEN', $call['env'], $call['run']);

                continue;
            }

            self::assertSame(['--no-provision', '--schema=fresh', '--timeout=4800'], $call['wrapper'], $call['run']);
            self::assertSame('orrery_ci_123_4', $call['env']['ORRERY_TEST_DATABASE'] ?? null);
            self::assertSame($token, $call['env']['ORRERY_TEST_TOKEN'] ?? null);
        }
    }

    /**
     * Rejects: a --memory-only leg that still runs a SQLite file or a server engine, the scripts or
     * workbench unit or the docker group (the quick CI tier runs only the fast suite), that drops the
     * in-memory required groups, or that runs another schema mode than fresh.
     * Still passes: a --memory-only leg whose in-memory runs take as long as before (the test counts
     * runs, not time).
     */
    public function test_memory_only_runs_only_the_in_memory_sqlite_suite_and_groups(): void
    {
        $run = $this->matrix(['--ci', '--memory-only', '--php=8.5', '--laravel=13', '--no-lowest'], ['CI' => 'true']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertSame(['same-none.sqlite.suite', 'same-none.sqlite.groups'], array_column($this->onlyLeg($run, 'php8.5-laravel13')['runs'], 'run'));

        foreach ($this->callsOf('bin/test') as $call) {
            if (! str_ends_with($call['run'], '/list')) {
                self::assertSame('sqlite', $call['engine'], $call['run']);
                self::assertNotContains('--sqlite-file', $call['wrapper'], $call['run']);
                self::assertContains('--schema=fresh', $call['wrapper'], $call['run']);
            }
        }
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: array<string, string>, 2: string}>
     */
    public static function refusedSelections(): iterable
    {
        yield 'separate only on legs without separate variants' => [['--mirror=separate'], [], 'leaves the leg php8.3-laravel12 without a variant'];
        yield 'an unknown PHP version' => [['--php=7.4'], [], "'7.4' is not one of 8.3, 8.4, 8.5"];
        yield 'no lowest and no latest' => [['--no-lowest', '--no-latest'], [], 'leave no leg'];
        yield 'lowest legs without PHP 8.3' => [['--php=8.4', '--no-latest'], [], 'the selection has no leg'];
        yield 'an unknown option' => [['--bogus'], [], 'unknown argument --bogus'];
        yield 'an unknown variant' => [['--variants=mixed/none'], [], "--variants: 'mixed/none' is not one of same/none, separate/none, same/prefix, separate/prefix"];
        yield 'a variant that only the variant leg has, on every leg' => [['--variants=separate/prefix'], [], '--variants=separate/prefix leaves the leg php8.3-laravel12 without a variant'];
        yield 'a variant that --mirror leaves out' => [['--php=8.4', '--laravel=12', '--no-lowest', '--mirror=same', '--variants=separate/none'], [], 'leaves the leg php8.4-laravel12 without a variant'];
        yield 'CI mode outside CI' => [['--ci', '--php=8.4', '--laravel=13', '--db=sqlite', '--no-lowest'], [], 'runs only where CI=true'];
        yield 'CI mode with two legs' => [['--ci', '--php=8.4', '--db=sqlite', '--no-lowest'], ['CI' => 'true'], '--ci runs exactly one leg'];
        yield '--quick with --php' => [['--quick', '--php=8.4'], [], '--quick selects its own legs, so it takes no --php'];
        yield '--quick with --laravel' => [['--quick', '--laravel=13'], [], '--quick selects its own legs, so it takes no --laravel'];
        yield '--quick with --no-lowest' => [['--quick', '--no-lowest'], [], '--quick selects its own legs, so it takes no --no-lowest'];
        yield '--quick with --no-latest' => [['--quick', '--no-latest'], [], '--quick selects its own legs, so it takes no --no-latest'];
        yield 'an option of the removed parallel mode' => [['--jobs=2'], [], 'unknown argument --jobs=2'];
        yield '--memory-only with a server engine' => [['--memory-only', '--db=sqlite,mysql'], [], '--memory-only runs in-memory SQLite only, so it takes no --db'];
        yield '--memory-only with --quick' => [['--memory-only', '--quick'], [], '--quick selects its own legs, so it takes no --memory-only'];
        yield 'CI mode without a token' => [['--ci', '--php=8.4', '--laravel=13', '--db=mysql', '--no-lowest'], ['CI' => 'true', 'ORRERY_TEST_DATABASE' => 'orrery_ci_1_1'], 'needs ORRERY_TEST_TOKEN'];
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    #[DataProvider('refusedSelections')]
    public function test_an_invalid_selection_is_refused_before_any_work(array $arguments, array $env, string $message): void
    {
        $run = $this->matrix($arguments, $env);

        self::assertSame(64, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString($message, $run['stderr']);
        self::assertSame([], array_diff(scandir($this->out) ?: [], ['.', '..']), 'a refused selection created a run directory');
        self::assertFileDoesNotExist($this->calls);
    }

    public function test_a_dry_run_prints_the_legs_and_their_runs_and_writes_nothing(): void
    {
        $run = $this->matrix(['--dry-run', '--laravel=12', '--db=sqlite,pgsql']);

        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertStringContainsString('bin/matrix --dry-run: 4 legs, 11 units, from ', $run['stdout']);
        self::assertStringContainsString('php8.4-laravel12: PHP 8.4 ('.$this->work.'/php/php8.4), Laravel 12, latest, orchestra/testbench:^10.0', $run['stdout']);
        self::assertStringContainsString('variants: same/none, separate/none, same/prefix, separate/prefix; engines: sqlite, pgsql; file runs: --schema=fresh; server runs: --schema=reuse', $run['stdout']);
        self::assertStringContainsString("  unit same/none (database orrery_m_<run>_p84l12_sn):\n  - same/none sqlite (memory) suite\n", $run['stdout']);
        self::assertStringContainsString("  unit separate/prefix (database orrery_m_<run>_p84l12_pp):\n", $run['stdout']);
        self::assertStringContainsString("  - separate/prefix sqlite (file) groups harness,process,process-control\n  - separate/prefix pgsql suite (schema reuse)\n", $run['stdout']);
        self::assertStringNotContainsString('unit reuse', $run['stdout']);
        self::assertStringContainsString("  alone, after every leg:\n  - group docker\n", $run['stdout']);
        self::assertStringContainsString('php8.3-laravel12-lowest: PHP 8.3 ('.$this->work.'/php/php8.3), Laravel 12, lowest (--prefer-lowest --prefer-stable, COMPOSER_NO_BLOCKING=1)', $run['stdout']);
        self::assertStringContainsString("  - same/none sqlite (memory) groups harness,process,process-control\n  - same/none sqlite (file) suite\n  - same/none sqlite (file) groups harness,process,process-control\n", $run['stdout']);
        self::assertStringContainsString('  - separate/prefix sqlite (file) suite', $run['stdout']);
        self::assertStringContainsString('  - group docker', $run['stdout']);
        self::assertSame([], array_diff(scandir($this->out) ?: [], ['.', '..']));
        self::assertFileDoesNotExist($this->calls);
    }

    /**
     * Rejects: a default matrix without the MariaDB leg, MariaDB on every leg without --db (hours
     * more, and CI legs without a MariaDB service), a MariaDB leg that skips the suite or the process
     * groups, and a --db=mariadb that does not run MariaDB on each selected leg.
     * Still passes: a MariaDB run that bin/test cannot start (the fake bin/test accepts every engine).
     */
    public function test_without_db_only_the_mariadb_leg_runs_on_mariadb(): void
    {
        $default = $this->matrix(['--dry-run', '--php=8.4,8.5', '--laravel=13', '--no-lowest']);

        self::assertSame(0, $default['exit'], $default['stderr']);
        self::assertStringContainsString('variants: same/none; engines: sqlite, mysql, pgsql; file runs: --schema=reuse', $default['stdout']);
        self::assertStringContainsString('variants: same/none; engines: sqlite, mysql, pgsql, mariadb; file runs: --schema=fresh', $default['stdout']);
        self::assertSame(2, substr_count($default['stdout'], ' mariadb '), 'one leg: its suite and its process groups');
        self::assertStringContainsString("  - same/none mariadb suite\n  - same/none mariadb groups harness,process,process-control\n", $default['stdout']);

        $chosen = $this->matrix(['--dry-run', '--php=8.4,8.5', '--laravel=13', '--no-lowest', '--db=sqlite,mariadb']);

        self::assertSame(0, $chosen['exit'], $chosen['stderr']);
        self::assertSame(2, substr_count($chosen['stdout'], 'engines: sqlite, mariadb;'));
        self::assertStringNotContainsString(' mysql ', $chosen['stdout']);
        self::assertStringContainsString('  - same/none mariadb suite (schema reuse)', $chosen['stdout']);
    }

    /**
     * Rejects: a CI leg that adds MariaDB without --db (its job has no MariaDB service), and a
     * --db=mariadb CI leg that runs MariaDB without --no-provision and the provisioned database.
     * Still passes: a CI MariaDB run on another port (bin/test --no-provision sets the port).
     */
    public function test_the_ci_mode_runs_mariadb_only_when_db_names_it(): void
    {
        $token = str_repeat('cd', 16);
        $env = ['CI' => 'true', 'ORRERY_TEST_DATABASE' => 'orrery_ci_123_mariadb', 'ORRERY_TEST_TOKEN' => $token];
        $default = $this->matrix(['--ci', '--php=8.5', '--laravel=13', '--no-lowest'], $env);

        self::assertSame(0, $default['exit'], $default['stdout'].$default['stderr']);
        self::assertNotContains('mariadb', array_column($this->callsOf('bin/test'), 'engine'));

        $run = $this->matrix(['--ci', '--php=8.5', '--laravel=13', '--no-lowest', '--db=mariadb'], $env);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $mariadb = array_values(array_filter($this->callsOf('bin/test'), static fn (array $call): bool => $call['engine'] === 'mariadb'));
        self::assertCount(2, $mariadb);

        foreach ($mariadb as $call) {
            self::assertSame(['--no-provision', '--schema=fresh', '--timeout=4800'], $call['wrapper'], $call['run']);
            self::assertSame('orrery_ci_123_mariadb', $call['env']['ORRERY_TEST_DATABASE'] ?? null);
            self::assertSame($token, $call['env']['ORRERY_TEST_TOKEN'] ?? null);
            self::assertSame('mariadb', $call['env']['ORRERY_EXPECT_DRIVER'] ?? null);
        }
    }

    /**
     * Rejects: a CI leg that drops ORRERY_TEST_SERVER_VERSION (bin/matrix removes every inherited
     * ORRERY_* variable, so the job of the previous MariaDB LTS release would expect 12.3 and fail),
     * that hands it to the SQLite runs (the harness refuses it there), or that passes it outside --ci.
     * Still passes: a value off the allowed list (the harness refuses it, not bin/matrix).
     */
    public function test_the_ci_mode_passes_the_server_release_to_the_server_runs_only(): void
    {
        $token = str_repeat('cd', 16);
        $env = ['CI' => 'true', 'ORRERY_TEST_DATABASE' => 'orrery_ci_123_mariadb_lts', 'ORRERY_TEST_TOKEN' => $token, 'ORRERY_TEST_SERVER_VERSION' => '11.8'];
        $run = $this->matrix(['--ci', '--php=8.5', '--laravel=13', '--no-lowest', '--db=mariadb'], $env);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $calls = $this->callsOf('bin/test');
        self::assertContains('mariadb', array_column($calls, 'engine'));

        foreach ($calls as $call) {
            if ($call['engine'] === 'mariadb') {
                self::assertSame('11.8', $call['env']['ORRERY_TEST_SERVER_VERSION'] ?? null, $call['run']);
            } else {
                self::assertArrayNotHasKey('ORRERY_TEST_SERVER_VERSION', $call['env'], $call['run']);
            }
        }

        @unlink($this->calls);
        $local = $this->matrix(['--php=8.4', '--laravel=13', '--db=sqlite', '--no-lowest'], ['ORRERY_TEST_SERVER_VERSION' => '11.8']);

        self::assertSame(0, $local['exit'], $local['stdout'].$local['stderr']);

        foreach ($this->callsOf('bin/test') as $call) {
            self::assertArrayNotHasKey('ORRERY_TEST_SERVER_VERSION', $call['env'], $call['run']);
        }
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: list<string>, 2: list<string>}>
     */
    public static function scriptCarriers(): iterable
    {
        yield 'the default matrix' => [[], ['php8.3-laravel13', 'php8.4-laravel13', 'php8.5-laravel13', 'php8.3-laravel12-lowest', 'php8.3-laravel13-lowest'], ['php8.3-laravel13', 'php8.4-laravel13', 'php8.5-laravel13']];
        yield 'Laravel 12 only' => [['--laravel=12'], ['php8.3-laravel12', 'php8.4-laravel12', 'php8.5-laravel12', 'php8.3-laravel12-lowest'], ['php8.3-laravel12', 'php8.4-laravel12', 'php8.5-laravel12']];
        yield 'the lowest legs only' => [['--no-latest'], ['php8.3-laravel12-lowest', 'php8.3-laravel13-lowest'], ['php8.3-laravel12-lowest']];
    }

    /**
     * Rejects: a plan that runs the scripts group on every leg (the old cost) or on none of a PHP
     * version, that prefers Laravel 12 when Laravel 13 is selected, that leaves the workbench group
     * out of a leg, that runs docker on every leg or inside a unit, that runs a fresh run in reuse
     * (the lowest legs, the file runs of the variant leg, php8.5-laravel13) or a reuse run fresh
     * (the variant leg's server runs, the other latest legs), that keeps the variant leg's reuse
     * block, and a dry run that does not show the units.
     *
     * @param  list<string>  $extra
     * @param  list<string>  $scripts  the legs that run the scripts group
     * @param  list<string>  $docker  the legs that run the docker group
     */
    #[DataProvider('scriptCarriers')]
    public function test_the_dry_run_shows_the_units_the_schema_modes_and_the_legs_that_run_scripts_and_docker(array $extra, array $scripts, array $docker): void
    {
        $run = $this->matrix(['--dry-run', '--db=sqlite,mysql', ...$extra]);

        self::assertSame(0, $run['exit'], $run['stderr']);
        $plan = self::parseDryRun($run['stdout']);
        self::assertSame($scripts, array_keys(array_filter($plan, static fn (array $units): bool => isset($units['scripts']))));
        self::assertSame($docker, array_keys(array_filter($plan, static fn (array $units): bool => isset($units['docker']))));

        foreach ($plan as $leg => $units) {
            self::assertSame(isset($units['scripts']), ! isset($units['workbench']), "{$leg}: the scripts unit or the workbench unit, exactly one");
            self::assertSame(['groups scripts,workbench (sqlite in memory)'], $units['scripts'] ?? ['groups scripts,workbench (sqlite in memory)']);
            self::assertSame(['group workbench (sqlite in memory)'], $units['workbench'] ?? ['group workbench (sqlite in memory)']);
            self::assertSame(['group docker'], $units['docker'] ?? ['group docker']);
            self::assertSame(isset($units['docker']) ? 'docker' : array_key_last($units), array_key_last($units), "{$leg}: docker runs after every leg");

            $serverFresh = str_ends_with($leg, '-lowest') || $leg === 'php8.5-laravel13';
            $fileFresh = $serverFresh || $leg === 'php8.4-laravel12';

            foreach ($units as $unit => $labels) {
                foreach ($labels as $label) {
                    $memory = str_contains($label, 'sqlite (memory)') || str_contains($label, 'sqlite in memory') || $label === 'group docker';
                    $server = str_contains($label, ' mysql ');
                    $reuse = ! $memory && ! ($server ? $serverFresh : $fileFresh);
                    self::assertSame($reuse, str_ends_with($label, '(schema reuse)'), "{$leg}, {$unit}: {$label}");
                }
            }
        }

        if (isset($plan['php8.4-laravel12'])) {
            self::assertSame(['same/none', 'separate/none', 'same/prefix', 'separate/prefix', isset($plan['php8.4-laravel12']['scripts']) ? 'scripts' : 'workbench'], array_keys(array_diff_key($plan['php8.4-laravel12'], ['docker' => true])));
        }

        self::assertSame([], array_diff(scandir($this->out) ?: [], ['.', '..']));
        self::assertFileDoesNotExist($this->calls);
    }

    /**
     * Rejects: a --quick that runs other legs than the three of the wave merges, that drops a
     * variant of the variant leg or keeps its reuse block, that runs php8.5-laravel13 or
     * the lowest leg in reuse, and that runs docker more than once or on another PHP than 8.4.
     */
    public function test_quick_runs_the_three_wave_merge_legs_and_docker_once(): void
    {
        $dry = $this->matrix(['--quick', '--dry-run', '--db=sqlite,mysql']);
        self::assertSame(0, $dry['exit'], $dry['stderr']);
        self::assertStringContainsString('bin/matrix --dry-run: 3 legs, 9 units, from ', $dry['stdout']);
        $plan = self::parseDryRun($dry['stdout']);
        self::assertSame(['php8.4-laravel12', 'php8.5-laravel13', 'php8.3-laravel13-lowest'], array_keys($plan));
        self::assertSame(['same/none', 'separate/none', 'same/prefix', 'separate/prefix', 'scripts', 'docker'], array_keys($plan['php8.4-laravel12']));
        self::assertSame(['same/none', 'scripts'], array_keys($plan['php8.5-laravel13']));
        self::assertSame(['same/none', 'scripts'], array_keys($plan['php8.3-laravel13-lowest']));

        foreach (['php8.5-laravel13', 'php8.3-laravel13-lowest'] as $leg) {
            foreach (array_merge(...array_values($plan[$leg])) as $label) {
                self::assertStringNotContainsString('(schema reuse)', $label, "{$leg}: {$label}");
            }
        }

        $run = $this->matrix(['--quick', '--db=sqlite,mysql']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertSame(['php8.4-laravel12', 'php8.5-laravel13', 'php8.3-laravel13-lowest'], array_column($run['summary']['legs'], 'leg'));
        $docker = array_values(array_filter(array_column($this->callsOf('bin/test'), 'run'), static fn (string $name): bool => str_ends_with($name, '/docker')));
        self::assertSame(['php8.4-laravel12/docker'], $docker);
    }

    /**
     * Rejects: a docker run inside a leg (beside the leg's leases in a later design, and before a
     * later leg's units), a docker run per leg instead of per PHP version, and a docker run on a
     * lowest leg when a latest leg of its PHP runs it.
     */
    public function test_docker_runs_after_every_leg_once_per_php_version(): void
    {
        $run = $this->matrix(['--php=8.3,8.4', '--laravel=13', '--db=mysql']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertSame(['php8.3-laravel13', 'php8.4-laravel13', 'php8.3-laravel13-lowest'], array_column($run['summary']['legs'], 'leg'));
        $runs = $this->runIntervals();
        $docker = array_values(array_filter($runs, static fn (array $call): bool => str_ends_with($call['run'], '/docker')));
        self::assertSame(['php8.3-laravel13/docker', 'php8.4-laravel13/docker'], array_column($docker, 'run'));
        self::assertSame(['php8.3-laravel13/docker', 'php8.4-laravel13/docker'], array_slice(array_column($runs, 'run'), -2), 'the docker runs are not the last runs');
        $others = array_filter($runs, static fn (array $call): bool => ! str_ends_with($call['run'], '/docker'));
        $lastEnd = max(array_column($others, 'ended'));

        foreach ($docker as $call) {
            self::assertGreaterThan($lastEnd, $call['started'], "{$call['run']} started before the last leg ended");
        }

        self::assertNotContains('docker', array_column($this->onlyLeg($run, 'php8.3-laravel13-lowest')['runs'], 'run'));
    }

    /**
     * Rejects: a matrix that starts a command before the previous one ended, that runs a leg's
     * runs before its Composer step and test list, that interleaves two legs, and that runs a
     * docker run before the last leg ended.
     */
    public function test_every_command_runs_alone_in_the_serial_order(): void
    {
        $run = $this->matrix(['--php=8.3,8.4', '--laravel=13', '--no-lowest', '--db=sqlite,mysql']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $calls = array_values(array_filter(array_map(static fn (string $line): array => json_decode($line, true), file($this->calls, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), static fn (array $call): bool => in_array($call['tool'], ['composer', 'bin/test'], true)));
        $order = array_map(static fn (array $call): string => $call['tool'] === 'composer' ? $call['leg'].'/composer '.$call['command'] : $call['run'], $calls);
        $leg = static fn (string $id): array => [
            "{$id}/composer require", "{$id}/composer update", "{$id}/list",
            "{$id}/same-none.sqlite.suite", "{$id}/same-none.sqlite.groups",
            "{$id}/same-none.sqlite-file.suite.reuse", "{$id}/same-none.sqlite-file.groups.reuse",
            "{$id}/same-none.mysql.suite.reuse", "{$id}/same-none.mysql.groups.reuse",
            "{$id}/scripts",
        ];
        self::assertSame([...$leg('php8.3-laravel13'), ...$leg('php8.4-laravel13'), 'php8.3-laravel13/docker', 'php8.4-laravel13/docker'], $order);
        self::assertSame(1, self::maxOverlap($this->intervals(['composer', 'bin/test'])), 'two commands ran at once');
    }

    /**
     * Rejects: an interrupted leg that passes (its preparation stopped, so it has no run), a
     * reason that does not say what did not run, and a leg that never started and still passes.
     */
    public function test_an_interruption_during_composer_fails_that_leg_and_every_later_leg(): void
    {
        $this->scenario['composer_hold'] = ['php8.3-laravel13/update'];
        $matrix = $this->startProcess($this->matrixCommand(['--php=8.3,8.4', '--laravel=13', '--no-lowest', '--db=sqlite']), $this->matrixEnv([]));
        $this->waitUntil(fn (): bool => file_exists($this->calls.'.composer-held') || ! $matrix->isRunning(), 60.0, 'the held Composer step did not start');
        self::assertTrue($matrix->isRunning(), 'bin/matrix ended before the held Composer step: '.$matrix->getOutput().$matrix->getErrorOutput());

        $matrix->signal(15);
        $matrix->wait();

        self::assertSame(143, $matrix->getExitCode(), $matrix->getOutput().$matrix->getErrorOutput());
        $summary = json_decode((string) file_get_contents($this->onlyRunDir().'/summary.json'), true);
        [$held, $later] = $summary['legs'];
        self::assertSame('fail', $held['verdict']);
        self::assertSame([], $held['runs']);
        self::assertContains('interrupted before same/none sqlite (memory) suite (during the preparation of the leg)', $held['reasons']);
        self::assertSame('fail', $later['verdict']);
        self::assertSame(['interrupted before the preparation of the leg (its first run: same/none sqlite (memory) suite)'], $later['reasons']);
        self::assertSame([], $this->callsOf('bin/test'), 'a run started after the interruption');
        self::assertStringNotContainsString('| PASS |', (string) file_get_contents($this->onlyRunDir().'/summary.md'));
    }

    /**
     * Rejects: an internal error that lets an earlier leg pass although its docker run, which
     * comes after every leg, never ran.
     */
    public function test_an_internal_error_fails_an_earlier_leg_whose_docker_run_did_not_run(): void
    {
        $run = $this->matrix(['--php=8.3,8.4', '--laravel=13', '--no-lowest', '--db=sqlite,mysql'], ['ORRERY_MATRIX_TEST_THROW' => 'php8.4-laravel13/same-none.sqlite.suite']);

        self::assertSame(255, $run['exit'], $run['stdout'].$run['stderr']);
        [$earlier, $current] = $run['summary']['legs'];
        self::assertNotContains('docker', array_column($earlier['runs'], 'run'));
        self::assertSame('fail', $earlier['verdict']);
        self::assertSame(['not run: group docker (bin/matrix stopped with an internal error)'], $earlier['reasons']);
        self::assertSame('fail', $current['verdict']);
    }

    /**
     * Rejects: a matrix that starts the next step of a leg's preparation (Composer update, the test
     * list) after a signal, when the stopped step itself ended with success.
     */
    public function test_nothing_starts_after_a_signal_also_inside_a_preparation(): void
    {
        $this->scenario['composer_hold'] = ['php8.3-laravel13/require'];
        $this->scenario['composer_hold_exit'] = 0;
        $matrix = $this->startProcess($this->matrixCommand(['--php=8.3', '--laravel=13', '--no-lowest', '--db=sqlite']), $this->matrixEnv([]));
        $this->waitUntil(fn (): bool => file_exists($this->calls.'.composer-held') || ! $matrix->isRunning(), 60.0, 'the held Composer step did not start');
        self::assertTrue($matrix->isRunning(), 'bin/matrix ended before the held Composer step: '.$matrix->getOutput().$matrix->getErrorOutput());

        $matrix->signal(15);
        $matrix->wait();

        self::assertSame(143, $matrix->getExitCode(), $matrix->getOutput().$matrix->getErrorOutput());
        self::assertSame(['require'], array_column($this->callsOf('composer'), 'command'), 'a Composer step started after the signal');
        self::assertSame([], $this->callsOf('bin/test'), 'the test list or a run started after the signal');
        $summary = json_decode((string) file_get_contents($this->onlyRunDir().'/summary.json'), true);
        self::assertSame('fail', $summary['legs'][0]['verdict']);
    }

    /**
     * Rejects: a matrix that stops, or reports an interruption, when a run's process cannot start
     * (exit 127), and one that does not record that run.
     */
    public function test_a_run_that_cannot_start_is_recorded_and_the_matrix_goes_on(): void
    {
        // PHP 8.4 answers the version probe and lists the tests, but cannot start any run.
        file_put_contents($this->work.'/php/php8.4', "#!/bin/sh\nif [ \"\$1\" = \"-n\" ] && [ \"\$2\" = \"-r\" ]; then\n    printf '{\"version\":\"8.4.99\",\"binary\":\"%s\"}' \"\$0\"\n    exit 0\nfi\ncase \"\$*\" in *--list-tests-xml*|*fake-composer*) exec ".escapeshellarg(PHP_BINARY)." \"\$@\" ;; esac\nexec ".escapeshellarg($this->work.'/missing/php')." \"\$@\"\n");

        $run = $this->matrix(['--php=8.4,8.5', '--laravel=13', '--no-lowest', '--db=sqlite']);

        self::assertSame(1, $run['exit'], $run['stdout'].$run['stderr']);
        [$broken, $next] = $run['summary']['legs'];
        self::assertSame(['same-none.sqlite.suite', 'same-none.sqlite.groups', 'same-none.sqlite-file.suite.reuse', 'same-none.sqlite-file.groups.reuse', 'scripts'], array_column($broken['runs'], 'run'));
        // The shell cannot run the missing binary: 126 or 127.
        self::assertSame([], array_diff(array_column($broken['runs'], 'exit'), [126, 127]));
        self::assertSame('fail', $broken['verdict']);
        self::assertSame([], $broken['reasons']);
        self::assertFalse($run['summary']['interrupted']);
        self::assertStringNotContainsString('interrupted', $run['stdout']);
        self::assertSame('pass', $next['verdict']);
        self::assertCount(5, $next['runs']);
    }

    /**
     * Rejects: a leg duration that spans the later legs (its docker run comes after them), and a
     * docker run that is not counted in its leg.
     */
    public function test_a_leg_duration_counts_only_its_own_commands(): void
    {
        $this->scenario['runs']['*'] = ['sleep' => 0.7];

        $run = $this->matrix(['--php=8.3,8.4', '--laravel=13', '--no-lowest', '--db=mysql']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $own = ['php8.3-laravel13' => 0.0, 'php8.4-laravel13' => 0.0];
        // The first leg's commands, its docker run included: from the first start to the last end.
        $firstStarted = INF;
        $firstEnded = 0.0;

        foreach ($this->intervals(['composer', 'bin/test']) as $call) {
            $leg = explode('/', $call['run'])[0];
            $own[$leg] += $call['ended'] - $call['started'];

            if ($leg === 'php8.3-laravel13') {
                $firstStarted = min($firstStarted, $call['started']);
                $firstEnded = max($firstEnded, $call['ended']);
            }
        }

        [$first] = $run['summary']['legs'];
        self::assertGreaterThan(0.6, $first['docker_seconds']);
        self::assertGreaterThan($own['php8.3-laravel13'] - 0.1, $first['seconds']);
        // A span from the leg's start to the end of its docker run is at least the span of the
        // leg's own commands, which holds the whole next leg (the docker runs come after every
        // leg). The leg's own duration is shorter by the next leg's work, whatever the load of
        // the machine; 0.05 s is the rounding of the summary's seconds.
        self::assertLessThan($firstEnded - $firstStarted - 0.05, $first['seconds'], 'the leg duration includes the next leg');
    }

    /**
     * Rejects: an internal error that leaves the running bin/test alive (it would keep its lease
     * and databases), that writes no summary, or that ends with a success status.
     */
    public function test_an_internal_error_stops_the_running_child_writes_the_summary_and_fails(): void
    {
        $this->scenario['runs']['*/same-none.sqlite.groups'] = ['hold' => true];

        $run = $this->matrix(['--php=8.4', '--laravel=13', '--no-lowest', '--db=sqlite'], ['ORRERY_MATRIX_TEST_THROW' => 'php8.4-laravel13/same-none.sqlite.groups']);

        self::assertSame(255, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('test: an internal error while php8.4-laravel13/same-none.sqlite.groups runs', $run['stderr'].$run['stdout']);
        $held = array_values(array_filter($this->callsOf('bin/test'), static fn (array $call): bool => $call['run'] === 'php8.4-laravel13/same-none.sqlite.groups' && isset($call['pid'])));
        self::assertCount(1, $held);
        self::assertFalse(posix_kill($held[0]['pid'], 0), 'the running bin/test still runs');
        $markdown = (string) file_get_contents($run['dir'].'/summary.md');
        self::assertStringContainsString('- Internal error: LogicException: test: an internal error while php8.4-laravel13/same-none.sqlite.groups runs', $markdown);
        self::assertSame('pass', array_column($run['summary']['legs'][0]['runs'], 'verdict', 'run')['same-none.sqlite.suite']);
        self::assertSame('fail', $run['summary']['legs'][0]['verdict']);
        self::assertContains('stopped by an internal error: LogicException: test: an internal error while php8.4-laravel13/same-none.sqlite.groups runs', $run['summary']['legs'][0]['reasons']);
        // The legs table (its rows have the snapshot hash); the runs table keeps the run that passed.
        self::assertMatchesRegularExpression('/^\| php8\.4-laravel13 \| [0-9a-f]{12} \| .* \| FAIL \|$/m', $markdown);
        self::assertDoesNotMatchRegularExpression('/^\| php8\.4-laravel13 \| [0-9a-f]{12} \| .* \| PASS \|$/m', $markdown);
    }

    /**
     * Rejects: an internal error that lets a later leg pass without a run, or without a reason.
     */
    public function test_an_internal_error_fails_the_later_legs_that_did_not_run(): void
    {
        $run = $this->matrix(['--php=8.3,8.4', '--laravel=13', '--no-lowest', '--db=sqlite'], ['ORRERY_MATRIX_TEST_THROW' => 'php8.3-laravel13/same-none.sqlite.suite']);

        self::assertSame(255, $run['exit'], $run['stdout'].$run['stderr']);
        [$current, $later] = $run['summary']['legs'];
        self::assertSame('fail', $current['verdict']);
        self::assertSame('fail', $later['verdict']);
        self::assertSame(['not run: bin/matrix stopped with an internal error'], $later['reasons']);
        self::assertSame([], $later['runs']);
    }

    /**
     * Rejects: a matrix that keeps --timeout to itself (bin/test would use
     * its own default), and a summary that says only that a run was
     * stopped, without the test that was running.
     */
    public function test_the_timeout_goes_to_every_bin_test_run_and_the_summary_names_the_running_test(): void
    {
        $this->scenario['runs']['*/same-none.mysql.suite.reuse'] = ['timeout' => 'Fx\\Tests\\FeatureTest::test_b'];

        $run = $this->matrix(['--php=8.4', '--laravel=13', '--db=mysql', '--no-lowest', '--timeout=900']);

        self::assertSame(1, $run['exit'], $run['stdout'].$run['stderr']);

        foreach ($this->callsOf('bin/test') as $call) {
            if (! str_ends_with($call['run'], '/list')) {
                self::assertSame('--timeout=900', end($call['wrapper']), $call['run']);
            }
        }

        $runs = array_column($this->onlyLeg($run, 'php8.4-laravel13')['runs'], null, 'run');
        $timedOut = $runs['same-none.mysql.suite.reuse'];
        $reason = 'bin/test stopped the run at its --timeout=900 s while it ran Fx\\Tests\\FeatureTest::test_b';
        self::assertSame(124, $timedOut['exit']);
        self::assertSame('Fx\\Tests\\FeatureTest::test_b', $timedOut['timed_out_test']);
        self::assertContains($reason, $timedOut['reasons']);
        self::assertNull($runs['same-none.mysql.groups.reuse']['timed_out_test']);
        self::assertStringContainsString($reason, (string) file_get_contents($this->onlyRunDir().'/summary.md'));
    }

    /**
     * Rejects: a matrix that takes no slot (it would run beside another
     * heavy run), one that waits without a limit, one that does not give
     * its token to its bin/test runs (their MySQL and Postgres runs would
     * wait for the matrix itself), and one that waits for the holder whose
     * token it inherited.
     */
    public function test_a_run_holds_the_heavy_run_slot_and_gives_its_token_to_every_bin_test_run(): void
    {
        $slotEnv = ['ORRERY_REGISTRY_TEST' => '1', 'ORRERY_REGISTRY_DIR' => $this->work.'/registry'];
        $arguments = ['--php=8.4', '--laravel=13', '--db=mysql', '--no-lowest'];
        $holder = $this->holdSlot($this->scriptEnv($slotEnv));

        $refused = $this->matrix([...$arguments, '--slot-wait=1'], $slotEnv + ['ORRERY_TEST_SLOT_REPORT_SECONDS' => '1']);
        self::assertSame(72, $refused['exit'], $refused['stdout'].$refused['stderr']);
        self::assertStringContainsString('bin/matrix: waiting for the heavy-run slot, held by run run_'.getmypid().'_', $refused['stdout']);
        self::assertStringContainsString('(--slot-wait=1)', $refused['stdout']);
        self::assertNull($refused['summary']);
        self::assertFileDoesNotExist($this->calls, 'the refused matrix ran a command');

        $nested = $this->matrix($arguments, $slotEnv + ['ORRERY_SLOT_TOKEN' => $holder]);
        self::assertSame(0, $nested['exit'], $nested['stdout'].$nested['stderr']);
        self::assertSame([$holder], array_values(array_unique(array_column(array_column($this->callsOf('bin/test'), 'env'), 'ORRERY_SLOT_TOKEN'))));

        $this->releaseSlot();
        file_put_contents($this->calls, '');
        array_map(fn (string $dir): bool => rename($this->out.'/'.$dir, $this->work.'/old-'.$dir), array_values(array_diff(scandir($this->out) ?: [], ['.', '..'])));
        $run = $this->matrix($arguments, $slotEnv);
        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $calls = $this->callsOf('bin/test');
        self::assertNotSame([], $calls);
        $tokens = array_values(array_unique(array_map(static fn (array $call): ?string => $call['env']['ORRERY_SLOT_TOKEN'] ?? null, $calls)));
        self::assertCount(1, $tokens, 'the bin/test runs did not all get the token of the matrix');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $tokens[0]);
        self::assertNotSame($holder, $tokens[0]);

        // The matrix gave the slot up when it ended.
        $status = $this->testdb(['status', '--no-services'], $this->scriptEnv($slotEnv + ['ORRERY_OPS' => 'fake']));
        self::assertSame(0, $status['exit'], $status['stderr']);
        self::assertNull($status['json']['slot']['holder']);
    }

    // Helpers

    /**
     * The units and their run labels of each leg in a --dry-run output.
     *
     * @return array<string, array<string, list<string>>> leg => unit => labels; the docker run is the unit "docker"
     */
    private static function parseDryRun(string $output): array
    {
        $plan = [];
        $leg = null;
        $unit = null;

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^(php[0-9.]+-laravel[0-9]+(?:-lowest)?): PHP /', $line, $match) === 1) {
                $leg = $match[1];
                $plan[$leg] = [];
            } elseif ($leg !== null && preg_match('/^  unit (.+?)(?: \(database [^)]+\))?:$/', $line, $match) === 1) {
                $unit = $match[1];
                $plan[$leg][$unit] = [];
            } elseif ($leg !== null && $line === '  alone, after every leg:') {
                $unit = 'docker';
                $plan[$leg][$unit] = [];
            } elseif ($leg !== null && $unit !== null && str_starts_with($line, '  - ')) {
                $plan[$leg][$unit][] = substr($line, 4);
            }
        }

        return $plan;
    }

    /**
     * The calls of the tools with their start and end.
     *
     * @param  list<string>  $tools
     * @return list<array{run: string, started: float, ended: float}>
     */
    private function intervals(array $tools): array
    {
        $ends = [];

        foreach ($this->callsOf('end') as $end) {
            $ends[$end['pid']] = $end['ended'];
        }

        $intervals = [];

        foreach ((file($this->calls, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            $call = json_decode($line, true);

            if (in_array($call['tool'], $tools, true) && isset($call['started'])) {
                self::assertArrayHasKey($call['pid'], $ends, 'a call has no end record');
                $intervals[] = ['run' => $call['tool'] === 'composer' ? $call['leg'].'/composer '.$call['command'] : $call['run'], 'started' => (float) $call['started'], 'ended' => (float) $ends[$call['pid']]];
            }
        }

        return $intervals;
    }

    /**
     * Each bin/test call of the run with its start, its end and its database.
     *
     * @return list<array{run: string, started: float, ended: float, database: string|null}>
     */
    private function runIntervals(): array
    {
        $ends = [];

        foreach ($this->callsOf('end') as $end) {
            $ends[$end['pid']] = $end['ended'];
        }

        $runs = [];

        foreach ($this->callsOf('bin/test') as $call) {
            if (! isset($call['started'])) {
                continue;
            }

            self::assertArrayHasKey($call['pid'], $ends, "{$call['run']} has no end record");
            $runs[] = ['run' => $call['run'], 'started' => (float) $call['started'], 'ended' => (float) $ends[$call['pid']], 'database' => $call['env']['ORRERY_TEST_DATABASE'] ?? null];
        }

        return $runs;
    }

    /**
     * The largest number of runs that ran at one time.
     *
     * @param  list<array{started: float, ended: float}>  $runs
     */
    private static function maxOverlap(array $runs): int
    {
        $events = [];

        foreach ($runs as $run) {
            $events[] = [$run['started'], 1];
            $events[] = [$run['ended'], -1];
        }

        // An end before a start at the same time.
        usort($events, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $now = 0;
        $max = 0;

        foreach ($events as [, $change]) {
            $now += $change;
            $max = max($max, $now);
        }

        return $max;
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    private static function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $extraEnv
     * @return array{exit: int, stdout: string, stderr: string, dir: string|null, summary: array<string, mixed>|null}
     */
    private function matrix(array $arguments, array $extraEnv = []): array
    {
        $run = $this->runProcess($this->matrixCommand($arguments), $this->matrixEnv($extraEnv), 120.0);
        $dirs = array_values(array_diff(scandir($this->out) ?: [], ['.', '..']));
        $dir = count($dirs) === 1 ? $this->out.'/'.$dirs[0] : null;
        $summary = $dir !== null && is_file($dir.'/summary.json') ? json_decode((string) file_get_contents($dir.'/summary.json'), true) : null;

        return ['exit' => $run['exit'], 'stdout' => $run['stdout'], 'stderr' => $run['stderr'], 'dir' => $dir, 'summary' => $summary];
    }

    /**
     * Writes the scenario, and returns the environment of bin/matrix.
     *
     * @param  array<string, string>  $extraEnv
     * @return array<string, string|false>
     */
    private function matrixEnv(array $extraEnv): array
    {
        file_put_contents($this->scenarioFile, json_encode($this->scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->scriptEnv(array_merge([
            'ORRERY_MATRIX_TEST' => '1',
            'ORRERY_MATRIX_SOURCE' => $this->source,
            'ORRERY_MATRIX_OUT' => $this->out,
            'ORRERY_MATRIX_BIN_TEST' => self::repoRoot().'/tests/Scripts/Fixtures/matrix/fake-bin-test.php',
            'ORRERY_MATRIX_SCENARIO' => $this->scenarioFile,
            // bin/matrix must keep it away from everything except the
            // Composer calls of a lowest leg.
            'COMPOSER_NO_BLOCKING' => '1',
            'CI' => false,
        ], $extraEnv));
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function matrixCommand(array $arguments): array
    {
        return [
            PHP_BINARY, self::repoRoot().'/bin/matrix', ...$arguments,
            '--composer='.self::repoRoot().'/tests/Scripts/Fixtures/matrix/fake-composer.php',
            '--php-binary=8.3='.$this->work.'/php/php8.3',
            '--php-binary=8.4='.$this->work.'/php/php8.4',
            '--php-binary=8.5='.$this->work.'/php/php8.5',
        ];
    }

    private function onlyRunDir(): string
    {
        $dirs = array_values(array_diff(scandir($this->out) ?: [], ['.', '..']));
        self::assertCount(1, $dirs, 'bin/matrix did not create exactly one run directory');

        return $this->out.'/'.$dirs[0];
    }

    /**
     * @param  array{summary: array<string, mixed>|null, stdout: string}  $run
     * @return array<string, mixed>
     */
    private function onlyLeg(array $run, string $id): array
    {
        self::assertIsArray($run['summary'], 'no summary.json: '.$run['stdout']);
        $legs = array_values(array_filter($run['summary']['legs'], static fn (array $leg): bool => $leg['leg'] === $id));
        self::assertCount(1, $legs);

        return $legs[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function callsOf(string $tool): array
    {
        $calls = [];

        foreach ((is_file($this->calls) ? file($this->calls, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false) ?: [] as $line) {
            $call = json_decode($line, true);

            if ($call['tool'] === $tool) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * @param  list<array<string, mixed>>  $extra
     * @return array<string, mixed>
     */
    private static function lock(string $framework, string $testbench, string $phpunit, array $extra = []): array
    {
        $core = ['v10.11.0' => 'v10.14.1', 'v10.0.0' => 'v10.0.0', 'v11.2.0' => 'v11.4.0', 'v11.0.0' => 'v11.0.0'][$testbench] ?? $testbench;

        return [
            'packages' => [
                ['name' => 'laravel/framework', 'version' => $framework],
                ['name' => 'nesbot/carbon', 'version' => '3.8.4'],
                ...$extra,
            ],
            'packages-dev' => [
                ['name' => 'orchestra/testbench', 'version' => $testbench],
                ['name' => 'orchestra/testbench-core', 'version' => $core],
                ['name' => 'phpunit/phpunit', 'version' => $phpunit],
            ],
        ];
    }

    /**
     * The snapshot source: a git checkout with tracked, new untracked,
     * ignored and deleted files, a link, an executable, and a stale .matrix
     * directory.
     */
    private function makeSource(): void
    {
        mkdir($this->source.'/tests', 0755, true);
        mkdir($this->source.'/bin', 0755, true);
        file_put_contents($this->source.'/composer.json', json_encode(['name' => 'fx/package', 'require' => ['php' => '^8.3', 'illuminate/support' => '^12.0.1|^13.0', 'illuminate/queue' => '^12.0.1|^13.0'], 'require-dev' => ['orchestra/testbench' => '^10.0|^11.0']], JSON_PRETTY_PRINT));
        file_put_contents($this->source.'/phpunit.xml.dist', "<?xml version=\"1.0\"?>\n<phpunit>\n    <groups>\n        <exclude>\n            <group>docker</group>\n            <group>scripts</group>\n            <group>workbench</group>\n        </exclude>\n    </groups>\n</phpunit>\n");
        file_put_contents($this->source.'/tests/unconditional.txt', "# fixture\nharness\nprocess-control\nFx\\Tests\\WorkbenchIsolationTest\n");
        file_put_contents($this->source.'/tests/known-failures.md', implode("\n", [
            '# Known runtime failures (fixture)',
            '',
            'Line 12 lists an unconditional test, and line 13 an unconditional class.',
            '',
            '| test | engine | signature | finding | fixed by |',
            '| --- | --- | --- | --- | --- |',
            '| `Fx\\Tests\\MetricsTest::test_prefixed` | `*/*/prefix` | `INSERT INTO metric_counters` | F10 | Spec B |',
            '| `Fx\\Tests\\FeatureTest::test_b` | `pgsql/*/*` | `only on Postgres` | F1 | Spec B |',
            '| `Fx\\Tests\\FeatureTest::test_b` | `mysql/*/*` | `only on MySQL` | F2 | Spec B |',
            '| `Fx\\Tests\\FeatureTest::test_b` | `sqlite/separate/*` | `only separate` | F3 | Spec B |',
            '| `Fx\\Tests\\FeatureTest::test_b` | `sqlite/same/none` | `plain` | F4 | Spec B |',
            '| `Fx\\Tests\\HarnessTest::test_identity` | `*/*/*` | `identity drift` | F99 | Spec X |',
            '| `Fx\\Tests\\WorkbenchIsolationTest::test_guard` | `*/*/*` | `isolation broken` | F98 | Spec X |',
            '',
        ]));
        file_put_contents($this->source.'/tracked.txt', "tracked\n");
        file_put_contents($this->source.'/deleted.txt', "deleted later\n");
        file_put_contents($this->source.'/bin/tool', "#!/bin/sh\n");
        chmod($this->source.'/bin/tool', 0755);
        symlink('tracked.txt', $this->source.'/link.txt');
        file_put_contents($this->source.'/.gitignore', "/ignored.txt\n");

        foreach ([['init', '--quiet'], ['add', '--all'], ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '-m', 'fixture']] as $arguments) {
            $git = $this->runProcess(['git', '-C', $this->source, ...$arguments], $this->scriptEnv(['GIT_CONFIG_NOSYSTEM' => '1']), 30.0);
            self::assertSame(0, $git['exit'], 'git '.implode(' ', $arguments).': '.$git['stderr']);
        }

        file_put_contents($this->source.'/untracked.txt', "new, not yet added\n");
        file_put_contents($this->source.'/ignored.txt', "ignored\n");
        unlink($this->source.'/deleted.txt');
        mkdir($this->source.'/.matrix/old', 0755, true);
        file_put_contents($this->source.'/.matrix/old/stale.txt', "a previous run\n");
    }

    private function headOf(string $checkout): string
    {
        $head = $this->runProcess(['git', '-C', $checkout, 'rev-parse', 'HEAD'], $this->scriptEnv(), 30.0);

        return trim($head['stdout']);
    }
}
