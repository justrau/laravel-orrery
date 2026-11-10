<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use Justrau\Orrery\Tests\Support\Barrier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A real worker: a bin/test run is
 * interrupted while a WorkerProcess worker still runs. The lease and both
 * database names must stay held until that worker has exited, also after
 * PHPUnit is gone.
 *
 * Run it with `composer test:docker` (group docker). It skips unless
 * ORRERY_DOCKER_TESTS=1, which only that script sets. The run uses a
 * generated database name, so it never collides with another run, and it
 * asserts only about its own lease. Every signal of the test goes to a
 * recorded PID with its recorded start time.
 */
#[Group('docker')]
final class WorkerInterruptDockerTest extends TestCase
{
    use RunsScripts;

    private const PROBE = 'tests/Scripts/Fixtures/WorkerInterruptProbe.php';

    /** bin/test's grace between SIGTERM and SIGKILL; the checks must end before it. */
    private const BIN_TEST_GRACE = 10.0;

    private ?Barrier $barrier = null;

    private ?string $out = null;

    /** @var array{pid: int, start: string}|null */
    private ?array $worker = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('ORRERY_DOCKER_TESTS') !== '1') {
            self::markTestSkipped('The Docker tests start and stop the orrery-testing containers. Run them with `composer test:docker`, which sets ORRERY_DOCKER_TESTS=1.');
        }

        exec('docker version --format "{{.Server.Version}}" 2>/dev/null', $output, $status);
        self::assertSame(0, $status, 'the docker group needs a running Docker daemon');
    }

    protected function tearDown(): void
    {
        // A held worker and a waiting PHPUnit can end by themselves first.
        if ($this->barrier !== null && is_dir($this->barrier->directory())) {
            $this->barrier->arrive('interrupt.hold.release');
        }

        if ($this->out !== null && is_dir($this->out)) {
            touch($this->out.'/phpunit.release');
        }

        $stopped = $this->stopScriptProcesses();

        // The worker is in the run's process group, which the shutdown above
        // stops. This is the last resort, by the recorded identity only.
        if ($this->worker !== null && self::startOf($this->worker['pid']) === $this->worker['start']) {
            self::signalIdentity($this->worker, SIGTERM);
            $this->waitUntil(fn (): bool => self::startOf($this->worker['pid']) !== $this->worker['start'], 5.0, 'the worker did not stop on SIGTERM');
        }

        $this->barrier?->remove();

        if (! $stopped) {
            self::fail('processes that this test started could not be confirmed stopped; the temporary directories were kept');
        }

        $this->removeScriptTempDirs();
        parent::tearDown();
    }

    public function test_an_interrupted_run_keeps_its_lease_and_names_until_its_worker_exits(): void
    {
        $this->barrier = Barrier::forTest();
        $this->out = $this->makeTempDir();
        $run = $this->startProcess([...$this->wrapper('bin/test'), 'mysql', '--php='.PHP_BINARY, self::PROBE], $this->env([
            'ORRERY_WORKER_PROBE_OUT' => $this->out,
            'ORRERY_WORKER_PROBE_BARRIER' => $this->barrier->directory(),
        ]));
        $supervisor = self::identityOf((int) $run->getPid());

        $this->waitUntil(fn (): bool => file_exists($this->out.'/probe.json') || ! $run->isRunning(), 120.0, 'the probe did not hold its worker');
        self::assertTrue($run->isRunning(), 'bin/test ended before the worker held: '.$run->getOutput().$run->getErrorOutput());

        $probe = self::readJson($this->out.'/probe.json');
        $phpunit = ['pid' => (int) $probe['phpunit']['pid'], 'start' => (string) $probe['phpunit']['start']];
        $worker = $this->worker = ['pid' => (int) $probe['worker']['pid'], 'start' => (string) $probe['worker']['start']];
        $runId = (string) $probe['run_id'];
        $base = (string) $probe['database'];
        $mirror = $base.'_mirror';
        self::assertMatchesRegularExpression('/^orrery_run_[0-9a-f]{8}$/', $base);

        // The worker is a recorded plain child of the lease, besides PHPUnit's process group.
        $lease = $this->projectStatus(['--no-services'])['leases'][$runId];
        $plain = array_values(array_map(
            static fn (array $child): array => ['pid' => $child['pid'], 'start' => $child['start']],
            array_filter($lease['children'], static fn (array $child): bool => ! ($child['group'] ?? false)),
        ));
        self::assertContains($worker, $plain);

        // The interrupt. bin/test's cleanup sends SIGTERM to PHPUnit's
        // process group: PHPUnit ends, and the daemon worker only stops after
        // its held job.
        self::assertTrue(self::signalIdentity($supervisor, SIGINT), 'bin/test is gone before the interrupt');
        $interrupted = microtime(true);
        $this->waitUntil(static fn (): bool => self::startOf($phpunit['pid']) !== $phpunit['start'], 15.0, 'PHPUnit did not end after the interrupt');
        self::assertSame($worker['start'], self::startOf($worker['pid']), 'the worker ended with PHPUnit');

        // PHPUnit is gone and the worker lives: the lease and both names stay.
        $status = $this->projectStatus(['--no-services']);
        self::assertArrayHasKey($runId, $status['leases'], 'the lease was released while the worker ran');
        self::assertTrue($status['leases'][$runId]['live']);
        self::assertContains($worker['pid'], $status['leases'][$runId]['alive']['children'], 'the registry does not count the worker as live');
        self::assertSame($runId, $status['names'][$base] ?? null);
        self::assertSame($runId, $status['names'][$mirror] ?? null);

        // Another checkout can neither take the name nor stop the project.
        $copy = $this->checkoutCopy();
        $sleeper = $this->spawnSleeper();
        $otherRun = sprintf('run_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $admit = $this->runProcess([PHP_BINARY, '-n', $copy.'/bin/lib/testdb.php', 'admit', $otherRun, (string) $sleeper['pid'], $sleeper['start'], $base, '--explicit', '--engine=mysql'], $this->env(), 60.0);
        self::assertSame(73, $admit['exit'], $admit['stderr']);
        self::assertStringContainsString($runId, $admit['stderr']);
        $create = $this->runProcess([PHP_BINARY, '-n', $copy.'/bin/lib/testdb.php', 'create', $base], $this->env(), 60.0);
        self::assertSame(73, $create['exit'], $create['stderr']);
        $down = $this->runProcess([PHP_BINARY, '-n', $copy.'/bin/lib/testdb.php', 'down', '--dry-run'], $this->env(), 60.0);
        self::assertSame(75, $down['exit'], $down['stderr']);
        self::assertStringContainsString($runId, $down['stderr']);
        $this->stopSleeper($sleeper);

        // All of that happened while the worker lived, inside bin/test's grace.
        self::assertSame($worker['start'], self::startOf($worker['pid']), 'the worker ended before the checks were done');
        self::assertLessThan(self::BIN_TEST_GRACE, microtime(true) - $interrupted, 'the checks took longer than bin/test\'s grace');
        self::assertTrue($run->isRunning(), 'bin/test ended while its worker ran');

        // The release: the worker finishes its job and exits. A status
        // without the lease must never be followed by a live worker.
        $this->barrier->arrive('interrupt.hold.release');
        $deadline = microtime(true) + 60.0;

        while ($run->isRunning()) {
            $status = $this->projectStatus(['--no-services']);

            if (! isset($status['leases'][$runId])) {
                self::assertNotSame($worker['start'], self::startOf($worker['pid']), 'the lease was released while the worker still ran');
            }

            if (microtime(true) >= $deadline) {
                self::fail('bin/test did not finish within 60 seconds after the release');
            }

            usleep(100_000);
        }

        self::assertSame(130, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        self::assertNotSame($worker['start'], self::startOf($worker['pid']), 'the worker outlived its run');

        // The cleanup is complete: no lease, no names, and the generated databases are dropped.
        $status = $this->projectStatus();
        self::assertArrayNotHasKey($runId, $status['leases']);
        self::assertArrayNotHasKey($base, $status['names']);
        self::assertArrayNotHasKey($mirror, $status['names']);
        self::assertNotContains($base, $status['services']['mysql']['databases']);
        self::assertNotContains($mirror, $status['services']['mysql']['databases']);
    }

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
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function projectStatus(array $options = []): array
    {
        $status = $this->runProcess([...$this->wrapper('bin/test-db'), 'status', ...$options], $this->env(), 120.0);
        self::assertSame(0, $status['exit'], $status['stderr']);

        return $status['json'];
    }

    /**
     * A second checkout: its own copy of the registry program and the
     * Compose file, as in a matrix copy.
     */
    private function checkoutCopy(): string
    {
        $copy = $this->makeTempDir('orrery-copy-');
        mkdir($copy.'/bin/lib', 0700, true);
        mkdir($copy.'/docker/testing', 0700, true);
        copy(self::testdbPath(), $copy.'/bin/lib/testdb.php');
        copy(self::repoRoot().'/docker/testing/compose.yaml', $copy.'/docker/testing/compose.yaml');

        return $copy;
    }
}
