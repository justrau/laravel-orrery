<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * bin/benchmark-tick: the argument checks that run
 * before any bin/test run, and its tmpfs sizes. The tests never start a
 * benchmark, a container or a database.
 */
#[Group('scripts')]
final class BenchmarkTickScriptTest extends TestCase
{
    /**
     * Rejects: a script that starts a run for a bad engine, an unknown option or a base name of another task.
     * Still passes: a script that checks the arguments but later ignores --share.
     *
     * @param  list<string>  $arguments
     */
    #[DataProvider('refusedArguments')]
    public function test_bad_arguments_exit_64_before_any_run(array $arguments, string $message): void
    {
        $process = new Process([PHP_BINARY, self::script(), ...$arguments], dirname(self::script(), 2));
        $process->setTimeout(20);
        $process->run();

        self::assertSame(64, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString($message, $process->getOutput().$process->getErrorOutput());
        self::assertStringNotContainsString('PHPUnit', $process->getOutput(), 'no bin/test run started');
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function refusedArguments(): array
    {
        return [
            'no engine' => [[], 'bin/benchmark-tick <sqlite|mysql|pgsql>'],
            'unknown engine' => [['mariadb'], 'the engine is sqlite, mysql or pgsql'],
            'unknown option' => [['mysql', '--rows'], 'unknown argument --rows'],
            'base of another task' => [['mysql', '--base=orrery_b21'], 'must start with orrery_b22_bm'],
        ];
    }

    /**
     * Rejects: a tmpfs size in the script that differs from docker/testing/compose.yaml, so a run could size itself past the tmpfs.
     * Still passes: a script that ignores its own constant (the test reads only the text).
     */
    public function test_the_tmpfs_sizes_match_the_compose_file(): void
    {
        $script = (string) file_get_contents(self::script());
        self::assertSame(1, preg_match("/const TMPFS_MB = \\['mysql' => (\\d+), 'pgsql' => (\\d+)\\];/", $script, $match));

        /** @var array{services: array<string, array<string, mixed>>} $compose */
        $compose = Yaml::parseFile(dirname(self::script(), 2).'/docker/testing/compose.yaml');

        foreach (['mysql' => (int) $match[1], 'postgres' => (int) $match[2]] as $service => $megabytes) {
            $sizes = [];

            foreach ((array) ($compose['services'][$service]['volumes'] ?? []) as $volume) {
                if (is_array($volume) && ($volume['type'] ?? null) === 'tmpfs') {
                    $sizes[] = (int) ($volume['tmpfs']['size'] ?? 0);
                }
            }

            self::assertSame([$megabytes * 1_048_576], $sizes, "the tmpfs of {$service}");
        }
    }

    /**
     * Rejects: an explicit --rows or --sizes that skips the capacity probe, so `bin/benchmark-tick mysql --rows=1000000` fills the shared tmpfs.
     * Still passes: a probe report whose numbers are wrong (the fake report is trusted).
     *
     * @param  list<string>  $arguments
     */
    #[DataProvider('oversizedRequests')]
    public function test_an_oversized_explicit_request_is_refused_before_any_seed(array $arguments): void
    {
        $process = $this->runWithFakeProbe($arguments);

        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('refused', $process->getErrorOutput());
        self::assertStringNotContainsString('Plan:', $process->getOutput());
        self::assertStringNotContainsString('PHPUnit', $process->getOutput().$process->getErrorOutput(), 'no bin/test run started');
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function oversizedRequests(): array
    {
        return [
            'rows' => [['mysql', '--rows=1000000']],
            'sizes' => [['mysql', '--sizes=10000,26000']],
            'dry run' => [['mysql', '--rows=30000', '--dry-run']],
            'postgres' => [['pgsql', '--rows=1000000']],
        ];
    }

    /**
     * Rejects: a capacity option that raises the 60% ceiling, or a probe or payload that could itself be large.
     * Still passes: a script that validates the options and then ignores them.
     *
     * @param  list<string>  $arguments
     */
    #[DataProvider('ceilingOptions')]
    public function test_capacity_options_can_only_lower_the_limit(array $arguments, string $message): void
    {
        $process = $this->runWithFakeProbe($arguments);

        self::assertSame(64, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString($message, $process->getErrorOutput());
        self::assertStringNotContainsString('Plan:', $process->getOutput());
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function ceilingOptions(): array
    {
        return [
            'share above 0.6' => [['mysql', '--share=0.61'], 'at most 0.6'],
            'share 1' => [['pgsql', '--share=1'], 'at most 0.6'],
            'smaller reserve' => [['mysql', '--reserve-mb=199'], 'at least 200'],
            'larger tmpfs' => [['mysql', '--tmpfs-mb=513'], 'at most the container tmpfs'],
            'large payload' => [['pgsql', '--payload=8193'], 'from 200 to 8192'],
            'large probe' => [['mysql', '--probe-rows=5001'], 'from 100 to 5000'],
        ];
    }

    /**
     * Rejects: a size inside the limit that is refused, or a fake probe that can start a seed.
     * Still passes: a limit that is lower than it has to be.
     */
    public function test_an_allowed_request_prints_its_plan_and_the_fake_probe_never_runs(): void
    {
        $process = $this->runWithFakeProbe(['mysql', '--rows=20000']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('at most 25000 rows', $process->getOutput());
        self::assertStringContainsString('Plan: mysql sizes 10000, 20000.', $process->getOutput());
        self::assertStringNotContainsString('PHPUnit', $process->getOutput().$process->getErrorOutput(), 'the fake probe never starts a run');
    }

    /**
     * Rejects: a script that seeds the probe (about 32 MB) before it reads the used server bytes, so a nearly full tmpfs gets filled by the probe itself.
     * Still passes: a used-bytes read that is wrong (the fake report is trusted), or a check that runs the probe and then refuses.
     *
     * @param  list<string>  $arguments
     */
    #[DataProvider('fullServers')]
    public function test_the_probe_is_refused_when_it_would_not_fit_beside_the_reserve(array $arguments): void
    {
        // Before the probe, the servers use 90 MB (MySQL) or 530 MB (Postgres). The budget is
        // 60% of the tmpfs less the used bytes and the 200 MB reserve: MySQL 307.2 - 90 - 200 =
        // 17.2 MB; Postgres with --tmpfs-mb=800 480 - 530 - 200, below zero. The probe needs
        // about 5,000 x 4,000 bytes (19.1 MB). The fake probe report itself (3 MB in use) would
        // allow the run.
        $process = $this->runWithFakeProbe($arguments, $arguments[0] === 'mysql' ? 94_371_840 : 555_745_280);

        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('the probe itself', $process->getErrorOutput());
        self::assertStringNotContainsString('Probe:', $process->getOutput(), 'no probe report was read');
        self::assertStringNotContainsString('Plan:', $process->getOutput());
        self::assertStringNotContainsString('PHPUnit', $process->getOutput().$process->getErrorOutput(), 'no bin/test run started');
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function fullServers(): array
    {
        return [
            'mysql dry run' => [['mysql', '--dry-run']],
            'mysql with rows' => [['mysql', '--rows=2000']],
            'postgres' => [['pgsql', '--tmpfs-mb=800', '--sizes=100,200']],
        ];
    }

    /**
     * Rejects: a used-bytes check that refuses a probe that fits (a check against the probe
     * without the reserve, or against the whole used budget).
     * Still passes: a check that is a little more generous than the budget.
     */
    public function test_a_probe_that_fits_beside_the_reserve_runs(): void
    {
        // Budget with 60 MB in use: 307.2 - 60 - 200 = 47.2 MB, more than the 19 MB probe.
        $process = $this->runWithFakeProbe(['mysql', '--rows=2000'], 62_914_560);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('Probe:', $process->getOutput());
        self::assertStringContainsString('Plan: mysql sizes 1000, 2000.', $process->getOutput());
    }

    /**
     * Runs the script with a probe report from a file: 3,281 bytes per row and 28 MB in use, so
     * the MySQL limit is 25,000 rows. $usedBefore is the used server bytes that the check before
     * the probe reads (`used_bytes` of the fake report; else its `server_bytes`).
     *
     * @param  list<string>  $arguments
     */
    private function runWithFakeProbe(array $arguments, ?int $usedBefore = null): Process
    {
        $probe = (string) tempnam(sys_get_temp_dir(), 'orrery-probe-');
        $report = ['bytes_per_row' => 3281, 'server_bytes' => 29_278_208, 'table_bytes' => ['jobs' => 1]];

        if ($usedBefore !== null) {
            $report['used_bytes'] = $usedBefore;
            $report['server_bytes'] = 3_145_728;
        }

        file_put_contents($probe, (string) json_encode($report));

        try {
            $process = new Process([PHP_BINARY, self::script(), ...$arguments], dirname(self::script(), 2), ['ORRERY_BENCHMARK_FAKE_PROBE' => $probe]);
            $process->setTimeout(20);
            $process->run();

            return $process;
        } finally {
            @unlink($probe);
        }
    }

    private static function script(): string
    {
        return dirname(__DIR__, 2).'/bin/benchmark-tick';
    }
}
