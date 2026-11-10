<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Closure;
use Justrau\Orrery\Tests\Support\Tia\Recorder;
use LogicException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A real worker process for the tests.
 *
 *   $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0'], $env);
 *   self::assertSame(0, $worker->waitForExit(60));
 *
 * The child runs tests/Support/bin/artisan with this process's PHP binary,
 * never vendor/bin/testbench. start() returns only when the child is
 * registered, runs, and has the intended identity:
 *
 * 1. Environment handoff (environmentFor()): every inherited DB_*,
 *    DATABASE_URL, ORRERY_TEST_*, ORRERY_EXPECT_* and ORRERY_IDENTITY_LOG
 *    variable is unset, then $env->toProcessEnv() sets the complete
 *    harness configuration, then $extraEnv is applied and wins. A value of
 *    false unsets a variable. An $extraEnv value that changes the
 *    configuration makes the identity check below fail: that is how a test
 *    proves the check. The test variables of the worker CLI itself pass
 *    through $extraEnv only, for example [self::FOREIGN_QUEUE => '1'],
 *    which makes the child bind Laravel's own `database` connector (core
 *    spec C2). An inherited one is unset like every ORRERY_TEST_* variable.
 *    A test that opted into the shared file cache (TestCase::useSharedCache(),
 *    SharedCache) passes its $env: toProcessEnv() then sets
 *    ORRERY_TEST_SHARED_CACHE, so the child's default store is the same
 *    `file` store, and its identity line must name it.
 *    ORRERY_TEST_CLOCK_FILE is the active TestClock file of this test, when
 *    the test shifted a database clock.
 *    During bin/tia record, launch() also adds Recorder::childEnvironment()
 *    (XDEBUG_MODE and the ORRERY_TIA_* variables), so the child records the
 *    files that it runs for the test class that started it.
 * 2. Startup gate (ORRERY_START_GATE, see tests/bootstrap.php): the child
 *    waits before it loads any code. With ORRERY_TEST_RUN_ID set (MySQL,
 *    Postgres), bin/lib/testdb.php add-child records the child in the run's
 *    lease first. If that fails, the child is stopped and the gate never
 *    opens. Without a run id (SQLite, CI) the gate opens at once.
 * 3. Identity gate (ORRERY_IDENTITY_GATE): the child checks its own identity,
 *    prints its identity line, and waits. This process asserts the line
 *    against $env (IdentityRecord::assertMatches(), plus the child's own PID
 *    as SQLite evidence, or a server session id), and only then opens the
 *    gate. So a child with a wrong configuration is stopped before its
 *    command runs, before it can reserve a job. The child runs PHP_BINARY;
 *    ORRERY_EXPECT_PHP, when set, checks its version.
 *
 * Every wait has a deadline. stop() signals only this helper's own child,
 * after it checked that the PID still has the recorded start time: SIGTERM,
 * a grace period, then SIGKILL. TestCase::tearDown() stops every worker that
 * is still running (stopAll()) before the schema rolls back. A worker whose
 * stop fails stays recorded (tracked()), and then TestCase keeps the schema
 * and the barriers, and refuses every later test of the process.
 *
 * When PHP ends, Symfony's Process destructor ends a child that is still
 * recorded, through its own process handle. The handle names only that
 * child: its PID stays reserved until this process reaps it.
 */
final class WorkerProcess
{
    public const ARTISAN = 'tests/Support/bin/artisan';

    /** With '1', the worker CLI binds a foreign `database` connector: Laravel's own DatabaseQueue. */
    public const FOREIGN_QUEUE = 'ORRERY_TEST_FOREIGN_QUEUE';

    public const START_GATE = 'ORRERY_START_GATE';

    public const IDENTITY_GATE = 'ORRERY_IDENTITY_GATE';

    /** The deadline for the identity line after the start. */
    public const START_SECONDS = 60.0;

    /** The time between SIGTERM and SIGKILL. */
    public const STOP_GRACE = 5.0;

    /** How often stopAll() tries to stop a worker, and the pause between the tries. */
    public const STOP_ATTEMPTS = 3;

    public const STOP_RETRY_PAUSE = 0.5;

    private const OUTPUT_TAIL = 4000;

    /** @var array<int, self> the workers of this process that stop() has not stopped yet, by PID */
    private static array $started = [];

    private static ?self $last = null;

    private ?string $startTime = null;

    private bool $startGateOpened = false;

    private ?string $identityLine = null;

    private ?IdentityRecord $identity = null;

    /**
     * @param  list<string>  $command
     */
    private function __construct(
        private readonly Process $process,
        private readonly int $pid,
        private readonly string $gates,
        private readonly array $command,
    ) {}

    /**
     * Starts `tests/Support/bin/artisan <$artisanArgs>` and returns when the
     * child's identity matches $env (see the class comment): launch(), then
     * accept().
     *
     * @param  list<string>  $artisanArgs  the artisan arguments, for example ['queue:work', 'database', '--once']; options may come first
     * @param  array<string, string|false>  $extraEnv  more variables for the child; they win over the handoff; false unsets
     *
     * @throws HarnessRefusal when the child cannot be registered, fails its own check, or does not match $env; the child is then stopped
     */
    public static function start(array $artisanArgs, TestEnvironment $env, array $extraEnv = []): self
    {
        $worker = self::launch($artisanArgs, $env, $extraEnv);
        $worker->accept($env);

        return $worker;
    }

    /**
     * Steps 1 and 2 of the class comment, and the wait for the identity line.
     * The child then waits at its identity gate: it has run no command yet.
     * Tests of the gate itself use this; every other test uses start().
     *
     * @param  list<string>  $artisanArgs
     * @param  array<string, string|false>  $extraEnv
     *
     * @throws HarnessRefusal when the child cannot be registered or prints no identity line; the child is then stopped
     */
    public static function launch(array $artisanArgs, TestEnvironment $env, array $extraEnv = []): self
    {
        // Options may come before the command name, as on a real artisan
        // command line. The worker CLI resolves the name and refuses (exit 64)
        // a missing one and every command outside its allowlist.
        if ($artisanArgs === [] || ! array_is_list($artisanArgs) || array_filter($artisanArgs, 'is_string') !== $artisanArgs) {
            throw new LogicException('WorkerProcess needs the Artisan arguments (the command name and its options) as a non-empty list of strings.');
        }

        $childEnv = self::environmentFor($env, $extraEnv);
        $root = self::root();
        $gates = self::gateDirectory();
        $command = [PHP_BINARY, $root.'/'.self::ARTISAN, ...$artisanArgs];

        // During bin/tia record, the variables that make the child record its
        // files for the current test class; [] otherwise.
        $process = new Process($command, $root, array_replace($childEnv, [
            self::START_GATE => $gates.'/start',
            self::IDENTITY_GATE => $gates.'/identity',
        ], Recorder::childEnvironment()), null, null);

        try {
            $process->start();
            $pid = $process->getPid();

            // The child waits at its start gate, so it runs unless it could not start at all.
            if ($pid === null) {
                $process->wait();

                throw new HarnessRefusal(sprintf('The worker ended at once (exit %s): %s', var_export($process->getExitCode(), true), trim($process->getErrorOutput())));
            }
        } catch (Throwable $throwable) {
            @rmdir($gates);

            throw $throwable;
        }

        $worker = new self($process, $pid, $gates, $command);
        self::$started[$worker->pid] = $worker;
        self::$last = $worker;

        $worker->orStop(static function () use ($worker, $childEnv): void {
            $worker->startTime = self::processStart($worker->pid);

            if ($worker->startTime === null) {
                throw new HarnessRefusal('The worker ended before it could be recorded.');
            }

            $runId = $childEnv['ORRERY_TEST_RUN_ID'] ?? false;

            if (is_string($runId) && $runId !== '') {
                $worker->register($runId, $childEnv);
            }

            $worker->openGate('start');
            $worker->startGateOpened = true;
            $worker->waitFor(static fn (): bool => $worker->findIdentityLine() !== null, self::START_SECONDS, 'it printed its identity line');
        });

        return $worker;
    }

    /**
     * Step 3 of the class comment: asserts the child's identity line against
     * $env, then opens the identity gate, so the child runs its command.
     *
     * @throws HarnessRefusal when the identity does not match; the child is then stopped
     */
    public function accept(TestEnvironment $env): void
    {
        if ($this->identity !== null) {
            throw new LogicException('The worker identity was accepted already.');
        }

        $this->orStop(function () use ($env): void {
            $this->acceptIdentity($env);
            $this->openGate('identity');
        });
    }

    /**
     * The complete environment of a child for $env (the handoff of the class
     * comment). The gate variables are this helper's own: $extraEnv cannot
     * set them.
     *
     * @param  array<string, string|false>  $extraEnv
     * @return array<string, string|false>
     */
    public static function environmentFor(TestEnvironment $env, array $extraEnv = []): array
    {
        $child = [];

        foreach ([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)] as $name) {
            if (is_string($name) && preg_match('/^(DB_|ORRERY_TEST_|ORRERY_EXPECT_|ORRERY_IDENTITY_LOG$|DATABASE_URL$)/', $name) === 1) {
                $child[$name] = false;
            }
        }

        $child = array_replace($child, $env->toProcessEnv());
        // The test clock file of this test, when it shifted a database clock.
        $child[TestClock::VARIABLE] = TestClock::file() ?? false;

        foreach ($extraEnv as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || (! is_string($value) && $value !== false)) {
                throw new LogicException('An extra variable needs a name and a string value, or false to unset it.');
            }

            if (in_array($name, [self::START_GATE, self::IDENTITY_GATE, 'ORRERY_START_GATE_TIMEOUT'], true)) {
                throw new LogicException("{$name} belongs to WorkerProcess; a test cannot set it.");
            }

            $child[$name] = $value;
        }

        $child[self::START_GATE] = false;
        $child[self::IDENTITY_GATE] = false;
        $child['ORRERY_START_GATE_TIMEOUT'] = false;

        return $child;
    }

    /**
     * The most recent worker that launch() started in this process, also
     * when its start failed (for the tests of the start itself).
     */
    public static function last(): ?self
    {
        return self::$last;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * The start time of the child (`LC_ALL=C TZ=UTC ps -o lstart=`), the
     * second half of its identity.
     */
    public function startTime(): ?string
    {
        return $this->startTime;
    }

    public function startGateOpened(): bool
    {
        return $this->startGateOpened;
    }

    /**
     * The identity line that the child printed (launch() waits for it).
     */
    public function identityLine(): string
    {
        return $this->identityLine ?? throw new LogicException('The worker has not printed an identity line.');
    }

    public function identity(): IdentityRecord
    {
        return $this->identity ?? throw new LogicException('The worker has no accepted identity.');
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * The exit status, or null while the child runs. 128 + N after signal N.
     */
    public function exitCode(): ?int
    {
        if ($this->process->isRunning()) {
            return null;
        }

        return $this->process->getExitCode();
    }

    public function output(): string
    {
        return $this->process->getOutput();
    }

    public function errorOutput(): string
    {
        return $this->process->getErrorOutput();
    }

    /**
     * Returns when $condition is true. It fails when the deadline passes, or
     * when the child exits and the condition is still false.
     *
     * @param  callable(): bool  $condition
     *
     * @throws WaitTimeout
     */
    public function waitUntil(callable $condition, float $seconds, string $description): void
    {
        try {
            $this->waitFor($condition, $seconds, $description);
        } catch (WaitTimeout $timeout) {
            throw new WaitTimeout($timeout->getMessage()."\n".$this->describe(), 0, $timeout);
        }
    }

    /**
     * Waits until the child exits, and returns its exit status.
     *
     * @throws WaitTimeout when it still runs after $seconds (it keeps running; stop() or the test's tearDown() ends it)
     */
    public function waitForExit(float $seconds = 60.0): int
    {
        $deadline = microtime(true) + $seconds;

        while ($this->process->isRunning()) {
            if (microtime(true) >= $deadline) {
                throw new WaitTimeout(sprintf('The worker did not exit within %s seconds.', $seconds)."\n".$this->describe());
            }

            usleep(10_000);
        }

        $this->finished();

        return (int) $this->process->getExitCode();
    }

    /**
     * For Barrier::await(): a reason to stop waiting once the child has exited.
     *
     * @return Closure(): ?string
     */
    public function abandonIfExited(): Closure
    {
        return fn (): ?string => $this->process->isRunning() ? null : 'the worker exited. '.$this->describe();
    }

    /**
     * Stops the child: SIGTERM, $grace seconds, then SIGKILL. It signals only
     * this helper's own child, and only while its PID still has the recorded
     * start time. Returns the exit status.
     *
     * @throws HarnessRefusal when the PID has another start time; nothing is signalled then
     */
    public function stop(float $grace = self::STOP_GRACE): ?int
    {
        if ($this->process->isRunning()) {
            $current = self::processStart($this->pid);

            if ($current !== null && $current !== $this->startTime) {
                throw new HarnessRefusal(sprintf(
                    'PID %d started at %s, but the worker was recorded with %s. It is not signalled.',
                    $this->pid,
                    $current,
                    $this->startTime ?? 'no start time',
                ));
            }

            if ($current !== null) {
                $this->process->stop($grace, 9);
            }

            $deadline = microtime(true) + $grace + 5.0;

            while ($this->process->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }

            if ($this->process->isRunning()) {
                throw new HarnessRefusal("The worker (PID {$this->pid}) still runs after SIGKILL.");
            }
        }

        $this->finished();

        return $this->process->getExitCode();
    }

    /**
     * Stops every worker of this process that is still recorded. A stop that
     * fails is tried again, $attempts times in all, STOP_RETRY_PAUSE seconds
     * apart. A worker whose stop still fails stays recorded (tracked()),
     * because nothing proves that it exited: it can still use the schema and
     * its barriers. Only a verified exit removes a worker from the record.
     *
     * @throws HarnessRefusal naming every worker that is not proved stopped
     */
    public static function stopAll(float $grace = 3.0, int $attempts = self::STOP_ATTEMPTS): void
    {
        $failures = [];

        foreach (self::$started as $pid => $worker) {
            for ($attempt = 1; ; $attempt++) {
                try {
                    // A verified stop also removes the worker from the record.
                    $worker->stop($grace);

                    break;
                } catch (Throwable $throwable) {
                    if ($attempt >= $attempts) {
                        $failures[] = sprintf('PID %d (%d attempts): %s', $pid, $attempt, $throwable->getMessage());

                        break;
                    }

                    usleep((int) (self::STOP_RETRY_PAUSE * 1_000_000));
                }
            }
        }

        if ($failures !== []) {
            throw new HarnessRefusal("These workers are not proved stopped, so they stay recorded:\n  - ".implode("\n  - ", $failures));
        }

        self::$last = null;
    }

    /**
     * The workers of this process that are not proved stopped yet.
     *
     * @return list<self>
     */
    public static function tracked(): array
    {
        return array_values(self::$started);
    }

    /**
     * The command, the state and the tail of both outputs, for messages.
     */
    public function describe(): string
    {
        $tail = static fn (string $text): string => $text === ''
            ? '(none)'
            : (strlen($text) > self::OUTPUT_TAIL ? '...'.substr($text, -self::OUTPUT_TAIL) : rtrim($text));

        return sprintf(
            "Worker PID %d (%s): %s\n--- stdout ---\n%s\n--- stderr ---\n%s",
            $this->pid,
            $this->process->isRunning() ? 'running' : 'exit '.$this->process->getExitCode(),
            implode(' ', array_map(static fn (string $part): string => str_contains($part, ' ') ? "'{$part}'" : $part, $this->command)),
            $tail($this->process->getOutput()),
            $tail($this->process->getErrorOutput()),
        );
    }

    /**
     * The start of a live process (`LC_ALL=C TZ=UTC ps -o stat=,lstart=`,
     * normalised as bin/lib/testdb.php does), or null when it is gone or a
     * zombie.
     *
     * @throws HarnessRefusal when ps fails or prints something else
     */
    public static function processStart(int $pid): ?string
    {
        $ps = new Process(['ps', '-o', 'stat=,lstart=', '-p', (string) $pid], null, ['LC_ALL' => 'C', 'TZ' => 'UTC'], null, 20.0);
        $ps->run();
        $out = trim($ps->getOutput());

        // The only proof of absence: ps exits 1 and prints nothing.
        if ($ps->getExitCode() === 1 && $out === '' && trim($ps->getErrorOutput()) === '') {
            return null;
        }

        if (! $ps->isSuccessful() || preg_match('/^(\S+)\s+([A-Z][a-z]{2} [A-Z][a-z]{2} +\d{1,2} \d{2}:\d{2}:\d{2} \d{4})$/D', $out, $match) !== 1) {
            throw new HarnessRefusal(sprintf('Cannot read the start time of PID %d (ps exited %s): %s', $pid, var_export($ps->getExitCode(), true), $out.trim($ps->getErrorOutput())));
        }

        return str_starts_with($match[1], 'Z') ? null : trim((string) preg_replace('/\s+/', ' ', $match[2]));
    }

    /**
     * @param  array<string, string|false>  $childEnv
     */
    private function register(string $runId, array $childEnv): void
    {
        $registration = new Process(
            [PHP_BINARY, '-n', self::root().'/bin/lib/testdb.php', 'add-child', $runId, (string) $this->pid, (string) $this->startTime],
            self::root(),
            $childEnv,
            null,
            60.0,
        );
        $registration->run();

        $result = json_decode(trim($registration->getOutput()), true);
        $child = is_array($result) && is_array($result['child'] ?? null) ? $result['child'] : [];

        if (! $registration->isSuccessful() || ($child['pid'] ?? null) !== $this->pid || ($child['start'] ?? null) !== $this->startTime) {
            throw new HarnessRefusal(sprintf(
                'bin/lib/testdb.php add-child %s %d did not record the worker in the lease (exit %s): %s',
                $runId,
                $this->pid,
                var_export($registration->getExitCode(), true),
                trim($registration->getErrorOutput()) !== '' ? trim($registration->getErrorOutput()) : trim($registration->getOutput()),
            ));
        }
    }

    /**
     * Runs $step. When it throws, the child is stopped (its closed gates stay
     * closed) and a HarnessRefusal with the child's output is thrown.
     *
     * @param  callable(): void  $step
     */
    private function orStop(callable $step): void
    {
        try {
            $step();
        } catch (Throwable $throwable) {
            try {
                $this->stop();
            } catch (Throwable $stopFailure) {
                throw new HarnessRefusal('The worker did not start: '.$throwable->getMessage()."\nIt could not be stopped either: ".$stopFailure->getMessage(), 0, $throwable);
            }

            throw new HarnessRefusal('The worker did not start: '.$throwable->getMessage()."\n".$this->describe(), 0, $throwable);
        }
    }

    private function acceptIdentity(TestEnvironment $env): void
    {
        $record = IdentityRecord::fromLine((string) $this->identityLine);
        $record->assertMatches($env);

        // The line must be this child's own: its SQLite PID, or a server
        // session of its own.
        $differences = [];

        foreach (['primary', 'mirror'] as $side) {
            if ($env->isServer()) {
                $session = $record->get("{$side}.evidence.session");

                if (! is_int($session) || $session < 1) {
                    $differences[] = "{$side}.evidence.session: expected the server session id, got ".json_encode($session);
                }
            } elseif ($record->get("{$side}.evidence.pid") !== $this->pid) {
                $differences[] = "{$side}.evidence.pid: expected the worker's PID {$this->pid}, got ".json_encode($record->get("{$side}.evidence.pid"));
            }
        }

        if ($differences !== []) {
            throw new HarnessRefusal("The worker's identity line is not the worker's own:\n  - ".implode("\n  - ", $differences)."\n".$this->identityLine);
        }

        $this->identity = $record;
    }

    private function findIdentityLine(): ?string
    {
        if ($this->identityLine !== null) {
            return $this->identityLine;
        }

        $lines = explode("\n", $this->process->getOutput());
        array_pop($lines);

        foreach ($lines as $line) {
            if (str_starts_with($line, IdentityRecord::LINE_PREFIX)) {
                return $this->identityLine = $line;
            }
        }

        return null;
    }

    /**
     * @param  callable(): bool  $condition
     */
    private function waitFor(callable $condition, float $seconds, string $description): void
    {
        $deadline = microtime(true) + $seconds;

        while (true) {
            if ($condition()) {
                return;
            }

            if (! $this->process->isRunning()) {
                if ($condition()) {
                    return;
                }

                throw new WaitTimeout("The worker exited before {$description}.");
            }

            if (microtime(true) >= $deadline) {
                throw new WaitTimeout(sprintf('Timed out after %s seconds: the worker never reached the point where %s.', $seconds, $description));
            }

            usleep(10_000);
        }
    }

    private function openGate(string $name): void
    {
        if (@file_put_contents($this->gates.'/'.$name, '') === false) {
            throw new HarnessRefusal("Cannot open the {$name} gate in {$this->gates}.");
        }
    }

    /**
     * The child has exited: forget it and remove its gate directory.
     */
    private function finished(): void
    {
        unset(self::$started[$this->pid]);

        foreach (['start', 'identity'] as $gate) {
            @unlink($this->gates.'/'.$gate);
        }

        @rmdir($this->gates);
    }

    private static function gateDirectory(): string
    {
        $parent = realpath(sys_get_temp_dir());

        if ($parent === false) {
            throw new HarnessRefusal('The temporary directory '.sys_get_temp_dir().' does not exist.');
        }

        $directory = $parent.'/orrery-worker-'.bin2hex(random_bytes(8));
        $umask = umask(0077);

        try {
            if (! @mkdir($directory, 0700)) {
                throw new HarnessRefusal("Cannot create the gate directory {$directory}.");
            }
        } finally {
            umask($umask);
        }

        return $directory;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
