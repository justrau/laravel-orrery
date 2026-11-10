<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * The run limits of bin/test: the hard timeout (--timeout) and the heavy-run
 * slot (--slot-wait), without Docker. Every run uses the fake backend and a
 * private test registry, so the slot of each test is in that registry, never
 * in the real one. PHPUnit is the stub of Fixtures/phpunit-stub.php.
 *
 * It is in the group scripts: `composer test:all` runs it. For this file
 * alone: `composer test -- --group scripts tests/Scripts/RunLimitsTest.php`.
 */
#[Group('scripts')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class RunLimitsTest extends TestCase
{
    use RunsScripts;

    private const SLOW_TEST = 'Fx\\Tests\\SlowTest::test_hangs';

    /** The --timeout of the timeout test, in seconds. */
    private const TIMEOUT = 5;

    /** The fallback wait for the slot helper in the slot release test, in seconds. */
    private const SLOT_RELEASE_SECONDS = 60;

    private const EVENTS = [
        'Test Runner Started',
        'Test Preparation Started (Fx\\Tests\\FastTest::test_done)',
        'Test Prepared (Fx\\Tests\\FastTest::test_done)',
        'Test Finished (Fx\\Tests\\FastTest::test_done)',
        'Test Preparation Started (Fx\\Tests\\SlowTest::test_hangs)',
        'Test Prepared (Fx\\Tests\\SlowTest::test_hangs)',
    ];

    private string $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->makeTempDir('orrery-limits-').'/registry';
    }

    protected function tearDown(): void
    {
        $this->cleanUpScripts();
        parent::tearDown();
    }

    // The timeout

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function timedModes(): iterable
    {
        yield 'mysql with a lease' => ['mysql', []];
        yield 'a sqlite file' => ['sqlite', ['--sqlite-file']];
        yield 'in-memory sqlite' => ['sqlite', []];
    }

    /**
     * Rejects: a timeout that only prints and lets PHPUnit run on; one that
     * exits at once and leaves the process group, the lease, the generated
     * databases or the run's directory behind; a line that names the last
     * finished test instead of the running one; an exit code of PHPUnit's.
     *
     * @param  list<string>  $options
     */
    #[DataProvider('timedModes')]
    public function test_a_run_past_its_timeout_names_the_running_test_is_stopped_and_exits_124(string $engine, array $options): void
    {
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir('orrery-hold-');
        // The limit must outlast the start of the stub's first test: bin/test counts it in whole
        // seconds from the spawn (bash SECONDS), so --timeout=N leaves between N - 1 and N seconds
        // for the owner join, the startup gate and the stub's bootstrap. With 2, a loaded machine
        // sometimes passed the deadline before the first test started.
        $result = $this->runProcess([...$this->wrapper('bin/test'), $engine, ...$options, '--timeout='.self::TIMEOUT, '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            // Slow to stop: the cleanup needs its SIGKILL after the grace.
            'ORRERY_STUB_IGNORE_TERM' => '1',
            'ORRERY_TEST_GRACE' => '0.3',
            'ORRERY_STUB_EVENTS' => json_encode(self::EVENTS),
        ]));

        self::assertSame(124, $result['exit'], $result['stderr']);
        self::assertStringContainsString('bin/test: timeout: the run passed --timeout='.self::TIMEOUT.' s while it ran '.self::SLOW_TEST.'; stopping it (exit 124)', $result['stderr']);
        self::assertSame(1, substr_count($result['stderr'], 'timeout:'), $result['stderr']);
        self::assertLessThan(20.0, $result['seconds']);
        self::assertFileExists($hold.'/reached', 'PHPUnit did not run');

        $records = self::stubRecords($out);
        self::assertCount(1, $records);
        $stub = self::readJson($records[0]);
        self::assertFalse(self::isAlive($stub['pid']), 'PHPUnit outlived the timeout');
        self::assertFalse(self::isAlive($stub['ppid']), 'the anchor outlived the timeout');
        self::assertDirectoryDoesNotExist($stub['run_dir'], 'the run directory stayed');

        if ($engine === 'mysql') {
            self::assertSame([], self::registryData($this->registry)['leases'], 'the lease stayed');
            $names = [$stub['env']['ORRERY_TEST_DATABASE'], $stub['env']['ORRERY_TEST_DATABASE'].'_mirror'];
            self::assertContains(['op' => 'drop_databases', 'names' => $names], array_map(static fn (array $call): array => ['op' => $call['op'], 'names' => $call['args']['names'] ?? null], self::fakeCalls($this->registry)));
            self::assertNull($this->registryStatus()['slot']['holder'], 'the slot stayed held');
        }
    }

    /**
     * Rejects: a reader that takes the last line with a test name (here a
     * finished test), one that stops at the first started test, or one
     * that fails on an event log that does not exist.
     */
    public function test_the_running_test_comes_from_the_last_started_test_of_the_event_log(): void
    {
        $dir = $this->makeTempDir();
        $cases = [
            'running' => [self::EVENTS, 'while it ran '.self::SLOW_TEST],
            'between tests' => [[...self::EVENTS, 'Test Passed (Fx\\Tests\\SlowTest::test_hangs)', 'Test Finished (Fx\\Tests\\SlowTest::test_hangs)'], 'after '.self::SLOW_TEST.' finished, between tests'],
            'a data set' => [['Test Preparation Started (Fx\\Tests\\T::test_x#with (parentheses))'], 'while it ran Fx\\Tests\\T::test_x#with (parentheses)'],
            'no test' => [['Test Runner Started'], 'before the first test started'],
        ];

        foreach ($cases as $name => [$lines, $text]) {
            file_put_contents($dir.'/events.txt', implode("\n", $lines)."\n");
            $result = $this->testdb(['running-test', $dir.'/events.txt'], $this->scriptEnv());
            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertSame($text, $result['json']['text'], $name);
        }

        $missing = $this->testdb(['running-test', $dir.'/missing.txt'], $this->scriptEnv());
        self::assertSame(0, $missing['exit'], $missing['stderr']);
        self::assertSame('before the first test started', $missing['json']['text']);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: int}>
     */
    public static function timeoutDefaults(): iterable
    {
        yield 'in-memory sqlite' => [['sqlite'], 600];
        yield 'a sqlite file' => [['sqlite', '--sqlite-file'], 900];
        yield 'a sqlite file in the fresh mode' => [['sqlite', '--sqlite-file', '--schema=fresh'], 900];
        yield 'mysql in the reuse mode' => [['mysql'], 2400];
        yield 'pgsql in the fresh mode' => [['pgsql', '--schema=fresh'], 3600];
        yield 'no-provision (fresh)' => [['mysql', '--no-provision'], 3600];
        yield 'an explicit limit' => [['pgsql', '--timeout=45'], 45];
        yield 'off' => [['mysql', '--timeout=0'], 0];
    }

    /**
     * Rejects: one default for every mode, a fresh-mode server run with the
     * reuse limit, and a limit of 0 that still asks PHPUnit for an event
     * log (or that times out at once).
     *
     * @param  list<string>  $arguments
     */
    #[DataProvider('timeoutDefaults')]
    public function test_the_timeout_follows_the_engine_and_the_schema_mode(array $arguments, int $expected): void
    {
        $out = $this->makeTempDir();
        $result = $this->runProcess([...$this->wrapper('bin/test'), ...$arguments, '--php='.PHP_BINARY, '--filter', 'SomeTest'], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_TEST_DATABASE' => in_array('--no-provision', $arguments, true) ? 'orrery_limits_ci' : false,
            'ORRERY_TEST_TOKEN' => in_array('--no-provision', $arguments, true) ? str_repeat('a', 32) : false,
        ]));

        self::assertSame(0, $result['exit'], $result['stderr']);
        $stub = self::readJson(self::stubRecords($out)[0]);
        self::assertSame((string) $expected, $stub['env']['ORRERY_RUN_TIMEOUT']);

        if ($expected === 0) {
            self::assertSame(['--filter', 'SomeTest'], $stub['argv']);
        } else {
            self::assertSame(['--log-events-text', $stub['run_dir'].'/events.txt', '--filter', 'SomeTest'], $stub['argv']);
        }
    }

    /**
     * Rejects: a limit that accepts a fraction, a sign or text, which the
     * shell's arithmetic cannot use.
     */
    public function test_invalid_limits_are_refused_before_anything_starts(): void
    {
        $out = $this->makeTempDir();

        foreach (['--timeout=1.5', '--timeout=-1', '--timeout=', '--timeout=1234567', '--slot-wait=soon', '--slot-wait='] as $option) {
            $result = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', $option, '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
                'ORRERY_TEST_PHPUNIT' => self::stubPath(),
                'ORRERY_STUB_OUT' => $out,
            ]));

            self::assertSame(64, $result['exit'], $option.': '.$result['stderr']);
        }

        self::assertSame([], self::stubRecords($out));
        self::assertFileDoesNotExist($this->registry.'/registry.json', 'a refused run reached the registry');
    }

    // The heavy-run slot

    /**
     * Rejects: a heavy run that does not take the slot, one that waits
     * after its admission (it would hold a lease and names while it waits),
     * a waiter that prints no holder, a status without the holder or the
     * waiter, and a waiter that does not start when the slot is free.
     */
    public function test_a_second_heavy_run_waits_for_the_slot_and_runs_when_the_first_ends(): void
    {
        $outA = $this->makeTempDir();
        $outB = $this->makeTempDir();
        $hold = $this->makeTempDir('orrery-hold-');
        $first = $this->startProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $outA,
            'ORRERY_STUB_HOLD' => $hold,
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached') || ! $first->isRunning(), 30.0, 'the first run did not reach its hold');
        self::assertTrue($first->isRunning(), $first->getErrorOutput());
        $stubA = self::readJson(self::stubRecords($outA)[0]);
        $runA = $stubA['env']['ORRERY_TEST_RUN_ID'];
        $tokenA = $stubA['env']['ORRERY_SLOT_TOKEN'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $tokenA);

        $second = $this->startProcess([...$this->wrapper('bin/test'), 'pgsql', '--slot-wait=60', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $outB,
            'ORRERY_TEST_SLOT_REPORT_SECONDS' => '1',
        ]));
        $this->waitUntil(static fn (): bool => substr_count($second->getErrorOutput(), 'waiting for the heavy-run slot') >= 2 || ! $second->isRunning(), 20.0, 'the second run did not report its wait twice');
        self::assertTrue($second->isRunning(), $second->getErrorOutput());
        self::assertStringContainsString("waiting for the heavy-run slot, held by run {$runA} (pid {$first->getPid()}, ".self::repoRoot().': bin/test mysql --php='.PHP_BINARY.')', $second->getErrorOutput());
        self::assertSame([], self::stubRecords($outB), 'the second run started PHPUnit while the slot was held');
        self::assertSame([$runA], array_keys(self::registryData($this->registry)['leases']), 'the waiting run took a lease');

        $slot = $this->registryStatus()['slot'];
        self::assertSame($runA, $slot['holder']['run_id']);
        self::assertSame($tokenA, $slot['holder']['token']);
        self::assertCount(1, $slot['waiters']);
        self::assertSame('bin/test pgsql --slot-wait=60 --php='.PHP_BINARY, $slot['waiters'][0]['command']);
        self::assertSame($second->getPid(), $slot['waiters'][0]['pid']);

        touch($hold.'/release');
        $first->wait();
        self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $second->wait();
        self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());

        $stubB = self::readJson(self::stubRecords($outB)[0]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $stubB['env']['ORRERY_SLOT_TOKEN']);
        self::assertNotSame($tokenA, $stubB['env']['ORRERY_SLOT_TOKEN']);
        self::assertSame(['holder' => null, 'waiters' => []], $this->registryStatus()['slot']);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function slotWaits(): iterable
    {
        yield 'no wait' => [0];
        yield 'two seconds' => [2];
    }

    /**
     * Rejects: a wait without a limit, a limit that admits the run anyway,
     * and a refused run that leaves a lease, a database, a waiter record
     * or its directory behind.
     */
    #[DataProvider('slotWaits')]
    public function test_a_run_that_cannot_get_the_slot_within_slot_wait_exits_72_without_an_admission(int $wait): void
    {
        $holder = $this->holdSlot($this->fakeEnv($this->registry));
        $out = $this->makeTempDir();
        $result = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--slot-wait='.$wait, '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
        ]));

        self::assertSame(72, $result['exit'], $result['stderr']);
        self::assertStringContainsString('the heavy-run slot is held by run run_'.getmypid().'_', $result['stderr']);
        self::assertStringContainsString("(--slot-wait={$wait})", $result['stderr']);
        self::assertGreaterThanOrEqual((float) $wait, $result['seconds']);
        self::assertLessThan($wait + 8.0, $result['seconds']);
        self::assertSame([], self::stubRecords($out));
        self::assertSame([], $this->leases());
        self::assertNotContains('create_databases', self::operations(self::fakeCalls($this->registry)));
        self::assertSame([], glob($this->scriptTmpDir.'/orrery-run.*') ?: [], 'the refused run left its directory behind');
        $slot = $this->registryStatus()['slot'];
        self::assertSame($holder, $slot['holder']['token']);
        self::assertSame([], $slot['waiters']);
    }

    /**
     * Rejects: a nested run that waits for its own ancestor (it would wait
     * until --slot-wait, or forever), a token that bin/test or the stub
     * chain strips, and a nested run that takes a new token.
     */
    public function test_a_nested_run_of_the_holder_enters_the_slot_at_once(): void
    {
        $out = $this->makeTempDir();
        $inner = $this->makeTempDir();
        $hold = $this->makeTempDir('orrery-hold-');
        $outer = $this->startProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
            'ORRERY_STUB_NESTED' => 'pgsql --slot-wait=0 --drop-database',
            // The outer run holds its own name.
            'ORRERY_STUB_NESTED_ENV' => json_encode(['ORRERY_TEST_DATABASE' => 'orrery_limits_inner']),
            'ORRERY_STUB_INNER_OUT' => $inner,
        ]));
        $this->waitUntil(static fn (): bool => (glob($out.'/nested-*.json') ?: []) !== [] || ! $outer->isRunning(), 30.0, 'the nested run did not end');
        $nested = self::readJson((glob($out.'/nested-*.json') ?: [])[0]);
        self::assertSame(0, $nested['exit'], 'the nested run did not enter the slot');

        $outerStub = self::readJson(self::stubRecords($out)[0]);
        $innerStub = self::readJson(self::stubRecords($inner)[0]);
        self::assertSame($outerStub['env']['ORRERY_SLOT_TOKEN'], $innerStub['env']['ORRERY_SLOT_TOKEN']);
        self::assertNotSame($outerStub['env']['ORRERY_TEST_RUN_ID'], $innerStub['env']['ORRERY_TEST_RUN_ID']);
        self::assertSame($outerStub['env']['ORRERY_TEST_RUN_ID'], $this->registryStatus()['slot']['holder']['run_id'], 'the nested run changed the holder');

        touch($hold.'/release');
        $outer->wait();
        self::assertSame(0, $outer->getExitCode(), $outer->getErrorOutput());
    }

    /**
     * Rejects: a slot that accepts any token (a foreign or a guessed one), and
     * a stale token that makes a run skip the slot when it is free.
     */
    public function test_only_the_token_of_the_live_holder_enters(): void
    {
        $out = $this->makeTempDir();
        $env = ['ORRERY_TEST_PHPUNIT' => self::stubPath(), 'ORRERY_STUB_OUT' => $out];
        $holder = $this->holdSlot($this->fakeEnv($this->registry));

        $foreign = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--slot-wait=0', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env + ['ORRERY_SLOT_TOKEN' => str_repeat('b', 32)]));
        self::assertSame(72, $foreign['exit'], $foreign['stderr']);

        $entered = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--slot-wait=0', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env + ['ORRERY_SLOT_TOKEN' => $holder]));
        self::assertSame(0, $entered['exit'], $entered['stderr']);

        $this->releaseSlot();
        $stale = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--slot-wait=0', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $env + ['ORRERY_SLOT_TOKEN' => $holder, 'ORRERY_STUB_STATUS' => '1']));
        self::assertSame(0, $stale['exit'], $stale['stderr']);
        $records = self::stubRecords($out);
        self::assertCount(2, $records);
        $tokens = array_map(static fn (string $file): string => self::readJson($file)['env']['ORRERY_SLOT_TOKEN'], $records);
        self::assertContains($holder, $tokens);
        $fresh = array_values(array_diff($tokens, [$holder]));
        self::assertCount(1, $fresh, 'the run with the stale token did not take a new token');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fresh[0]);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: array<string, string>}>
     */
    public static function runsWithoutASlot(): iterable
    {
        yield 'in-memory sqlite' => [['sqlite'], []];
        yield 'a sqlite file' => [['sqlite', '--sqlite-file'], []];
        yield 'no-provision' => [['pgsql', '--no-provision'], ['ORRERY_TEST_DATABASE' => 'orrery_limits_ci', 'ORRERY_TEST_TOKEN' => '0123456789abcdef0123456789abcdef']];
    }

    /**
     * Rejects: a slot for every engine (these runs would exit 72 here), and
     * a bin/test that strips the inherited token on its way to PHPUnit.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $extra
     */
    #[DataProvider('runsWithoutASlot')]
    public function test_runs_without_a_lease_need_no_slot_and_pass_the_token_on(array $arguments, array $extra): void
    {
        $holder = $this->holdSlot($this->fakeEnv($this->registry));
        $out = $this->makeTempDir();
        $result = $this->runProcess([...$this->wrapper('bin/test'), ...$arguments, '--slot-wait=0', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $extra + [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_SLOT_TOKEN' => $holder,
        ]));

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame($holder, self::readJson(self::stubRecords($out)[0])['env']['ORRERY_SLOT_TOKEN']);

        $plain = $this->runProcess([...$this->wrapper('bin/test'), ...$arguments, '--slot-wait=0', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, $extra + [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $this->makeTempDir(),
        ]));
        self::assertSame(0, $plain['exit'], $plain['stderr']);
        self::assertSame([], $this->registryStatus()['slot']['waiters']);
    }

    /**
     * Rejects: a slot kept in a file without a lock (a record of a dead
     * holder would block every later run), and a helper that outlives its
     * killed bin/test. The helper has two signs of that end (its stdin pipe
     * ends, and its parent changes), so it still passes when only one of
     * them works: when PHPUnit inherits the pipe, or without posix.
     */
    public function test_a_killed_bin_test_frees_the_slot(): void
    {
        $out = $this->makeTempDir();
        $hold = $this->makeTempDir('orrery-hold-');
        $first = $this->startProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $out,
            'ORRERY_STUB_HOLD' => $hold,
        ]));
        $this->waitUntil(static fn (): bool => file_exists($hold.'/reached') || ! $first->isRunning(), 30.0, 'the first run did not reach its hold');
        self::assertTrue($first->isRunning(), $first->getErrorOutput());
        $identity = self::identityOf((int) $first->getPid());

        // The supervisor dies without its cleanup; its PHPUnit still holds.
        self::assertTrue(self::signalIdentity($identity, SIGKILL));
        $this->waitUntil(static fn (): bool => ! $first->isRunning(), 10.0, 'the killed run did not end');

        $second = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--slot-wait=10', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $this->makeTempDir(),
        ]));
        self::assertSame(0, $second['exit'], $second['stderr']);
        self::assertLessThan(8.0, $second['seconds'], 'the second run waited for the slot of a dead holder');
    }

    /**
     * Rejects: a slot helper that keeps a copy of its own pipe open (bash 3.2
     * keeps one for a redirection on an exec), so that only the 5-second
     * fallback of the cleanup ends it, and a run that ends with its helper
     * still running.
     */
    #[DataProvider('bashVersions')]
    public function test_bin_test_gives_the_slot_up_at_once_when_it_ends(string $label, ?string $bash): void
    {
        $this->useBash($label, $bash);
        $result = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $this->makeTempDir(),
            // A helper that only the fallback ends makes the run last this long. The whole run
            // without it stays far below, also on a loaded machine (a fixed 5 s fallback did not).
            'ORRERY_TEST_SLOT_RELEASE_MS' => (string) (self::SLOT_RELEASE_SECONDS * 1000),
        ]));

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertLessThan(self::SLOT_RELEASE_SECONDS / 2, $result['seconds'], 'the slot helper did not end with its pipe');
        self::assertNull($this->registryStatus()['slot']['holder']);
        self::assertFileDoesNotExist($this->registry.'/slot.json');
    }

    /**
     * Rejects: a test helper that releases the slot only in
     * stopScriptProcesses(). The tearDown of TestDbDockerTest takes the slot
     * again after it (its drops use env()), and that holder blocked the next
     * docker test until --slot-wait.
     */
    public function test_a_slot_taken_late_in_a_teardown_is_released_with_the_directories(): void
    {
        $this->holdSlot($this->fakeEnv($this->registry));
        self::assertTrue($this->stopScriptProcesses());
        $this->holdSlot($this->fakeEnv($this->registry));
        $late = $this->slotProcess();
        self::assertNotNull($late);

        $this->removeScriptTempDirs();

        self::assertFalse($late->isRunning(), 'the slot taken after stopScriptProcesses() is still held');
        self::assertNull($this->slotProcess());
    }

    /**
     * Rejects: a slot whose release depends on the holder's own cleanup.
     */
    public function test_a_killed_slot_holder_frees_the_slot(): void
    {
        $this->holdSlot($this->fakeEnv($this->registry));
        $process = $this->slotProcess();
        self::assertNotNull($process);
        self::assertNotNull($this->registryStatus()['slot']['holder']);

        self::assertTrue(self::signalIdentity(self::identityOf((int) $process->getPid()), SIGKILL));
        $this->waitUntil(static fn (): bool => ! $process->isRunning(), 10.0, 'the slot helper did not end');

        // Its record stays, but nothing holds its lock any more.
        self::assertFileExists($this->registry.'/slot.json');
        self::assertNull($this->registryStatus()['slot']['holder']);
        $run = $this->runProcess([...$this->wrapper('bin/test'), 'mysql', '--slot-wait=0', '--php='.PHP_BINARY], $this->fakeEnv($this->registry, [
            'ORRERY_TEST_PHPUNIT' => self::stubPath(),
            'ORRERY_STUB_OUT' => $this->makeTempDir(),
        ]));
        self::assertSame(0, $run['exit'], $run['stderr']);
    }

    /**
     * Rejects: a slot helper environment of the docker tests that drops the
     * inherited ORRERY_SLOT_TOKEN (the scrubbed `false` wins over it). Under
     * a holder, for example a bin/matrix leg, the helper then waits for the
     * slot of its own ancestor: in the wave-3 matrix every docker run waited
     * until bin/test's --timeout (1,800 s).
     */
    public function test_the_docker_slot_helper_enters_with_the_inherited_token(): void
    {
        // The outer holder (bin/matrix): its own helper in the test registry.
        $out = $this->makeTempDir('orrery-slot-').'/outer.out';
        $input = new InputStream();
        $outer = new Process([
            PHP_BINARY, '-n', self::testdbPath(), 'slot-hold',
            sprintf('--run-id=run_%d_%s', getmypid(), bin2hex(random_bytes(4))),
            '--pid='.getmypid(),
            '--checkout='.self::repoRoot(),
            '--command=bin/matrix (test)',
            '--wait=0',
            '--out='.$out,
        ], self::repoRoot(), $this->fakeEnv($this->registry), $input, null);
        $outer->start();

        try {
            $this->waitUntil(static fn (): bool => file_exists($out) || ! $outer->isRunning(), 30.0, 'the outer helper did not take the slot');
            $token = self::readJson($out)['token'] ?? null;
            self::assertIsString($token, $outer->getErrorOutput());

            putenv('ORRERY_SLOT_TOKEN='.$token);

            try {
                // No wait: a helper without the token fails at once (exit 72).
                $env = $this->heavySlotEnv(['ORRERY_REGISTRY_TEST' => '1', 'ORRERY_REGISTRY_DIR' => $this->registry], 0);
            } finally {
                putenv('ORRERY_SLOT_TOKEN');
            }

            self::assertSame(['ORRERY_SLOT_TOKEN' => $token], $env);
            self::assertSame($token, $this->registryStatus()['slot']['holder']['token'], 'the nested entry changed the holder');
        } finally {
            $input->close();
            $outer->setTimeout(10.0);
            $outer->wait();
        }
    }

    /**
     * The leases of the test registry: none before the first admission.
     *
     * @return array<string, mixed>
     */
    private function leases(): array
    {
        return is_file($this->registry.'/registry.json') ? self::registryData($this->registry)['leases'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function registryStatus(): array
    {
        $status = $this->testdb(['status', '--no-services'], $this->fakeEnv($this->registry));
        self::assertSame(0, $status['exit'], $status['stderr']);

        return $status['json'];
    }
}
