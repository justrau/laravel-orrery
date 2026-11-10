<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use Justrau\Orrery\Tests\Support\LeaseProof;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The lease proof of tests/bootstrap.php, in a real PHPUnit process started
 * directly (vendor/bin/phpunit), without bin/test and without Docker. The
 * registry is a private test registry with the fake backend, so nothing
 * reaches the canonical registry or the containers.
 *
 * A refused run exits 78 before PHPUnit runs any test, and its message points
 * to bin/test. The probe (Fixtures/BootstrapProbe.php) records whether a test
 * ran. It is in the group scripts, like the other script tests.
 */
#[Group('scripts')]
#[RequiresPhpExtension('posix')]
final class HarnessBootstrapTest extends TestCase
{
    use RunsScripts;

    private const PROBE = 'tests/Scripts/Fixtures/BootstrapProbe.php';

    private const BASE = 'orrery_boot';

    private string $registry;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->makeTempDir('orrery-boot-').'/registry';
        $this->out = $this->makeTempDir('orrery-boot-out-');
    }

    protected function tearDown(): void
    {
        $this->cleanUpScripts();
        parent::tearDown();
    }

    public function test_server_variables_without_an_admitted_run_are_refused_before_any_test(): void
    {
        $run = $this->phpunit($this->server());

        self::assertSame(78, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('Run the suite through bin/test mysql', $run['stderr']);
        self::assertFileDoesNotExist($this->out.'/ran');
    }

    public function test_a_run_id_without_a_lease_is_refused_before_any_test(): void
    {
        $run = $this->phpunit($this->server(['ORRERY_TEST_RUN_ID' => self::runId()]) + $this->registryEnv());

        self::assertSame(78, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('holds no lease', $run['stderr']);
        self::assertStringContainsString('Run the suite through bin/test mysql', $run['stderr']);
        self::assertFileDoesNotExist($this->out.'/ran');
    }

    public function test_a_lease_that_does_not_list_this_process_group_is_refused(): void
    {
        [$runId, $token] = $this->admit(false);

        $run = $this->phpunit($this->server(['ORRERY_TEST_RUN_ID' => $runId, 'ORRERY_TEST_TOKEN' => $token]) + $this->registryEnv());

        self::assertSame(78, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('does not list the process group', $run['stderr']);
        self::assertFileDoesNotExist($this->out.'/ran');
    }

    public function test_a_reused_group_number_is_refused_although_the_lease_is_live(): void
    {
        // The lease lists this process group by number, but with another
        // leader start: the recorded group ended, and this group reused the
        // number. The live supervisor keeps the lease live.
        [$runId, $token] = $this->admit(true, 'Thu Jan 1 00:00:00 2026');

        $run = $this->phpunit($this->server(['ORRERY_TEST_RUN_ID' => $runId, 'ORRERY_TEST_TOKEN' => $token]) + $this->registryEnv());

        self::assertSame(78, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('as owned now', $run['stderr']);
        self::assertFileDoesNotExist($this->out.'/ran');
    }

    public function test_a_lease_that_holds_the_names_and_this_process_group_lets_the_tests_run(): void
    {
        [$runId, $token] = $this->admit(true);

        $run = $this->phpunit($this->server(['ORRERY_TEST_RUN_ID' => $runId, 'ORRERY_TEST_TOKEN' => $token]) + $this->registryEnv());

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertFileExists($this->out.'/ran');
    }

    public function test_ci_mode_has_no_registry_and_skips_the_lease(): void
    {
        $run = $this->phpunit($this->server(['ORRERY_TEST_CI' => '1']));

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertFileExists($this->out.'/ran');
        self::assertDirectoryDoesNotExist($this->registry);
    }

    public function test_an_invalid_configuration_is_refused_before_any_test(): void
    {
        $run = $this->phpunit(['ORRERY_TEST_PREFIX' => 'Not-A-Prefix']);

        self::assertSame(78, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('ORRERY_TEST_PREFIX must match', $run['stderr']);
        self::assertFileDoesNotExist($this->out.'/ran');
    }

    /**
     * Rejects: a bootstrap that lets the reuse schema mode start on in-memory SQLite, or an unknown mode start at all.
     */
    public function test_an_impossible_schema_mode_is_refused_before_any_test(): void
    {
        $memory = $this->phpunit(['ORRERY_TEST_SCHEMA' => 'reuse']);

        self::assertSame(78, $memory['exit'], $memory['stdout'].$memory['stderr']);
        self::assertStringContainsString('In-memory SQLite runs only the fresh mode', $memory['stderr']);
        self::assertFileDoesNotExist($this->out.'/ran');

        $unknown = $this->phpunit(['ORRERY_TEST_SCHEMA' => 'sometimes']);

        self::assertSame(78, $unknown['exit'], $unknown['stdout'].$unknown['stderr']);
        self::assertStringContainsString("ORRERY_TEST_SCHEMA must be fresh or reuse, not 'sometimes'", $unknown['stderr']);
        self::assertFileDoesNotExist($this->out.'/ran');

        $fresh = $this->phpunit(['ORRERY_TEST_SCHEMA' => 'fresh']);
        self::assertSame(0, $fresh['exit'], $fresh['stdout'].$fresh['stderr']);
        self::assertFileExists($this->out.'/ran');
    }

    /**
     * Admits a run in the test registry with the fake backend, with a live
     * supervisor, and optionally records this process group as its group
     * child (as the join of bin/test's anchor does). The entry records the
     * real start of the group's leader, or $leaderStart instead.
     *
     * @return array{0: string, 1: string}  the run id and the registry token
     */
    private function admit(bool $withGroup, ?string $leaderStart = null): array
    {
        $supervisor = $this->spawnSleeper();
        $runId = self::runId();
        $admit = $this->testdb(['admit', $runId, (string) $supervisor['pid'], $supervisor['start'], self::BASE, '--explicit', '--engine=mysql'], $this->fakeEnv($this->registry));
        self::assertSame(0, $admit['exit'], $admit['stderr']);

        if ($withGroup) {
            $group = LeaseProof::processGroup();
            $realStart = self::startOf($group);
            self::assertNotNull($realStart, "the leader of process group {$group} is not running");
            self::assertNotSame($realStart, $leaderStart);
            $leaderStart ??= $realStart;
            $data = self::registryData($this->registry);
            $data['leases'][$runId]['children'][] = ['pid' => $group, 'start' => $leaderStart, 'group' => true, 'run' => $runId, 'token' => bin2hex(random_bytes(16))];
            self::writeJson($this->registry.'/registry.json', $data);
        }

        $status = $this->testdb(['status', '--no-services'], $this->fakeEnv($this->registry));
        self::assertSame(0, $status['exit'], $status['stderr']);
        self::assertTrue($status['json']['leases'][$runId]['live']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $status['json']['token']);

        return [$runId, $status['json']['token']];
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function server(array $extra = []): array
    {
        return array_merge([
            'ORRERY_TEST_DRIVER' => 'mysql',
            'ORRERY_TEST_DATABASE' => self::BASE,
            'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
            // A port that nothing listens on: the probe never connects.
            'ORRERY_TEST_MYSQL_PORT' => '1024',
        ], $extra);
    }

    /**
     * @return array<string, string>
     */
    private function registryEnv(): array
    {
        return ['ORRERY_REGISTRY_TEST' => '1', 'ORRERY_OPS' => 'fake', 'ORRERY_REGISTRY_DIR' => $this->registry];
    }

    /**
     * Runs PHPUnit directly, as a developer would, with the probe.
     *
     * @param  array<string, string>  $variables
     * @return array{exit: int, json: array<string, mixed>, stdout: string, stderr: string, seconds: float}
     */
    private function phpunit(array $variables): array
    {
        return $this->runProcess(
            [PHP_BINARY, 'vendor/bin/phpunit', '--do-not-cache-result', self::PROBE],
            $this->scriptEnv(array_merge(['ORRERY_PROBE_OUT' => $this->out], $variables)),
            120.0,
        );
    }

    private static function runId(): string
    {
        return sprintf('run_%d_%s', getmypid(), bin2hex(random_bytes(4)));
    }
}
