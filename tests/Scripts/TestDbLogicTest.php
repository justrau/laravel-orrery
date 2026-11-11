<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use OrreryTestDb\DockerOps;
use OrreryTestDb\Failure;
use OrreryTestDb\Names;
use OrreryTestDb\Processes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The lifecycle logic of bin/lib/testdb.php, bin/test-db and bin/test,
 * without Docker: every command runs with the fake backend (ORRERY_OPS=fake)
 * and a private test registry. Process checks are real, so the supervisors
 * and children in these tests are real processes. The process controls run
 * once with bash 3.2 and once with bash 5.
 *
 * It is in the group scripts: `composer test` (the fast package loop) skips
 * it, and `composer test:all` runs it. bin/matrix, CI and the final gate run
 * test:all. For one file while working: `composer test -- --group scripts
 * tests/Scripts/TestDbLogicTest.php`.
 */
#[Group('scripts')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class TestDbLogicTest extends TestCase
{
    use RunsScripts;

    private const GATE_PROBE = 'require $argv[1]; file_put_contents($argv[2], getenv("ORRERY_START_GATE") === false ? "gate-variable-removed" : "gate-variable-kept");';

    private string $base;

    private string $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = $this->makeTempDir('orrery-logic-');
        // Not created here: testdb.php creates it, and the tests of the modes
        // without a registry check that it never appears.
        $this->registry = $this->base.'/registry';
    }

    protected function tearDown(): void
    {
        $this->cleanUpScripts();
        parent::tearDown();
    }

    // Name rules

    public function test_the_base_name_rule_allows_a_40_character_suffix_and_nothing_else(): void
    {
        require_once self::testdbPath();

        $longest = 'orrery_'.str_repeat('a', 40);
        self::assertSame([$longest, $longest.'_mirror'], Names::pair($longest));
        self::assertSame(47, strlen($longest));
        self::assertSame(54, strlen(Names::mirror($longest)));

        foreach (['orrery_a', 'orrery_0', 'orrery__', 'orrery_run_0123abcd', 'orrery_a2_run1', 'orrery_mirror_x', 'orrery_markers'] as $valid) {
            self::assertSame([$valid, $valid.'_mirror'], Names::pair($valid), $valid);
        }

        $invalid = [
            ['orrery_'.str_repeat('a', 41), 'a base name must match'],
            ['orrery_', 'a base name must match'],
            ['orrery_a_mirror', 'must not end in _mirror'],
            ['orrery_'.str_repeat('b', 33).'_mirror', 'must not end in _mirror'],
            ['orrery_marker', 'reserved for the endpoint marker'],
            ['mysql', 'a base name must match'],
            ['information_schema', 'a base name must match'],
            ['performance_schema', 'a base name must match'],
            ['sys', 'a base name must match'],
            ['postgres', 'a base name must match'],
            ['template0', 'a base name must match'],
            ['template1', 'a base name must match'],
            ['a;b', 'a base name must match'],
            ['orrery_a;b', 'a base name must match'],
            ['orrery_a;DROP DATABASE mysql', 'a base name must match'],
            ['orrery_a b', 'a base name must match'],
            ["orrery_a'--", 'a base name must match'],
            ['orrery_a`', 'a base name must match'],
            ['orrery_a"', 'a base name must match'],
            ["orrery_a\n", 'a base name must match'],
            ["orrery_a\0", 'a base name must match'],
            ['orrery_a/../b', 'a base name must match'],
            ['orrery_A', 'a base name must match'],
            ['ORRERY_A', 'a base name must match'],
            ['orrery-a', 'a base name must match'],
            ['xorrery_a', 'a base name must match'],
            [' orrery_a', 'a base name must match'],
            ['', 'a base name must match'],
        ];

        foreach ($invalid as [$name, $reason]) {
            try {
                Names::pair($name);
                self::fail('accepted the base name '.json_encode($name));
            } catch (Failure $failure) {
                self::assertSame(64, $failure->exitCode, json_encode($name) ?: '');
                self::assertStringContainsString($reason, $failure->getMessage(), json_encode($name) ?: '');
            }
        }

        // The last check before SQL accepts test names in both roles only.
        Names::assertPhysical($longest.'_mirror');
        Names::assertPhysical('orrery_a');

        foreach (['orrery_marker', 'mysql', 'postgres', 'template1', 'orrery_'.str_repeat('a', 48)] as $name) {
            try {
                Names::assertPhysical($name);
                self::fail("assertPhysical accepted {$name}");
            } catch (Failure $failure) {
                self::assertSame(64, $failure->exitCode);
            }
        }
    }

    public function test_the_commands_refuse_invalid_names_before_they_touch_the_registry(): void
    {
        $env = $this->fakeEnv($this->registry);

        foreach ([['drop', 'mysql'], ['create', 'a;b'], ['create', 'orrery_a_mirror'], ['drop', 'orrery_marker'], ['validate-name', 'orrery_'.str_repeat('a', 41)]] as $arguments) {
            $result = $this->runProcess([...$this->wrapper('bin/test-db'), ...$arguments], $env);
            self::assertSame(64, $result['exit'], implode(' ', $arguments).': '.$result['stderr']);
            self::assertFalse($result['json']['ok']);
        }

        $longest = $this->runProcess([...$this->wrapper('bin/test-db'), 'validate-name', 'orrery_'.str_repeat('a', 40)], $env);
        self::assertSame(0, $longest['exit'], $longest['stderr']);
        self::assertSame('orrery_'.str_repeat('a', 40).'_mirror', $longest['json']['mirror']);

        $admit = $this->testdb(['admit', 'run_1_00000001', (string) getmypid(), 'auto', 'orrery_a;b', '--generated'], $env);
        self::assertSame(64, $admit['exit']);

        self::assertDirectoryDoesNotExist($this->registry);
    }

    // Leases and name locks

    public function test_admit_reserves_both_names_and_release_drops_only_generated_databases(): void
    {
        $supervisor = $this->spawnSleeper();
        self::assertSame(0, $this->testdb(['up'], $this->fakeEnv($this->registry))['exit'], 'bin/test-db up starts every default server');

        $generated = $this->admit('run_1_0000000a', $supervisor, 'orrery_gen', '--generated', 'mysql');
        self::assertSame('orrery_gen_mirror', $generated['mirror']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $generated['token']);
        self::assertSame('kept', $generated['generation']);

        $explicit = $this->admit('run_1_0000000b', $supervisor, 'orrery_exp', '--explicit', 'pgsql');
        self::assertSame($generated['token'], $explicit['token']);
        self::assertSame('kept', $explicit['generation']);

        $status = $this->testdb(['status'], $this->fakeEnv($this->registry));
        self::assertSame(0, $status['exit'], $status['stderr']);
        self::assertSame([
            'orrery_gen' => 'run_1_0000000a',
            'orrery_gen_mirror' => 'run_1_0000000a',
            'orrery_exp' => 'run_1_0000000b',
            'orrery_exp_mirror' => 'run_1_0000000b',
        ], $status['json']['names']);
        self::assertTrue($status['json']['leases']['run_1_0000000a']['live']);

        $services = self::fakeState($this->registry)['services'];
        self::assertSame(['orrery_marker', 'orrery_gen', 'orrery_gen_mirror'], $services['mysql']['databases']);
        self::assertSame(['orrery_marker', 'orrery_exp', 'orrery_exp_mirror'], $services['postgres']['databases']);

        $release = $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry));
        self::assertSame(0, $release['exit'], $release['stderr']);
        self::assertSame(['mysql' => ['orrery_gen', 'orrery_gen_mirror']], $release['json']['dropped']);

        $release = $this->testdb(['release', 'run_1_0000000b'], $this->fakeEnv($this->registry));
        self::assertSame(0, $release['exit'], $release['stderr']);
        self::assertSame([], $release['json']['dropped']);

        $services = self::fakeState($this->registry)['services'];
        self::assertSame(['orrery_marker'], $services['mysql']['databases']);
        self::assertSame(['orrery_marker', 'orrery_exp', 'orrery_exp_mirror'], $services['postgres']['databases'], 'an explicit database stays after release');
        self::assertSame([], self::registryData($this->registry)['leases']);

        $again = $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry));
        self::assertSame(0, $again['exit']);
        self::assertFalse($again['json']['released']);
    }

    public function test_a_name_held_in_either_role_is_refused_before_any_database_change(): void
    {
        $supervisor = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_a', '--explicit', 'mysql');

        // A lease from an older registry that holds orrery_b in the mirror role.
        $data = self::registryData($this->registry);
        $data['leases']['run_1_0000000c'] = $data['leases']['run_1_0000000a'];
        $data['leases']['run_1_0000000c']['run_id'] = 'run_1_0000000c';
        $data['leases']['run_1_0000000c']['base'] = 'orrery_c';
        $data['leases']['run_1_0000000c']['names'] = ['orrery_c', 'orrery_b'];
        self::writeJson($this->registry.'/registry.json', $data);

        $before = count(self::fakeCalls($this->registry));

        $mirrorAsBase = $this->admitRaw('run_1_0000000b', $supervisor, 'orrery_a_mirror', '--explicit', 'mysql');
        self::assertSame(64, $mirrorAsBase['exit'], $mirrorAsBase['stderr']);

        $sameBase = $this->admitRaw('run_1_0000000b', $supervisor, 'orrery_a', '--generated', 'mysql');
        self::assertSame(73, $sameBase['exit'], $sameBase['stderr']);
        self::assertSame('run_1_0000000a', $sameBase['json']['holder']['run_id']);
        self::assertStringContainsString('held by run run_1_0000000a', $sameBase['stderr']);

        $mirrorRole = $this->admitRaw('run_1_0000000d', $supervisor, 'orrery_b', '--explicit', 'mysql');
        self::assertSame(73, $mirrorRole['exit'], $mirrorRole['stderr']);
        self::assertSame('run_1_0000000c', $mirrorRole['json']['holder']['run_id']);

        $leases = self::registryData($this->registry)['leases'];
        self::assertSame(['run_1_0000000a', 'run_1_0000000c'], array_keys($leases));

        $operations = self::operations(array_slice(self::fakeCalls($this->registry), $before));
        self::assertSame([], array_values(array_intersect($operations, ['up', 'down', 'write_marker', 'create_databases', 'drop_databases'])));
    }

    public function test_a_lease_stays_live_while_any_recorded_process_lives(): void
    {
        $supervisor = $this->spawnSleeper();
        $child = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_stale', '--generated', 'mysql');
        $this->addChild('run_1_0000000a', $child);

        $this->stopSleeper($supervisor);

        // A dead supervisor with a live child: the lease is live.
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry));
        self::assertSame(75, $down['exit'], $down['stderr']);
        self::assertStringContainsString('run_1_0000000a', $down['stderr']);

        $status = $this->testdb(['status', '--no-services'], $this->fakeEnv($this->registry));
        $lease = $status['json']['leases']['run_1_0000000a'];
        self::assertTrue($lease['live']);
        self::assertSame(['supervisor' => false, 'children' => [$child['pid']], 'unverified' => []], $lease['alive']);

        $this->stopSleeper($child);

        // Both dead: the lease is stale, and its generated names become orphans.
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry));
        self::assertSame(0, $down['exit'], $down['stderr']);
        self::assertSame(['run_1_0000000a'], $down['json']['pruned']);

        $data = self::registryData($this->registry);
        self::assertSame([], $data['leases']);
        self::assertSame([
            ['name' => 'orrery_stale', 'services' => ['mysql']],
            ['name' => 'orrery_stale_mirror', 'services' => ['mysql']],
        ], $data['orphans']);

        // The next command with healthy services drops the orphans.
        $up = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame(0, $up['exit'], $up['stderr']);
        self::assertSame([], self::registryData($this->registry)['orphans']);
        self::assertSame(['orrery_marker'], self::fakeState($this->registry)['services']['mysql']['databases']);
    }

    public function test_liveness_is_exact_for_more_processes_than_one_ps_call_per_pid_covers(): void
    {
        // More than 12 recorded processes switch testdb.php from one ps call
        // per PID to a single `ps -A`.
        $supervisor = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_many', '--explicit', 'mysql');
        $children = [];

        for ($i = 0; $i < 13; $i++) {
            $children[] = $this->spawnSleeper();
        }

        // Written directly: this test is about the liveness check, and the
        // add-child tests cover how children are recorded.
        $data = self::registryData($this->registry);
        $data['leases']['run_1_0000000a']['children'] = array_map(static fn (array $child): array => ['pid' => $child['pid'], 'start' => $child['start']], $children);
        self::writeJson($this->registry.'/registry.json', $data);

        $this->stopSleeper($supervisor);
        $this->stopSleeper($children[3]);
        $this->stopSleeper($children[9]);

        $status = $this->testdb(['status', '--no-services'], $this->fakeEnv($this->registry));
        self::assertSame(0, $status['exit'], $status['stderr']);
        $alive = array_values(array_map(static fn (array $child): int => $child['pid'], array_filter($children, static fn (int $index): bool => ! in_array($index, [3, 9], true), ARRAY_FILTER_USE_KEY)));
        self::assertSame(['supervisor' => false, 'children' => $alive, 'unverified' => []], $status['json']['leases']['run_1_0000000a']['alive']);

        // A failed full listing is no evidence: nothing is released.
        $before = (string) file_get_contents($this->registry.'/registry.json');
        $fakePs = $this->fakePsDir();

        $cases = [['exit2', ['down', '--dry-run']], ['exit2', ['status', '--no-services']], ['garbage', ['down', '--dry-run']], ['silent', ['down', '--dry-run']], ['stderr1', ['down', '--dry-run']]];

        foreach ($cases as [$mode, $arguments]) {
            $env = $this->fakeEnv($this->registry, ['PATH' => $fakePs.':'.getenv('PATH'), 'ORRERY_FAKE_PS' => $mode]);
            $result = $this->testdb($arguments, $env);
            self::assertSame(74, $result['exit'], "ps {$mode}, ".implode(' ', $arguments).': '.$result['stderr']);
            self::assertStringContainsString('process inspection failed (ps -A', $result['stderr']);
        }

        self::assertSame($before, file_get_contents($this->registry.'/registry.json'), 'a failed process listing changed the registry');

        // The pass-through of the fake ps works, so the failures above came from the injected errors.
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry, ['PATH' => $fakePs.':'.getenv('PATH')]));
        self::assertSame(75, $down['exit'], $down['stderr']);
    }

    public function test_a_failed_process_inspection_releases_nothing(): void
    {
        // The lease is live only through its child: a false "absent" frees it.
        $supervisor = $this->spawnSleeper();
        $child = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_insp', '--generated', 'mysql');
        $this->addChild('run_1_0000000a', $child);
        // A second lease without children: its release needs no inspection
        // of its own, but the prune of the other leases does.
        $this->admit('run_1_0000000b', $child, 'orrery_insp2', '--generated', 'mysql');
        $this->stopSleeper($supervisor);
        $before = (string) file_get_contents($this->registry.'/registry.json');
        $calls = count(self::fakeCalls($this->registry));
        $fakePs = $this->fakePsDir();

        $every = [
            ['down', '--dry-run'],
            ['up'],
            ['status', '--no-services'],
            ['release', 'run_1_0000000a'],
            ['release', 'run_1_0000000b'],
            ['add-child', 'run_1_0000000a', (string) $child['pid'], 'auto'],
            ['admit', 'run_2_0000000c', (string) $child['pid'], 'auto', 'orrery_other', '--generated', '--engine=mysql'],
            ['create', 'orrery_free'],
            ['stop-children', 'run_1_0000000a'],
            ['stop-group', (string) $child['pid'], $child['start']],
        ];
        $cases = array_merge(
            array_map(static fn (array $arguments): array => ['exit2', $arguments], $every),
            [['garbage', ['down', '--dry-run']], ['garbage', ['release', 'run_1_0000000a']]],
            [['silent', ['down', '--dry-run']], ['silent', ['release', 'run_1_0000000a']]],
            [['stderr1', ['down', '--dry-run']], ['stderr1', ['release', 'run_1_0000000a']]],
        );

        foreach ($cases as [$mode, $arguments]) {
            $env = $this->fakeEnv($this->registry, ['PATH' => $fakePs.':'.getenv('PATH'), 'ORRERY_FAKE_PS' => $mode]);
            $result = $this->testdb($arguments, $env);
            self::assertSame(74, $result['exit'], "ps {$mode}, ".implode(' ', $arguments).': '.$result['stderr']);
            self::assertStringContainsString('process inspection failed', $result['stderr']);
        }

        self::assertSame($before, file_get_contents($this->registry.'/registry.json'), 'a failed inspection changed the registry');
        self::assertSame([], array_values(array_intersect(
            self::operations(array_slice(self::fakeCalls($this->registry), $calls)),
            ['up', 'down', 'write_marker', 'create_databases', 'drop_databases'],
        )));
        self::assertTrue(self::isAlive($child['pid']), 'a failed inspection led to a signal');

        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry, ['PATH' => $fakePs.':'.getenv('PATH')]));
        self::assertSame(75, $down['exit'], 'the pass-through of the fake ps does not work: '.$down['stderr']);
    }

    public function test_a_ps_that_a_signal_ends_runs_again_and_never_counts_as_absence(): void
    {
        // An outer run's stop sends SIGTERM to every process of this run's
        // groups, the ps of an inspection included. Such a ps is no evidence:
        // it runs again. If it always ends that way, the inspection fails.
        $group = $this->startGroup();
        $supervisor = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_psterm', '--generated', 'mysql');
        $this->stopSleeper($supervisor);
        $data = self::registryData($this->registry);
        $data['leases']['run_1_0000000a']['children'] = [['pid' => $group['pid'], 'start' => $group['start'], 'group' => true]];
        self::writeJson($this->registry.'/registry.json', $data);
        $before = (string) file_get_contents($this->registry.'/registry.json');
        $fakePs = $this->fakePsDir();
        $mode = $this->base.'/ps-mode';
        $path = ['PATH' => $fakePs.':'.getenv('PATH'), 'ORRERY_FAKE_PS_FILE' => $mode];

        file_put_contents($mode, 'term-once');
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry, $path));
        self::assertFileExists($mode.'.used', 'the fake ps did not end by SIGTERM');
        self::assertSame(75, $down['exit'], 'a ps that SIGTERM ended counted as an inspection: '.$down['stderr']);

        file_put_contents($mode, 'term-always');
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry, $path));
        self::assertSame(74, $down['exit'], $down['stderr']);
        self::assertStringContainsString('process inspection failed', $down['stderr']);
        self::assertSame($before, file_get_contents($this->registry.'/registry.json'), 'a ps that SIGTERM ended counted as absence');
        self::assertTrue(self::isAlive($group['pid']));
    }

    public function test_a_zombie_and_a_dead_process_count_as_gone(): void
    {
        $supervisor = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_zombie', '--explicit', 'mysql');

        // A child that exits and is not reaped: a zombie of this process.
        $process = proc_open(['sh', '-c', 'read line'], [0 => ['pipe', 'r']], $pipes);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        $start = null;
        $this->waitUntil(static function () use ($pid, &$start): bool {
            $start = self::startOf($pid);

            return $start !== null;
        }, 5.0, 'the child did not start');
        $this->addChild('run_1_0000000a', ['pid' => $pid, 'start' => (string) $start]);
        $this->stopSleeper($supervisor);
        fclose($pipes[0]);
        $this->waitUntil(static fn (): bool => str_contains(trim((string) shell_exec('ps -o stat= -p '.$pid.' 2>/dev/null')), 'Z'), 5.0, 'the child did not become a zombie');

        $status = $this->testdb(['status', '--no-services'], $this->fakeEnv($this->registry));
        self::assertSame(0, $status['exit'], $status['stderr']);
        self::assertSame(['supervisor' => false, 'children' => [], 'unverified' => []], $status['json']['leases']['run_1_0000000a']['alive']);

        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry));
        self::assertSame(0, $down['exit'], $down['stderr']);
        self::assertSame(['run_1_0000000a'], $down['json']['pruned']);

        proc_close($process);
    }

    public function test_a_reused_pid_with_another_start_time_is_not_the_recorded_process(): void
    {
        $supervisor = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_reuse', '--explicit', 'mysql');

        $data = self::registryData($this->registry);
        $data['leases']['run_1_0000000a']['supervisor'] = ['pid' => getmypid(), 'start' => 'Thu Jan 1 00:00:00 1970'];
        self::writeJson($this->registry.'/registry.json', $data);

        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry));
        self::assertSame(0, $down['exit'], $down['stderr']);
        self::assertSame(['run_1_0000000a'], $down['json']['pruned']);
    }

    public function test_release_is_refused_while_a_recorded_child_is_alive(): void
    {
        $supervisor = $this->spawnSleeper();
        $child = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_rel', '--generated', 'mysql');
        $this->addChild('run_1_0000000a', $child);

        $release = $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry));
        self::assertSame(75, $release['exit'], $release['stderr']);
        self::assertSame([$child['pid']], $release['json']['children']);
        self::assertArrayHasKey('run_1_0000000a', self::registryData($this->registry)['leases']);
        self::assertNotContains('drop_databases', self::operations(self::fakeCalls($this->registry)));

        $this->stopSleeper($child);

        $release = $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry));
        self::assertSame(0, $release['exit'], $release['stderr']);
        self::assertContains('drop_databases', self::operations(self::fakeCalls($this->registry)));
        self::assertSame([], self::registryData($this->registry)['leases']);
    }

    public function test_add_child_and_token_report_missing_or_wrong_state(): void
    {
        $env = $this->fakeEnv($this->registry);

        self::assertSame(66, $this->testdb(['token'], $env)['exit']);
        self::assertSame(66, $this->testdb(['add-child', 'run_1_00000001', (string) getmypid(), 'auto'], $env)['exit']);

        $supervisor = $this->spawnSleeper();
        $child = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_kids', '--explicit', 'mysql');

        $wrongStart = $this->testdb(['add-child', 'run_1_0000000a', (string) $child['pid'], 'Thu Jan 1 00:00:00 1970'], $env);
        self::assertSame(64, $wrongStart['exit'], $wrongStart['stderr']);

        $added = $this->testdb(['add-child', 'run_1_0000000a', (string) $child['pid'], 'auto'], $env);
        self::assertSame(0, $added['exit'], $added['stderr']);
        $again = $this->testdb(['add-child', 'run_1_0000000a', (string) $child['pid'], $child['start']], $env);
        self::assertSame(0, $again['exit'], $again['stderr']);
        self::assertSame([['pid' => $child['pid'], 'start' => $child['start']]], $again['json']['children']);

        $this->stopSleeper($child);
        $dead = $this->testdb(['add-child', 'run_1_0000000a', (string) $child['pid'], 'auto'], $env);
        self::assertSame(64, $dead['exit'], $dead['stderr']);

        $token = $this->testdb(['token'], $env);
        self::assertSame(0, $token['exit']);
        self::assertSame(self::registryData($this->registry)['token'], $token['json']['token']);
    }

    public function test_stop_children_stops_the_recorded_children_only(): void
    {
        $supervisor = $this->spawnSleeper();
        $child = $this->spawnSleeper();
        $bystander = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_stop', '--explicit', 'mysql');
        $this->addChild('run_1_0000000a', $child);

        $stop = $this->testdb(['stop-children', 'run_1_0000000a'], $this->fakeEnv($this->registry));
        self::assertSame(0, $stop['exit'], $stop['stderr']);
        self::assertSame([$child['pid']], $stop['json']['terminated']);

        $this->waitUntil(static fn (): bool => ! self::isAlive($child['pid']), 5.0, 'the recorded child is still alive');
        self::assertTrue(self::isAlive($supervisor['pid']));
        self::assertTrue(self::isAlive($bystander['pid']));
    }

    // Owner records and group liveness

    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function leaseOrders(): iterable
    {
        yield 'the current lease first' => [true];
        yield 'the stale lease first' => [false];
    }

    #[DataProvider('leaseOrders')]
    public function test_group_liveness_is_judged_by_each_recorded_identity(bool $currentFirst): void
    {
        // Lease A recorded the live process group P with its real start. Lease
        // B recorded an earlier group with the same PID and another start:
        // that group ended, and the PID was reused. Both supervisors are gone.
        $group = $this->startGroup();
        $supervisor = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_cur', '--generated', 'mysql');
        $this->admit('run_1_0000000b', $supervisor, 'orrery_old', '--generated', 'mysql');
        $this->stopSleeper($supervisor);
        $data = self::registryData($this->registry);
        $leaseA = ['children' => [['pid' => $group['pid'], 'start' => $group['start'], 'group' => true]]] + $data['leases']['run_1_0000000a'];
        $leaseB = ['children' => [['pid' => $group['pid'], 'start' => 'Mon Jan 1 00:00:00 2024', 'group' => true]]] + $data['leases']['run_1_0000000b'];
        $data['leases'] = $currentFirst
            ? ['run_1_0000000a' => $leaseA, 'run_1_0000000b' => $leaseB]
            : ['run_1_0000000b' => $leaseB, 'run_1_0000000a' => $leaseA];
        self::writeJson($this->registry.'/registry.json', $data);

        $status = $this->testdb(['status', '--no-services'], $this->fakeEnv($this->registry));
        self::assertSame(0, $status['exit'], $status['stderr']);
        self::assertSame(['supervisor' => false, 'children' => [$group['pid']], 'unverified' => []], $status['json']['leases']['run_1_0000000a']['alive'], 'the current group is not live');
        self::assertSame(['supervisor' => false, 'children' => [], 'unverified' => []], $status['json']['leases']['run_1_0000000b']['alive'], 'the stale entry took the liveness of another group');

        $release = $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry));
        self::assertSame(75, $release['exit'], 'release accepted a lease with a live group: '.$release['stderr']);

        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry));
        self::assertSame(75, $down['exit'], $down['stderr']);
        $after = self::registryData($this->registry);
        self::assertSame(['run_1_0000000a'], array_keys($after['leases']), 'the prune kept the stale lease or removed the current one');
        self::assertSame(['orrery_old', 'orrery_old_mirror'], array_column($after['orphans'], 'name'));
        self::assertNotContains('drop_databases', self::operations(self::fakeCalls($this->registry)));
    }

    public function test_a_closing_record_refuses_new_children_and_directories(): void
    {
        $supervisor = $this->spawnSleeper();
        $child = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_close', '--explicit', 'mysql');
        $data = self::registryData($this->registry);
        $data['leases']['run_1_0000000a']['closing'] = true;
        self::writeJson($this->registry.'/registry.json', $data);

        $add = $this->testdb(['add-child', 'run_1_0000000a', (string) $child['pid'], 'auto'], $this->fakeEnv($this->registry));
        self::assertSame(76, $add['exit'], $add['stderr']);
        self::assertStringContainsString('run_1_0000000a is closing', $add['stderr']);
        self::assertSame([], self::registryData($this->registry)['leases']['run_1_0000000a']['children']);

        // A private owner record takes a directory only while it is open.
        $store = $this->makeTempDir();
        $owner = $this->ownerInit($store, 'run_1_0000000c', $supervisor);
        $first = $this->makeTempDir();
        $open = $this->testdb(['owner-path', $first], $this->scriptEnv(['ORRERY_OWNERS' => $owner]));
        self::assertSame(0, $open['exit'], $open['stderr']);
        self::assertSame([$store, $first], self::registryData($store)['leases']['run_1_0000000c']['paths']);

        self::changeRecord($store, 'run_1_0000000c', static fn (array $record): array => ['closing' => true] + $record);
        $second = $this->makeTempDir();
        $closed = $this->testdb(['owner-path', $second], $this->scriptEnv(['ORRERY_OWNERS' => $owner]));
        self::assertSame(76, $closed['exit'], $closed['stderr']);
        self::assertSame([$store, $first], self::registryData($store)['leases']['run_1_0000000c']['paths']);

        // A record that is gone, or an unusable entry, is an error, never an
        // empty list of owners.
        $gone = $this->testdb(['owner-path', $second], $this->scriptEnv(['ORRERY_OWNERS' => 'run_1_0000000d '.$store]));
        self::assertSame(66, $gone['exit'], $gone['stderr']);
        $missing = $this->testdb(['owner-path', $second], $this->scriptEnv(['ORRERY_OWNERS' => 'run_1_0000000c '.$store.'-gone']));
        self::assertSame(66, $missing['exit'], $missing['stderr']);
        $malformed = $this->testdb(['owner-path', $second], $this->scriptEnv(['ORRERY_OWNERS' => 'not an owner entry']));
        self::assertSame(78, $malformed['exit'], $malformed['stderr']);
    }

    public function test_shutdown_reads_the_record_again_until_every_group_is_gone(): void
    {
        // Both groups ignore SIGTERM, so the shutdown runs for its whole grace
        // period. The second one appears in the record after the close (the
        // refusal of such a join is tested with bin/test), and it watches the
        // record's directory.
        $store = $this->makeTempDir();
        $log = $this->base.'/watch.log';
        $first = $this->startGroup(['ORRERY_GROUP_IGNORE_TERM' => '1']);
        $late = $this->startGroup(['ORRERY_GROUP_IGNORE_TERM' => '1', 'ORRERY_GROUP_WATCH' => $store, 'ORRERY_GROUP_WATCH_LOG' => $log]);
        $owner = $this->ownerInit($store, 'run_1_0000000a', ['pid' => getmypid(), 'start' => 'auto']);
        self::changeRecord($store, 'run_1_0000000a', static fn (array $record): array => ['children' => [['pid' => $first['pid'], 'start' => $first['start'], 'group' => true]]] + $record);

        $shutdown = $this->startTestdb(['shutdown', '--owner='.$owner, '--grace=2'], $this->scriptEnv());
        $this->waitUntil(static fn (): bool => (self::registryData($store)['leases']['run_1_0000000a']['closing'] ?? false) === true, 10.0, 'the shutdown did not close the record');
        usleep(300_000);
        self::changeRecord($store, 'run_1_0000000a', static function (array $record) use ($late): array {
            $record['children'][] = ['pid' => $late['pid'], 'start' => $late['start'], 'group' => true];

            return $record;
        });
        $shutdown->wait();

        self::assertSame(0, $shutdown->getExitCode(), $shutdown->getErrorOutput());
        self::assertFalse(self::isAlive($first['pid']));
        self::assertFalse(self::isAlive($late['pid']), 'a group that the record listed during the shutdown survived it');
        self::assertDirectoryDoesNotExist($store);
        self::assertFileDoesNotExist($log, 'the shutdown removed the record directory while a recorded group was alive');
    }

    public function test_shutdown_leaves_a_group_whose_leader_has_another_start_time_alone(): void
    {
        // The recorded start differs from the live process at the PID: the
        // recorded group ended and the PID was reused.
        $group = $this->startGroup();
        $store = $this->makeTempDir();
        $owner = $this->ownerInit($store, 'run_1_0000000a', ['pid' => getmypid(), 'start' => 'auto']);
        self::changeRecord($store, 'run_1_0000000a', static fn (array $record): array => ['children' => [['pid' => $group['pid'], 'start' => 'Thu Jan 1 00:00:00 1970', 'group' => true]]] + $record);

        $foreign = $this->testdb(['shutdown', '--owner='.$owner, '--grace=1'], $this->scriptEnv());
        self::assertSame(0, $foreign['exit'], $foreign['stderr']);
        self::assertSame([$group['pid']], $foreign['json']['skipped']);
        self::assertSame([], $foreign['json']['terminated']);
        usleep(100_000);
        self::assertTrue(self::isAlive($group['pid']), 'the shutdown signalled a process with another start time');
        self::assertDirectoryDoesNotExist($store);

        // With the recorded start, the same shutdown stops the group.
        $store = $this->makeTempDir();
        $owner = $this->ownerInit($store, 'run_1_0000000b', ['pid' => getmypid(), 'start' => 'auto']);
        self::changeRecord($store, 'run_1_0000000b', static fn (array $record): array => ['children' => [['pid' => $group['pid'], 'start' => $group['start'], 'group' => true]]] + $record);

        $own = $this->testdb(['shutdown', '--owner='.$owner, '--grace=1'], $this->scriptEnv());
        self::assertSame(0, $own['exit'], $own['stderr']);
        self::assertSame([$group['pid']], $own['json']['terminated']);
        self::assertFalse(self::isAlive($group['pid']));
        self::assertDirectoryDoesNotExist($store);
    }

    public function test_group_membership_needs_the_recorded_leader_or_the_group_token(): void
    {
        require_once self::testdbPath();

        // Synthetic process listings: the recorded group has ID 4101, a
        // leader start of 2024-01-01 and the token $ours. Nothing runs.
        $ours = str_repeat('a', 32);
        $recorded = 'Mon Jan 1 10:00:00 2024';
        $later = 'Tue Jan 2 10:00:00 2024';
        $earlier = 'Sun Dec 31 10:00:00 2023';
        $process = static fn (int $pid, int $pgid, string $start, bool $zombie = false): array => ['pid' => $pid, 'ppid' => 1, 'pgid' => $pgid, 'zombie' => $zombie, 'start' => $start];
        $listing = static function (array ...$processes): array {
            $all = [];

            foreach ($processes as $process) {
                $all[$process['pid']] = $process;
            }

            return $all;
        };
        // Which PIDs carry the token: 4103 does, every other PID does not.
        $carries = static fn (array $pids): array => array_combine($pids, array_map(static fn (int $pid): bool => $pid === 4103, $pids));
        $members = static fn (array $all, array $known = []): ?array => Processes::groupMembers($all, 4101, $recorded, $carries, $known);

        // The recorded leader, live or a zombie: the recorded group. Every member is owned.
        self::assertSame(['owned' => [4101 => $recorded, 4102 => $later], 'unverified' => []], $members($listing($process(4101, 4101, $recorded), $process(4102, 4101, $later))));
        self::assertSame(['owned' => [4102 => $later], 'unverified' => []], $members($listing($process(4101, 4101, $recorded, true), $process(4102, 4101, $later))));

        // A leader with another start, live or a zombie: the group ID was reused. Nothing is owned.
        self::assertNull($members($listing($process(4101, 4101, $later), $process(4102, 4101, $later), $process(4103, 4101, $later))));
        self::assertNull($members($listing($process(4101, 4101, $later, true), $process(4102, 4101, $later), $process(4103, 4101, $later))), 'a foreign zombie leader was taken as the recorded one');

        // No leader: only the token, or an earlier proof of this caller, makes a member owned.
        self::assertSame(
            ['owned' => [4103 => $later], 'unverified' => [4102 => $later]],
            $members($listing($process(4102, 4101, $later), $process(4103, 4101, $later))),
            'without its leader, a group ID alone made a process owned',
        );
        self::assertSame(['owned' => [4102 => $later], 'unverified' => []], $members($listing($process(4102, 4101, $later)), [4102 => $later]));
        self::assertSame(['owned' => [], 'unverified' => [4102 => $later]], $members($listing($process(4102, 4101, $later)), [4102 => $recorded]), 'an earlier proof for another start counted');

        // A process that started before the recorded leader is not in the recorded group.
        self::assertSame(['owned' => [], 'unverified' => []], $members($listing($process(4104, 4101, $earlier))));
    }

    public function test_the_linux_token_reader_needs_the_exact_environment_entry(): void
    {
        require_once self::testdbPath();

        // /proc/<pid>/environ: the environment entries alone, NUL-separated.
        $ours = str_repeat('a', 32);
        $other = str_repeat('b', 32);
        $environ = static fn (string ...$entries): string => implode("\0", $entries)."\0";

        self::assertTrue(Processes::environHasToken($environ('PATH=/usr/bin', "ORRERY_GROUP_TOKEN={$ours}", 'HOME=/x'), $ours));
        self::assertFalse(Processes::environHasToken($environ('PATH=/usr/bin', "ORRERY_GROUP_TOKEN={$other}"), $ours), 'another token matched');
        self::assertFalse(Processes::environHasToken($environ('PATH=/usr/bin'), $ours), 'a missing token matched');
        self::assertFalse(Processes::environHasToken($environ("DECOY=x ORRERY_GROUP_TOKEN={$ours} y", "ORRERY_GROUP_TOKEN={$other}"), $ours), 'the value of another variable counted');
        self::assertFalse(Processes::environHasToken($environ("DECOY=ORRERY_GROUP_TOKEN={$ours}"), $ours), 'the value of another variable counted');
        self::assertFalse(Processes::environHasToken($environ("XORRERY_GROUP_TOKEN={$ours}"), $ours), 'another variable name counted');
        self::assertFalse(Processes::environHasToken($environ("ORRERY_GROUP_TOKEN={$ours}x"), $ours), 'a longer value counted');
        self::assertFalse(Processes::environHasToken($environ("ORRERY_GROUP_TOKEN={$other}", "ORRERY_GROUP_TOKEN={$ours}"), $ours), 'two token entries are ambiguous');
        self::assertFalse(Processes::environHasToken($environ("ORRERY_GROUP_TOKEN={$ours}", "ORRERY_GROUP_TOKEN={$ours}"), $ours), 'two token entries are ambiguous');

        if (PHP_OS_FAMILY !== 'Linux') {
            // No exact source elsewhere: a process with the right token still has no proof.
            $process = proc_open([PHP_BINARY, '-r', 'sleep(10);'], [], $pipes, null, ['ORRERY_GROUP_TOKEN' => $ours, 'PATH' => (string) getenv('PATH')]);
            self::assertIsResource($process);
            $pid = proc_get_status($process)['pid'];

            try {
                self::assertSame([$pid => false], Processes::carryToken([$pid], $ours));
            } finally {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function groupsWithoutTheirLeader(): iterable
    {
        yield 'a foreign group whose leader is a zombie' => ['foreign', true];
        yield 'a foreign group whose leader is gone' => ['foreign', false];
        yield 'our group whose leader is a zombie' => ['ours', true];
        yield 'our group whose leader is gone' => ['ours', false];
        yield 'a foreign group without its leader, with our token in an argument' => ['argument', false];
        yield 'a foreign group without its leader, with our token in another value' => ['value', false];
    }

    #[DataProvider('groupsWithoutTheirLeader')]
    public function test_a_group_without_its_live_leader_is_ours_only_by_its_leader_start_or_its_token(string $kind, bool $zombie): void
    {
        // A real process group: a leader and one member, both of which record
        // a SIGTERM. The leader exits. This test keeps it as a zombie (it does
        // not reap it), or reaps it, so the group has no leader. The entry
        // always has this run's token.
        // - "foreign": the group has another token, and the entry an older
        //   leader start, as when the recorded group ended and its ID was
        //   reused.
        // - "ours": the group has the entry's token and leader start.
        // - "argument": the member's token differs, and one of its arguments
        //   is "ORRERY_GROUP_TOKEN=<the entry's token>".
        // - "value": the member has no token, and the value of another
        //   variable contains "ORRERY_GROUP_TOKEN=<the entry's token>".
        // Without its leader, only Linux can read a member's environment
        // exactly (see Processes::carryToken()), so only there our group is
        // proved by its token. Everywhere else it fails closed.
        $dir = $this->makeTempDir();
        $token = bin2hex(random_bytes(16));
        $exit = $dir.'/leader-exit';
        $changes = [
            'ORRERY_GROUP_TOKEN' => $kind === 'ours' ? $token : bin2hex(random_bytes(16)),
            'ORRERY_GROUP_MEMBER' => '1',
            'ORRERY_GROUP_LEADER_EXIT' => $exit,
            'ORRERY_GROUP_RECORD_TERM' => '1',
        ];

        if ($kind === 'argument') {
            $changes['ORRERY_GROUP_MEMBER_ARGUMENT'] = 'ORRERY_GROUP_TOKEN='.$token;
        }

        if ($kind === 'value') {
            $changes['ORRERY_GROUP_MEMBER_TOKEN'] = '-';
            $changes['ORRERY_GROUP_DECOY'] = "x ORRERY_GROUP_TOKEN={$token} y";
        }

        $leader = proc_open([PHP_BINARY, self::groupPath(), $dir], [], $pipes, self::repoRoot(), self::realEnv($this->scriptEnv($changes)));
        self::assertIsResource($leader);
        $leaderPid = proc_get_status($leader)['pid'];
        $member = 0;
        $memberIdentity = null;

        try {
            $this->waitUntil(static fn (): bool => file_exists("{$dir}/group-{$leaderPid}.ready"), 10.0, 'the group did not start');
            $member = (int) trim((string) file_get_contents("{$dir}/group-{$leaderPid}.ready"));
            $memberIdentity = self::identityOf($member);
            $leaderStart = (string) self::startOf($leaderPid);
            touch($exit);
            $this->waitUntil(static fn (): bool => str_contains(trim((string) shell_exec('ps -o stat= -p '.$leaderPid.' 2>/dev/null')), 'Z'), 10.0, 'the leader did not exit');

            if (! $zombie) {
                proc_close($leader);
                $leader = null;
            }

            self::assertTrue(self::isAlive($member));

            if ($kind === 'argument') {
                self::assertStringContainsString('ORRERY_GROUP_TOKEN='.$token, (string) shell_exec('ps -ww -o command= -p '.$member), 'the decoy argument is missing');
            }

            $entry = ['pid' => $leaderPid, 'start' => $kind === 'foreign' ? 'Mon Jan 1 00:00:00 2024' : $leaderStart, 'group' => true, 'token' => $token];
            $owned = $kind === 'ours' && ($zombie || PHP_OS_FAMILY === 'Linux');
            $skipped = $kind === 'foreign' && $zombie;

            // Liveness: a lease with this entry, and a supervisor that is gone.
            $supervisor = $this->spawnSleeper();
            $this->admit('run_1_0000000a', $supervisor, 'orrery_grp', '--generated', 'mysql');
            $this->stopSleeper($supervisor);
            $data = self::registryData($this->registry);
            $data['leases']['run_1_0000000a']['children'] = [$entry + ['run' => 'run_1_0000000a']];
            self::writeJson($this->registry.'/registry.json', $data);

            $expected = match (true) {
                $owned => ['children' => [$leaderPid], 'unverified' => []],
                $skipped => ['children' => [], 'unverified' => []],
                default => ['children' => [], 'unverified' => [$member]],
            };
            $status = $this->testdb(['status', '--no-services'], $this->fakeEnv($this->registry));
            self::assertSame(0, $status['exit'], $status['stderr']);
            self::assertSame(['supervisor' => false] + $expected, $status['json']['leases']['run_1_0000000a']['alive']);
            self::assertSame(! $skipped, $status['json']['leases']['run_1_0000000a']['live']);

            if (! $owned && ! $skipped) {
                // The recovery command does not signal it either, and it fails.
                // It waits the whole grace for the unverified member, then fails.
                $stop = $this->testdb(['stop-children', 'run_1_0000000a', '--grace=0.3'], $this->fakeEnv($this->registry));
                self::assertSame(74, $stop['exit'], $stop['stderr']);
                self::assertSame([$member], $stop['json']['unverified']);
                usleep(200_000);
                self::assertFileDoesNotExist("{$dir}/term-{$member}", 'stop-children sent SIGTERM to a member that it could not verify');
            }

            $release = $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry));
            self::assertSame(match (true) {
                $owned => 75,
                $skipped => 0,
                default => 74,
            }, $release['exit'], $release['stderr']);

            if (! $owned && ! $skipped) {
                self::assertStringContainsString("process {$member} has the group ID of a recorded process group whose leader is gone", $release['stderr']);
                self::assertArrayHasKey('run_1_0000000a', self::registryData($this->registry)['leases']);
                self::assertNotContains('drop_databases', self::operations(self::fakeCalls($this->registry)));
            }

            // The shutdown of a private record with the same entry.
            $store = $this->makeTempDir();
            $owner = $this->ownerInit($store, 'run_1_0000000b', ['pid' => getmypid(), 'start' => 'auto']);
            self::changeRecord($store, 'run_1_0000000b', static fn (array $record): array => ['children' => [$entry + ['run' => 'run_1_0000000b']]] + $record);
            // Our member ends at its SIGTERM, so for it the grace is only a
            // bound. An unverified member keeps the stop waiting for the whole
            // grace, so that case gets a short one.
            $shutdown = $this->testdb(['shutdown', '--owner='.$owner, '--grace='.($owned ? '2' : '0.3')], $this->scriptEnv());

            if ($owned) {
                self::assertSame(0, $shutdown['exit'], $shutdown['stderr']);
                self::assertSame([$member], $shutdown['json']['terminated']);
                $this->waitUntil(static fn (): bool => ! self::isAlive($member), 5.0, 'the member of our group survived the shutdown');
                self::assertFileExists("{$dir}/term-{$member}");
                self::assertDirectoryDoesNotExist($store);
            } else {
                self::assertSame($skipped ? 0 : 74, $shutdown['exit'], $shutdown['stderr']);
                usleep(200_000);
                self::assertTrue(self::isAlive($member), 'the shutdown stopped a member that is not proved ours');
                self::assertFileDoesNotExist("{$dir}/term-{$member}", 'the shutdown sent SIGTERM to a member that is not proved ours');

                if ($skipped) {
                    self::assertSame([$leaderPid], $shutdown['json']['skipped']);
                    self::assertDirectoryDoesNotExist($store);
                } else {
                    self::assertSame([$member], $shutdown['json']['unverified']);
                    self::assertDirectoryExists($store, 'the shutdown removed the directory although a process with the group ID could not be verified');
                    self::assertTrue(self::registryData($store)['leases']['run_1_0000000b']['closing']);
                }
            }
        } finally {
            if ($memberIdentity !== null) {
                self::signalIdentity($memberIdentity, SIGKILL);
            }

            if (is_resource($leader)) {
                proc_close($leader);
            }
        }
    }

    /**
     * Rejects: a stop that fails (74) at once when the only process left in a group without its
     * leader is unverified, although that process ends by itself within the grace period (a
     * short-lived child of the stopped anchor, or a process that is still exiting on a loaded
     * machine). Also rejects a stop that signals the unverified process instead of waiting.
     */
    public function test_a_stop_waits_within_the_grace_for_an_unverified_member_that_ends_by_itself(): void
    {
        $dir = $this->makeTempDir();
        $leaderExit = $dir.'/leader-exit';
        $memberExit = $dir.'/member-exit';
        $leader = proc_open([PHP_BINARY, self::groupPath(), $dir], [], $pipes, self::repoRoot(), self::realEnv($this->scriptEnv([
            // The member has no token (and macOS reads none), so without the
            // leader it cannot be proved.
            'ORRERY_GROUP_TOKEN' => bin2hex(random_bytes(16)),
            'ORRERY_GROUP_MEMBER_TOKEN' => '-',
            'ORRERY_GROUP_MEMBER' => '1',
            'ORRERY_GROUP_LEADER_EXIT' => $leaderExit,
            'ORRERY_GROUP_MEMBER_EXIT' => $memberExit,
            'ORRERY_GROUP_RECORD_TERM' => '1',
        ])));
        self::assertIsResource($leader);
        $leaderPid = proc_get_status($leader)['pid'];
        $memberIdentity = null;

        try {
            $this->waitUntil(static fn (): bool => file_exists("{$dir}/group-{$leaderPid}.ready"), 10.0, 'the group did not start');
            $member = (int) trim((string) file_get_contents("{$dir}/group-{$leaderPid}.ready"));
            $memberIdentity = self::identityOf($member);
            $leaderStart = (string) self::startOf($leaderPid);
            touch($leaderExit);
            $this->waitUntil(static fn (): bool => str_contains(trim((string) shell_exec('ps -o stat= -p '.$leaderPid.' 2>/dev/null')), 'Z'), 10.0, 'the leader did not exit');
            proc_close($leader);
            $leader = null;

            $store = $this->makeTempDir();
            $owner = $this->ownerInit($store, 'run_1_0000000c', ['pid' => getmypid(), 'start' => 'auto']);
            $entry = ['pid' => $leaderPid, 'start' => $leaderStart, 'group' => true, 'token' => bin2hex(random_bytes(16)), 'run' => 'run_1_0000000c'];
            self::changeRecord($store, 'run_1_0000000c', static fn (array $record): array => ['children' => [$entry]] + $record);

            // The stop reports each round in which it waits for an unverified
            // member (a test hook). The member ends only after that report, so
            // the test proves that the stop saw it and kept waiting.
            $seen = $dir.'/unverified-wait';
            $shutdown = $this->startProcess([PHP_BINARY, '-n', self::testdbPath(), 'shutdown', '--owner='.$owner, '--grace=30'], $this->fakeEnv($this->registry, ['ORRERY_TEST_UNVERIFIED_WAIT' => $seen]));
            $this->waitUntil(static fn (): bool => file_exists($seen) || ! $shutdown->isRunning(), 20.0, 'the shutdown did not report the unverified member');
            self::assertTrue($shutdown->isRunning(), 'the shutdown ended before the member ended: '.$shutdown->getOutput().$shutdown->getErrorOutput());
            self::assertSame((string) $member, trim((string) file_get_contents($seen)), 'the shutdown waited for another process');
            touch($memberExit);
            $shutdown->wait();

            self::assertSame(0, $shutdown->getExitCode(), $shutdown->getOutput().$shutdown->getErrorOutput());
            self::assertFileDoesNotExist("{$dir}/term-{$member}", 'the shutdown signalled a member that it could not verify');
            self::assertDirectoryDoesNotExist($store, 'the shutdown did not finish after the member ended');
        } finally {
            if ($memberIdentity !== null) {
                self::signalIdentity($memberIdentity, SIGKILL);
            }

            if (is_resource($leader)) {
                proc_close($leader);
            }
        }
    }

    public function test_the_registry_path_does_not_depend_on_tmpdir(): void
    {
        $canonical = self::canonicalRegistry();
        $first = $this->makeTempDir();
        $second = $this->makeTempDir();

        $a = $this->testdb(['path'], $this->scriptEnv(['TMPDIR' => $first.'/']));
        $b = $this->testdb(['path'], $this->scriptEnv(['TMPDIR' => $second.'/']));
        $wrapper = $this->runProcess([...$this->wrapper('bin/test-db'), 'path'], $this->scriptEnv(['TMPDIR' => $second.'/', 'ORRERY_TEST_PHP' => PHP_BINARY]));

        self::assertSame($canonical, $a['json']['path']);
        self::assertSame($canonical, $b['json']['path']);
        self::assertSame($canonical, $wrapper['json']['path']);

        // The override counts only in test mode.
        $ignored = $this->testdb(['path'], $this->scriptEnv(['ORRERY_REGISTRY_DIR' => $first]));
        self::assertSame($canonical, $ignored['json']['path']);
        self::assertStringContainsString('ignoring ORRERY_REGISTRY_DIR', $ignored['stderr']);
    }

    public function test_the_registry_must_be_a_private_directory_that_is_not_a_symlink(): void
    {
        $up = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame(0, $up['exit'], $up['stderr']);
        self::assertSame(0700, fileperms($this->registry) & 0777);

        $target = $this->makeTempDir();
        symlink($target, $this->base.'/linked');
        $linked = $this->testdb(['up'], $this->fakeEnv($this->base.'/linked'));
        self::assertSame(78, $linked['exit']);
        self::assertStringContainsString('symlink', $linked['stderr']);

        mkdir($this->base.'/open', 0755);
        chmod($this->base.'/open', 0755);
        $open = $this->testdb(['up'], $this->fakeEnv($this->base.'/open'));
        self::assertSame(78, $open['exit']);
        self::assertStringContainsString('it must be 0700', $open['stderr']);
    }

    public function test_the_fake_backend_needs_test_mode_and_the_docker_backend_refuses_a_test_registry(): void
    {
        $fakeWithoutTestMode = $this->testdb(['up'], $this->scriptEnv(['ORRERY_OPS' => 'fake', 'ORRERY_REGISTRY_DIR' => $this->registry]));
        self::assertSame(78, $fakeWithoutTestMode['exit']);
        self::assertStringContainsString('ORRERY_OPS=fake requires ORRERY_REGISTRY_TEST=1', $fakeWithoutTestMode['stderr']);

        $dockerWithTestRegistry = $this->testdb(['up'], $this->scriptEnv(['ORRERY_REGISTRY_TEST' => '1', 'ORRERY_REGISTRY_DIR' => $this->registry]));
        self::assertSame(78, $dockerWithTestRegistry['exit']);
        self::assertStringContainsString('Docker backend refuses a test registry override', $dockerWithTestRegistry['stderr']);

        $noDirectory = $this->testdb(['up'], $this->scriptEnv(['ORRERY_REGISTRY_TEST' => '1', 'ORRERY_OPS' => 'fake']));
        self::assertSame(78, $noDirectory['exit']);

        $canonical = $this->testdb(['up'], $this->fakeEnv(self::canonicalRegistry()));
        self::assertSame(78, $canonical['exit']);
        self::assertStringContainsString('must not be the canonical registry', $canonical['stderr']);

        self::assertDirectoryDoesNotExist($this->registry);

        // The permitted combination works, so the refusals above are not
        // the only possible outcome.
        $fake = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame(0, $fake['exit'], $fake['stderr']);
    }

    public function test_a_test_registry_that_aliases_the_canonical_registry_is_refused(): void
    {
        // A disposable stand-in for /tmp/orrery-testing-<uid>, with a
        // sentinel registry that no command may change.
        $production = $this->base.'/production';
        mkdir($production, 0700);
        $sentinel = json_encode(['version' => 1, 'token' => str_repeat('5', 32), 'leases' => [], 'orphans' => []])."\n";
        file_put_contents($production.'/registry.json', $sentinel);
        mkdir($this->base.'/sub', 0700);
        symlink($production, $this->base.'/alias');
        symlink($this->base, $this->base.'/parent-alias');

        $aliases = [
            $production => 'must not be the canonical registry',
            $production.'/' => 'must not be the canonical registry',
            $production.'/.' => 'normalised path',
            $this->base.'/sub/../production' => 'normalised path',
            $this->base.'//production' => 'normalised path',
            $this->base.'/alias' => 'must not be the canonical registry',
            $this->base.'/parent-alias/production' => 'must not be the canonical registry',
        ];

        foreach ($aliases as $alias => $message) {
            $result = $this->testdb(['up'], $this->fakeEnv($alias, ['ORRERY_TEST_CANONICAL_REGISTRY' => $production]));
            self::assertSame(78, $result['exit'], "{$alias}: {$result['stderr']}");
            self::assertStringContainsString($message, $result['stderr'], $alias);
        }

        self::assertSame($sentinel, file_get_contents($production.'/registry.json'));
        self::assertSame(['registry.json'], array_values(array_diff(scandir($production) ?: [], ['.', '..'])), 'a command wrote into the protected directory');

        // Another directory next to it is a valid test registry.
        $other = $this->testdb(['up'], $this->fakeEnv($this->base.'/other', ['ORRERY_TEST_CANONICAL_REGISTRY' => $production]));
        self::assertSame(0, $other['exit'], $other['stderr']);
    }

    // The decision-to-action lock

    public function test_down_waits_while_admit_holds_the_lock_between_its_decision_and_its_action(): void
    {
        $supervisor = $this->spawnSleeper();
        $barrier = $this->makeTempDir();

        $admit = $this->startTestdb(
            ['admit', 'run_1_0000000a', (string) $supervisor['pid'], $supervisor['start'], 'orrery_lock', '--generated', '--engine=mysql'],
            $this->fakeEnv($this->registry, ['ORRERY_FAKE_HOLD_AT' => 'create_databases', 'ORRERY_FAKE_BARRIER_DIR' => $barrier]),
        );
        $this->waitUntil(static fn (): bool => file_exists($barrier.'/create_databases.reached'), 20.0, 'admit did not reach its action: '.$admit->getErrorOutput());

        // The decision is saved: the lease holds both names.
        self::assertSame(['orrery_lock', 'orrery_lock_mirror'], self::registryData($this->registry)['leases']['run_1_0000000a']['names']);
        self::assertNotContains('create_databases', self::operations(self::fakeCalls($this->registry)));
        self::assertFalse(self::lockIsFree($this->registry), 'admit released the registry lock between its decision and its action');

        $down = $this->startTestdb(['down'], $this->fakeEnv($this->registry, ['ORRERY_TEST_BEFORE_LOCK' => $barrier.'/down.locking']));
        $this->waitUntil(static fn (): bool => file_exists($barrier.'/down.locking'), 20.0, 'down did not reach the registry lock');
        usleep(200_000);
        self::assertTrue($down->isRunning(), 'down finished while admit was held between its decision and its action: '.$down->getErrorOutput());

        touch($barrier.'/create_databases.release');
        $admit->wait();
        $down->wait();

        $admitted = self::processResult($admit);
        $refused = self::processResult($down);
        self::assertSame(0, $admitted['exit'], $admitted['stderr']);
        self::assertSame(75, $refused['exit'], $refused['stderr']);
        self::assertStringContainsString('run_1_0000000a', $refused['stderr']);

        $operations = self::operations(self::fakeCalls($this->registry));
        self::assertContains('create_databases', $operations);
        self::assertNotContains('down', $operations);
    }

    public function test_a_second_admit_for_the_same_name_waits_for_the_first_and_is_refused(): void
    {
        $supervisor = $this->spawnSleeper();
        $barrier = $this->makeTempDir();

        $first = $this->startTestdb(
            ['admit', 'run_1_0000000a', (string) $supervisor['pid'], $supervisor['start'], 'orrery_same', '--explicit', '--engine=mysql'],
            $this->fakeEnv($this->registry, ['ORRERY_FAKE_HOLD_AT' => 'create_databases', 'ORRERY_FAKE_BARRIER_DIR' => $barrier]),
        );
        $this->waitUntil(static fn (): bool => file_exists($barrier.'/create_databases.reached'), 20.0, 'the first admit did not reach its action: '.$first->getErrorOutput());
        self::assertFalse(self::lockIsFree($this->registry), 'admit released the registry lock between its decision and its action');

        $second = $this->startTestdb(
            ['admit', 'run_2_0000000b', (string) $supervisor['pid'], $supervisor['start'], 'orrery_same', '--explicit', '--engine=mysql'],
            $this->fakeEnv($this->registry, ['ORRERY_TEST_BEFORE_LOCK' => $barrier.'/second.locking']),
        );
        $this->waitUntil(static fn (): bool => file_exists($barrier.'/second.locking'), 20.0, 'the second admit did not reach the registry lock');
        usleep(200_000);
        self::assertTrue($second->isRunning(), 'the second admit finished while the first was held between its decision and its action');

        touch($barrier.'/create_databases.release');
        $first->wait();
        $second->wait();

        self::assertSame(0, self::processResult($first)['exit'], $first->getErrorOutput());
        $refused = self::processResult($second);
        self::assertSame(73, $refused['exit'], $refused['stderr']);
        self::assertSame('run_1_0000000a', $refused['json']['holder']['run_id']);

        $creates = array_values(array_filter(self::fakeCalls($this->registry), static fn (array $call): bool => $call['op'] === 'create_databases'));
        self::assertCount(1, $creates);
    }

    // Service ensure

    public function test_a_cold_start_and_a_restart_create_a_new_generation(): void
    {
        $env = $this->fakeEnv($this->registry);

        $cold = $this->testdb(['up'], $env);
        self::assertSame(0, $cold['exit'], $cold['stderr']);
        self::assertSame('new', $cold['json']['generation']);
        $token = $cold['json']['token'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        self::assertSame(['ps', 'up', 'read_marker', 'read_marker', 'read_marker', 'write_marker', 'write_marker', 'write_marker'], self::operations(self::fakeCalls($this->registry)));
        self::assertSame($token, self::fakeState($this->registry)['services']['mysql']['marker']);
        self::assertSame($token, self::fakeState($this->registry)['services']['postgres']['marker']);
        self::assertSame($token, self::fakeState($this->registry)['services']['mariadb']['marker']);
        self::assertSame($token, self::registryData($this->registry)['token']);

        $before = count(self::fakeCalls($this->registry));
        $again = $this->testdb(['up'], $env);
        self::assertSame(0, $again['exit'], $again['stderr']);
        self::assertSame('kept', $again['json']['generation']);
        self::assertSame($token, $again['json']['token']);
        self::assertSame(['ps', 'up', 'read_marker', 'read_marker', 'read_marker'], self::operations(array_slice(self::fakeCalls($this->registry), $before)));

        // A restart discarded the tmpfs data, including both markers.
        self::changeFakeState($this->registry, static function (array $state): array {
            foreach (['mysql', 'postgres'] as $service) {
                $state['services'][$service]['marker'] = null;
                $state['services'][$service]['databases'] = [];
            }

            return $state;
        });

        $restarted = $this->testdb(['up'], $env);
        self::assertSame(0, $restarted['exit'], $restarted['stderr']);
        self::assertSame('new', $restarted['json']['generation']);
        self::assertNotSame($token, $restarted['json']['token']);
        self::assertSame($restarted['json']['token'], self::fakeState($this->registry)['services']['postgres']['marker']);
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: int}>
     */
    public static function unusableServicesWithALiveLease(): iterable
    {
        yield 'mysql stopped' => [static function (array $state): array {
            $state['services']['mysql']['state'] = 'exited';
            $state['services']['mysql']['health'] = '';

            return $state;
        }, 71];

        yield 'postgres unhealthy' => [static function (array $state): array {
            $state['services']['postgres']['health'] = 'unhealthy';

            return $state;
        }, 71];

        yield 'postgres removed' => [static function (array $state): array {
            $state['services']['postgres'] = array_replace($state['services']['postgres'], ['state' => 'absent', 'health' => '', 'ports' => [], 'marker' => null]);

            return $state;
        }, 71];

        yield 'mysql marker differs from the token' => [static function (array $state): array {
            $state['services']['mysql']['marker'] = str_repeat('f', 32);

            return $state;
        }, 70];

        yield 'postgres marker missing' => [static function (array $state): array {
            $state['services']['postgres']['marker'] = null;

            return $state;
        }, 70];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    #[DataProvider('unusableServicesWithALiveLease')]
    public function test_with_a_live_lease_unusable_services_are_refused_without_a_service_change(callable $change, int $expected): void
    {
        $supervisor = $this->spawnSleeper();
        self::assertSame(0, $this->testdb(['up'], $this->fakeEnv($this->registry))['exit'], 'bin/test-db up starts every default server');
        $this->admit('run_1_0000000a', $supervisor, 'orrery_live', '--explicit', 'mysql');
        $token = self::registryData($this->registry)['token'];
        self::changeFakeState($this->registry, $change);
        $before = count(self::fakeCalls($this->registry));

        $up = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame($expected, $up['exit'], $up['stderr']);

        // The engine `all` needs every default server.
        $admit = $this->admitRaw('run_1_0000000b', $supervisor, 'orrery_other', '--generated', 'all');
        self::assertSame($expected, $admit['exit'], $admit['stderr']);

        $operations = self::operations(array_slice(self::fakeCalls($this->registry), $before));
        self::assertSame([], array_values(array_intersect($operations, ['up', 'down', 'write_marker', 'create_databases', 'drop_databases'])));
        self::assertSame($token, self::registryData($this->registry)['token']);
        self::assertSame(['run_1_0000000a'], array_keys(self::registryData($this->registry)['leases']));
    }

    public function test_with_a_live_lease_a_changed_definition_reuses_the_running_generation(): void
    {
        $supervisor = $this->spawnSleeper();
        self::assertSame(0, $this->testdb(['up'], $this->fakeEnv($this->registry))['exit'], 'bin/test-db up starts every default server');
        $first = $this->admit('run_1_0000000a', $supervisor, 'orrery_def', '--explicit', 'mysql');
        $token = $first['token'];

        // The checkout now has another Compose definition than the running one.
        self::changeFakeState($this->registry, static function (array $state): array {
            $state['definition'] = 'v2';

            return $state;
        });
        $before = count(self::fakeCalls($this->registry));

        $up = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame(0, $up['exit'], $up['stderr']);
        self::assertSame('kept', $up['json']['generation']);
        self::assertSame($token, $up['json']['token']);

        $second = $this->admit('run_1_0000000b', $supervisor, 'orrery_def2', '--generated', 'mysql');
        self::assertSame('kept', $second['generation']);
        self::assertSame($token, $second['token']);

        $operations = self::operations(array_slice(self::fakeCalls($this->registry), $before));
        self::assertSame([], array_values(array_intersect($operations, ['up', 'down', 'write_marker'])));
        self::assertSame('v1', self::fakeState($this->registry)['running_definition']);

        // The changed definition applies at the next start without live leases.
        self::assertSame(0, $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry))['exit']);
        self::assertSame(0, $this->testdb(['release', 'run_1_0000000b'], $this->fakeEnv($this->registry))['exit']);

        $restart = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame(0, $restart['exit'], $restart['stderr']);
        self::assertSame('new', $restart['json']['generation']);
        self::assertNotSame($token, $restart['json']['token']);
        $state = self::fakeState($this->registry);
        self::assertSame('v2', $state['running_definition']);
        self::assertSame('fake-mysql-2', $state['services']['mysql']['id']);
    }

    public function test_a_port_in_use_outside_the_project_fails_before_anything_starts(): void
    {
        mkdir($this->registry, 0700);
        self::writeJson($this->registry.'/fake-ops.json', ['ports_taken' => [33106]]);

        $taken = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame(69, $taken['exit'], $taken['stderr']);
        self::assertStringContainsString('127.0.0.1:33106', $taken['stderr']);
        self::assertNotContains('up', self::operations(self::fakeCalls($this->registry)));

        // A port override is checked the same way.
        self::changeFakeState($this->registry, static fn (array $state): array => ['ports_taken' => [54999]] + $state);
        $override = $this->testdb(['up'], $this->fakeEnv($this->registry, ['ORRERY_TEST_PGSQL_PORT' => '54999']));
        self::assertSame(69, $override['exit'], $override['stderr']);
        self::assertStringContainsString('127.0.0.1:54999', $override['stderr']);
        self::assertNotContains('up', self::operations(self::fakeCalls($this->registry)));

        // The ports that the project's own containers publish are not taken.
        self::changeFakeState($this->registry, static fn (array $state): array => ['ports_taken' => []] + $state);
        self::assertSame(0, $this->testdb(['up'], $this->fakeEnv($this->registry))['exit']);
        self::changeFakeState($this->registry, static fn (array $state): array => ['ports_taken' => [33106, 54336]] + $state);
        $ours = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame(0, $ours['exit'], $ours['stderr']);
    }

    public function test_with_a_live_lease_a_port_that_the_services_do_not_publish_is_refused(): void
    {
        $supervisor = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_port', '--explicit', 'mysql');
        $before = count(self::fakeCalls($this->registry));

        $free = $this->testdb(['up'], $this->fakeEnv($this->registry, ['ORRERY_TEST_MYSQL_PORT' => '34001']));
        self::assertSame(71, $free['exit'], $free['stderr']);

        self::changeFakeState($this->registry, static fn (array $state): array => ['ports_taken' => [34001]] + $state);
        $taken = $this->testdb(['up'], $this->fakeEnv($this->registry, ['ORRERY_TEST_MYSQL_PORT' => '34001']));
        self::assertSame(69, $taken['exit'], $taken['stderr']);

        self::assertNotContains('up', self::operations(array_slice(self::fakeCalls($this->registry), $before)));
    }

    // create, drop and down --force

    public function test_create_and_drop_need_the_name_lock(): void
    {
        $supervisor = $this->spawnSleeper();
        self::assertSame(0, $this->testdb(['up'], $this->fakeEnv($this->registry))['exit'], 'bin/test-db up starts every default server');
        $this->admit('run_1_0000000a', $supervisor, 'orrery_named', '--explicit', 'mysql');
        $before = count(self::fakeCalls($this->registry));

        $other = $this->fakeEnv($this->registry, ['ORRERY_TEST_RUN_ID' => 'run_2_0000000b']);
        self::assertSame(73, $this->testdb(['create', 'orrery_named'], $other)['exit']);
        self::assertSame(73, $this->testdb(['drop', 'orrery_named'], $other)['exit']);

        $nobody = $this->fakeEnv($this->registry);
        $held = $this->testdb(['create', 'orrery_named'], $nobody);
        self::assertSame(73, $held['exit'], $held['stderr']);
        self::assertSame('run_1_0000000a', $held['json']['holder']['run_id']);
        self::assertSame(73, $this->runProcess([...$this->wrapper('bin/test-db'), 'drop', 'orrery_named'], $nobody)['exit']);

        $freeButNotHeld = $this->testdb(['create', 'orrery_free'], $this->fakeEnv($this->registry, ['ORRERY_TEST_RUN_ID' => 'run_1_0000000a']));
        self::assertSame(73, $freeButNotHeld['exit'], $freeButNotHeld['stderr']);

        self::assertSame([], array_values(array_intersect(
            self::operations(array_slice(self::fakeCalls($this->registry), $before)),
            ['create_databases', 'drop_databases'],
        )));

        $holder = $this->fakeEnv($this->registry, ['ORRERY_TEST_RUN_ID' => 'run_1_0000000a']);
        $create = $this->testdb(['create', 'orrery_named'], $holder);
        self::assertSame(0, $create['exit'], $create['stderr']);
        self::assertSame(['mysql'], $create['json']['services']);
        $drop = $this->testdb(['drop', 'orrery_named'], $holder);
        self::assertSame(0, $drop['exit'], $drop['stderr']);

        // A free name: the command holds it for its own duration.
        $free = $this->runProcess([...$this->wrapper('bin/test-db'), 'create', 'orrery_free'], $nobody);
        self::assertSame(0, $free['exit'], $free['stderr']);
        self::assertSame(['mysql', 'postgres', 'mariadb'], $free['json']['services']);
        self::assertContains('orrery_free_mirror', self::fakeState($this->registry)['services']['postgres']['databases']);
        self::assertSame(0, $this->runProcess([...$this->wrapper('bin/test-db'), 'drop', 'orrery_free'], $nobody)['exit']);
        self::assertNotContains('orrery_free', self::fakeState($this->registry)['services']['postgres']['databases']);
    }

    public function test_down_force_refuses_without_a_terminal(): void
    {
        $forced = $this->runProcess([PHP_BINARY, self::testdbPath(), 'down', '--force'], $this->fakeEnv($this->registry));
        self::assertSame(77, $forced['exit'], $forced['stderr']);
        self::assertStringContainsString('interactive terminal', $forced['stderr']);

        $wrapper = $this->runProcess([...$this->wrapper('bin/test-db'), 'down', '--force'], $this->fakeEnv($this->registry));
        self::assertSame(77, $wrapper['exit'], $wrapper['stderr']);

        self::assertSame([], self::fakeCalls($this->registry));

        // Without --force, down works (the refusal above is not the only outcome).
        self::assertSame(0, $this->testdb(['up'], $this->fakeEnv($this->registry))['exit']);
        $down = $this->runProcess([...$this->wrapper('bin/test-db'), 'down'], $this->fakeEnv($this->registry));
        self::assertSame(0, $down['exit'], $down['stderr']);
        self::assertContains('down', self::operations(self::fakeCalls($this->registry)));
        self::assertNull(self::registryData($this->registry)['token']);
    }

    // The startup gate

    public function test_a_child_behind_the_startup_gate_does_nothing_before_the_gate_opens(): void
    {
        $dir = $this->makeTempDir();
        $child = $this->startProcess(
            [PHP_BINARY, '-r', self::GATE_PROBE, '--', self::repoRoot().'/tests/bootstrap.php', $dir.'/ran'],
            $this->scriptEnv(['ORRERY_START_GATE' => $dir.'/open']),
        );

        $until = microtime(true) + 0.3;

        while (microtime(true) < $until) {
            self::assertFileDoesNotExist($dir.'/ran', 'the child ran before the startup gate opened');
            self::assertTrue($child->isRunning(), 'the child exited before the startup gate opened: '.$child->getErrorOutput());
            usleep(20_000);
        }

        touch($dir.'/open');
        $child->wait();

        self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        self::assertSame('gate-variable-removed', file_get_contents($dir.'/ran'));
    }

    public function test_the_startup_gate_exits_79_at_its_deadline(): void
    {
        $dir = $this->makeTempDir();
        $result = $this->runProcess(
            [PHP_BINARY, '-r', self::GATE_PROBE, '--', self::repoRoot().'/tests/bootstrap.php', $dir.'/ran'],
            $this->scriptEnv(['ORRERY_START_GATE' => $dir.'/never', 'ORRERY_START_GATE_TIMEOUT' => '0.3']),
            20.0,
        );

        self::assertSame(79, $result['exit'], $result['stderr']);
        self::assertStringContainsString('did not open within 0.3 seconds', $result['stderr']);
        self::assertFileDoesNotExist($dir.'/ran');

        $ungated = $this->runProcess(
            [PHP_BINARY, '-r', self::GATE_PROBE, '--', self::repoRoot().'/tests/bootstrap.php', $dir.'/ran'],
            $this->scriptEnv(),
            20.0,
        );
        self::assertSame(0, $ungated['exit'], $ungated['stderr']);
        self::assertFileExists($dir.'/ran');
    }

    // bin/test

    #[DataProvider('bashVersions')]
    public function test_bin_test_admits_registers_opens_the_gate_and_releases(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        $out = $this->makeTempDir();
        $result = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY, '--filter', 'SomeTest'], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_STATUS' => '1',
            'ORRERY_STUB_EXIT' => '3',
            'DB_URL' => 'mysql://intruder:secret@203.0.113.7:3306/production',
            'DATABASE_URL' => 'mysql://intruder:secret@203.0.113.7:3306/production',
            'DB_HOST' => '203.0.113.7',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'production',
            'DB_USERNAME' => 'intruder',
            'DB_PASSWORD' => 'secret',
            'DB_CONNECTION' => 'mysql',
            'ORRERY_TEST_CI' => '1',
            'ORRERY_TEST_RUN_ID' => 'run_9_99999999',
            'ORRERY_TEST_TOKEN' => str_repeat('0', 32),
            'ORRERY_TEST_SQLITE_FILE' => '/tmp/intruder.sqlite',
            'ORRERY_TEST_PASSWORD' => 'intruder',
        ]));

        self::assertSame(3, $result['exit'], 'bin/test must return the PHPUnit exit code: '.$result['stderr']);

        $records = self::stubRecords($out);
        self::assertCount(1, $records);
        $stub = self::readJson($records[0]);
        $env = $stub['env'];
        $runId = $env['ORRERY_TEST_RUN_ID'];

        self::assertMatchesRegularExpression('/^run_\d+_[0-9a-f]{8}$/', $runId);
        self::assertMatchesRegularExpression('/^orrery_run_[0-9a-f]{8}$/', $env['ORRERY_TEST_DATABASE']);
        self::assertSame('mysql', $env['ORRERY_TEST_DRIVER']);
        self::assertSame('orrery', $env['ORRERY_TEST_PASSWORD']);
        self::assertSame('33106', $env['ORRERY_TEST_MYSQL_PORT']);
        self::assertSame('54336', $env['ORRERY_TEST_PGSQL_PORT']);
        self::assertSame(self::fakeState($this->registry)['services']['mysql']['marker'], $env['ORRERY_TEST_TOKEN']);

        foreach (['DB_URL', 'DATABASE_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CONNECTION', 'ORRERY_TEST_CI', 'ORRERY_TEST_SQLITE_FILE', 'ORRERY_START_GATE'] as $removed) {
            self::assertArrayNotHasKey($removed, $env, "{$removed} reached PHPUnit");
        }

        self::assertIsString($stub['gate'], 'PHPUnit was not started behind the startup gate');
        // bin/test asks PHPUnit for an event log in the run's directory (see --timeout).
        self::assertSame(['--log-events-text', dirname($stub['gate']).'/events.txt', '--filter', 'SomeTest'], $stub['argv']);
        self::assertSame(self::repoRoot(), $stub['cwd']);

        // The lease is the run's owner record, below this test's record.
        self::assertStringEndsWith($this->ownerEntry()."\n{$runId} {$this->registry}", $env['ORRERY_OWNERS']);

        // The gate opened only after PHPUnit's process group joined the lease.
        $lease = $stub['status']['leases'][$runId];
        self::assertSame(
            [['pid' => $stub['ppid'], 'group' => true, 'run' => $runId]],
            array_map(static fn (array $child): array => ['pid' => $child['pid'], 'group' => $child['group'] ?? false, 'run' => $child['run'] ?? null], $lease['children']),
        );
        // The group's token is in PHPUnit's environment and in the entry.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $env['ORRERY_GROUP_TOKEN']);
        self::assertSame($env['ORRERY_GROUP_TOKEN'], $lease['children'][0]['token']);
        self::assertSame([dirname($stub['gate'])], $lease['paths']);
        self::assertFalse($lease['closing']);

        // Released: the generated databases are dropped and the directory is removed.
        self::assertSame([], self::registryData($this->registry)['leases']);
        $calls = self::fakeCalls($this->registry);
        $names = [$env['ORRERY_TEST_DATABASE'], $env['ORRERY_TEST_DATABASE'].'_mirror'];
        self::assertContains(['op' => 'create_databases', 'names' => $names], array_map(static fn (array $call): array => ['op' => $call['op'], 'names' => $call['args']['names'] ?? null], $calls));
        self::assertContains(['op' => 'drop_databases', 'names' => $names], array_map(static fn (array $call): array => ['op' => $call['op'], 'names' => $call['args']['names'] ?? null], $calls));
        self::assertDirectoryDoesNotExist(dirname($stub['gate']));
    }

    public function test_bin_test_replaces_a_held_generated_name_and_fails_fast_on_a_held_explicit_name(): void
    {
        $supervisor = $this->spawnSleeper();
        $this->admit('run_1_0000000c', $supervisor, 'orrery_run_aaaaaaaa', '--generated', 'mysql');
        $out = $this->makeTempDir();
        $env = ['ORRERY_TEST_PHPUNIT' => self::stubPath(), 'ORRERY_STUB_OUT' => $out];

        $generated = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env + [
            'ORRERY_TEST_GENERATED_NAMES' => 'orrery_run_aaaaaaaa orrery_run_bbbbbbbb',
        ]));
        self::assertSame(0, $generated['exit'], $generated['stderr']);
        self::assertCount(1, self::stubRecords($out));
        self::assertSame('orrery_run_bbbbbbbb', self::readJson(self::stubRecords($out)[0])['env']['ORRERY_TEST_DATABASE']);

        $explicit = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env + [
            'ORRERY_TEST_DATABASE' => 'orrery_run_aaaaaaaa',
        ]));
        self::assertSame(73, $explicit['exit'], $explicit['stderr']);
        self::assertStringContainsString('held by run run_1_0000000c', $explicit['stderr']);
        self::assertLessThan(10.0, $explicit['seconds']);
        self::assertCount(1, self::stubRecords($out), 'PHPUnit started although the admission failed');

        self::assertSame(['run_1_0000000c'], array_keys(self::registryData($this->registry)['leases']));
        self::assertContains('orrery_run_aaaaaaaa', self::fakeState($this->registry)['services']['mysql']['databases']);
        self::assertSame([], glob($this->scriptTmpDir.'/orrery-run.*') ?: [], 'the refused run left its directory behind');
    }

    #[DataProvider('bashVersions')]
    public function test_bin_test_stopped_before_registration_leaves_no_child_and_no_lease(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        [$wrapper, $child, $runId, $out] = $this->startWrapperPausedBeforeRegistration(onlyTheWrapperStops: true);

        $wrapper->signal(15);
        $wrapper->wait();

        self::assertSame(143, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertFalse(self::isAlive($child), 'the unregistered child outlived the cleanup of its supervisor');
        self::assertSame([], self::stubRecords($out), 'the unregistered child ran');
        self::assertSame([], self::registryData($this->registry)['leases']);
        self::assertContains('drop_databases', self::operations(self::fakeCalls($this->registry)), "the generated databases of {$runId} were not dropped");
    }

    #[DataProvider('bashVersions')]
    public function test_bin_test_killed_before_registration_leaves_no_child(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        [$wrapper, $child, $runId, $out] = $this->startWrapperPausedBeforeRegistration();

        $wrapper->signal(9);
        $wrapper->wait();

        $this->waitUntil(static fn (): bool => ! self::isAlive($child), 5.0, 'the unregistered child outlived its killed supervisor');
        self::assertSame([], self::stubRecords($out), 'the unregistered child ran');

        // No recorded process is alive, so the next command removes the lease.
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry));
        self::assertSame(0, $down['exit'], $down['stderr']);
        self::assertSame([$runId], $down['json']['pruned']);
    }

    #[DataProvider('bashVersions')]
    public function test_bin_test_kills_a_child_that_it_cannot_register_without_opening_the_gate(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        [$wrapper, $child, $runId, $out, $pause] = $this->startWrapperPausedBeforeRegistration(onlyTheWrapperStops: true);

        // The lease disappears before the join, so the join fails.
        $data = self::registryData($this->registry);
        unset($data['leases'][$runId]);
        self::writeJson($this->registry.'/registry.json', $data);
        touch($pause.'/resume');
        $wrapper->wait();

        self::assertSame(74, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertStringContainsString('without opening the startup gate', $wrapper->getErrorOutput());
        self::assertFalse(self::isAlive($child), 'the unregistered child is still running');
        self::assertSame([], self::stubRecords($out), 'the unregistered child ran');
    }

    #[DataProvider('bashVersions')]
    public function test_bin_test_stops_every_recorded_child_before_it_releases(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 20.0, 'the stub did not start: '.$wrapper->getErrorOutput());

        $stub = self::readJson(self::stubRecords($out)[0]);
        $runId = $stub['env']['ORRERY_TEST_RUN_ID'];

        // A worker that PHPUnit started and recorded in the lease.
        $worker = $this->spawnSleeper();
        $this->addChild($runId, $worker);

        $wrapper->signal(15);
        $wrapper->wait();

        self::assertSame(143, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        $this->waitUntil(static fn (): bool => ! self::isAlive($worker['pid']), 5.0, 'a recorded worker outlived the cleanup');
        self::assertFalse(self::isAlive($stub['pid']), 'PHPUnit outlived the cleanup');
        self::assertSame([], self::registryData($this->registry)['leases']);
    }

    public function test_stop_group_leaves_a_process_with_another_start_time_alone(): void
    {
        $bystander = $this->spawnSleeper();

        $mismatch = $this->testdb(['stop-group', (string) $bystander['pid'], 'Thu Jan 1 00:00:00 1970'], $this->scriptEnv());
        self::assertSame(0, $mismatch['exit'], $mismatch['stderr']);
        self::assertTrue($mismatch['json']['skipped']);
        usleep(100_000);
        self::assertTrue(self::isAlive($bystander['pid']), 'stop-group signalled a process with another start time');

        // With the right identity, the same command stops it.
        $match = $this->testdb(['stop-group', (string) $bystander['pid'], $bystander['start']], $this->scriptEnv());
        self::assertSame(0, $match['exit'], $match['stderr']);
        self::assertSame([$bystander['pid']], $match['json']['terminated']);
        $this->waitUntil(static fn (): bool => ! self::isAlive($bystander['pid']), 5.0, 'stop-group did not stop the matching process');
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function modesWithAChild(): iterable
    {
        return self::withBashes([
            'sqlite file, no registry' => ['sqlite', ['--sqlite-file'], []],
            'no-provision, no registry' => ['mysql', ['--no-provision'], ['ORRERY_TEST_DATABASE' => 'orrery_ci_1_2', 'ORRERY_TEST_TOKEN' => str_repeat('a', 32)]],
            'registry' => ['mysql', [], []],
        ]);
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, string>  $env
     */
    #[DataProvider('modesWithAChild')]
    public function test_an_interrupted_run_stops_every_descendant_before_it_removes_files(string $engine, array $options, array $env, string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), $engine, ...$options, '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env + [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_STUB_DESCENDANT' => '1',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 20.0, 'the stub did not start: '.$wrapper->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        $descendant = $stub['descendant'];
        self::assertTrue(self::isAlive($descendant), 'the descendant did not start');

        $wrapper->signal(15);
        $wrapper->wait();

        self::assertSame(143, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertFalse(self::isAlive($descendant), 'a descendant of PHPUnit outlived bin/test');
        self::assertFalse(self::isAlive($stub['pid']), 'PHPUnit outlived bin/test');
        $record = self::readJson($out.'/descendant-'.$descendant.'.json');

        if ($options === ['--sqlite-file']) {
            self::assertTrue($record['sqlite_dir_existed'], 'bin/test removed the SQLite files before it stopped the descendant');
            self::assertDirectoryDoesNotExist(dirname($stub['env']['ORRERY_TEST_SQLITE_FILE']));
        }

        self::assertDirectoryDoesNotExist($stub['run_dir']);
    }

    #[DataProvider('bashVersions')]
    public function test_a_descendant_that_outlives_phpunit_is_stopped_before_the_files_are_removed(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // PHPUnit ends normally but leaves a worker-like descendant behind.
        $out = $this->makeTempDir();
        $result = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_DESCENDANT' => '1',
        ]));

        self::assertSame(0, $result['exit'], $result['stderr']);
        $stub = self::readJson(self::stubRecords($out)[0]);
        self::assertFalse(self::isAlive($stub['descendant']), 'the descendant outlived bin/test');
        self::assertTrue(self::readJson($out.'/descendant-'.$stub['descendant'].'.json')['sqlite_dir_existed']);
        self::assertDirectoryDoesNotExist(dirname($stub['env']['ORRERY_TEST_SQLITE_FILE']));
    }

    #[DataProvider('bashVersions')]
    public function test_the_anchor_is_stopped_last_so_it_proves_the_whole_group(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // PHPUnit's descendants, /bin/bash and a sleep, ignore SIGTERM, and
        // macOS hides their environment. The anchor (PHPUnit's parent, the
        // group's leader) must outlive them: while it exists, its PID and
        // start prove the group, so the stop needs no environment.
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_TEST_GRACE' => '2',
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_STUB_DESCENDANT' => 'bash',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 20.0, 'the stub did not start: '.$wrapper->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        $anchor = $stub['ppid'];
        $descendants = $stub['bash_descendant'];
        self::assertCount(2, $descendants);

        foreach ([$anchor, ...$descendants] as $pid) {
            self::assertTrue(self::isAlive($pid), "process {$pid} did not start");
        }

        $wrapper->signal(15);
        $early = [];

        while ($wrapper->isRunning()) {
            // isRunning() never checks the timeout of startProcess().
            $wrapper->checkTimeout();
            $living = array_values(array_filter($descendants, static fn (int $pid): bool => self::isAlive($pid)));

            if ($living !== [] && ! self::isAlive($anchor)) {
                $early = $living;
            }

            usleep(20_000);
        }

        self::assertSame(143, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertSame([], $early, 'the anchor ended while members of its group still lived');

        foreach ([$anchor, $stub['pid'], ...$descendants] as $pid) {
            self::assertFalse(self::isAlive($pid), "process {$pid} survived bin/test");
        }

        self::assertDirectoryDoesNotExist($stub['run_dir']);
    }

    #[DataProvider('bashVersions')]
    public function test_members_that_the_stop_proved_stay_owned_after_the_anchor_dies(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // PHPUnit ignores SIGTERM, so the shutdown runs for its whole grace
        // period. After its first round, the anchor is killed from outside.
        // PHPUnit is then in a group without its leader. The stop proved it
        // while the anchor lived, so it stays owned and gets SIGKILL. (On
        // Linux its token proves it as well. On macOS nothing else does.)
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_TEST_GRACE' => '2',
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_STUB_IGNORE_TERM' => '1',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 20.0, 'the stub did not start: '.$wrapper->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        $record = self::ownRecord($stub);
        $anchor = self::identityOf($stub['ppid']);

        $wrapper->signal(15);
        $this->waitUntil(static fn (): bool => (self::registryData($record['dir'])['leases'][$record['run']]['closing'] ?? false) === true, 10.0, 'the shutdown did not close the record');
        usleep(400_000);
        self::assertTrue(self::isAlive($stub['pid']), 'PHPUnit did not ignore SIGTERM');
        self::assertTrue(self::signalIdentity($anchor, SIGKILL), 'the anchor was gone before the test killed it');
        $wrapper->wait();

        self::assertSame(143, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertFalse(self::isAlive($stub['pid']), 'PHPUnit survived the shutdown after its anchor died');
        self::assertDirectoryDoesNotExist($stub['run_dir']);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function unusableStatuses(): iterable
    {
        return self::withBashes([
            'an empty status file' => ['empty', false],
            'a status file without an exit status' => ['invalid', false],
            'a status that the anchor cannot write' => ['unwritable', false],
            'a status that the anchor cannot write, with descendants left' => ['unwritable', true],
        ]);
    }

    #[DataProvider('unusableStatuses')]
    public function test_a_status_that_cannot_be_read_ends_the_run_with_74_in_bounded_time(string $kind, bool $descendants, string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // The anchor reports PHPUnit's exit status through $run_dir/status.
        // - "empty" and "invalid": the test puts a status file without an
        //   exit status there while PHPUnit runs. bin/test must not wait for
        //   the anchor, which waits for bin/test.
        // - "unwritable": a directory at the anchor's temporary path makes its
        //   write fail after PHPUnit ends. The anchor ends, and bin/test sees
        //   it gone without a status.
        // Either way, bin/test ends through its cleanup with 74. With
        // descendants that ignore SIGTERM and outlive the anchor, macOS cannot
        // prove them, so the cleanup keeps the directory (Linux proves them by
        // their token).
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_TEST_GRACE' => '0.5',
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
        ] + ($descendants ? ['ORRERY_STUB_DESCENDANT' => 'bash'] : [])));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 20.0, 'the stub did not start: '.$wrapper->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        $anchor = self::identityOf($stub['ppid']);
        $left = array_map(static fn (int $pid): array => self::identityOf($pid), $descendants ? $stub['bash_descendant'] : []);

        try {
            match ($kind) {
                'empty' => file_put_contents($stub['run_dir'].'/status', ''),
                'invalid' => file_put_contents($stub['run_dir'].'/status', "not an exit status\n"),
                'unwritable' => mkdir($stub['run_dir'].'/status.tmp'),
            };

            if ($kind === 'unwritable') {
                touch($hold.'/release');
            }

            $deadline = microtime(true) + 30;

            while ($wrapper->isRunning() && microtime(true) < $deadline) {
                usleep(50_000);
            }

            self::assertFalse($wrapper->isRunning(), 'bin/test did not end within 30 s: it waits for a status that cannot come');
            self::assertSame(74, $wrapper->getExitCode(), $wrapper->getErrorOutput());
            self::assertStringContainsString($kind === 'unwritable' ? 'ended (exit 74) without PHPUnit\'s exit status' : 'holds no exit status of PHPUnit', $wrapper->getErrorOutput());
            self::assertFalse(self::isAlive($stub['pid']), 'PHPUnit outlived bin/test');
            self::assertFalse(self::isAlive($anchor['pid']), 'the anchor outlived bin/test');

            if ($left === [] || PHP_OS_FAMILY === 'Linux') {
                foreach ($left as $process) {
                    self::assertFalse(self::isAlive($process['pid']), "descendant {$process['pid']} outlived bin/test");
                }

                self::assertDirectoryDoesNotExist($stub['run_dir']);

                return;
            }

            // macOS: the descendants are unverified, so everything stays.
            self::assertStringContainsString('kept the owner record', $wrapper->getErrorOutput());
            self::assertDirectoryExists($stub['run_dir']);

            foreach ($left as $process) {
                self::assertTrue(self::isAlive($process['pid']), "descendant {$process['pid']} was signalled without proof");
                self::signalIdentity($process, SIGKILL);
            }

            foreach ($left as $process) {
                $this->waitUntil(static fn (): bool => ! self::isAlive($process['pid']), 5.0, "descendant {$process['pid']} did not end");
            }

            // The manual recovery of the handoff: the processes are gone, so the shutdown completes.
            $recover = $this->testdb(['shutdown', '--owner='.self::ownRecord($stub)['entry'], '--grace=1'], $this->scriptEnv());
            self::assertSame(0, $recover['exit'], $recover['stderr']);
            self::assertDirectoryDoesNotExist($stub['run_dir']);
        } finally {
            foreach ([$anchor, ...$left] as $process) {
                self::signalIdentity($process, SIGKILL);
            }
        }
    }

    /**
     * Rejects: a cleanup that waits for its interrupted poll helper with the wait builtin, or
     * without a bound. Here the helper (a sleep of the status poll) ignores SIGTERM and lives
     * 30 s, so such a cleanup ends after about 30 s; once, the wait builtin blocked for good on
     * the anchor. The cleanup must poll it for at most the grace (0.5 s), then stop PHPUnit and
     * remove the run's directory.
     */
    #[DataProvider('bashVersions')]
    public function test_an_interrupted_poll_wait_never_holds_the_cleanup_past_the_grace(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $mode = $this->base.'/sleep-mode';
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'PATH' => $this->fakeSleepDir().':'.getenv('PATH'),
            'ORRERY_FAKE_SLEEP_FILE' => $mode,
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_TEST_GRACE' => '0.5',
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
        ]));
        $helper = null;

        try {
            $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 20.0, 'the stub did not start: '.$wrapper->getErrorOutput());
            $stub = self::readJson(self::stubRecords($out)[0]);
            // The next poll of the status wait takes the mode.
            touch($mode);
            $this->waitUntil(static fn (): bool => file_exists($mode.'.pid'), 10.0, 'the status wait did not start the held sleep');
            $helper = self::identityOf((int) trim((string) file_get_contents($mode.'.pid')));

            $signalled = microtime(true);
            $wrapper->signal(15);
            $this->waitUntil(static fn (): bool => ! $wrapper->isRunning(), 10.0, 'the cleanup waited for its held poll helper');

            self::assertLessThan(10.0, microtime(true) - $signalled);
            self::assertSame(143, $wrapper->getExitCode(), $wrapper->getErrorOutput());
            self::assertTrue(self::isAlive($helper['pid']), 'the held sleep ended, so this control proves nothing');
            self::assertFalse(self::isAlive($stub['pid']), 'PHPUnit outlived bin/test');
            self::assertDirectoryDoesNotExist($stub['run_dir']);
        } finally {
            if ($helper !== null) {
                self::signalIdentity($helper, SIGKILL);
            }
        }
    }

    #[DataProvider('bashVersions')]
    public function test_a_supervisor_killed_while_it_opens_the_alive_pipe_leaves_nothing_waiting(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // bin/test is held right after it opened both ends of the alive pipe
        // and before it starts the anchor. Only the root supervisor gets
        // SIGKILL, and no owner record above it can stop what it left.
        $out = $this->makeTempDir();
        $barrier = $this->makeTempDir();
        $env = $this->fakeEnv($this->registry, [
            'ORRERY_OWNERS' => false,
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_TEST_SPAWN_BARRIER' => $barrier,
            'ORRERY_TEST_SPAWN_BARRIER_AT' => 'pipes',
        ]);
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $env);
        $this->waitUntil(static fn (): bool => file_exists($barrier.'/pipes.reached'), 20.0, 'bin/test did not reach the barrier: '.$wrapper->getErrorOutput());
        $entry = trim((string) file_get_contents($barrier.'/pipes.reached'));
        [, $runDir] = explode(' ', $entry, 2);
        $supervisor = (int) $wrapper->getPid();

        // Every process that the supervisor has started so far.
        $children = [];

        foreach (explode("\n", trim((string) shell_exec('ps -A -o pid=,ppid='))) as $line) {
            [$pid, $ppid] = array_map('intval', preg_split('/\s+/', trim($line)) ?: []);
            $start = $ppid === $supervisor ? self::startOf($pid) : null;

            if ($start !== null) {
                $children[] = ['pid' => $pid, 'start' => $start];
            }
        }

        try {
            $wrapper->signal(SIGKILL);
            $wrapper->wait();

            foreach ($children as $child) {
                $this->waitUntil(static fn (): bool => self::startOf($child['pid']) !== $child['start'], 3.0, "process {$child['pid']} that the killed supervisor started still waits");
            }

            self::assertSame([], self::stubRecords($out), 'PHPUnit started');

            // Recovery of the run's own record: nothing is left, so it completes.
            $recover = $this->testdb(['shutdown', '--owner='.$entry, '--grace=1'], $this->scriptEnv(['ORRERY_OWNERS' => false]));
            self::assertSame(0, $recover['exit'], $recover['stderr']);
            self::assertDirectoryDoesNotExist($runDir);
        } finally {
            foreach ($children as $child) {
                self::signalIdentity($child, SIGKILL);
            }
        }
    }

    #[DataProvider('bashVersions')]
    public function test_a_failed_group_stop_keeps_the_lease_until_the_group_is_gone(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // PHPUnit ends and leaves a descendant. Only the full process listing
        // fails, so single-PID checks still see PHPUnit as gone.
        $out = $this->makeTempDir();
        $fakePs = $this->fakePsDir();
        $mode = $this->base.'/ps-mode';
        $log = $this->base.'/ps-log';
        file_put_contents($mode, 'list-fails');
        $path = ['PATH' => $fakePs.':'.getenv('PATH'), 'ORRERY_FAKE_PS_FILE' => $mode, 'ORRERY_FAKE_PS_LOG' => $log];

        // The anchor stays until a SIGTERM (ORRERY_TEST_ANCHOR_STAYS), so the
        // stop below must signal it. Without the hook, the anchor ends by
        // itself once its group is empty, and on a loaded machine it can do
        // that between two listings of the stop.
        $run = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $path + [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_DESCENDANT' => '1',
            'ORRERY_TEST_ANCHOR_STAYS' => '1',
        ]));

        self::assertSame(74, $run['exit'], $run['stderr']);
        self::assertStringContainsString('kept the owner record of run_', $run['stderr']);
        $stub = self::readJson(self::stubRecords($out)[0]);
        $runId = $stub['env']['ORRERY_TEST_RUN_ID'];
        $base = $stub['env']['ORRERY_TEST_DATABASE'];
        self::assertTrue(self::isAlive($stub['descendant']));
        // The hook (ORRERY_TEST_ANCHOR_STAYS) holds the anchor here, so this
        // does not test anchor-linger's own wait for its group:
        // test_a_killed_supervisor_keeps_the_lease_while_its_process_group_lives
        // does, without the hook.
        self::assertTrue(self::isAlive($stub['ppid']), 'the anchor ended although its group still has a live process');

        // The lease, both names, the directory and both databases stay.
        $lease = self::registryData($this->registry)['leases'][$runId] ?? null;
        self::assertNotNull($lease, 'the lease was released although the stop failed');
        self::assertSame([$base, $base.'_mirror'], $lease['names']);
        self::assertTrue($lease['closing']);
        self::assertContains(['pid' => $stub['ppid'], 'start' => $lease['children'][0]['start'], 'group' => true, 'run' => $runId, 'token' => $stub['env']['ORRERY_GROUP_TOKEN']], $lease['children']);
        self::assertNotContains('drop_databases', self::operations(self::fakeCalls($this->registry)));
        self::assertDirectoryExists($stub['run_dir']);

        // After the failed stop, bin/test tried no release: the only full
        // listings are the ones of the failed stop and of the stop of its own
        // group.
        $listings = array_filter(file($log, FILE_IGNORE_NEW_LINES) ?: [], static fn (string $line): bool => preg_match('/(^| )-A( |$)/', $line) === 1);
        self::assertCount(2, $listings, implode("\n", $listings));

        // A later prune frees nothing while the listing fails ...
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry, $path));
        self::assertSame(74, $down['exit'], $down['stderr']);

        // ... or while any process of the group lives.
        unlink($mode);
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry, $path));
        self::assertSame(75, $down['exit'], $down['stderr']);
        $release = $this->testdb(['release', $runId], $this->fakeEnv($this->registry, $path));
        self::assertSame(75, $release['exit'], $release['stderr']);
        self::assertArrayHasKey($runId, self::registryData($this->registry)['leases']);

        // With the listing back, the recorded group is stopped, the anchor
        // last, and the run released.
        // The grace leaves a loaded machine time to end the descendant after
        // its SIGTERM; the stop returns as soon as the group is gone.
        $stop = $this->testdb(['stop-children', $runId, '--grace=10'], $this->fakeEnv($this->registry, $path));
        self::assertSame(0, $stop['exit'], $stop['stderr']);
        self::assertSame([$stub['descendant'], $stub['ppid']], $stop['json']['terminated'], 'the anchor was not stopped last: '.json_encode($stop['json']));
        self::assertSame([], $stop['json']['killed'], json_encode($stop['json']));
        self::assertFalse(self::isAlive($stub['descendant']));
        self::assertFalse(self::isAlive($stub['ppid']));
        $release = $this->testdb(['release', $runId], $this->fakeEnv($this->registry, $path));
        self::assertSame(0, $release['exit'], $release['stderr']);
        self::assertSame(['mysql' => [$base, $base.'_mirror']], $release['json']['dropped']);
        self::assertSame([$stub['run_dir']], $release['json']['removed']);
        self::assertArrayNotHasKey($runId, self::registryData($this->registry)['leases']);
        self::assertDirectoryDoesNotExist($stub['run_dir']);
    }

    #[DataProvider('bashVersions')]
    public function test_a_failed_stop_keeps_the_lease_while_a_nested_group_lives(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // An outer server run starts a nested --no-provision run, which uses
        // the outer run's databases. The nested supervisor dies, and the outer
        // PHPUnit ends. Then the stop of the lease cannot inspect processes.
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $innerOut = $this->makeTempDir();
        $innerHold = $this->makeTempDir();
        $fakePs = $this->fakePsDir();
        $mode = $this->base.'/ps-mode';
        $path = ['PATH' => $fakePs.':'.getenv('PATH'), 'ORRERY_FAKE_PS_FILE' => $mode];

        $outer = $this->startProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $path + [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_STUB_NESTED' => 'mysql --no-provision',
            'ORRERY_STUB_INNER_OUT' => $innerOut,
            'ORRERY_STUB_INNER_HOLD' => $innerHold,
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached') && file_exists($innerHold.'/reached'), 20.0, 'the nested run did not start: '.$outer->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        $inner = self::readJson(self::stubRecords($innerOut)[0]);
        $runId = $stub['env']['ORRERY_TEST_RUN_ID'];
        $base = $stub['env']['ORRERY_TEST_DATABASE'];
        self::assertSame($base, $inner['env']['ORRERY_TEST_DATABASE'], 'the nested run does not use the outer databases');
        $this->assertRunsWithSelectedBash($stub['nested']);
        $nested = self::identityOf($stub['nested']);

        self::signalIdentity($nested, SIGKILL);
        $this->waitUntil(static fn (): bool => ! self::isAlive($stub['nested']), 5.0, 'the nested supervisor did not die');
        file_put_contents($mode, 'list-fails');
        touch($hold.'/release');
        $outer->wait();

        self::assertSame(74, $outer->getExitCode(), $outer->getErrorOutput());
        self::assertStringContainsString("kept the owner record of {$runId}", $outer->getErrorOutput());
        self::assertFalse(self::isAlive($stub['pid']), 'the primary group did not end');
        self::assertTrue(self::isAlive($inner['pid']));

        // The lease lists the nested group too, so it stays with its names,
        // its directory and its databases.
        $lease = self::registryData($this->registry)['leases'][$runId] ?? null;
        self::assertNotNull($lease, 'the lease was released although the stop failed');
        $entries = array_map(static fn (array $child): array => ['pid' => $child['pid'], 'group' => $child['group'] ?? false, 'run' => $child['run'] ?? null], $lease['children']);
        self::assertContains(['pid' => $inner['ppid'], 'group' => true, 'run' => self::ownRecord($inner)['run']], $entries, 'the lease does not list the nested group');
        self::assertNotContains('drop_databases', self::operations(self::fakeCalls($this->registry)));
        self::assertDirectoryExists($stub['run_dir']);

        // While the listing fails, nothing is freed.
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry, $path));
        self::assertSame(74, $down['exit'], $down['stderr']);

        // With the listing back, the nested group keeps the lease live.
        unlink($mode);
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry, $path));
        self::assertSame(75, $down['exit'], 'the lease was freed while its nested group still ran: '.$down['stderr']);
        $release = $this->testdb(['release', $runId], $this->fakeEnv($this->registry, $path));
        self::assertSame(75, $release['exit'], $release['stderr']);
        self::assertContains($inner['ppid'], $release['json']['children'], 'the refused release does not name the nested group');
        self::assertNotContains('drop_databases', self::operations(self::fakeCalls($this->registry)));

        $stop = $this->testdb(['stop-children', $runId, '--grace=2'], $this->fakeEnv($this->registry, $path));
        self::assertSame(0, $stop['exit'], $stop['stderr']);
        self::assertFalse(self::isAlive($inner['pid']));
        $release = $this->testdb(['release', $runId], $this->fakeEnv($this->registry, $path));
        self::assertSame(0, $release['exit'], $release['stderr']);
        self::assertSame(['mysql' => [$base, $base.'_mirror']], $release['json']['dropped']);
        self::assertDirectoryDoesNotExist($stub['run_dir']);
    }

    #[DataProvider('bashVersions')]
    public function test_a_killed_supervisor_keeps_the_lease_while_its_process_group_lives(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // The supervisor dies without a cleanup, PHPUnit ends, and only an
        // unregistered descendant of PHPUnit is left.
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_STUB_DESCENDANT' => '1',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 20.0, 'the stub did not start: '.$wrapper->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        $runId = $stub['env']['ORRERY_TEST_RUN_ID'];
        $descendant = self::identityOf($stub['descendant']);

        $wrapper->signal(9);
        $wrapper->wait();
        touch($hold.'/release');
        $this->waitUntil(static fn (): bool => ! self::isAlive($stub['pid']), 10.0, 'the stub did not end');
        self::assertTrue(self::isAlive($stub['descendant']));

        // The lease stays live through the process group that it recorded.
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry));
        self::assertSame(75, $down['exit'], 'the lease was freed while a process of its group still ran: '.$down['stderr']);
        self::assertTrue($this->testdb(['status', '--no-services'], $this->fakeEnv($this->registry))['json']['leases'][$runId]['live']);
        // Without the test hook, the anchor (anchor-linger) stays while a
        // member of its group lives. Rejects: an anchor that ends when its
        // supervisor and PHPUnit are gone, whatever its group holds.
        self::assertTrue(self::isAlive($stub['ppid']), 'the anchor ended while its group lived');

        // Once the group is gone, the lease is stale. The anchor ends by
        // itself after PHPUnit, because its supervisor is gone.
        self::signalIdentity($descendant, SIGTERM);
        $this->waitUntil(static fn (): bool => ! self::isAlive($stub['descendant']), 5.0, 'the descendant did not end');
        $this->waitUntil(static fn (): bool => ! self::isAlive($stub['ppid']), 5.0, 'the anchor outlived its killed supervisor and PHPUnit');
        $down = $this->testdb(['down', '--dry-run'], $this->fakeEnv($this->registry));
        self::assertSame(0, $down['exit'], $down['stderr']);
        self::assertSame([$runId], $down['json']['pruned']);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function childStartPoints(): iterable
    {
        $modes = [
            'sqlite file' => ['sqlite', ['--sqlite-file'], []],
            'no-provision' => ['mysql', ['--no-provision'], ['ORRERY_TEST_DATABASE' => 'orrery_ci_1_2', 'ORRERY_TEST_TOKEN' => str_repeat('a', 32)]],
            'registry' => ['mysql', [], []],
        ];
        $cases = [];

        foreach ($modes as $mode => $case) {
            $cases["{$mode}, before its join"] = [...$case, 'join'];
            $cases["{$mode}, right after the start"] = [...$case, 'spawned'];
        }

        return self::withBashes($cases);
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, string>  $env
     */
    #[DataProvider('childStartPoints')]
    public function test_a_signal_while_the_child_starts_never_leaves_it_running(string $engine, array $options, array $env, string $point, string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // The child is descendant.php: once it runs, it records whether the
        // SQLite directory still existed when its SIGTERM arrived.
        $out = $this->makeTempDir();
        $barrier = $this->makeTempDir();
        $hold = $point === 'join'
            ? ['ORRERY_TEST_PAUSE_BEFORE_REGISTER' => $barrier, 'ORRERY_TEST_GATE_IGNORE_PARENT' => '1']
            : ['ORRERY_TEST_SPAWN_BARRIER' => $barrier, 'ORRERY_TEST_SPAWN_BARRIER_AT' => 'spawned'];
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), $engine, ...$options, '--php='.PHP_BINARY, '--', $out], $this->fakeEnv($this->registry, $env + $hold + [
            'ORRERY_TEST_PHPUNIT' => self::descendantPath(),
        ]));
        $reached = $point === 'join' ? $barrier.'/paused' : $barrier.'/spawned.reached';
        $this->waitUntil(static fn (): bool => file_exists($reached), 20.0, 'bin/test did not reach the barrier: '.$wrapper->getErrorOutput());
        // The child is the anchor of the group, and descendant.php runs as its child.
        $child = (int) trim((string) file_get_contents($reached));
        $payload = static fn (string $suffix): array => glob("{$out}/descendant-*.{$suffix}") ?: [];

        if ($point === 'spawned') {
            // The child joins on its own while bin/test waits at the barrier.
            $this->waitUntil(static fn (): bool => $payload('ready') !== [], 10.0, 'the child did not start');
        }

        $wrapper->signal(15);
        $wrapper->wait();

        self::assertSame(143, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertFalse(self::isAlive($child), 'the child outlived bin/test');

        if ($point === 'join') {
            usleep(200_000);
            self::assertSame([], $payload('ready'), 'the child ran although its join never completed');
        } elseif ($options === ['--sqlite-file']) {
            self::assertCount(1, $payload('json'), 'the child did not get SIGTERM');
            self::assertTrue(self::readJson($payload('json')[0])['sqlite_dir_existed'], 'bin/test removed the SQLite files before it stopped the child');
        }

        self::assertSame([], glob($this->scriptTmpDir.'/orrery-run.*') ?: [], 'bin/test left its directory behind');

        if ($options === []) {
            self::assertSame([], self::registryData($this->registry)['leases']);
        }
    }

    #[DataProvider('bashVersions')]
    public function test_a_signal_during_the_join_wait_ends_the_join_helper_before_the_record_is_removed(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // bin/test waits for the join through a testdb.php helper that reads
        // the owner store. The child is held before its join, so the helper
        // still polls when SIGTERM arrives. The shutdown is held before it
        // removes the owner store: no helper may read or lock it then.
        $out = $this->makeTempDir();
        $barrier = $this->makeTempDir();
        $removal = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY, '--', $out], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::descendantPath(),
            'ORRERY_TEST_PAUSE_BEFORE_REGISTER' => $barrier,
            'ORRERY_TEST_GATE_IGNORE_PARENT' => '1',
            'ORRERY_TEST_REMOVAL_HOLD' => $removal,
            'ORRERY_TEST_REMOVAL_HOLD_AT' => 'store',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($barrier.'/paused'), 20.0, 'bin/test did not reach the join: '.$wrapper->getErrorOutput());
        $child = (int) trim((string) file_get_contents($barrier.'/paused'));
        $runDirs = glob($this->scriptTmpDir.'/orrery-run.*') ?: [];
        self::assertCount(1, $runDirs);
        $runDir = $runDirs[0];
        $helpers = static function () use ($runDir): array {
            $list = new Process(['ps', '-axo', 'pid=,command=']);
            $list->mustRun();

            return array_values(array_filter(
                explode("\n", $list->getOutput()),
                static fn (string $line): bool => str_contains($line, ' owner-wait ') && str_contains($line, $runDir),
            ));
        };
        $this->waitUntil(static fn (): bool => $helpers() !== [], 10.0, 'bin/test did not start its join helper');

        $wrapper->signal(15);
        $this->waitUntil(static fn (): bool => file_exists($removal.'/store.reached'), 20.0, 'the shutdown did not reach the removal of the owner store: '.$wrapper->getErrorOutput());
        self::assertSame($runDir, trim((string) file_get_contents($removal.'/store.reached')));
        $left = $helpers();
        touch($removal.'/store.release');
        $wrapper->wait();

        self::assertSame([], $left, 'the join helper still ran when the shutdown removed the owner store');
        self::assertSame(143, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertFalse(self::isAlive($child), 'the child outlived bin/test');
        self::assertDirectoryDoesNotExist($runDir);
    }

    public function test_a_late_reader_of_an_owner_store_cannot_stop_its_removal(): void
    {
        // A helper that did not see the close (a late owner-join or
        // owner-wait) locks the store by its path. The shutdown is held after
        // it emptied the store and before its rmdir, and a late reader runs
        // then. It must not recreate anything in the removed tree.
        $store = $this->makeTempDir();
        $removal = $this->makeTempDir();
        $owner = $this->ownerInit($store, 'run_1_0000000d', ['pid' => getmypid(), 'start' => 'auto']);
        $env = $this->fakeEnv($this->registry, ['ORRERY_TEST_REMOVAL_HOLD' => $removal, 'ORRERY_TEST_REMOVAL_HOLD_AT' => 'rmdir']);

        $shutdown = $this->startTestdb(['shutdown', '--owner='.$owner, '--grace=1'], $env);
        $this->waitUntil(static fn (): bool => file_exists($removal.'/rmdir.reached'), 20.0, 'the shutdown did not reach the rmdir: '.$shutdown->getErrorOutput());
        $late = $this->testdb(['owner-wait', '--owner='.$owner, (string) getmypid(), '--timeout=5'], $this->scriptEnv());
        touch($removal.'/rmdir.release');
        $shutdown->wait();

        self::assertNotSame(0, $late['exit'], 'the late reader found a record: '.$late['stdout']);
        self::assertSame(0, $shutdown->getExitCode(), $shutdown->getErrorOutput());
        self::assertDirectoryDoesNotExist($store);
        self::assertSame([], glob($store.'*') ?: [], 'the removal left a directory next to the store');
    }

    #[DataProvider('bashVersions')]
    public function test_a_child_that_ends_before_bin_test_sees_its_join_keeps_its_exit_status(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        $out = $this->makeTempDir();
        $barrier = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_EXIT' => '5',
            'ORRERY_TEST_SPAWN_BARRIER' => $barrier,
            'ORRERY_TEST_SPAWN_BARRIER_AT' => 'spawned',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($barrier.'/spawned.reached'), 20.0, 'bin/test did not reach the barrier: '.$wrapper->getErrorOutput());
        // PHPUnit (the stub) ends at once. Its anchor stays until bin/test is gone.
        $this->waitUntil(static fn (): bool => self::stubRecords($out) !== [] && ! self::isAlive(self::readJson(self::stubRecords($out)[0])['pid']), 10.0, 'the short child did not end');

        touch($barrier.'/spawned.release');
        $wrapper->wait();

        self::assertSame(5, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertStringNotContainsString('could not', $wrapper->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        self::assertDirectoryDoesNotExist(dirname($stub['env']['ORRERY_TEST_SQLITE_FILE']));
        self::assertDirectoryDoesNotExist($stub['run_dir']);
    }

    #[DataProvider('bashVersions')]
    public function test_a_child_whose_identity_cannot_be_read_never_starts(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // The single-process check fails from the moment the child joins.
        $out = $this->makeTempDir();
        $pause = $this->makeTempDir();
        $fakePs = $this->fakePsDir();
        $mode = $this->base.'/ps-mode';
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY, '--', $out], $this->fakeEnv($this->registry, [
            'PATH' => $fakePs.':'.getenv('PATH'),
            'ORRERY_FAKE_PS_FILE' => $mode,
            'ORRERY_TEST_PHPUNIT' => self::descendantPath(),
            'ORRERY_TEST_PAUSE_BEFORE_REGISTER' => $pause,
            'ORRERY_TEST_GATE_IGNORE_PARENT' => '1',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($pause.'/paused'), 20.0, 'bin/test did not pause: '.$wrapper->getErrorOutput());
        $child = (int) trim((string) file_get_contents($pause.'/paused'));
        file_put_contents($mode, 'pid-fails');
        touch($pause.'/resume');
        $wrapper->wait();

        self::assertSame(74, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertStringContainsString('could not confirm that PHPUnit', $wrapper->getErrorOutput());
        self::assertFalse(self::isAlive($child));
        self::assertSame([], glob($out.'/descendant-*') ?: [], 'the child ran although its identity could not be read');
        self::assertSame([], glob($this->scriptTmpDir.'/orrery-run.*') ?: [], 'bin/test left its directory behind');
    }

    #[DataProvider('bashVersions')]
    public function test_teardown_lets_each_run_finish_its_cleanup_and_stops_orphaned_groups(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        [$sibling, $siblingPid, $unmanaged] = $this->startUnrelatedSibling();

        try {
            // Run A is alive at teardown. Run B's supervisor is killed first,
            // so only this test's owner record lists its PHPUnit group.
            $runs = [];

            foreach (['a', 'b'] as $name) {
                $out = $this->makeTempDir();
                $hold = $this->makeTempDir();
                $runs[$name] = ['out' => $out, 'hold' => $hold, 'wrapper' => $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
                    'ORRERY_TEST_PHPUNIT' => self::stubPath(),
                    'ORRERY_STUB_OUT' => $out,
                    'ORRERY_STUB_HOLD' => $hold,
                    'ORRERY_STUB_IGNORE_TERM' => '1',
                    'ORRERY_STUB_WATCH' => $out,
                    'ORRERY_STUB_WATCH_LOG' => $unmanaged.'/watch.log',
                    'ORRERY_TEST_GRACE' => '0.5',
                ]))];
            }

            foreach ($runs as $name => $run) {
                $this->waitUntil(static fn (): bool => file_exists($run['hold'].'/reached'), 20.0, "run {$name} did not start");
                $record = self::readJson(self::stubRecords($run['out'])[0]);
                $runs[$name]['stub'] = $record['pid'];
                $runs[$name]['run_dir'] = $record['run_dir'];
            }

            $runs['b']['wrapper']->signal(9);
            $runs['b']['wrapper']->wait();

            $this->cleanUpScripts();

            self::assertSame(143, $runs['a']['wrapper']->getExitCode(), 'teardown killed run A before it finished its own cleanup');
            self::assertFalse(self::isAlive($runs['a']['stub']), 'the PHPUnit of run A survived teardown');
            self::assertFalse(self::isAlive($runs['b']['stub']), 'the PHPUnit of run B (supervisor killed) survived teardown');
            self::assertDirectoryDoesNotExist($runs['a']['out']);
            self::assertDirectoryDoesNotExist($runs['b']['run_dir'], 'the directory of run B (supervisor killed) remained');
            self::assertFileDoesNotExist($unmanaged.'/watch.log', 'teardown deleted a directory while a process of the test still ran');
            self::assertTrue(self::isAlive($siblingPid), 'teardown stopped an unrelated process');
        } finally {
            self::stopUnrelatedSibling($sibling, $unmanaged);
        }
    }

    #[DataProvider('bashVersions')]
    public function test_a_chain_of_live_nested_runs_stops_from_the_inside_out(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        [$sibling, $siblingPid, $unmanaged] = $this->startUnrelatedSibling();

        try {
            // Four levels of bin/test sqlite --sqlite-file. Each PHPUnit stub
            // but the last starts the next level, and on SIGTERM it waits for
            // that level to end. Each stub below the first watches the SQLite
            // directory of the level above it.
            $levels = 4;
            $outs = [];
            $holds = [];
            $env = ['ORRERY_TEST_PHPUNIT' => self::stubPath()];

            for ($level = 1; $level <= $levels; $level++) {
                $outs[$level] = $this->makeTempDir();
                $holds[$level] = $this->makeTempDir();
                $prefix = 'ORRERY_STUB_'.str_repeat('INNER_', $level - 1);
                $env[$prefix.'OUT'] = $outs[$level];
                $env[$prefix.'HOLD'] = $holds[$level];

                if ($level < $levels) {
                    $env[$prefix.'NESTED'] = 'sqlite --sqlite-file';
                    $env[$prefix.'GRACEFUL'] = '1';
                }

                if ($level > 1) {
                    $env[$prefix.'WATCH'] = '@outer-sqlite';
                    $env[$prefix.'WATCH_LOG'] = $unmanaged.'/watch.log';
                }
            }

            $outer = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env));
            $stubs = [];

            for ($level = 1; $level <= $levels; $level++) {
                $hold = $holds[$level];
                $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 30.0, "level {$level} did not start: ".$outer->getErrorOutput());
                $stubs[$level] = self::readJson(self::stubRecords($outs[$level])[0]);
            }

            for ($level = 1; $level < $levels; $level++) {
                $this->assertRunsWithSelectedBash($stubs[$level]['nested']);
            }

            $outer->signal(15);
            $outer->wait();

            self::assertSame(143, $outer->getExitCode(), $outer->getErrorOutput());

            for ($level = 1; $level <= $levels; $level++) {
                if ($level < $levels) {
                    self::assertSame(143, $this->nestedExit($outs[$level]), 'the bin/test of level '.($level + 1)." did not finish its own cleanup:\n".$outer->getErrorOutput());
                    self::assertFalse(self::isAlive($stubs[$level]['nested']), 'the bin/test of level '.($level + 1).' survived');
                }

                self::assertFalse(self::isAlive($stubs[$level]['pid']), "the PHPUnit of level {$level} survived");
                self::assertDirectoryDoesNotExist($stubs[$level]['run_dir'], "the directory of level {$level} remained");
            }

            self::assertFileDoesNotExist($unmanaged.'/watch.log', 'a level removed its SQLite files while a level below it still ran');
            self::assertTrue(self::isAlive($siblingPid), 'an unrelated process was stopped');
        } finally {
            self::stopUnrelatedSibling($sibling, $unmanaged);
        }
    }

    #[DataProvider('bashVersions')]
    public function test_an_interrupted_outer_run_stops_the_orphaned_group_of_a_killed_inner_run(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        [$sibling, $siblingPid, $unmanaged] = $this->startUnrelatedSibling();

        try {
            $out = $this->makeTempDir();
            $innerOut = $this->makeTempDir();
            $hold = $this->makeTempDir();
            $innerHold = $this->makeTempDir();
            $outer = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
                'ORRERY_TEST_PHPUNIT' => self::stubPath(),
                'ORRERY_TEST_GRACE' => '0.5',
                'ORRERY_STUB_OUT' => $out,
                'ORRERY_STUB_HOLD' => $hold,
                'ORRERY_STUB_NESTED' => 'sqlite',
                'ORRERY_STUB_INNER_OUT' => $innerOut,
                'ORRERY_STUB_INNER_HOLD' => $innerHold,
                'ORRERY_STUB_INNER_IGNORE_TERM' => '1',
                'ORRERY_STUB_INNER_WATCH' => '@outer-sqlite',
                'ORRERY_STUB_INNER_WATCH_LOG' => $unmanaged.'/watch.log',
            ]));
            $this->waitUntil(static fn (): bool => file_exists($innerHold.'/reached') && file_exists($hold.'/reached'), 20.0, 'the nested run did not start: '.$outer->getErrorOutput());
            $outerStub = self::readJson(self::stubRecords($out)[0]);
            $innerStub = self::readJson(self::stubRecords($innerOut)[0]);
            $this->assertRunsWithSelectedBash($outerStub['nested']);
            $nested = self::identityOf($outerStub['nested']);

            // Only the owner records know the inner PHPUnit group now.
            self::signalIdentity($nested, SIGKILL);
            $this->waitUntil(static fn (): bool => ! self::isAlive($outerStub['nested']), 5.0, 'the inner supervisor did not die');

            $outer->signal(15);
            $outer->wait();

            self::assertSame(143, $outer->getExitCode(), $outer->getErrorOutput());
            self::assertFalse(self::isAlive($innerStub['pid']), 'the PHPUnit of the inner run survived');
            self::assertFalse(self::isAlive($outerStub['pid']), 'the PHPUnit of the outer run survived');
            self::assertFileDoesNotExist($unmanaged.'/watch.log', 'the outer run deleted its SQLite files while an inner process still ran');
            self::assertDirectoryDoesNotExist(dirname($outerStub['env']['ORRERY_TEST_SQLITE_FILE']));
            self::assertDirectoryDoesNotExist($innerStub['run_dir'], 'the directory of the killed inner run remained');
            self::assertTrue(self::isAlive($siblingPid), 'an unrelated process was stopped');
        } finally {
            self::stopUnrelatedSibling($sibling, $unmanaged);
        }
    }

    #[DataProvider('bashVersions')]
    public function test_a_nested_group_that_cannot_join_a_record_above_it_never_starts(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        [$sibling, $siblingPid, $unmanaged] = $this->startUnrelatedSibling();

        try {
            // The nested run's child is held before its join. Meanwhile the
            // outer run's record becomes unwritable, so the join fails there.
            $out = $this->makeTempDir();
            $hold = $this->makeTempDir();
            $innerOut = $this->makeTempDir();
            $innerHold = $this->makeTempDir();
            $pause = $this->makeTempDir();
            $outer = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
                'ORRERY_TEST_PHPUNIT' => self::stubPath(),
                'ORRERY_STUB_OUT' => $out,
                'ORRERY_STUB_HOLD' => $hold,
                'ORRERY_STUB_NESTED' => 'sqlite',
                'ORRERY_STUB_NESTED_ENV' => (string) json_encode(['ORRERY_TEST_PAUSE_BEFORE_REGISTER' => $pause, 'ORRERY_TEST_GATE_IGNORE_PARENT' => '1']),
                'ORRERY_STUB_INNER_OUT' => $innerOut,
                'ORRERY_STUB_INNER_HOLD' => $innerHold,
                'ORRERY_STUB_INNER_IGNORE_TERM' => '1',
                'ORRERY_STUB_INNER_WATCH' => '@outer-sqlite',
                'ORRERY_STUB_INNER_WATCH_LOG' => $unmanaged.'/watch.log',
            ]));
            $this->waitUntil(static fn (): bool => file_exists($pause.'/paused') && file_exists($hold.'/reached'), 20.0, 'the nested run did not start: '.$outer->getErrorOutput());
            $stub = self::readJson(self::stubRecords($out)[0]);
            $record = self::ownRecord($stub);
            $child = (int) trim((string) file_get_contents($pause.'/paused'));
            $this->assertRunsWithSelectedBash($stub['nested']);

            chmod($record['dir'], 0500);

            try {
                touch($pause.'/resume');
                $this->waitUntil(static fn (): bool => (glob($out.'/nested-*.json') ?: []) !== [] || self::stubRecords($innerOut) !== [], 20.0, 'the nested run neither failed nor started');
            } finally {
                chmod($record['dir'], 0700);
            }

            self::assertSame([], self::stubRecords($innerOut), 'the nested PHPUnit started although its group is not in the record above it');
            self::assertSame(74, $this->nestedExit($out), 'the nested run did not fail on the refused join');
            self::assertFalse(self::isAlive($child));
            self::assertStringContainsString('could not join the owner records', $outer->getErrorOutput());

            $outer->signal(15);
            $outer->wait();

            self::assertSame(143, $outer->getExitCode(), $outer->getErrorOutput());
            self::assertDirectoryDoesNotExist($record['dir']);
            self::assertFileDoesNotExist($unmanaged.'/watch.log');
            self::assertTrue(self::isAlive($siblingPid), 'an unrelated process was stopped');
        } finally {
            self::stopUnrelatedSibling($sibling, $unmanaged);
        }
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function unreadableRecords(): iterable
    {
        return self::withBashes(['a private owner record' => ['sqlite'], 'a lease' => ['mysql']]);
    }

    #[DataProvider('unreadableRecords')]
    public function test_an_unreadable_owner_record_keeps_every_resource(string $engine, string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // The inner supervisor dies, so only the owner records know the inner
        // PHPUnit group. Then the outer record cannot be read at the shutdown.
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $innerOut = $this->makeTempDir();
        $innerHold = $this->makeTempDir();
        $outer = $this->startProcess([...$this->wrapper('bin/test'), $engine, ...($engine === 'sqlite' ? ['--sqlite-file'] : []), '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_STUB_NESTED' => 'sqlite',
            'ORRERY_STUB_INNER_OUT' => $innerOut,
            'ORRERY_STUB_INNER_HOLD' => $innerHold,
            'ORRERY_STUB_INNER_IGNORE_TERM' => '1',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($innerHold.'/reached') && file_exists($hold.'/reached'), 20.0, 'the nested run did not start: '.$outer->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        $inner = self::readJson(self::stubRecords($innerOut)[0]);
        $record = self::ownRecord($stub);
        $this->assertRunsWithSelectedBash($stub['nested']);
        $nested = self::identityOf($stub['nested']);
        [$sibling, $siblingPid, $unmanaged] = $this->startUnrelatedSibling([$stub['run_dir'], $innerOut]);

        try {
            self::signalIdentity($nested, SIGKILL);
            $this->waitUntil(static fn (): bool => ! self::isAlive($stub['nested']), 5.0, 'the inner supervisor did not die');
            $file = $record['dir'].'/registry.json';
            $before = (string) file_get_contents($file);
            chmod($file, 0);

            try {
                $outer->signal(15);
                $outer->wait();
            } finally {
                chmod($file, 0600);
            }

            self::assertSame(74, $outer->getExitCode(), $outer->getErrorOutput());
            self::assertStringContainsString('cannot read', $outer->getErrorOutput());
            self::assertStringContainsString("kept the owner record of {$record['run']}", $outer->getErrorOutput());
            self::assertDirectoryExists($stub['run_dir'], 'a directory was removed although the record could not be read');
            self::assertTrue(self::isAlive($inner['pid']), 'the inner group is gone, so this control proves nothing');
            self::assertSame($before, file_get_contents($file), 'the record changed although it could not be read');
            self::assertNotContains('drop_databases', self::operations(self::fakeCalls($this->registry)));
            self::assertFileDoesNotExist($unmanaged.'/watch.log');

            // Once the record can be read, its shutdown stops the orphaned
            // group, and only then removes the directories and the lease.
            $recover = $this->testdb(['shutdown', '--owner='.$record['entry'], '--grace=1'], $engine === 'sqlite' ? $this->scriptEnv() : $this->fakeEnv($this->registry));
            self::assertSame(0, $recover['exit'], $recover['stderr']);
            self::assertFalse(self::isAlive($inner['pid']));
            self::assertDirectoryDoesNotExist($stub['run_dir']);

            if ($engine !== 'sqlite') {
                self::assertSame([], self::registryData($this->registry)['leases']);
                self::assertContains('drop_databases', self::operations(self::fakeCalls($this->registry)));
            }

            self::assertFileDoesNotExist($unmanaged.'/watch.log', 'a directory was removed while the inner group still ran');
            self::assertTrue(self::isAlive($siblingPid), 'an unrelated process was stopped');
        } finally {
            self::stopUnrelatedSibling($sibling, $unmanaged);
        }
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function innerSupervisorStates(): iterable
    {
        return self::withBashes(['the inner supervisor ignores SIGTERM' => [false], 'the inner supervisor is frozen' => [true]]);
    }

    #[DataProvider('innerSupervisorStates')]
    public function test_a_join_held_until_an_outer_run_closes_is_refused(bool $freeze, string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        // The nested run's child is held before its join until the outer run
        // has closed its record, and released near the end of the outer grace
        // period. The outer PHPUnit ignores SIGTERM, so the outer shutdown
        // runs for its whole grace period. The inner bin/test inherits that
        // ignored SIGTERM (bash cannot trap a signal that was ignored when it
        // started), or it is frozen: either way it does not close its own
        // record, so only the closed outer record refuses the join.
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $innerOut = $this->makeTempDir();
        $innerHold = $this->makeTempDir();
        $pause = $this->makeTempDir();
        $outer = $this->startProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_TEST_GRACE' => '2',
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_STUB_IGNORE_TERM' => '1',
            'ORRERY_STUB_NESTED' => 'sqlite',
            'ORRERY_STUB_NESTED_ENV' => (string) json_encode(['ORRERY_TEST_PAUSE_BEFORE_REGISTER' => $pause, 'ORRERY_TEST_GATE_IGNORE_PARENT' => '1']),
            'ORRERY_STUB_INNER_OUT' => $innerOut,
            'ORRERY_STUB_INNER_HOLD' => $innerHold,
            'ORRERY_STUB_INNER_IGNORE_TERM' => '1',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($pause.'/paused') && file_exists($hold.'/reached'), 20.0, 'the nested run did not start: '.$outer->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        $record = self::ownRecord($stub);
        $sqliteDir = dirname($stub['env']['ORRERY_TEST_SQLITE_FILE']);
        $child = (int) trim((string) file_get_contents($pause.'/paused'));
        $this->assertRunsWithSelectedBash($stub['nested']);
        $nested = self::identityOf($stub['nested']);
        [$sibling, $siblingPid, $unmanaged] = $this->startUnrelatedSibling([$sqliteDir, $innerOut]);

        try {
            if ($freeze) {
                self::signalIdentity($nested, SIGSTOP);
            }

            $outer->signal(15);
            // A deadline, not a pause: on a loaded machine the outer bin/test
            // needs longer to reach its shutdown. The order stays proved: the
            // held join resumes only after the record is closed.
            $this->waitUntil(static fn (): bool => (self::registryData($record['dir'])['leases'][$record['run']]['closing'] ?? false) === true, 30.0, 'the outer run did not close its record');
            usleep(1_300_000);
            touch($pause.'/resume');
            $outer->wait();

            self::assertSame(143, $outer->getExitCode(), $outer->getErrorOutput());
            usleep(300_000);
            self::assertSame([], self::stubRecords($innerOut), 'the nested PHPUnit started after the outer record closed');
            self::assertFalse(self::isAlive($child), 'the held child outlived the outer run');
            self::assertFalse(self::isAlive($stub['nested']), 'the inner bin/test outlived the outer run');
            self::assertFalse(self::isAlive($stub['pid']), 'the outer PHPUnit outlived the outer run');
            self::assertDirectoryDoesNotExist($sqliteDir);
            self::assertDirectoryDoesNotExist($record['dir']);
            self::assertFileDoesNotExist($unmanaged.'/watch.log', 'the outer run removed its SQLite files while a nested process still ran');
            self::assertTrue(self::isAlive($siblingPid), 'an unrelated process was stopped');
        } finally {
            if ($freeze) {
                self::signalIdentity($nested, SIGCONT);
            }

            self::stopUnrelatedSibling($sibling, $unmanaged);
        }
    }

    public function test_modes_without_a_registry_start_at_once_without_a_gate(): void
    {
        $out = $this->makeTempDir();
        $inherited = [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_TEST_RUN_ID' => 'run_9_99999999',
            'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
        ];

        $file = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--sqlite-file', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $inherited));
        self::assertSame(0, $file['exit'], $file['stderr']);
        self::assertLessThan(10.0, $file['seconds']);
        $stub = self::readJson(self::stubRecords($out)[0]);
        self::assertNull($stub['gate']);
        self::assertSame('sqlite', $stub['env']['ORRERY_TEST_DRIVER']);
        self::assertMatchesRegularExpression('#/orrery-sqlite-run_\d+_[0-9a-f]{8}/primary\.sqlite$#', $stub['env']['ORRERY_TEST_SQLITE_FILE']);
        self::assertSame($stub['env']['ORRERY_TEST_SQLITE_FILE'], $stub['sqlite_canonical'] ?? null, 'the exported SQLite path is not canonical');
        self::assertSame(['file' => true, 'mirror' => true, 'dir_mode' => 0700], $stub['sqlite']);
        self::assertArrayNotHasKey('ORRERY_TEST_RUN_ID', $stub['env']);
        self::assertArrayNotHasKey('ORRERY_TEST_TOKEN', $stub['env']);
        // The run's private owner record is in its own directory, which also
        // holds the SQLite directory.
        self::assertSame(dirname($stub['env']['ORRERY_TEST_SQLITE_FILE'], 2), self::ownRecord($stub)['dir']);
        // The owned directory and its temporary parent are removed after the run.
        self::assertDirectoryDoesNotExist(dirname($stub['env']['ORRERY_TEST_SQLITE_FILE']));
        self::assertDirectoryDoesNotExist(dirname($stub['env']['ORRERY_TEST_SQLITE_FILE'], 2));
        array_map('unlink', self::stubRecords($out));

        $memory = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $inherited + ['ORRERY_TEST_SQLITE_FILE' => '/tmp/intruder.sqlite']));
        self::assertSame(0, $memory['exit'], $memory['stderr']);
        $stub = self::readJson(self::stubRecords($out)[0]);
        self::assertNull($stub['gate']);
        self::assertArrayNotHasKey('ORRERY_TEST_SQLITE_FILE', $stub['env']);
        array_map('unlink', self::stubRecords($out));

        $ci = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--no-provision', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $inherited + [
            'ORRERY_TEST_DATABASE' => 'orrery_ci_1_2',
        ]));
        self::assertSame(0, $ci['exit'], $ci['stderr']);
        self::assertLessThan(10.0, $ci['seconds']);
        $stub = self::readJson(self::stubRecords($out)[0]);
        self::assertNull($stub['gate']);
        self::assertSame('1', $stub['env']['ORRERY_TEST_CI']);
        self::assertArrayNotHasKey('ORRERY_TEST_RUN_ID', $stub['env']);
        self::assertSame('orrery_ci_1_2', $stub['env']['ORRERY_TEST_DATABASE']);
        self::assertSame(str_repeat('a', 32), $stub['env']['ORRERY_TEST_TOKEN']);
        self::assertSame('mysql', $stub['env']['ORRERY_TEST_DRIVER']);

        $noToken = $this->runProcess([...$this->wrapper('bin/test'), 'pgsql', '--no-provision', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, ['ORRERY_TEST_DATABASE' => 'orrery_ci_1_2']));
        self::assertSame(78, $noToken['exit'], $noToken['stderr']);
        $noDatabase = $this->runProcess([...$this->wrapper('bin/test'), 'pgsql', '--no-provision', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, ['ORRERY_TEST_TOKEN' => str_repeat('a', 32)]));
        self::assertSame(78, $noDatabase['exit'], $noDatabase['stderr']);
        $badName = $this->runProcess([...$this->wrapper('bin/test'), 'pgsql', '--no-provision', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, ['ORRERY_TEST_DATABASE' => 'mysql', 'ORRERY_TEST_TOKEN' => str_repeat('a', 32)]));
        self::assertSame(64, $badName['exit'], $badName['stderr']);

        self::assertDirectoryDoesNotExist($this->registry, 'a mode without a registry touched the registry');
        self::assertSame([], glob($this->scriptTmpDir.'/orrery-run.*') ?: [], 'a run left its directory behind');
    }

    public function test_bin_test_chooses_php_from_the_option_then_the_variable_then_path(): void
    {
        $bin = $this->makeTempDir();
        $trace = $bin.'/trace';

        foreach (['option', 'variable', 'path'] as $label) {
            mkdir($bin.'/'.$label);
            file_put_contents($bin.'/'.$label.'/php', "#!/bin/sh\necho {$label} >> \"\$ORRERY_PHP_TRACE\"\nexec ".escapeshellarg(PHP_BINARY)." \"\$@\"\n");
            chmod($bin.'/'.$label.'/php', 0755);
        }

        $env = $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_PHP_TRACE' => $trace,
            'PATH' => $bin.'/path:'.getenv('PATH'),
            'ORRERY_TEST_PHP' => $bin.'/variable/php',
        ]);
        // The stub and every testdb.php call of the run: owner-path, owner-init,
        // owner-join, owner-wait and shutdown.
        $calls = 6;

        $option = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--php='.$bin.'/option/php'], $env);
        self::assertSame(0, $option['exit'], $option['stderr']);
        self::assertSame(str_repeat("option\n", $calls), file_get_contents($trace), 'the stub and every testdb.php call must use the selected PHP');
        unlink($trace);

        $variable = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite'], $env);
        self::assertSame(0, $variable['exit'], $variable['stderr']);
        self::assertSame(str_repeat("variable\n", $calls), file_get_contents($trace));
        unlink($trace);

        $path = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite'], ['ORRERY_TEST_PHP' => false] + $env);
        self::assertSame(0, $path['exit'], $path['stderr']);
        self::assertSame(str_repeat("path\n", $calls), file_get_contents($trace));

        $missing = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--php=/nonexistent/php'], $env);
        self::assertSame(64, $missing['exit'], $missing['stderr']);
    }

    /**
     * Rejects: a wait override (ORRERY_TEST_GRACE, ORRERY_TEST_POLL_MS) that bin/test or
     * testdb.php reads outside test mode, so a stray variable could shorten the grace or the polls
     * of a real run; one that accepts a value that the shell's arithmetic or sleep cannot use; and
     * a test mode that refuses a valid fractional grace.
     *
     * A real-mode run cannot use the stub (ORRERY_TEST_PHPUNIT is a test-mode variable too), so
     * its PHP is a shim that ends the PHPUnit call at once and runs every testdb.php call. Invalid
     * values there prove that nothing reads them: a read would refuse them, as test mode does.
     */
    public function test_the_wait_overrides_apply_only_in_test_mode(): void
    {
        $out = $this->makeTempDir();
        $test = fn (array $extra): array => $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
        ] + $extra));

        foreach (['abc', '1.2345', '.5', '5.', '1.2.3', '-1', '600.5', '18446744073709552'] as $grace) {
            $result = $test(['ORRERY_TEST_GRACE' => $grace]);
            self::assertSame(64, $result['exit'], "ORRERY_TEST_GRACE={$grace}: ".$result['stderr']);
            self::assertStringContainsString('ORRERY_TEST_GRACE must be a number of seconds', $result['stderr']);
        }

        foreach (['0', '1000', 'abc', '-5', '2.5'] as $poll) {
            $result = $test(['ORRERY_TEST_POLL_MS' => $poll]);
            self::assertSame(64, $result['exit'], "ORRERY_TEST_POLL_MS={$poll}: ".$result['stderr']);
            self::assertStringContainsString('ORRERY_TEST_POLL_MS must be a whole number of milliseconds', $result['stderr']);
        }

        self::assertSame([], self::stubRecords($out), 'a run with an invalid override started PHPUnit');

        $valid = $test(['ORRERY_TEST_GRACE' => '0.25', 'ORRERY_TEST_POLL_MS' => '5']);
        self::assertSame(0, $valid['exit'], $valid['stderr']);
        self::assertCount(1, self::stubRecords($out));

        // testdb.php refuses an invalid poll in test mode before the command runs.
        $refused = $this->testdb(['running-test', $out.'/missing.txt'], $this->fakeEnv($this->registry, ['ORRERY_TEST_POLL_MS' => 'abc']));
        self::assertSame(64, $refused['exit'], $refused['stderr']);
        self::assertStringContainsString('ORRERY_TEST_POLL_MS must be a whole number of milliseconds', $refused['stderr']);

        // Real mode reads neither variable.
        $shim = $this->makeTempDir().'/php';
        file_put_contents($shim, "#!/bin/sh\nif [ \"\$1\" = vendor/bin/phpunit ]; then\n    echo ran >> \"\$ORRERY_SHIM_LOG\"\n    exit 0\nfi\nexec ".escapeshellarg(PHP_BINARY)." \"\$@\"\n");
        chmod($shim, 0755);
        $log = $out.'/shim.log';
        $real = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--php='.$shim], $this->scriptEnv([
            'ORRERY_TEST_GRACE' => 'abc',
            'ORRERY_TEST_POLL_MS' => 'abc',
            'ORRERY_SHIM_LOG' => $log,
        ]));
        self::assertSame(0, $real['exit'], 'a real-mode run read a test-mode wait override: '.$real['stderr']);
        self::assertSame("ran\n", (string) @file_get_contents($log), 'the real-mode run did not reach PHPUnit');

        $ignored = $this->testdb(['running-test', $out.'/missing.txt'], $this->scriptEnv(['ORRERY_TEST_POLL_MS' => 'abc']));
        self::assertSame(0, $ignored['exit'], 'testdb.php read ORRERY_TEST_POLL_MS outside test mode: '.$ignored['stderr']);
    }

    // The alt profile (part 0)

    /**
     * Rejects: a service ensure that asks for the profile without --alt.
     */
    public function test_default_up_starts_no_alt_service(): void
    {
        $up = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame(0, $up['exit'], $up['stderr']);

        $ups = array_values(array_filter(self::fakeCalls($this->registry), static fn (array $call): bool => $call['op'] === 'up'));
        self::assertSame([['alt' => false]], array_map(static fn (array $call): array => $call['args'], $ups));

        $services = self::fakeState($this->registry)['services'];
        self::assertSame('running', $services['mysql']['state']);
        self::assertSame('running', $services['postgres']['state']);
        self::assertSame('absent', $services['mysql-alt']['state']);
        self::assertSame('absent', $services['pgsql-alt']['state']);
    }

    /**
     * Rejects: a production up that always passes --profile alt.
     */
    public function test_docker_ops_passes_the_alt_profile_only_where_required(): void
    {
        require_once self::testdbPath();

        $recorded = [];
        $runner = static function (array $command, array $env, float $timeout) use (&$recorded): array {
            $recorded[] = ['command' => $command, 'ports' => array_intersect_key($env, array_flip(['ORRERY_TEST_MYSQL_PORT', 'ORRERY_TEST_PGSQL_PORT', 'ORRERY_TEST_MARIADB_PORT', 'ORRERY_TEST_MYSQL_ALT_PORT', 'ORRERY_TEST_PGSQL_ALT_PORT']))];

            return [0, in_array('ps', $command, true) ? '[]' : '', ''];
        };
        $file = '/nonexistent/compose.yaml';
        $ports = ['mysql' => 33106, 'postgres' => 54336, 'mariadb' => 33108, 'mysql-alt' => 33107, 'pgsql-alt' => 54337];
        $ops = new DockerOps($file, $ports, $runner);

        $ops->up(false);
        $ops->up(true);
        $services = $ops->services();
        $ops->down();

        $base = ['docker', 'compose', '--project-name', 'orrery-testing', '--file', $file];
        self::assertSame([
            [...$base, 'up', '--detach', '--wait', '--wait-timeout', '180'],
            [...$base, '--profile', 'alt', 'up', '--detach', '--wait', '--wait-timeout', '180'],
            [...$base, '--profile', 'alt', 'ps', '--all', '--format', 'json'],
            [...$base, '--profile', 'alt', 'down', '--volumes', '--timeout', '10'],
        ], array_column($recorded, 'command'));

        foreach ($recorded as $call) {
            self::assertEquals(['ORRERY_TEST_MYSQL_PORT' => '33106', 'ORRERY_TEST_PGSQL_PORT' => '54336', 'ORRERY_TEST_MARIADB_PORT' => '33108', 'ORRERY_TEST_MYSQL_ALT_PORT' => '33107', 'ORRERY_TEST_PGSQL_ALT_PORT' => '54337'], $call['ports']);
        }

        self::assertSame(['mysql', 'postgres', 'mariadb', 'mysql-alt', 'pgsql-alt'], array_keys($services));
    }

    /**
     * Rejects: a MariaDB service operated with the `mysql` client (the MariaDB image has
     * none, so every marker and database command would fail), and a MySQL service
     * operated with the `mariadb` client.
     * Still passes: a client that runs the SQL with another option set (the test reads the command only).
     */
    public function test_docker_ops_uses_the_client_of_each_mysql_family_service(): void
    {
        require_once self::testdbPath();

        $recorded = [];
        $runner = static function (array $command, array $env, float $timeout) use (&$recorded): array {
            $recorded[] = $command;

            return [0, str_repeat('a', 32)."\n", ''];
        };
        $ports = ['mysql' => 33106, 'postgres' => 54336, 'mariadb' => 33108, 'mysql-alt' => 33107, 'pgsql-alt' => 54337];
        $ops = new DockerOps('/nonexistent/compose.yaml', $ports, $runner);

        foreach (['mariadb' => 'mariadb', 'mysql' => 'mysql', 'mysql-alt' => 'mysql'] as $service => $client) {
            $recorded = [];
            self::assertSame(str_repeat('a', 32), $ops->readMarker($service), $service);
            $ops->createDatabases($service, ['orrery_client']);

            foreach ($recorded as $command) {
                $index = array_search($service, $command, true);
                self::assertIsInt($index, $service);
                self::assertSame($client, $command[$index + 1], $service);
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function altMarkerMismatches(): iterable
    {
        yield 'mysql-alt wrong' => ['mysql-alt', str_repeat('f', 32)];
        yield 'mysql-alt missing' => ['mysql-alt', null];
        yield 'pgsql-alt wrong' => ['pgsql-alt', str_repeat('f', 32)];
        yield 'pgsql-alt missing' => ['pgsql-alt', null];
    }

    /**
     * Rejects: a marker loop that checks only SERVICES.
     */
    #[DataProvider('altMarkerMismatches')]
    public function test_alt_marker_mismatch_under_a_live_lease_exits_70(string $service, ?string $marker): void
    {
        $supervisor = $this->liveLeaseWithFourServices();
        self::changeFakeState($this->registry, static function (array $state) use ($service, $marker): array {
            $state['services'][$service]['marker'] = $marker;

            return $state;
        });
        $registry = (string) file_get_contents($this->registry.'/registry.json');
        $before = count(self::fakeCalls($this->registry));
        $databases = array_map(static fn (array $current): array => $current['databases'], self::fakeState($this->registry)['services']);

        $admit = $this->testdb(['admit', 'run_1_0000000b', (string) $supervisor['pid'], $supervisor['start'], 'orrery_other', '--generated', '--engine=all', '--alt'], $this->fakeEnv($this->registry));
        self::assertSame(70, $admit['exit'], $admit['stderr']);
        self::assertStringContainsString($service, $admit['stderr']);

        $operations = self::operations(array_slice(self::fakeCalls($this->registry), $before));
        self::assertSame([], array_values(array_intersect($operations, ['up', 'down', 'write_marker', 'create_databases', 'drop_databases'])));
        self::assertSame($databases, array_map(static fn (array $current): array => $current['databases'], self::fakeState($this->registry)['services']));
        self::assertSame($registry, (string) file_get_contents($this->registry.'/registry.json'), 'the registry changed');
    }

    /**
     * Rejects: an --alt ensure that runs up under a live lease.
     */
    public function test_alt_admission_with_matching_markers_reuses_the_services(): void
    {
        $supervisor = $this->liveLeaseWithFourServices();
        $token = self::registryData($this->registry)['token'];
        $before = count(self::fakeCalls($this->registry));

        $admit = $this->testdb(['admit', 'run_1_0000000b', (string) $supervisor['pid'], $supervisor['start'], 'orrery_other', '--generated', '--engine=pgsql', '--alt'], $this->fakeEnv($this->registry));
        self::assertSame(0, $admit['exit'], $admit['stderr']);
        self::assertSame('kept', $admit['json']['generation']);
        self::assertSame($token, $admit['json']['token']);

        $calls = array_slice(self::fakeCalls($this->registry), $before);
        self::assertSame([], array_values(array_intersect(self::operations($calls), ['up', 'down', 'write_marker'])));
        $created = array_values(array_map(
            static fn (array $call): array => [$call['args']['service'], $call['args']['names']],
            array_filter($calls, static fn (array $call): bool => $call['op'] === 'create_databases'),
        ));
        self::assertSame([['postgres', ['orrery_other', 'orrery_other_mirror']], ['pgsql-alt', ['orrery_other', 'orrery_other_mirror']]], $created);
        self::assertSame(['postgres', 'pgsql-alt'], self::registryData($this->registry)['leases']['run_1_0000000b']['services']);
    }

    /**
     * Rejects: a port mismatch reported as a marker mismatch (70).
     */
    public function test_alt_port_mismatch_keeps_the_a2_exits(): void
    {
        $supervisor = $this->liveLeaseWithFourServices();
        self::changeFakeState($this->registry, static function (array $state): array {
            $state['services']['mysql-alt']['ports'] = [34999];

            return $state;
        });
        $before = count(self::fakeCalls($this->registry));
        $arguments = ['admit', 'run_1_0000000b', (string) $supervisor['pid'], $supervisor['start'], 'orrery_other', '--generated', '--engine=mysql', '--alt'];

        $free = $this->testdb($arguments, $this->fakeEnv($this->registry));
        self::assertSame(71, $free['exit'], $free['stderr']);
        self::assertStringContainsString('mysql-alt', $free['stderr']);

        self::changeFakeState($this->registry, static fn (array $state): array => ['ports_taken' => [33107]] + $state);
        $taken = $this->testdb($arguments, $this->fakeEnv($this->registry));
        self::assertSame(69, $taken['exit'], $taken['stderr']);
        self::assertStringContainsString('127.0.0.1:33107', $taken['stderr']);

        $operations = self::operations(array_slice(self::fakeCalls($this->registry), $before));
        self::assertSame([], array_values(array_intersect($operations, ['up', 'down', 'write_marker', 'create_databases', 'drop_databases'])));
    }

    /**
     * Rejects: an ensure that starts the alt profile under a live lease.
     */
    public function test_alt_up_with_a_live_lease_changes_nothing(): void
    {
        $supervisor = $this->spawnSleeper();
        self::assertSame(0, $this->testdb(['up'], $this->fakeEnv($this->registry))['exit'], 'bin/test-db up starts every default server');
        $this->admit('run_1_0000000a', $supervisor, 'orrery_live', '--explicit', 'mysql');
        $token = self::registryData($this->registry)['token'];
        $before = count(self::fakeCalls($this->registry));

        $up = $this->testdb(['up', '--alt'], $this->fakeEnv($this->registry));
        $operations = self::operations(array_slice(self::fakeCalls($this->registry), $before));
        self::assertSame([], array_values(array_intersect($operations, ['up', 'down', 'write_marker', 'create_databases', 'drop_databases'])), 'a Compose change under a live lease');
        self::assertSame(71, $up['exit'], $up['stderr']);
        self::assertStringContainsString('mysql-alt', $up['stderr']);
        $services = self::fakeState($this->registry)['services'];
        self::assertSame('absent', $services['mysql-alt']['state']);
        self::assertSame('absent', $services['pgsql-alt']['state']);
        self::assertSame($token, self::registryData($this->registry)['token']);
    }

    /**
     * Rejects: an alt database that outlives its lease.
     */
    public function test_alt_admission_creates_and_release_drops_the_alt_databases(): void
    {
        $supervisor = $this->spawnSleeper();

        $admit = $this->testdb(['admit', 'run_1_0000000a', (string) $supervisor['pid'], $supervisor['start'], 'orrery_altgen', '--generated', '--engine=mysql', '--alt'], $this->fakeEnv($this->registry));
        self::assertSame(0, $admit['exit'], $admit['stderr']);
        self::assertSame([['alt' => true, 'only' => ['mysql', 'mysql-alt']]], array_values(array_map(
            static fn (array $call): array => $call['args'],
            array_filter(self::fakeCalls($this->registry), static fn (array $call): bool => $call['op'] === 'up'),
        )), 'only the servers of the engine');

        $services = self::fakeState($this->registry)['services'];
        self::assertSame(['orrery_marker', 'orrery_altgen', 'orrery_altgen_mirror'], $services['mysql']['databases']);
        self::assertSame(['orrery_marker', 'orrery_altgen', 'orrery_altgen_mirror'], $services['mysql-alt']['databases']);
        self::assertSame('absent', $services['pgsql-alt']['state']);

        $release = $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry));
        self::assertSame(0, $release['exit'], $release['stderr']);
        self::assertSame(['mysql' => ['orrery_altgen', 'orrery_altgen_mirror'], 'mysql-alt' => ['orrery_altgen', 'orrery_altgen_mirror']], $release['json']['dropped']);

        $services = self::fakeState($this->registry)['services'];
        self::assertSame(['orrery_marker'], $services['mysql']['databases']);
        self::assertSame(['orrery_marker'], $services['mysql-alt']['databases']);
    }

    /**
     * Rejects: an admission that starts or needs the servers of other engines (a MySQL run that
     * waits for the MariaDB or Postgres container), a later admission of another engine that
     * gives the running server a new token while its lease is live, and an admission under a live
     * lease that starts a server.
     * Still passes: an admission that starts its server with another Compose command (the fake
     * records only the arguments of up).
     */
    public function test_an_admission_starts_and_needs_only_the_servers_of_its_engine(): void
    {
        $supervisor = $this->spawnSleeper();

        $mysql = $this->admit('run_1_0000000a', $supervisor, 'orrery_one', '--generated', 'mysql');
        self::assertSame('new', $mysql['generation']);
        $services = self::fakeState($this->registry)['services'];
        self::assertSame('running', $services['mysql']['state']);
        self::assertSame('absent', $services['postgres']['state']);
        self::assertSame('absent', $services['mariadb']['state']);
        self::assertSame([['alt' => false, 'only' => ['mysql']]], array_values(array_map(
            static fn (array $call): array => $call['args'],
            array_filter(self::fakeCalls($this->registry), static fn (array $call): bool => $call['op'] === 'up'),
        )));

        // Under the live MySQL lease, a Postgres run cannot start its server.
        $pgsql = $this->admitRaw('run_1_0000000b', $supervisor, 'orrery_two', '--generated', 'pgsql');
        self::assertSame(71, $pgsql['exit'], $pgsql['stderr']);
        self::assertStringContainsString('postgres', $pgsql['stderr']);

        // After the release, it starts only Postgres, and the running MySQL server gets the new token too.
        self::assertSame(0, $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry))['exit']);
        $second = $this->admit('run_1_0000000b', $supervisor, 'orrery_two', '--generated', 'pgsql');
        $services = self::fakeState($this->registry)['services'];
        self::assertSame('running', $services['postgres']['state']);
        self::assertSame('absent', $services['mariadb']['state']);
        self::assertSame($second['token'], $services['mysql']['marker']);
        self::assertSame($second['token'], $services['postgres']['marker']);
    }

    /**
     * Rejects: a MariaDB admission that leaves its databases on another service or outlives its
     * lease, and an --alt MariaDB admission that silently runs without an alt server.
     * Still passes: a MariaDB lease on a server of another version (the fake has no versions).
     */
    public function test_a_mariadb_admission_uses_only_the_mariadb_service_and_refuses_alt(): void
    {
        $supervisor = $this->spawnSleeper();

        $alt = $this->testdb(['admit', 'run_1_0000000a', (string) $supervisor['pid'], $supervisor['start'], 'orrery_maria', '--generated', '--engine=mariadb', '--alt'], $this->fakeEnv($this->registry));
        self::assertSame(64, $alt['exit'], $alt['stderr']);
        self::assertStringContainsString('has no alt server', $alt['stderr']);

        $admit = $this->testdb(['admit', 'run_1_0000000a', (string) $supervisor['pid'], $supervisor['start'], 'orrery_maria', '--generated', '--engine=mariadb'], $this->fakeEnv($this->registry));
        self::assertSame(0, $admit['exit'], $admit['stderr']);

        $services = self::fakeState($this->registry)['services'];
        self::assertSame(['orrery_marker', 'orrery_maria', 'orrery_maria_mirror'], $services['mariadb']['databases']);
        self::assertSame('absent', $services['mysql']['state'], 'a MariaDB run starts no MySQL server');
        self::assertSame('absent', $services['postgres']['state']);
        self::assertSame(self::registryData($this->registry)['token'], $services['mariadb']['marker']);

        $release = $this->testdb(['release', 'run_1_0000000a'], $this->fakeEnv($this->registry));
        self::assertSame(0, $release['exit'], $release['stderr']);
        self::assertSame(['mariadb' => ['orrery_maria', 'orrery_maria_mirror']], $release['json']['dropped']);
        self::assertSame(['orrery_marker'], self::fakeState($this->registry)['services']['mariadb']['databases']);
    }

    /**
     * Rejects: a token change that leaves a stale alt marker.
     */
    public function test_new_token_reaches_running_alt_services(): void
    {
        $up = $this->testdb(['up', '--alt'], $this->fakeEnv($this->registry));
        self::assertSame(0, $up['exit'], $up['stderr']);
        $first = $up['json']['token'];

        // A restart of the default services lost their markers.
        self::changeFakeState($this->registry, static function (array $state): array {
            $state['services']['mysql']['marker'] = null;
            $state['services']['postgres']['marker'] = null;

            return $state;
        });

        $again = $this->testdb(['up'], $this->fakeEnv($this->registry));
        self::assertSame(0, $again['exit'], $again['stderr']);
        self::assertSame('new', $again['json']['generation']);
        $token = $again['json']['token'];
        self::assertNotSame($first, $token);

        $services = self::fakeState($this->registry)['services'];

        foreach (['mysql', 'postgres', 'mysql-alt', 'pgsql-alt'] as $service) {
            self::assertSame($token, $services[$service]['marker'], "the {$service} marker");
        }
    }

    /**
     * Rejects: a down that leaves the alt services running.
     */
    public function test_down_includes_the_alt_profile(): void
    {
        $up = $this->testdb(['up', '--alt'], $this->fakeEnv($this->registry));
        self::assertSame(0, $up['exit'], $up['stderr']);
        self::assertSame('running', self::fakeState($this->registry)['services']['pgsql-alt']['state']);

        $down = $this->testdb(['down'], $this->fakeEnv($this->registry));
        self::assertSame(0, $down['exit'], $down['stderr']);

        foreach (self::fakeState($this->registry)['services'] as $service => $current) {
            self::assertSame('absent', $current['state'], "{$service} is still running");
        }
    }

    /**
     * Rejects: an --alt run without an alt database.
     */
    public function test_alt_needs_a_provisioned_server_engine(): void
    {
        $env = $this->fakeEnv($this->registry, ['ORRERY_TEST_PHPUNIT' => self::stubPath(), 'ORRERY_STUB_OUT' => $this->makeTempDir()]);

        $sqlite = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--alt', '--php='.PHP_BINARY], $env);
        self::assertSame(64, $sqlite['exit'], $sqlite['stderr']);
        self::assertStringContainsString('--alt', $sqlite['stderr']);

        $ci = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--no-provision', '--alt', '--php='.PHP_BINARY], $env + ['ORRERY_TEST_DATABASE' => 'orrery_ci_1_2', 'ORRERY_TEST_TOKEN' => str_repeat('a', 32)]);
        self::assertSame(64, $ci['exit'], $ci['stderr']);
        self::assertStringContainsString('--alt', $ci['stderr']);

        self::assertDirectoryDoesNotExist($this->registry, 'a refused --alt run touched the registry');
    }

    /**
     * Rejects: a bin/test that accepts --alt but admits without it, or leaves an inherited ORRERY_TEST_ALT in a run without it.
     */
    public function test_bin_test_alt_admits_the_alt_service_and_exports_its_port(): void
    {
        $out = $this->makeTempDir();
        $env = $this->fakeEnv($this->registry, ['ORRERY_TEST_PHPUNIT' => self::stubPath(), 'ORRERY_STUB_OUT' => $out, 'ORRERY_STUB_STATUS' => '1']);

        $alt = $this->runProcess([...$this->wrapper('bin/test'), 'pgsql', '--alt', '--php='.PHP_BINARY], $env);
        self::assertSame(0, $alt['exit'], $alt['stderr']);
        $stub = self::readJson(self::stubRecords($out)[0]);
        self::assertSame('1', $stub['env']['ORRERY_TEST_ALT']);
        self::assertSame('54337', $stub['env']['ORRERY_TEST_PGSQL_ALT_PORT']);
        self::assertSame(['postgres', 'pgsql-alt'], $stub['status']['leases'][$stub['env']['ORRERY_TEST_RUN_ID']]['services']);
        array_map('unlink', self::stubRecords($out));

        $plain = $this->runProcess([...$this->wrapper('bin/test'), 'pgsql', '--php='.PHP_BINARY], $env + ['ORRERY_TEST_ALT' => '1']);
        self::assertSame(0, $plain['exit'], $plain['stderr']);
        $stub = self::readJson(self::stubRecords($out)[0]);
        self::assertArrayNotHasKey('ORRERY_TEST_ALT', $stub['env']);
        self::assertSame(['postgres'], $stub['status']['leases'][$stub['env']['ORRERY_TEST_RUN_ID']]['services']);
    }

    /**
     * Rejects: a bin/test whose schema mode ignores --schema, reads an inherited ORRERY_TEST_SCHEMA, defaults to fresh on a lease or a SQLite file, defaults to reuse in memory or on CI, or accepts reuse in memory.
     */
    public function test_bin_test_selects_the_schema_mode(): void
    {
        $out = $this->makeTempDir();
        $env = $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            // An inherited value never selects the mode.
            'ORRERY_TEST_SCHEMA' => 'inherited',
        ]);
        $mode = function (array $arguments, array $extra = []) use ($env, $out): string {
            $run = $this->runProcess([...$this->wrapper('bin/test'), ...$arguments], array_merge($env, $extra));
            self::assertSame(0, $run['exit'], implode(' ', $arguments).': '.$run['stderr']);
            $records = self::stubRecords($out);
            self::assertCount(1, $records);
            $stub = self::readJson($records[0]);
            array_map('unlink', $records);

            return $stub['env']['ORRERY_TEST_SCHEMA'] ?? '(unset)';
        };

        self::assertSame('fresh', $mode(['sqlite', '--php='.PHP_BINARY]));
        self::assertSame('reuse', $mode(['sqlite', '--sqlite-file', '--php='.PHP_BINARY]));
        self::assertSame('fresh', $mode(['sqlite', '--sqlite-file', '--schema=fresh', '--php='.PHP_BINARY]));
        self::assertSame('reuse', $mode(['pgsql', '--php='.PHP_BINARY]));
        self::assertSame('fresh', $mode(['mysql', '--schema=fresh', '--php='.PHP_BINARY]));
        self::assertSame('fresh', $mode(['mysql', '--no-provision', '--php='.PHP_BINARY], ['ORRERY_TEST_DATABASE' => 'orrery_ci_1_2', 'ORRERY_TEST_TOKEN' => str_repeat('a', 32)]));
        self::assertSame('reuse', $mode(['pgsql', '--no-provision', '--schema=reuse', '--php='.PHP_BINARY], ['ORRERY_TEST_DATABASE' => 'orrery_ci_1_2', 'ORRERY_TEST_TOKEN' => str_repeat('a', 32)]));

        $memory = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--schema=reuse', '--php='.PHP_BINARY], $env);
        self::assertSame(64, $memory['exit'], $memory['stderr']);
        self::assertStringContainsString('--schema=reuse needs a database that lives longer than one test', $memory['stderr']);
        $unknown = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--schema=sometimes', '--php='.PHP_BINARY], $env);
        self::assertSame(64, $unknown['exit'], $unknown['stderr']);
        self::assertStringContainsString("--schema must be reuse or fresh, not 'sometimes'", $unknown['stderr']);
        self::assertSame([], self::stubRecords($out), 'a refused mode started PHPUnit');
    }

    // Helpers

    /**
     * Starts the four services without a lease (up --alt), then admits a
     * live lease on mysql. Returns the lease's supervisor.
     *
     * @return array{pid: int, start: string, process: Process}
     */
    private function liveLeaseWithFourServices(): array
    {
        $up = $this->testdb(['up', '--alt'], $this->fakeEnv($this->registry));
        self::assertSame(0, $up['exit'], $up['stderr']);
        $supervisor = $this->spawnSleeper();
        $this->admit('run_1_0000000a', $supervisor, 'orrery_live', '--explicit', 'mysql');

        $services = self::fakeState($this->registry)['services'];
        $token = self::registryData($this->registry)['token'];

        foreach (['mysql' => 33106, 'postgres' => 54336, 'mariadb' => 33108, 'mysql-alt' => 33107, 'pgsql-alt' => 54337] as $service => $port) {
            self::assertSame(['running', 'healthy', [$port], $token], [$services[$service]['state'], $services[$service]['health'], $services[$service]['ports'], $services[$service]['marker']], $service);
        }

        return $supervisor;
    }

    /**
     * @param  array{pid: int, start: string}  $supervisor
     * @return array<string, mixed>
     */
    private function admit(string $runId, array $supervisor, string $base, string $mode, string $engine): array
    {
        $result = $this->admitRaw($runId, $supervisor, $base, $mode, $engine);
        self::assertSame(0, $result['exit'], $result['stderr']);

        return $result['json'];
    }

    /**
     * @param  array{pid: int, start: string}  $supervisor
     * @return array{exit: int, json: array<string, mixed>, stdout: string, stderr: string, seconds: float}
     */
    private function admitRaw(string $runId, array $supervisor, string $base, string $mode, string $engine): array
    {
        return $this->testdb(['admit', $runId, (string) $supervisor['pid'], $supervisor['start'], $base, $mode, '--engine='.$engine], $this->fakeEnv($this->registry));
    }

    /**
     * @param  array{pid: int, start: string}  $child
     */
    private function addChild(string $runId, array $child): void
    {
        $result = $this->testdb(['add-child', $runId, (string) $child['pid'], $child['start']], $this->fakeEnv($this->registry));
        self::assertSame(0, $result['exit'], $result['stderr']);
    }

    /**
     * A complete environment for proc_open(): this process's environment
     * with the changes of a scriptEnv() array (false removes a variable).
     *
     * @param  array<string, string|false>  $changes
     * @return array<string, string>
     */
    private static function realEnv(array $changes): array
    {
        $env = getenv();

        foreach ($changes as $name => $value) {
            if ($value === false) {
                unset($env[$name]);
            } else {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * A private owner record in $dir.
     *
     * @param  array{pid: int, start: string}  $supervisor
     * @return string its owner entry
     */
    private function ownerInit(string $dir, string $runId, array $supervisor): string
    {
        $init = $this->testdb(['owner-init', $dir, $runId, (string) $supervisor['pid'], $supervisor['start']], $this->scriptEnv());
        self::assertSame(0, $init['exit'], $init['stderr']);

        return $runId.' '.$dir;
    }

    /**
     * Changes an owner record under its store's lock, like a registration.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private static function changeRecord(string $store, string $runId, callable $change): void
    {
        $lock = fopen($store.'/lock', 'c');
        self::assertIsResource($lock);
        flock($lock, LOCK_EX);

        try {
            $data = self::registryData($store);
            $data['leases'][$runId] = $change($data['leases'][$runId]);
            self::writeJson($store.'/registry.json.tmp', $data);
            rename($store.'/registry.json.tmp', $store.'/registry.json');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A process that leads its own process group (Fixtures/group.php).
     *
     * @param  array<string, string>  $env
     * @return array{pid: int, start: string}
     */
    private function startGroup(array $env = []): array
    {
        $dir = $this->makeTempDir();
        $process = $this->startProcess([PHP_BINARY, self::groupPath(), $dir], $this->scriptEnv($env));
        $pid = (int) $process->getPid();
        $this->waitUntil(static fn (): bool => file_exists("{$dir}/group-{$pid}.ready"), 10.0, 'the group leader did not start: '.$process->getErrorOutput());
        $start = self::startOf($pid);
        self::assertNotNull($start);

        return ['pid' => $pid, 'start' => $start];
    }

    /**
     * The first stub record in $dir, once it exists.
     *
     * @return array<string, mixed>
     */
    private function stubRecord(string $dir, string $what): array
    {
        $this->waitUntil(static fn (): bool => self::stubRecords($dir) !== [], 20.0, "{$what} did not start");

        return self::readJson(self::stubRecords($dir)[0]);
    }

    /**
     * The owner entry of a run's own record, from its stub's environment.
     *
     * @param  array<string, mixed>  $stub
     * @return array{run: string, dir: string, entry: string}
     */
    private static function ownRecord(array $stub): array
    {
        $owners = explode("\n", $stub['env']['ORRERY_OWNERS']);
        $entry = (string) end($owners);
        [$run, $dir] = explode(' ', $entry, 2);

        return ['run' => $run, 'dir' => $dir, 'entry' => $entry];
    }

    /**
     * The exit status of the nested run that a stub started, once it ended.
     */
    private function nestedExit(string $out, float $seconds = 20.0): int
    {
        $this->waitUntil(static fn (): bool => (glob($out.'/nested-*.json') ?: []) !== [], $seconds, 'the nested run did not end');

        return (int) self::readJson((glob($out.'/nested-*.json') ?: [])[0])['exit'];
    }

    /**
     * A process and a directory that the helpers of this test do not own: a
     * cleanup must leave the process alive, and the directory survives the
     * deletion of the test's directories (it holds the watch log).
     *
     * With $watch = [<dir>, <records dir>], the sibling is the external
     * watcher (Fixtures/watcher.php): it writes <unmanaged>/watch.log when
     * <dir> disappears while a process of a stub record in <records dir>
     * still lives.
     *
     * @param  array{0: string, 1: string}|null  $watch
     * @return array{0: resource, 1: int, 2: string}
     */
    private function startUnrelatedSibling(?array $watch = null): array
    {
        $unmanaged = sys_get_temp_dir().'/orrery-unmanaged-'.bin2hex(random_bytes(6));
        mkdir($unmanaged, 0700);
        $command = $watch === null ? ['sleep', '120'] : [PHP_BINARY, self::watcherPath(), $unmanaged.'/watch.log', $watch[0], $watch[1]];
        $sibling = proc_open($command, [], $pipes);
        self::assertIsResource($sibling);

        return [$sibling, proc_get_status($sibling)['pid'], $unmanaged];
    }

    /**
     * @param  resource  $sibling
     */
    private static function stopUnrelatedSibling($sibling, string $unmanaged): void
    {
        proc_terminate($sibling, 9);
        proc_close($sibling);
        @unlink($unmanaged.'/watch.log');
        @rmdir($unmanaged);
    }

    private static function lockIsFree(string $registry): bool
    {
        $handle = fopen($registry.'/lock', 'c');
        self::assertIsResource($handle);
        $free = flock($handle, LOCK_EX | LOCK_NB);

        if ($free) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return $free;
    }

    /**
     * @param  bool  $onlyTheWrapperStops  the child ignores the exit of its parent,
     *                                     so only a stop by bin/test ends it early
     * @return array{0: Process, 1: int, 2: string, 3: string, 4: string}
     */
    private function startWrapperPausedBeforeRegistration(bool $onlyTheWrapperStops = false): array
    {
        $pause = $this->makeTempDir();
        $out = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_TEST_PAUSE_BEFORE_REGISTER' => $pause,
        ] + ($onlyTheWrapperStops ? ['ORRERY_TEST_GATE_IGNORE_PARENT' => '1', 'ORRERY_START_GATE_TIMEOUT' => '5'] : [])));
        $this->waitUntil(static fn (): bool => file_exists($pause.'/paused'), 20.0, 'bin/test did not pause before registration: '.$wrapper->getErrorOutput());

        $child = (int) trim((string) file_get_contents($pause.'/paused'));
        self::assertTrue(self::isAlive($child), 'the child is not running');

        $leases = self::registryData($this->registry)['leases'];
        self::assertCount(1, $leases);
        $runId = (string) array_key_first($leases);
        self::assertSame([], $leases[$runId]['children']);

        usleep(200_000);
        self::assertSame([], self::stubRecords($out), 'the child ran before it was registered');

        return [$wrapper, $child, $runId, $out, $pause];
    }
}
