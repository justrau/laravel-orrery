<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The workflows in .github/workflows/: the jobs of tests.yml, the quick tier
 * and the rules every workflow keeps. The test reads the workflows; it never
 * runs GitHub Actions.
 */
#[Group('scripts')]
final class CiWorkflowTest extends TestCase
{
    /** The longest measured CI leg (2026-09-27, run 36345672089), in minutes. */
    private const LONGEST_LEG_MINUTES = 31;

    /** The expected time of one variant job, in minutes (26 for the variant, the scripts groups and the setup). */
    private const VARIANT_JOB_MINUTES = 33;

    private const VARIANTS = ['same/none', 'separate/none', 'same/prefix', 'separate/prefix'];

    /** GitHub's limit for one job on a hosted runner, in minutes. */
    private const GITHUB_JOB_LIMIT_MINUTES = 360;

    /**
     * Rejects: a workflow that runs the four variants of the PHP 8.4 x Laravel 12 leg in one job (it passed
     * the 60-minute limit), that drops a leg or a variant, that gives a job less than 50% margin over its
     * measured time, that sets a limit above GitHub's job limit (GitHub would stop the job there), that
     * reuses a database name across jobs, or that runs another schema mode than fresh.
     * Still passes: a job whose real time grows past its limit later (the test knows only the measured
     * times above).
     */
    public function test_each_leg_and_each_variant_of_the_variant_leg_is_its_own_job(): void
    {
        $workflow = Yaml::parse((string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/tests.yml'));
        self::assertIsArray($workflow);
        $job = $workflow['jobs']['leg'];
        $jobs = $job['strategy']['matrix']['include'];
        self::assertArrayNotHasKey('php', $job['strategy']['matrix'], 'a matrix axis besides include adds jobs without a variant or a timeout');
        self::assertSame('${{ matrix.timeout }}', $job['timeout-minutes']);

        $seen = [];

        foreach ($jobs as $entry) {
            $key = "{$entry['php']}/{$entry['laravel']}/{$entry['dependencies']}";
            $seen[$key][] = $entry['variant'];
            $minutes = $entry['variant'] === '' ? self::LONGEST_LEG_MINUTES : self::VARIANT_JOB_MINUTES;
            self::assertGreaterThanOrEqual($minutes * 1.5, $entry['timeout'], "{$key} {$entry['variant']}: less than 50% margin");
            self::assertLessThanOrEqual(self::GITHUB_JOB_LIMIT_MINUTES, $entry['timeout'], "{$key} {$entry['variant']}: over GitHub's job limit");
            self::assertSame($entry['variant'] === '' ? '' : '-'.str_replace('/', '-', $entry['variant']), $entry['variant_key'], $key);
        }

        $expected = [];

        foreach (['8.3', '8.4', '8.5'] as $php) {
            foreach (['12', '13'] as $laravel) {
                $expected["{$php}/{$laravel}/latest"] = $php === '8.4' && $laravel === '12' ? self::VARIANTS : [''];
            }
        }

        $expected['8.3/12/lowest'] = [''];
        $expected['8.3/13/lowest'] = [''];
        ksort($expected);
        ksort($seen);
        self::assertSame($expected, $seen);

        // One database name per job: the run id and the job index.
        self::assertSame('orrery_ci_${{ github.run_id }}_${{ strategy.job-index }}', $job['env']['ORRERY_TEST_DATABASE']);
        self::assertSame('${{ matrix.variant }}', $job['env']['VARIANT']);

        $script = implode("\n", array_column($job['steps'], 'run'));
        self::assertStringContainsString('variants=("--variants=${VARIANT}")', $script);
        self::assertStringContainsString('bin/matrix --ci ', $script);
        self::assertStringNotContainsString('--schema=reuse', $script, 'CI signs off in the fresh schema mode only');
        self::assertStringNotContainsString('ORRERY_TEST_SCHEMA', $script);

        $artifact = array_values(array_filter($job['steps'], static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/upload-artifact')))[0];
        self::assertStringEndsWith('${{ matrix.variant_key }}', $artifact['with']['name'], 'two variant jobs would upload one artifact name');
    }

    /**
     * Rejects: a quick tier that a push to main or a pull request does not start, that runs for a
     * docs-only change, that a newer push does not cancel, that drops one of the four in-memory legs
     * or runs them on the full engine set (no --memory-only), that leaves out the engine job, Pint,
     * static analysis or the assets check.
     * Still passes: a leg that passes --memory-only but whose bin/matrix ignores it (MatrixRunnerTest
     * guards that).
     */
    public function test_the_quick_tier_runs_on_pushes_and_pull_requests_with_the_fast_legs(): void
    {
        $workflow = self::workflow('quick.yml');

        self::assertSame(['main'], $workflow['on']['push']['branches']);
        self::assertSame(['docs/**', '**.md'], $workflow['on']['push']['paths-ignore']);
        self::assertSame(['docs/**', '**.md'], $workflow['on']['pull_request']['paths-ignore']);
        self::assertTrue($workflow['concurrency']['cancel-in-progress']);
        self::assertStringContainsString('${{ github.ref }}', $workflow['concurrency']['group']);

        $jobs = $workflow['jobs'];
        self::assertSame('./.github/workflows/static-analysis.yml', $jobs['static-analysis']['uses']);
        self::assertSame('./.github/workflows/assets.yml', $jobs['assets']['uses']);

        foreach (['static-analysis.yml', 'assets.yml'] as $called) {
            self::assertArrayHasKey('workflow_call', self::workflow($called)['on'], "{$called} cannot be called");
        }

        self::assertContains('composer format:check', array_column($jobs['format']['steps'], 'run'));

        $legs = array_map(static fn (array $leg): string => "{$leg['php']}/{$leg['laravel']}/{$leg['dependencies']}", $jobs['memory']['strategy']['matrix']['include']);
        self::assertSame(['8.3/12/lowest', '8.3/13/lowest', '8.5/12/latest', '8.5/13/latest'], $legs);
        self::assertStringContainsString('bin/matrix --ci --memory-only ', implode("\n", array_column($jobs['memory']['steps'], 'run')));

        $engines = $jobs['engines'];
        self::assertSame(['mysql', 'postgres'], array_keys($engines['services']));
        $script = implode("\n", array_column($engines['steps'], 'run'));
        self::assertStringContainsString('bin/matrix --ci ', $script);
        self::assertStringContainsString('--php=8.5 --laravel=13 --no-lowest', $script);
        self::assertStringNotContainsString('--memory-only', $script);
        self::assertStringNotContainsString('--db=', $script, 'the engine job runs every engine');
        self::assertStringNotContainsString('--schema=reuse', $script);
    }

    /**
     * Rejects: a workflow that grants more than read access to the contents, that keeps the
     * checkout credentials, or that uses an action by a tag or a branch instead of a commit SHA.
     * Still passes: a SHA that is not the commit of the release its comment names.
     */
    public function test_every_workflow_reads_only_drops_the_credentials_and_pins_actions_by_sha(): void
    {
        $files = glob(self::root().'/.github/workflows/*.yml') ?: [];
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $name = basename($file);
            $workflow = self::workflow($name);
            self::assertSame(['contents' => 'read'], $workflow['permissions'], $name);

            foreach ($workflow['jobs'] as $id => $job) {
                self::assertArrayNotHasKey('permissions', $job, "{$name} {$id}");

                foreach ($job['steps'] ?? [] as $step) {
                    $uses = (string) ($step['uses'] ?? '');

                    if ($uses === '') {
                        continue;
                    }

                    self::assertMatchesRegularExpression('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40}$/D', $uses, "{$name} {$id}: {$uses}");

                    if (str_starts_with($uses, 'actions/checkout@')) {
                        self::assertFalse($step['with']['persist-credentials'] ?? true, "{$name} {$id}: the checkout keeps its credentials");
                    }
                }
            }
        }
    }

    /**
     * Rejects: a full matrix without the nightly schedule or the manual start, a nightly run that
     * spends the matrix on a main without new commits (no gate, or legs that do not wait for it),
     * and a gate that also skips a manual run.
     * Still passes: a gate whose git log reads a wrong time window (the test reads the script text).
     */
    public function test_the_full_matrix_runs_nightly_only_after_a_recent_commit(): void
    {
        $workflow = self::workflow('tests.yml');

        self::assertCount(1, $workflow['on']['schedule']);
        self::assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/D', $workflow['on']['schedule'][0]['cron'], 'not daily');
        self::assertArrayHasKey('workflow_dispatch', $workflow['on']);

        $gate = $workflow['jobs']['recent'];
        $script = implode("\n", array_column($gate['steps'], 'run'));
        self::assertStringContainsString("git log --since='24 hours ago'", $script);
        self::assertStringContainsString('!= schedule', $script, 'a manual run must not be skipped');
        self::assertSame('recent', $workflow['jobs']['leg']['needs']);
        self::assertSame("needs.recent.outputs.run == 'true'", $workflow['jobs']['leg']['if']);
    }

    /**
     * Rejects: a framework-latest workflow without its weekly schedule or its manual start.
     */
    public function test_the_latest_framework_check_runs_weekly(): void
    {
        $workflow = self::workflow('framework-latest.yml');

        self::assertCount(1, $workflow['on']['schedule'] ?? []);
        self::assertMatchesRegularExpression('/^\d+ \d+ \* \* [0-6]$/D', $workflow['on']['schedule'][0]['cron'], 'not weekly');
        self::assertArrayHasKey('workflow_dispatch', $workflow['on']);
    }

    /**
     * Rejects: a zizmor workflow that a change of .github/ does not start, that runs zizmor at a
     * floating version, and a Dependabot configuration that updates anything but the GitHub
     * Actions, or less often than weekly.
     * Still passes: a pinned zizmor version that does not exist (only CI can show that).
     */
    public function test_zizmor_lints_workflow_changes_and_dependabot_updates_the_actions_weekly(): void
    {
        $workflow = self::workflow('zizmor.yml');

        self::assertSame(['.github/**'], $workflow['on']['push']['paths']);
        self::assertSame(['.github/**'], $workflow['on']['pull_request']['paths']);
        self::assertMatchesRegularExpression('/zizmor==\d+\.\d+\.\d+ --offline /', implode("\n", array_column($workflow['jobs']['zizmor']['steps'], 'run')));

        $dependabot = Yaml::parse((string) file_get_contents(self::root().'/.github/dependabot.yml'));
        self::assertIsArray($dependabot);
        self::assertSame(2, $dependabot['version']);
        self::assertSame([['package-ecosystem' => 'github-actions', 'directory' => '/', 'schedule' => ['interval' => 'weekly']]], array_map(
            static fn (array $update): array => array_intersect_key($update, array_flip(['package-ecosystem', 'directory', 'schedule'])),
            $dependabot['updates'],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function workflow(string $name): array
    {
        $workflow = Yaml::parse((string) file_get_contents(self::root().'/.github/workflows/'.$name));
        self::assertIsArray($workflow);

        return $workflow;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
