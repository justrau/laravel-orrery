<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use Justrau\Orrery\Tests\Support\IdentityRecord;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bin/test-db and bin/test against the real orrery-testing containers.
 *
 * Run them with `composer test:docker`. Every test skips unless
 * ORRERY_DOCKER_TESTS=1, which only that script sets. The tests assert only about
 * their own leases and names, because other runs can hold leases at the same
 * time. Their explicit database names start with orrery_a2_, and tearDown
 * drops them. They leave the containers running.
 */
#[Group('docker')]
final class TestDbDockerTest extends TestCase
{
    use RunsScripts;

    private const PROBE = 'tests/Scripts/Fixtures/RunProbe.php';

    /** @var list<string> */
    private array $explicitNames = [];

    /** @var list<string> */
    private array $holds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // An explicit opt-in, checked before the first Docker call. The group
        // exclusion in phpunit.xml.dist is not enough: a command-line
        // --exclude-group replaces it, and a file argument selects this class.
        if (getenv('ORRERY_DOCKER_TESTS') !== '1') {
            self::markTestSkipped('The Docker tests start and stop the orrery-testing containers. Run them with `composer test:docker`, which sets ORRERY_DOCKER_TESTS=1.');
        }

        exec('docker version --format "{{.Server.Version}}" 2>/dev/null', $output, $status);
        self::assertSame(0, $status, 'the docker group needs a running Docker daemon');
    }

    protected function tearDown(): void
    {
        foreach ($this->holds as $hold) {
            if (is_dir($hold)) {
                touch($hold.'/release');
            }
        }

        // Stop the runs first (they hold the names), drop the names, and
        // only then remove the temporary directories that the drops use.
        $stopped = $this->stopScriptProcesses();

        foreach ($this->explicitNames as $name) {
            $drop = $this->testDb(['drop', $name]);

            if ($drop['exit'] !== 0) {
                fwrite(STDERR, "tearDown could not drop {$name}: {$drop['stderr']}\n");
            }
        }

        if (! $stopped) {
            self::fail('processes that this test started could not be confirmed stopped; the temporary directories were kept');
        }

        $this->removeScriptTempDirs();
        parent::tearDown();
    }

    public function test_a_cold_run_without_up_starts_the_services_provisions_and_releases(): void
    {
        $this->stopProjectOrSkip();
        $out = $this->makeTempDir();

        $run = $this->binTest('mysql', ['ORRERY_PROBE_OUT' => $out]);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $probe = $this->onlyProbe($out);
        self::assertMatchesRegularExpression('/^orrery_run_[0-9a-f]{8}$/', $probe['current']);
        self::assertSame($this->token(), $probe['marker']);
        self::assertTrue($probe['mirror_exists']);

        $status = $this->projectStatus();
        self::assertArrayNotHasKey($probe['env']['ORRERY_TEST_RUN_ID'], $status['leases']);
        self::assertNotContains($probe['current'], $status['services']['mysql']['databases'], 'the generated database was not dropped');
        self::assertNotContains($probe['current'].'_mirror', $status['services']['mysql']['databases']);
    }

    public function test_a_cold_run_passes_the_harness_identity_check(): void
    {
        $this->stopProjectOrSkip();
        $log = $this->makeTempDir().'/identity.log';

        $run = $this->binTest('mysql', [
            'ORRERY_IDENTITY_LOG' => $log,
            'ORRERY_EXPECT_DRIVER' => 'mysql',
            'ORRERY_EXPECT_PHP' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
        ], ['--filter', 'HarnessIdentityTest']);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertMatchesRegularExpression('/OK \((\d{2,}) tests/', $run['stdout'], 'the harness tests did not run');
        $record = $this->onlyIdentity($log);
        self::assertSame('mysql', $record->get('primary.driver'));
        self::assertMatchesRegularExpression('/^orrery_run_[0-9a-f]{8}$/', $record->get('primary.database'));
        self::assertSame($record->get('primary.database'), $record->get('mirror.database'));
        self::assertSame('endpoint-token', $record->get('primary.evidence.kind'));
        self::assertSame($this->token(), $record->get('primary.evidence.marker'));

        $status = $this->projectStatus();
        self::assertSame([], array_keys(array_filter($status['leases'], static fn (array $lease): bool => in_array($record->get('primary.database'), $lease['names'], true))));
        self::assertNotContains($record->get('primary.database'), $status['services']['mysql']['databases'], 'the generated database was not dropped');
    }

    public function test_up_during_a_live_run_keeps_the_token_and_a_new_run_still_passes(): void
    {
        $hold = $this->hold();
        $out = $this->makeTempDir();
        $first = $this->startBinTest('mysql', ['ORRERY_PROBE_OUT' => $out, 'ORRERY_PROBE_HOLD' => $hold]);
        $this->waitForHold($hold, $first);

        $before = $this->token();
        $up = $this->testDb(['up']);
        self::assertSame(0, $up['exit'], $up['stderr']);
        self::assertSame('kept', $up['json']['generation']);
        self::assertSame($before, $up['json']['token']);
        self::assertStringNotContainsString('docker compose up', $up['stderr'], 'up ran docker compose up while a lease was live');

        // A new run passes the real harness identity check against the kept token.
        $log = $this->makeTempDir().'/identity.log';
        $second = $this->binTest('mysql', ['ORRERY_IDENTITY_LOG' => $log], ['--filter', 'test_the_resolved_identity_equals_the_environment']);
        self::assertSame(0, $second['exit'], $second['stdout'].$second['stderr']);
        self::assertSame($before, $this->onlyIdentity($log)->get('primary.evidence.marker'));

        touch($hold.'/release');
        $first->wait();
        self::assertSame(0, $first->getExitCode(), $first->getOutput().$first->getErrorOutput());
        self::assertSame($before, $this->token());
    }

    public function test_up_with_an_occupied_port_fails_and_leaves_the_occupant_alone(): void
    {
        $dir = $this->makeTempDir();
        $port = self::freePort();
        $listener = $this->startProcess(
            [PHP_BINARY, '-n', '-r', '$s = stream_socket_server("tcp://127.0.0.1:".$argv[1], $e, $m); if ($s === false) { exit(3); } touch($argv[2]); sleep(60);', '--', (string) $port, $dir.'/ready'],
            $this->scriptEnv(),
        );
        $this->waitUntil(static fn (): bool => file_exists($dir.'/ready'), 10.0, 'the throwaway listener did not start: '.$listener->getErrorOutput());
        $before = $this->projectStatus();

        $up = $this->testDb(['up'], ['ORRERY_TEST_MYSQL_PORT' => (string) $port]);

        self::assertSame(69, $up['exit'], $up['stderr']);
        self::assertStringContainsString("127.0.0.1:{$port} is in use by a process outside the orrery-testing project", $up['stderr']);
        self::assertTrue($listener->isRunning(), 'the occupant of the port was stopped');
        $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 2.0);
        self::assertNotFalse($client, 'the occupant no longer accepts connections');
        fclose($client);

        $after = $this->projectStatus();
        self::assertSame($before['token'], $after['token']);
        self::assertSame($before['services']['mysql']['id'], $after['services']['mysql']['id'], 'the MySQL container was recreated');
        self::assertSame($before['services']['postgres']['id'], $after['services']['postgres']['id'], 'the Postgres container was recreated');

        $listener->stop(1);
    }

    public function test_two_runs_started_together_both_pass_with_their_own_databases(): void
    {
        $first = $this->makeTempDir();
        $second = $this->makeTempDir();
        // The two-sided hold: each probe waits at the meeting point until the
        // other arrives, and checks there that the other's session is open.
        $meet = ['ORRERY_PROBE_MEET' => $this->makeTempDir('orrery-meet-'), 'ORRERY_PROBE_PEERS' => '2', 'ORRERY_PROBE_MEET_TIMEOUT' => '90'];

        $a = $this->startBinTest('mysql', ['ORRERY_PROBE_OUT' => $first] + $meet);
        $b = $this->startBinTest('mysql', ['ORRERY_PROBE_OUT' => $second] + $meet);
        $a->wait();
        $b->wait();

        self::assertSame(0, $a->getExitCode(), $a->getOutput().$a->getErrorOutput());
        self::assertSame(0, $b->getExitCode(), $b->getOutput().$b->getErrorOutput());
        $probeA = $this->onlyProbe($first);
        $probeB = $this->onlyProbe($second);
        self::assertNotSame($probeA['current'], $probeB['current']);
        self::assertNotSame($probeA['env']['ORRERY_TEST_RUN_ID'], $probeB['env']['ORRERY_TEST_RUN_ID']);

        // Both PHPUnit bodies overlapped: each saw the other's open session on the other's database.
        self::assertSame([['pid' => $probeB['pid'], 'database' => $probeB['current'], 'session' => $probeB['session'], 'live' => true]], $probeA['met']);
        self::assertSame([['pid' => $probeA['pid'], 'database' => $probeA['current'], 'session' => $probeA['session'], 'live' => true]], $probeB['met']);

        $status = $this->projectStatus();
        foreach ([$probeA, $probeB] as $probe) {
            self::assertArrayNotHasKey($probe['env']['ORRERY_TEST_RUN_ID'], $status['leases']);
            self::assertNotContains($probe['current'], $status['services']['mysql']['databases']);
        }
    }

    public function test_an_explicit_name_held_by_a_live_run_fails_fast_without_a_database_change(): void
    {
        $name = $this->explicitName('orrery_a2_held');
        $hold = $this->hold();
        $out = $this->makeTempDir();
        $first = $this->startBinTest('mysql', [
            'ORRERY_TEST_DATABASE' => $name,
            'ORRERY_PROBE_SENTINEL' => '1',
            'ORRERY_PROBE_HOLD' => $hold,
            'ORRERY_PROBE_OUT' => $out,
        ]);
        $this->waitForHold($hold, $first);
        $holder = $this->onlyProbe($out)['env']['ORRERY_TEST_RUN_ID'];

        $secondOut = $this->makeTempDir();
        $second = $this->binTest('mysql', ['ORRERY_TEST_DATABASE' => $name, 'ORRERY_PROBE_OUT' => $secondOut]);

        self::assertSame(73, $second['exit'], $second['stderr']);
        self::assertStringContainsString("held by run {$holder}", $second['stderr']);
        self::assertLessThan(30.0, $second['seconds']);
        self::assertSame([], self::probeFiles($secondOut), 'PHPUnit started although the name was held');
        self::assertSame(['orrery_probe_sentinel'], self::mysqlTables($name), 'the held database was changed');

        touch($hold.'/release');
        $first->wait();
        self::assertSame(0, $first->getExitCode(), $first->getOutput().$first->getErrorOutput());
    }

    public function test_an_interrupted_run_keeps_its_lease_until_phpunit_is_gone(): void
    {
        $name = $this->explicitName('orrery_a2_intr');
        $hold = $this->hold();
        $out = $this->makeTempDir();
        $run = $this->startBinTest('mysql', [
            'ORRERY_TEST_DATABASE' => $name,
            'ORRERY_PROBE_HOLD' => $hold,
            'ORRERY_PROBE_OUT' => $out,
            'ORRERY_PROBE_IGNORE_TERM' => '1',
        ]);
        $this->waitForHold($hold, $run);
        $probe = $this->onlyProbe($out);
        $runId = $probe['env']['ORRERY_TEST_RUN_ID'];
        $phpunit = $probe['pid'];

        $run->signal(2);

        // PHPUnit ignores SIGTERM, so it lives through the 10-second grace
        // period of the cleanup. A status that shows no lease must never be
        // followed by a live PHPUnit: processes do not come back.
        $observedHeld = false;

        while ($run->isRunning()) {
            // isRunning() never checks the timeout of startProcess().
            $run->checkTimeout();
            $status = $this->projectStatus(['--no-services']);

            if (isset($status['leases'][$runId])) {
                $observedHeld = true;
                self::assertSame($runId, $status['names'][$name] ?? null);
            } else {
                self::assertFalse(self::isAlive($phpunit), 'the lease was released while PHPUnit was still running');
            }

            usleep(100_000);
        }

        self::assertTrue($observedHeld, 'the lease was never observed while PHPUnit was still running');
        self::assertSame(130, $run->getExitCode(), $run->getErrorOutput());
        self::assertFalse(self::isAlive($phpunit));
        $status = $this->projectStatus(['--no-services']);
        self::assertArrayNotHasKey($runId, $status['leases']);
        self::assertArrayNotHasKey($name, $status['names']);
    }

    public function test_a_killed_supervisor_keeps_the_lease_live_while_phpunit_runs(): void
    {
        $name = $this->explicitName('orrery_a2_kill');
        $hold = $this->hold();
        $out = $this->makeTempDir();
        $run = $this->startBinTest('mysql', ['ORRERY_TEST_DATABASE' => $name, 'ORRERY_PROBE_HOLD' => $hold, 'ORRERY_PROBE_OUT' => $out]);
        $this->waitForHold($hold, $run);
        $probe = $this->onlyProbe($out);
        $runId = $probe['env']['ORRERY_TEST_RUN_ID'];

        $run->signal(9);
        $run->wait();
        self::assertTrue(self::isAlive($probe['pid']), 'PHPUnit died with its supervisor');

        $lease = $this->projectStatus(['--no-services'])['leases'][$runId];
        self::assertTrue($lease['live']);
        // The group entry is the anchor of PHPUnit's process group (its parent).
        self::assertSame(['supervisor' => false, 'children' => [$probe['ppid']], 'unverified' => []], $lease['alive']);

        $down = $this->testDb(['down']);
        self::assertSame(75, $down['exit'], $down['stderr']);
        self::assertStringContainsString($runId, $down['stderr']);

        $second = $this->binTest('mysql', ['ORRERY_TEST_DATABASE' => $name, 'ORRERY_PROBE_OUT' => $this->makeTempDir()]);
        self::assertSame(73, $second['exit'], $second['stderr']);

        touch($hold.'/release');
        $this->waitUntil(static fn (): bool => ! self::isAlive($probe['pid']), 30.0, 'PHPUnit did not finish');
        // The anchor ends by itself after PHPUnit, because its supervisor is gone.
        $this->waitUntil(static fn (): bool => ! self::isAlive($probe['ppid']), 10.0, 'the anchor outlived its killed supervisor and PHPUnit');

        // Now no recorded process lives: the lease is stale, and the next
        // registry command removes it. A registry command of another run
        // (a concurrent bin/test or bin/matrix) can be that command, so the
        // lease is either still listed and not live, or already pruned.
        $stale = $this->projectStatus(['--no-services'])['leases'][$runId] ?? null;
        self::assertTrue($stale === null || $stale['live'] === false, 'the lease is still live after PHPUnit and its anchor ended');
        $this->testDb(['down', '--dry-run']);
        $status = $this->projectStatus(['--no-services']);
        self::assertArrayNotHasKey($runId, $status['leases']);
        self::assertArrayNotHasKey($name, $status['names']);
    }

    public function test_down_from_the_primary_checkout_is_refused_while_a_copy_holds_a_lease(): void
    {
        $name = $this->explicitName('orrery_a2_copy');
        $copy = $this->makeTempDir('orrery-copy-');
        mkdir($copy.'/bin/lib', 0700, true);
        mkdir($copy.'/docker/testing', 0700, true);
        copy(self::testdbPath(), $copy.'/bin/lib/testdb.php');
        copy(self::repoRoot().'/docker/testing/compose.yaml', $copy.'/docker/testing/compose.yaml');
        $supervisor = $this->spawnSleeper();
        $runId = sprintf('run_%d_%s', getmypid(), bin2hex(random_bytes(4)));

        $path = $this->runProcess([PHP_BINARY, '-n', $copy.'/bin/lib/testdb.php', 'path'], $this->env());
        self::assertSame(self::canonicalRegistry(), $path['json']['path']);

        $admit = $this->runProcess(
            [PHP_BINARY, '-n', $copy.'/bin/lib/testdb.php', 'admit', $runId, (string) $supervisor['pid'], $supervisor['start'], $name, '--explicit', '--engine=mysql'],
            $this->env(),
            300.0,
        );
        self::assertSame(0, $admit['exit'], $admit['stderr']);

        $down = $this->testDb(['down']);
        self::assertSame(75, $down['exit'], $down['stderr']);
        self::assertStringContainsString($runId, $down['stderr']);

        self::assertTrue($this->projectStatus(['--no-services'])['leases'][$runId]['live']);

        $this->stopSleeper($supervisor);
        $released = $this->runProcess([PHP_BINARY, '-n', self::testdbPath(), 'release', $runId], $this->env());
        self::assertSame(0, $released['exit'], $released['stderr']);
    }

    public function test_a_run_paused_before_registration_leaves_the_database_untouched_and_no_phpunit(): void
    {
        $pause = $this->makeTempDir();
        $out = $this->makeTempDir();
        $run = $this->startBinTest('mysql', [
            'ORRERY_TEST_PAUSE_BEFORE_REGISTER' => $pause,
            'ORRERY_PROBE_SENTINEL' => '1',
            'ORRERY_PROBE_OUT' => $out,
        ]);
        $this->waitUntil(static fn (): bool => file_exists($pause.'/paused'), 120.0, 'bin/test did not pause: '.$run->getErrorOutput());
        $phpunit = (int) trim((string) file_get_contents($pause.'/paused'));
        self::assertTrue(self::isAlive($phpunit));

        $lease = $this->leaseOfSupervisor((int) $run->getPid());
        self::assertSame([], $lease['children']);
        $base = $lease['base'];

        usleep(300_000);
        self::assertSame([], self::mysqlTables($base), 'PHPUnit touched the database before it was registered');
        self::assertSame([], self::probeFiles($out));

        $run->signal(15);
        $run->wait();

        self::assertSame(143, $run->getExitCode(), $run->getErrorOutput());
        self::assertFalse(self::isAlive($phpunit), 'PHPUnit outlived its supervisor');
        self::assertSame([], self::probeFiles($out));
        $status = $this->projectStatus();
        self::assertArrayNotHasKey($lease['run_id'], $status['leases']);
        self::assertNotContains($base, $status['services']['mysql']['databases']);
    }

    public function test_no_provision_with_a_preprovisioned_database_and_marker_passes_without_the_registry(): void
    {
        $name = $this->explicitName('orrery_a2_np');
        $up = $this->testDb(['up']);
        self::assertSame(0, $up['exit'], $up['stderr']);
        $create = $this->testDb(['create', $name, '--engine=mysql']);
        self::assertSame(0, $create['exit'], $create['stderr']);
        $out = $this->makeTempDir();

        $run = $this->binTest('mysql', [
            'ORRERY_TEST_DATABASE' => $name,
            'ORRERY_TEST_TOKEN' => $this->token(),
            'ORRERY_PROBE_OUT' => $out,
        ], ['--no-provision', self::PROBE]);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $probe = $this->onlyProbe($out);
        self::assertSame($name, $probe['current']);
        self::assertSame('1', $probe['env']['ORRERY_TEST_CI']);
        self::assertArrayNotHasKey('ORRERY_TEST_RUN_ID', $probe['env']);
        self::assertArrayNotHasKey($name, $this->projectStatus(['--no-services'])['names']);
    }

    public function test_no_provision_with_a_mismatched_token_fails_before_migration(): void
    {
        $name = $this->explicitName('orrery_a2_nptok');
        $up = $this->testDb(['up']);
        self::assertSame(0, $up['exit'], $up['stderr']);
        $create = $this->testDb(['create', $name, '--engine=mysql']);
        self::assertSame(0, $create['exit'], $create['stderr']);
        $token = $this->token();
        $wrong = $token === str_repeat('0', 32) ? str_repeat('1', 32) : str_repeat('0', 32);
        $filter = ['--no-provision', '--filter', 'test_the_resolved_identity_equals_the_environment'];

        $run = $this->binTest('mysql', ['ORRERY_TEST_DATABASE' => $name, 'ORRERY_TEST_TOKEN' => $wrong], $filter);

        self::assertNotSame(0, $run['exit'], $run['stdout']);
        self::assertStringContainsString("primary.evidence.marker: expected \"{$wrong}\", got \"{$token}\"", $run['stdout']);
        self::assertSame([], self::mysqlTables($name), 'a run with a mismatched token changed the database');
        self::assertSame([], self::mysqlTables($name.'_mirror'));

        // The control: the right token migrates, so the empty database above is the refusal's doing.
        $log = $this->makeTempDir().'/identity.log';
        $control = $this->binTest('mysql', ['ORRERY_TEST_DATABASE' => $name, 'ORRERY_TEST_TOKEN' => $token, 'ORRERY_IDENTITY_LOG' => $log], $filter);
        self::assertSame(0, $control['exit'], $control['stdout'].$control['stderr']);
        self::assertSame($name, $this->onlyIdentity($log)->get('primary.database'));
        self::assertContains('migrations', self::mysqlTables($name));
    }

    public function test_a_run_pointed_at_a_foreign_port_fails_the_endpoint_proof_before_migrating(): void
    {
        $name = $this->explicitName('orrery_a2_foreign');
        $up = $this->testDb(['up']);
        self::assertSame(0, $up['exit'], $up['stderr']);
        $create = $this->testDb(['create', $name, '--engine=mysql']);
        self::assertSame(0, $create['exit'], $create['stderr']);

        // A throwaway listener that is not this project's container: it
        // accepts each connection, counts it and closes it.
        $dir = $this->makeTempDir();
        $port = self::freePort();
        $listener = $this->startProcess(
            [PHP_BINARY, '-n', '-r', '$s = stream_socket_server("tcp://127.0.0.1:".$argv[1], $e, $m); if ($s === false) { exit(3); } touch($argv[2]); $n = 0; $end = time() + 120; while (time() < $end) { $c = @stream_socket_accept($s, 1); if ($c !== false) { $n++; file_put_contents($argv[3], (string) $n); fclose($c); } }', '--', (string) $port, $dir.'/ready', $dir.'/accepted'],
            $this->scriptEnv(),
        );
        $this->waitUntil(static fn (): bool => file_exists($dir.'/ready'), 10.0, 'the throwaway listener did not start: '.$listener->getErrorOutput());

        $run = $this->binTest('mysql', [
            'ORRERY_TEST_DATABASE' => $name,
            'ORRERY_TEST_TOKEN' => $this->token(),
            'ORRERY_TEST_MYSQL_PORT' => (string) $port,
        ], ['--no-provision', '--filter', 'test_the_resolved_identity_equals_the_environment']);

        self::assertNotSame(0, $run['exit'], $run['stdout']);
        self::assertStringContainsString('The resolved database identity does not match', $run['stdout']);
        self::assertStringContainsString('primary.error', $run['stdout']);
        self::assertGreaterThanOrEqual(1, (int) @file_get_contents($dir.'/accepted'), 'the run never reached the foreign endpoint');
        self::assertSame([], self::mysqlTables($name), 'the run migrated a database');

        $listener->stop(1);
    }

    public function test_hostile_database_variables_do_not_reach_the_run(): void
    {
        $out = $this->makeTempDir();
        $run = $this->binTest('mysql', [
            'DB_URL' => 'mysql://intruder:secret@127.0.0.1:1/production',
            'DATABASE_URL' => 'mysql://intruder:secret@127.0.0.1:1/production',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_DATABASE' => 'production',
            'DB_USERNAME' => 'intruder',
            'DB_PASSWORD' => 'secret',
            'DB_CONNECTION' => 'intruder',
            'ORRERY_PROBE_OUT' => $out,
        ]);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $probe = $this->onlyProbe($out);
        self::assertMatchesRegularExpression('/^orrery_run_[0-9a-f]{8}$/', $probe['current']);

        foreach (['DB_URL', 'DATABASE_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $variable) {
            self::assertArrayNotHasKey($variable, $probe['env'], "{$variable} reached PHPUnit");
        }

        // phpunit.xml.dist sets DB_CONNECTION for the test application.
        self::assertSame('testbench', $probe['env']['DB_CONNECTION']);
    }

    public function test_a_postgres_run_provisions_marks_and_releases(): void
    {
        $out = $this->makeTempDir();
        $run = $this->binTest('pgsql', ['ORRERY_PROBE_OUT' => $out]);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        $probe = $this->onlyProbe($out);
        self::assertSame('pgsql', $probe['driver']);
        self::assertSame($this->token(), $probe['marker']);
        self::assertTrue($probe['mirror_exists']);
        self::assertNotContains($probe['current'], $this->projectStatus()['services']['postgres']['databases']);
    }

    public function test_runs_with_different_tmpdir_values_share_one_registry(): void
    {
        $first = $this->makeTempDir();
        $second = $this->makeTempDir();
        $hold = $this->hold();
        $out = $this->makeTempDir();
        $run = $this->startBinTest('mysql', ['TMPDIR' => $first.'/', 'ORRERY_PROBE_HOLD' => $hold, 'ORRERY_PROBE_OUT' => $out]);
        $this->waitForHold($hold, $run);
        $runId = $this->onlyProbe($out)['env']['ORRERY_TEST_RUN_ID'];

        $seen = $this->testDb(['status', '--no-services'], ['TMPDIR' => $second.'/']);
        self::assertSame(self::canonicalRegistry(), $seen['json']['registry']);
        self::assertArrayHasKey($runId, $seen['json']['leases']);

        touch($hold.'/release');
        $run->wait();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
    }

    /**
     * Rejects: alt services without the profile, which the default up starts.
     */
    public function test_compose_default_services_exclude_the_alt_profile(): void
    {
        // Read-only: `config` only renders the Compose file.
        $compose = ['docker', 'compose', '--project-name', 'orrery-testing', '--file', self::repoRoot().'/docker/testing/compose.yaml'];

        $default = $this->runProcess([...$compose, 'config', '--services'], $this->env());
        self::assertSame(0, $default['exit'], $default['stderr']);
        $services = preg_split('/\R/', trim($default['stdout'])) ?: [];
        sort($services);
        self::assertSame(['mysql', 'postgres'], $services);

        $alt = $this->runProcess([...$compose, '--profile', 'alt', 'config', '--services'], $this->env());
        self::assertSame(0, $alt['exit'], $alt['stderr']);
        $services = preg_split('/\R/', trim($alt['stdout'])) ?: [];
        sort($services);
        self::assertSame(['mysql', 'mysql-alt', 'pgsql-alt', 'postgres'], $services);
    }

    // Helpers

    /**
     * @param  array<string, string|false>  $extra
     * @return array<string, string|false>
     */
    private function env(array $extra = []): array
    {
        // Its bin/test runs enter the heavy-run slot that this test holds,
        // so two runs of one test never wait for each other.
        return $this->scriptEnv(array_merge(['ORRERY_TEST_PHP' => PHP_BINARY], $this->heavySlotEnv(), $extra));
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $extra
     * @return array{exit: int, json: array<string, mixed>, stdout: string, stderr: string, seconds: float}
     */
    private function testDb(array $arguments, array $extra = []): array
    {
        return $this->runProcess([...$this->wrapper('bin/test-db'), ...$arguments], $this->env($extra), 300.0);
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function projectStatus(array $options = []): array
    {
        $status = $this->testDb(['status', ...$options]);
        self::assertSame(0, $status['exit'], $status['stderr']);

        return $status['json'];
    }

    private function token(): string
    {
        $token = $this->testDb(['token']);
        self::assertSame(0, $token['exit'], $token['stderr']);

        return $token['json']['token'];
    }

    /**
     * @param  array<string, string|false>  $extra
     * @param  list<string>  $arguments
     * @return array{exit: int, json: array<string, mixed>, stdout: string, stderr: string, seconds: float}
     */
    private function binTest(string $engine, array $extra, array $arguments = [self::PROBE]): array
    {
        return $this->runProcess([...$this->wrapper('bin/test'), $engine, '--php='.PHP_BINARY, ...$arguments], $this->env($extra), 300.0);
    }

    /**
     * @param  array<string, string|false>  $extra
     */
    private function startBinTest(string $engine, array $extra): Process
    {
        return $this->startProcess([...$this->wrapper('bin/test'), $engine, '--php='.PHP_BINARY, self::PROBE], $this->env($extra));
    }

    private function hold(): string
    {
        return $this->holds[] = $this->makeTempDir('orrery-hold-');
    }

    private function waitForHold(string $hold, Process $run): void
    {
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached') || ! $run->isRunning(), 120.0, 'the probe did not reach its hold');
        self::assertTrue($run->isRunning(), 'bin/test ended before the probe held: '.$run->getOutput().$run->getErrorOutput());
    }

    private function explicitName(string $name): string
    {
        $this->explicitNames[] = $name;

        return $name;
    }

    private function onlyIdentity(string $log): IdentityRecord
    {
        self::assertFileExists($log, 'the harness identity test did not run');
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        self::assertCount(1, $lines);

        return IdentityRecord::fromLine($lines[0]);
    }

    /**
     * @return list<string>
     */
    private static function probeFiles(string $dir): array
    {
        return glob($dir.'/probe-*.json') ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyProbe(string $dir): array
    {
        $files = self::probeFiles($dir);
        self::assertCount(1, $files);

        return self::readJson($files[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function leaseOfSupervisor(int $pid): array
    {
        foreach ($this->projectStatus(['--no-services'])['leases'] as $lease) {
            if ($lease['supervisor']['pid'] === $pid) {
                return $lease;
            }
        }

        self::fail("no lease has supervisor pid {$pid}");
    }

    /**
     * @return list<string>
     */
    private static function mysqlTables(string $database): array
    {
        $pdo = new PDO('mysql:host=127.0.0.1;port=33106', 'root', 'orrery', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $query = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
        $query->execute([$database]);

        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function stopProjectOrSkip(): void
    {
        $status = $this->projectStatus(['--no-services']);
        $live = array_keys(array_filter($status['leases'], static fn (array $lease): bool => $lease['live']));

        if ($live !== []) {
            self::markTestSkipped('other runs hold live leases ('.implode(', ', $live).'), so the project cannot be stopped for a cold start');
        }

        $down = $this->testDb(['down']);
        self::assertSame(0, $down['exit'], $down['stderr']);
        $status = $this->projectStatus();
        self::assertSame('absent', $status['services']['mysql']['state']);
        self::assertNull($status['token']);
    }
}
