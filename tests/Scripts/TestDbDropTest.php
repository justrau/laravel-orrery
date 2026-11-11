<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * bin/test --drop-database and testdb.php admit --drop-at-release: the
 * explicit database names that bin/matrix uses are dropped by the lease that
 * holds them, like generated names. The release drops them only after every
 * recorded process is confirmed stopped, and while the lease still holds the
 * names. A stale lease leaves them as orphans, which the next admission
 * drops.
 *
 * Fake backend (ORRERY_OPS=fake) with a private test registry, real
 * processes, and the PHPUnit stand-in of the logic tests. It is in the
 * group scripts: `composer test` skips it, `composer test:all` runs it.
 */
#[Group('scripts')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class TestDbDropTest extends TestCase
{
    use RunsScripts;

    private const NAME = 'orrery_a7_drop';

    private string $registry;

    protected function setUp(): void
    {
        parent::setUp();
        // Not created here: testdb.php creates it.
        $this->registry = $this->makeTempDir('orrery-drop-').'/registry';
    }

    protected function tearDown(): void
    {
        $this->cleanUpScripts();
        parent::tearDown();
    }

    public function test_an_explicit_name_is_dropped_at_release_only_with_drop_database(): void
    {
        // The control: without the option, bin/test keeps an explicit database.
        $kept = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $this->makeTempDir(),
            'ORRERY_TEST_DATABASE' => 'orrery_a7_keep',
        ]));
        self::assertSame(0, $kept['exit'], $kept['stderr']);
        self::assertContains('orrery_a7_keep', self::databases($this->registry, 'mysql'));
        self::assertContains('orrery_a7_keep_mirror', self::databases($this->registry, 'mysql'));

        // With the option, also after a failed PHPUnit run.
        $out = $this->makeTempDir();
        $dropped = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--drop-database', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_STATUS' => '1',
            'ORRERY_STUB_EXIT' => '2',
            'ORRERY_TEST_DATABASE' => self::NAME,
        ]));

        self::assertSame(2, $dropped['exit'], 'bin/test must return the PHPUnit exit code: '.$dropped['stderr']);
        $stub = self::readJson(self::stubRecords($out)[0]);
        $lease = $stub['status']['leases'][$stub['env']['ORRERY_TEST_RUN_ID']];
        self::assertSame(self::NAME, $stub['env']['ORRERY_TEST_DATABASE'], 'the explicit name was replaced');
        self::assertFalse($lease['generated']);
        self::assertTrue($lease['drop']);
        self::assertNotContains(self::NAME, self::databases($this->registry, 'mysql'));
        self::assertNotContains(self::NAME.'_mirror', self::databases($this->registry, 'mysql'));
        self::assertContains(['mysql', [self::NAME, self::NAME.'_mirror']], self::drops($this->registry));
        self::assertSame([], self::registryData($this->registry)['leases']);
        self::assertContains('orrery_a7_keep', self::databases($this->registry, 'mysql'), 'the release dropped a name of another run');
    }

    public function test_an_interrupted_run_drops_its_databases_after_phpunit_is_gone_and_while_it_holds_the_names(): void
    {
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $barrier = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'pgsql', '--drop-database', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_TEST_DATABASE' => self::NAME,
            'ORRERY_FAKE_HOLD_AT' => 'drop_databases',
            'ORRERY_FAKE_BARRIER_DIR' => $barrier,
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 20.0, 'the stub did not start: '.$wrapper->getErrorOutput());
        $stub = self::readJson(self::stubRecords($out)[0]);
        $runId = $stub['env']['ORRERY_TEST_RUN_ID'];
        self::assertContains(self::NAME, self::databases($this->registry, 'postgres'));

        $wrapper->signal(15);

        // The release holds inside its drop.
        $this->waitUntil(static fn (): bool => file_exists($barrier.'/drop_databases.reached') || ! $wrapper->isRunning(), 30.0, 'the release never reached its drop');
        self::assertFileExists($barrier.'/drop_databases.reached', 'bin/test ended without a drop: '.$wrapper->getErrorOutput());
        self::assertFalse(self::isAlive($stub['pid']), 'the drop started while PHPUnit was alive');
        $held = self::registryData($this->registry)['leases'][$runId] ?? null;
        self::assertIsArray($held, 'the lease was gone before the drop');
        self::assertSame([self::NAME, self::NAME.'_mirror'], $held['names'], 'the lease did not hold the names during the drop');

        touch($barrier.'/drop_databases.release');
        $wrapper->wait();

        self::assertSame(143, $wrapper->getExitCode(), $wrapper->getErrorOutput());
        self::assertNotContains(self::NAME, self::databases($this->registry, 'postgres'));
        self::assertNotContains(self::NAME.'_mirror', self::databases($this->registry, 'postgres'));
        self::assertSame([], self::registryData($this->registry)['leases']);
    }

    public function test_a_stale_lease_of_a_drop_name_leaves_orphans_that_the_next_admission_drops(): void
    {
        $gone = $this->spawnSleeper();
        $env = $this->fakeEnv($this->registry);

        $drop = $this->testdb(['admit', 'run_1_0000000a', (string) $gone['pid'], $gone['start'], 'orrery_a7_stale', '--explicit', '--drop-at-release', '--engine=mysql'], $env);
        self::assertSame(0, $drop['exit'], $drop['stderr']);
        self::assertTrue($drop['json']['drop']);

        // The control: a stale lease of an explicit name without the option.
        $keep = $this->testdb(['admit', 'run_1_0000000b', (string) $gone['pid'], $gone['start'], 'orrery_a7_kept', '--explicit', '--engine=mysql'], $env);
        self::assertSame(0, $keep['exit'], $keep['stderr']);
        self::assertFalse($keep['json']['drop']);

        // The supervisor dies without a release: both leases become stale.
        $this->stopSleeper($gone);
        $live = $this->spawnSleeper();
        $next = $this->testdb(['admit', 'run_1_0000000c', (string) $live['pid'], $live['start'], 'orrery_a7_next', '--explicit', '--engine=mysql'], $env);
        self::assertSame(0, $next['exit'], $next['stderr']);

        self::assertEqualsCanonicalizing(['run_1_0000000a', 'run_1_0000000b'], $next['json']['pruned']);
        self::assertContains(['mysql', ['orrery_a7_stale', 'orrery_a7_stale_mirror']], self::drops($this->registry));
        $databases = self::databases($this->registry, 'mysql');
        self::assertNotContains('orrery_a7_stale', $databases);
        self::assertNotContains('orrery_a7_stale_mirror', $databases);
        self::assertContains('orrery_a7_kept', $databases, 'a stale explicit name without the option was dropped');
        self::assertContains('orrery_a7_next', $databases);
    }

    public function test_the_option_is_refused_where_no_lease_owns_the_name(): void
    {
        $sqlite = $this->runProcess([...$this->wrapper('bin/test'), 'sqlite', '--drop-database', '--php='.PHP_BINARY], $this->fakeEnv($this->registry));
        self::assertSame(64, $sqlite['exit']);
        self::assertStringContainsString('--drop-database applies to mysql, pgsql and mariadb with a lease', $sqlite['stderr']);

        $ci = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--no-provision', '--drop-database', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_DATABASE' => 'orrery_ci_1_2',
            'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
        ]));
        self::assertSame(64, $ci['exit']);
        self::assertStringContainsString('--drop-database applies to mysql, pgsql and mariadb with a lease', $ci['stderr']);

        $sleeper = $this->spawnSleeper();
        $generated = $this->testdb(['admit', 'run_1_0000000d', (string) $sleeper['pid'], $sleeper['start'], 'orrery_a7_gen', '--generated', '--drop-at-release', '--engine=mysql'], $this->fakeEnv($this->registry));
        self::assertSame(64, $generated['exit']);
        self::assertStringContainsString('--drop-at-release applies to --explicit only', $generated['stderr']);
    }

    /**
     * Rejects: a run that keeps an explicit database silently, a warning with the wrong names or
     * without the cleanup command, more than one warning line, a warning on stdout, and a warning
     * that changes the exit code or drops the kept databases.
     */
    public function test_a_kept_explicit_database_gets_one_warning_with_the_drop_command(): void
    {
        $run = $this->runProcess([...$this->wrapper('bin/test'), 'pgsql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $this->makeTempDir(),
            'ORRERY_STUB_STATUS' => '1',
            'ORRERY_STUB_EXIT' => '2',
            'ORRERY_TEST_DATABASE' => 'orrery_hf_keep',
        ]));

        self::assertSame(2, $run['exit'], 'bin/test must return the PHPUnit exit code: '.$run['stderr']);
        self::assertSame(
            ['bin/test: this run did not drop its explicit databases orrery_hf_keep and orrery_hf_keep_mirror (no --drop-database). They stay while the service keeps its data. To drop them, run: bin/test-db drop orrery_hf_keep --engine=pgsql'],
            self::keepWarnings($run['stderr']),
        );
        self::assertSame([], self::keepWarnings($run['stdout']), 'the warning went to stdout');
        self::assertContains('orrery_hf_keep', self::databases($this->registry, 'postgres'));
        self::assertContains('orrery_hf_keep_mirror', self::databases($this->registry, 'postgres'));
        self::assertSame([], self::drops($this->registry));
        self::assertSame([], self::registryData($this->registry)['leases']);

        // The printed command drops the pair (bin/test-db runs testdb.php).
        $drop = $this->testdb(['drop', 'orrery_hf_keep', '--engine=pgsql'], $this->fakeEnv($this->registry));
        self::assertSame(0, $drop['exit'], $drop['stderr']);
        self::assertNotContains('orrery_hf_keep', self::databases($this->registry, 'postgres'));
        self::assertNotContains('orrery_hf_keep_mirror', self::databases($this->registry, 'postgres'));
    }

    /**
     * Rejects: a warning on every server run, also when the release drops the databases
     * (--drop-database or a generated name), and a warning for a --no-provision run, whose
     * database no local registry or container holds.
     */
    public function test_no_keep_warning_when_the_run_keeps_no_database(): void
    {
        $env = ['ORRERY_TEST_PHPUNIT' => self::stubPath(), 'ORRERY_STUB_OUT' => $this->makeTempDir()];

        $dropped = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--drop-database', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env + [
            'ORRERY_TEST_DATABASE' => 'orrery_hf_drop',
        ]));
        self::assertSame(0, $dropped['exit'], $dropped['stderr']);
        self::assertSame([], self::keepWarnings($dropped['stderr']));
        self::assertNotContains('orrery_hf_drop', self::databases($this->registry, 'mysql'));

        $generated = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env));
        self::assertSame(0, $generated['exit'], $generated['stderr']);
        self::assertSame([], self::keepWarnings($generated['stderr']));

        $ci = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--no-provision', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env + [
            'ORRERY_TEST_DATABASE' => 'orrery_ci_1_2',
            'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
        ]));
        self::assertSame(0, $ci['exit'], $ci['stderr']);
        self::assertSame([], self::keepWarnings($ci['stderr']));
        self::assertStringNotContainsString('bin/test-db drop', $ci['stderr']);
    }

    /**
     * Rejects: a warning that reports a failed release (the lease, the names and the databases
     * stay for the recovery) as a successful retention, or a warning after a refused admission.
     */
    public function test_no_keep_warning_when_the_release_fails_or_the_admission_is_refused(): void
    {
        // A refused admission: another lease holds the explicit name.
        $supervisor = $this->spawnSleeper();
        $held = $this->testdb(['admit', 'run_1_0000000e', (string) $supervisor['pid'], $supervisor['start'], 'orrery_hf_held', '--explicit', '--engine=mysql'], $this->fakeEnv($this->registry));
        self::assertSame(0, $held['exit'], $held['stderr']);
        $refused = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $this->makeTempDir(),
            'ORRERY_TEST_DATABASE' => 'orrery_hf_held',
        ]));
        self::assertSame(73, $refused['exit'], $refused['stderr']);
        self::assertSame([], self::keepWarnings($refused['stderr']));
        $release = $this->testdb(['release', 'run_1_0000000e'], $this->fakeEnv($this->registry));
        self::assertSame(0, $release['exit'], $release['stderr']);
        $this->stopSleeper($supervisor);

        // A failed release: PHPUnit leaves a descendant, and the full process listing fails.
        $out = $this->makeTempDir();
        $mode = $this->makeTempDir().'/ps-mode';
        file_put_contents($mode, 'list-fails');
        $path = ['PATH' => $this->fakePsDir().':'.getenv('PATH'), 'ORRERY_FAKE_PS_FILE' => $mode];

        $run = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $path + [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_DESCENDANT' => '1',
            'ORRERY_TEST_DATABASE' => 'orrery_hf_failed',
        ]));

        self::assertSame(74, $run['exit'], $run['stderr']);
        self::assertStringContainsString('kept the owner record of run_', $run['stderr']);
        self::assertSame([], self::keepWarnings($run['stderr']), 'a failed release was reported as a kept database');
        $runId = self::readJson(self::stubRecords($out)[0])['env']['ORRERY_TEST_RUN_ID'];
        self::assertArrayHasKey($runId, self::registryData($this->registry)['leases']);

        // The recovery: with the listing back, stop the group and release the lease.
        unlink($mode);
        $stop = $this->testdb(['stop-children', $runId, '--grace=2'], $this->fakeEnv($this->registry, $path));
        self::assertSame(0, $stop['exit'], $stop['stderr']);
        $release = $this->testdb(['release', $runId], $this->fakeEnv($this->registry, $path));
        self::assertSame(0, $release['exit'], $release['stderr']);
    }

    /**
     * Rejects: a notice that claims that the pair was kept after the service lost its data (an
     * OOM stop of a tmpfs server): the release does not check the service, so the notice states
     * only that the run did not drop the pair. Also rejects a drop or a changed exit code then.
     */
    public function test_the_notice_claims_no_retention_when_the_service_stopped_during_the_run(): void
    {
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir();
        $wrapper = $this->startProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_STUB_STATUS' => '1',
            'ORRERY_STUB_EXIT' => '1',
            'ORRERY_TEST_DATABASE' => 'orrery_hf_lost',
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached'), 20.0, 'the stub did not start: '.$wrapper->getErrorOutput());
        self::assertContains('orrery_hf_lost', self::databases($this->registry, 'mysql'));

        // The server stops while PHPUnit runs, and its tmpfs data is gone.
        self::changeFakeState($this->registry, static function (array $state): array {
            $state['services']['mysql'] = array_replace($state['services']['mysql'], ['state' => 'exited', 'health' => '', 'databases' => []]);

            return $state;
        });
        touch($hold.'/release');
        $wrapper->wait();

        self::assertSame(1, $wrapper->getExitCode(), 'bin/test must return the PHPUnit exit code: '.$wrapper->getErrorOutput());
        $notices = self::keepWarnings($wrapper->getErrorOutput());
        self::assertCount(1, $notices, $wrapper->getErrorOutput());
        self::assertStringNotContainsString('kept', $notices[0]);
        self::assertStringContainsString('did not drop its explicit databases orrery_hf_lost and orrery_hf_lost_mirror', $notices[0]);
        self::assertStringContainsString('They stay while the service keeps its data.', $notices[0]);
        self::assertSame([], self::databases($this->registry, 'mysql'));
        self::assertSame([], self::drops($this->registry));
        self::assertSame([], self::registryData($this->registry)['leases']);
    }

    /**
     * The keep warnings of bin/test in an output.
     *
     * @return list<string>
     */
    private static function keepWarnings(string $output): array
    {
        return array_values(array_filter(
            explode("\n", $output),
            static fn (string $line): bool => str_contains($line, 'did not drop its explicit databases') || str_contains($line, 'kept the databases'),
        ));
    }

    /**
     * @return list<string>
     */
    private static function databases(string $registry, string $service): array
    {
        return self::fakeState($registry)['services'][$service]['databases'];
    }

    /**
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function drops(string $registry): array
    {
        $drops = [];

        foreach (self::fakeCalls($registry) as $call) {
            if ($call['op'] === 'drop_databases') {
                $drops[] = [$call['args']['service'], $call['args']['names']];
            }
        }

        return $drops;
    }
}
