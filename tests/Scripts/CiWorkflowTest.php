<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The jobs of .github/workflows/tests.yml. The test reads the workflow; it
 * never runs GitHub Actions.
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
}
