<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts\Concerns;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Helpers for the tests of bin/lib/testdb.php, bin/test-db and bin/test.
 *
 * Every child process gets an environment without the inherited database,
 * registry and gate variables, so an outer bin/test run cannot leak into it.
 * Every temporary directory and started process is removed in tearDown.
 *
 * Each test owns a private owner record (see bin/lib/testdb.php). The bin/test
 * runs that it starts, at any depth, record their process groups and their
 * directories in it, after the records of any bin/test above this PHPUnit
 * process (ORRERY_OWNERS). tearDown shuts it down, so it stops the groups of
 * runs whose supervisor died, and it removes their directories last.
 */
trait RunsScripts
{
    /** @var list<string> */
    private array $scriptTempDirs = [];

    /** @var list<Process> */
    private array $scriptProcesses = [];

    private ?string $scriptTmpDir = null;

    /** The owner entry ("<run id> <directory>") of this test's record. */
    private ?string $scriptOwner = null;

    /** The interpreter of the wrapper scripts for this test (see useBash()). */
    private ?string $scriptBash = null;

    /** The helper that holds the heavy-run slot for this test (see holdSlot()). */
    private ?Process $scriptSlot = null;

    /** Its stdin: the helper holds the slot until this stream is closed. */
    private ?InputStream $scriptSlotInput = null;

    /** The slot token of this test, from holdSlot(). */
    private ?string $scriptSlotToken = null;

    /** bin/test's grace before SIGKILL, plus a margin for its confirmation. */
    private const TEARDOWN_WAIT = 14.0;

    /** The longest time that wait() waits for a process of startProcess(). */
    private const PROCESS_TIMEOUT = 300.0;

    /** The poll interval of the test-mode runs (ORRERY_TEST_POLL_MS). */
    protected const TEST_POLL_MS = 20;

    protected static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected static function testdbPath(): string
    {
        return self::repoRoot().'/bin/lib/testdb.php';
    }

    protected static function stubPath(): string
    {
        return self::repoRoot().'/tests/Scripts/Fixtures/phpunit-stub.php';
    }

    protected static function descendantPath(): string
    {
        return self::repoRoot().'/tests/Scripts/Fixtures/descendant.php';
    }

    protected static function groupPath(): string
    {
        return self::repoRoot().'/tests/Scripts/Fixtures/group.php';
    }

    protected static function watcherPath(): string
    {
        return self::repoRoot().'/tests/Scripts/Fixtures/watcher.php';
    }

    /**
     * The command that runs a wrapper script (bin/test or bin/test-db). The
     * interpreter is the one that useBash() selected, else ORRERY_TEST_BASH,
     * else the shebang. scriptEnv() passes it on as ORRERY_TEST_BASH, so the
     * stub's nested bin/test uses it too.
     *
     * @return list<string>
     */
    protected function wrapper(string $script): array
    {
        $bash = $this->selectedBash();
        $path = self::repoRoot().'/'.$script;

        return $bash !== null ? [$bash, $path] : [$path];
    }

    protected function selectedBash(): ?string
    {
        if ($this->scriptBash !== null) {
            return $this->scriptBash;
        }

        $bash = getenv('ORRERY_TEST_BASH');

        return is_string($bash) && $bash !== '' ? $bash : null;
    }

    /**
     * The process controls run with bash 3.2 (macOS /bin/bash) and bash 5.
     * A version that is not installed is null, and its data sets skip.
     *
     * @return array{'bash 3.2': string|null, 'bash 5': string|null}
     */
    protected static function bashes(): array
    {
        static $found = null;

        if ($found !== null) {
            return $found;
        }

        $found = ['bash 3.2' => null, 'bash 5' => null];

        foreach (['/bin/bash', '/usr/bin/bash', '/opt/homebrew/bin/bash', '/usr/local/bin/bash'] as $candidate) {
            if (! is_executable($candidate)) {
                continue;
            }

            $version = trim((string) shell_exec(escapeshellarg($candidate).' -c \'echo "${BASH_VERSINFO[0]}.${BASH_VERSINFO[1]}"\' 2>/dev/null'));

            if ($version === '3.2') {
                $found['bash 3.2'] ??= $candidate;
            } elseif ((int) $version >= 5) {
                $found['bash 5'] ??= $candidate;
            }
        }

        return $found;
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function bashVersions(): iterable
    {
        foreach (self::bashes() as $label => $path) {
            yield $label => [$label, $path];
        }
    }

    /**
     * Every case once per bash version: the label and the path are added
     * as the last two arguments.
     *
     * @param  iterable<string, list<mixed>>  $cases
     * @return iterable<string, list<mixed>>
     */
    protected static function withBashes(iterable $cases): iterable
    {
        foreach ($cases as $name => $arguments) {
            foreach (self::bashes() as $label => $path) {
                yield "{$name}, {$label}" => [...$arguments, $label, $path];
            }
        }
    }

    protected function useBash(string $label, ?string $path): void
    {
        if ($path === null) {
            self::markTestSkipped("{$label} is not installed");
        }

        $this->scriptBash = $path;
    }

    /**
     * The command line of a live wrapper process starts with the selected
     * interpreter (the shebang runs it as "bash <script>" otherwise).
     */
    protected function assertRunsWithSelectedBash(int $pid): void
    {
        $bash = $this->selectedBash();
        $command = trim((string) shell_exec('ps -o command= -p '.$pid.' 2>/dev/null'));

        self::assertNotSame('', $command, "process {$pid} is not running");
        self::assertStringEndsWith('/bin/test', explode(' ', $command)[1] ?? '', "process {$pid} is not a bin/test: {$command}");

        if ($bash !== null) {
            self::assertStringStartsWith($bash.' ', $command, "the nested bin/test does not run with {$bash}");
        }
    }

    /**
     * The fixed registry path, never derived from TMPDIR.
     */
    protected static function canonicalRegistry(): string
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) trim((string) shell_exec('id -u'));

        return '/tmp/orrery-testing-'.$uid;
    }

    /**
     * @param  array<string, string|false>  $extra
     * @return array<string, string|false>
     */
    protected function scriptEnv(array $extra = []): array
    {
        $env = self::scrubbedEnv();
        // A killed bin/test cannot remove its temporary directories, so the
        // children work in a TMPDIR that tearDown removes.
        $env['TMPDIR'] = ($this->scriptTmpDir ??= $this->makeTempDir('orrery-tmp-')).'/';
        // The runs that this test starts record their process groups and
        // directories in this test's owner record, after the records of any
        // bin/test above this PHPUnit process.
        $inherited = getenv('ORRERY_OWNERS');
        $env['ORRERY_OWNERS'] = (is_string($inherited) && $inherited !== '' ? $inherited."\n" : '').$this->ownerEntry();

        $bash = $this->selectedBash();

        if ($bash !== null) {
            $env['ORRERY_TEST_BASH'] = $bash;
        }

        return array_merge($env, $extra);
    }

    /**
     * The inherited environment without the database, registry, ownership
     * and gate variables. ORRERY_GROUP_TOKEN stays: the processes that this
     * test starts are in this PHPUnit's process group, so they keep the token
     * of the bin/test above it (see bin/test).
     *
     * @return array<string, string|false>
     */
    private static function scrubbedEnv(): array
    {
        $env = [];

        foreach (getenv() as $name => $value) {
            if ($name !== 'ORRERY_GROUP_TOKEN' && preg_match('/^(ORRERY_|DB_|DATABASE_URL$)/', $name) === 1) {
                $env[$name] = false;
            }
        }

        $env['XDEBUG_MODE'] = 'off';

        return $env;
    }

    /**
     * The owner entry of this test's private owner record, created on first
     * use. Its supervisor is this PHPUnit process.
     */
    protected function ownerEntry(): string
    {
        if ($this->scriptOwner !== null) {
            return $this->scriptOwner;
        }

        $dir = $this->makeTempDir('orrery-own-');
        $runId = sprintf('run_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $init = new Process([PHP_BINARY, '-n', self::testdbPath(), 'owner-init', $dir, $runId, (string) getmypid(), 'auto'], self::repoRoot(), self::scrubbedEnv(), null, 30.0);
        $init->run();
        self::assertSame(0, $init->getExitCode(), 'owner-init of the test record failed: '.$init->getErrorOutput());

        return $this->scriptOwner = $runId.' '.$dir;
    }

    /**
     * The environment of the fake backend with a test registry.
     *
     * @param  array<string, string|false>  $extra
     * @return array<string, string|false>
     */
    protected function fakeEnv(string $registry, array $extra = []): array
    {
        return $this->scriptEnv(array_merge([
            'ORRERY_REGISTRY_TEST' => '1',
            'ORRERY_OPS' => 'fake',
            'ORRERY_REGISTRY_DIR' => $registry,
            'ORRERY_TEST_PHP' => PHP_BINARY,
            // Every poll of bin/test and testdb.php every 20 ms instead of
            // every 100 ms: a run reacts sooner, and waits the same time.
            'ORRERY_TEST_POLL_MS' => (string) self::TEST_POLL_MS,
        ], $extra));
    }

    /**
     * A directory with a fake `sleep` for PATH. While the file
     * ORRERY_FAKE_SLEEP_FILE exists, the next call removes it, ignores SIGTERM,
     * writes its PID into <file>.pid and stays until <file>.release exists: a
     * poll helper that a stop cannot end. It never ends by itself while the
     * test runs, so a caller that waits for it never ends either. The bound
     * of 300 s (the process bound of these tests) only ends it when a test
     * died before its own cleanup. Every other call runs /bin/sleep.
     */
    protected function fakeSleepDir(): string
    {
        $dir = $this->makeTempDir('orrery-fakesleep-');
        file_put_contents($dir.'/sleep', <<<'SH'
            #!/bin/sh
            if [ -n "${ORRERY_FAKE_SLEEP_FILE:-}" ] && rm "$ORRERY_FAKE_SLEEP_FILE" 2>/dev/null; then
                trap '' TERM
                echo $$ >"$ORRERY_FAKE_SLEEP_FILE.pid.tmp"
                mv "$ORRERY_FAKE_SLEEP_FILE.pid.tmp" "$ORRERY_FAKE_SLEEP_FILE.pid"
                waited=0
                while [ ! -e "$ORRERY_FAKE_SLEEP_FILE.release" ] && [ "$waited" -lt 3000 ]; do
                    /bin/sleep 0.1
                    waited=$((waited + 1))
                done
                exit 0
            fi
            exec /bin/sleep "$@"

            SH);
        chmod($dir.'/sleep', 0755);

        return $dir;
    }

    /**
     * A directory with a fake `ps` for PATH. ORRERY_FAKE_PS selects a failure:
     * exit2 (exit 2), garbage (exit 0 with output that is not ps output),
     * silent (exit 0 without output) or stderr1 (exit 1 with an error).
     * ORRERY_FAKE_PS_FILE names a file whose content selects the mode while it
     * exists, so a test can switch it; it adds list-fails (only `ps -A`
     * fails), pid-fails (only the single-process `ps ... -p <pid>` fails),
     * term-once (the first call ends by SIGTERM) and term-always (every call
     * ends by SIGTERM).
     * ORRERY_FAKE_PS_LOG names a file that gets one line per call. Without a
     * mode it runs /bin/ps.
     */
    protected function fakePsDir(): string
    {
        $dir = $this->makeTempDir('orrery-fakeps-');
        file_put_contents($dir.'/ps', <<<'SH'
            #!/bin/sh
            if [ -n "${ORRERY_FAKE_PS_LOG:-}" ]; then
                echo "$*" >>"$ORRERY_FAKE_PS_LOG"
            fi
            mode=${ORRERY_FAKE_PS:-}
            if [ -n "${ORRERY_FAKE_PS_FILE:-}" ] && [ -f "$ORRERY_FAKE_PS_FILE" ]; then
                mode=$(cat "$ORRERY_FAKE_PS_FILE")
            fi
            case "$mode" in
                exit2) echo "ps: simulated failure" >&2; exit 2 ;;
                garbage) echo "this is not ps output"; exit 0 ;;
                silent) exit 0 ;;
                stderr1) echo "ps: simulated warning" >&2; exit 1 ;;
                list-fails)
                    for argument in "$@"; do
                        if [ "$argument" = -A ]; then
                            echo "ps: simulated failure of the full listing" >&2
                            exit 2
                        fi
                    done
                    ;;
                pid-fails)
                    for argument in "$@"; do
                        if [ "$argument" = -p ]; then
                            echo "ps: simulated failure of a single-process check" >&2
                            exit 2
                        fi
                    done
                    ;;
                term-once)
                    # Ended by SIGTERM on the first call only, as when an
                    # outer run's stop hits it.
                    if [ ! -e "$ORRERY_FAKE_PS_FILE.used" ]; then
                        : >"$ORRERY_FAKE_PS_FILE.used"
                        kill -TERM $$
                    fi
                    ;;
                term-always) kill -TERM $$ ;;
            esac
            exec /bin/ps "$@"

            SH);
        chmod($dir.'/ps', 0755);

        return $dir;
    }

    /**
     * Takes the heavy-run slot of the registry of $env for this test with
     * `testdb.php slot-hold`, and returns its token. With the holder's
     * ORRERY_SLOT_TOKEN in $env, it enters at once. The helper holds the
     * slot until releaseSlot() or the end of the test.
     *
     * @param  array<string, string|false>  $env
     */
    protected function holdSlot(array $env, int $wait = 7200): string
    {
        self::assertNull($this->scriptSlot, 'this test holds the slot already');
        $out = $this->makeTempDir('orrery-slot-').'/slot.out';
        $input = new InputStream;
        $process = new Process([
            PHP_BINARY, '-n', self::testdbPath(), 'slot-hold',
            sprintf('--run-id=run_%d_%s', getmypid(), bin2hex(random_bytes(4))),
            '--pid='.getmypid(),
            '--checkout='.self::repoRoot(),
            '--command=phpunit '.static::class.'::'.$this->name(),
            '--wait='.$wait,
            '--out='.$out,
        ], self::repoRoot(), $env, $input, null);
        $process->start();
        $this->scriptSlot = $process;
        $this->scriptSlotInput = $input;
        $this->waitUntil(static fn (): bool => file_exists($out) || ! $process->isRunning(), $wait + 30.0, 'the slot helper did not take the slot');
        self::assertFileExists($out, 'the slot helper ended without the slot: '.$process->getErrorOutput());
        $result = self::readJson($out);
        self::assertTrue($result['acquired'] ?? null);
        self::assertIsString($result['token'] ?? null);

        return $this->scriptSlotToken = $result['token'];
    }

    /**
     * The variable that lets the bin/test runs of a docker test enter the
     * heavy-run slot: the token of the slot that this test holds in the real
     * registry. Under a holder (for example a bin/matrix leg), it enters
     * with the inherited token, else it waits for the slot like a heavy run.
     *
     * $registry names another registry (the scripts tests' own), and $wait
     * is the longest wait for the slot.
     *
     * @param  array<string, string>  $registry
     * @return array{ORRERY_SLOT_TOKEN: string}
     */
    protected function heavySlotEnv(array $registry = [], int $wait = 7200): array
    {
        if ($this->scriptSlotToken === null) {
            $inherited = getenv('ORRERY_SLOT_TOKEN');
            // scrubbedEnv() maps every inherited ORRERY_* name to false, the token
            // included, so the values here must replace its entries, not join them.
            $this->holdSlot(array_replace(self::scrubbedEnv(), $registry, ['ORRERY_SLOT_TOKEN' => is_string($inherited) && $inherited !== '' ? $inherited : false]), $wait);
        }

        return ['ORRERY_SLOT_TOKEN' => (string) $this->scriptSlotToken];
    }

    /**
     * Ends the slot helper: closes its stdin and waits until it ends.
     */
    protected function releaseSlot(): void
    {
        if ($this->scriptSlot === null) {
            return;
        }

        $this->scriptSlotInput?->close();

        try {
            $this->scriptSlot->setTimeout(10.0);
            $this->scriptSlot->wait();
        } catch (\Throwable) {
            $this->scriptSlot->stop(0);
        }

        $this->scriptSlot = null;
        $this->scriptSlotInput = null;
        $this->scriptSlotToken = null;
    }

    /**
     * The slot helper of this test, for a crash: SIGKILL ends it without its
     * own release.
     */
    protected function slotProcess(): ?Process
    {
        return $this->scriptSlot;
    }

    protected function makeTempDir(string $prefix = 'orrery-t-'): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $real = realpath($dir);
        self::assertIsString($real);
        $this->scriptTempDirs[] = $real;

        return $real;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string|false>  $env
     */
    protected function startProcess(array $command, array $env): Process
    {
        // A hard bound: wait() on a process that hangs throws a
        // ProcessTimedOutException and stops it, so a hang fails the test
        // instead of the whole run. Every script test ends in seconds; the
        // bound leaves room for a heavily loaded machine.
        $process = new Process($command, self::repoRoot(), $env, null, self::PROCESS_TIMEOUT);
        $process->start();
        $this->scriptProcesses[] = $process;

        return $process;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string|false>  $env
     * @return array{exit: int, json: array<string, mixed>, stdout: string, stderr: string, seconds: float}
     */
    protected function runProcess(array $command, array $env, float $timeout = 60.0): array
    {
        $started = microtime(true);
        $process = new Process($command, self::repoRoot(), $env, null, $timeout);
        $process->run();

        return self::processResult($process, microtime(true) - $started);
    }

    /**
     * @return array{exit: int, json: array<string, mixed>, stdout: string, stderr: string, seconds: float}
     */
    protected static function processResult(Process $process, float $seconds = 0.0): array
    {
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput())), static fn (string $line): bool => $line !== ''));
        $json = $lines === [] ? null : json_decode(end($lines), true);

        return [
            'exit' => (int) $process->getExitCode(),
            'json' => is_array($json) ? $json : [],
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'seconds' => $seconds,
        ];
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $env
     * @return array{exit: int, json: array<string, mixed>, stdout: string, stderr: string, seconds: float}
     */
    protected function testdb(array $arguments, array $env, float $timeout = 60.0): array
    {
        // Like the wrappers: without ini files (see bin/test).
        return $this->runProcess([PHP_BINARY, '-n', self::testdbPath(), ...$arguments], $env, $timeout);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $env
     */
    protected function startTestdb(array $arguments, array $env): Process
    {
        return $this->startProcess([PHP_BINARY, '-n', self::testdbPath(), ...$arguments], $env);
    }

    /**
     * A real process that lives until it is stopped or the test ends.
     *
     * @return array{pid: int, start: string, process: Process}
     */
    protected function spawnSleeper(): array
    {
        $process = $this->startProcess(['sleep', '300'], $this->scriptEnv());
        $pid = (int) $process->getPid();
        $start = null;
        $this->waitUntil(static function () use ($pid, &$start): bool {
            $start = self::startOf($pid);

            return $start !== null;
        }, 5.0, 'the sleeper process did not start');

        return ['pid' => $pid, 'start' => (string) $start, 'process' => $process];
    }

    /**
     * @param  array{pid: int, start: string, process: Process}  $sleeper
     */
    protected function stopSleeper(array $sleeper): void
    {
        $sleeper['process']->stop(1);
        $this->waitUntil(static fn (): bool => ! self::isAlive($sleeper['pid']), 5.0, "process {$sleeper['pid']} did not stop");
    }

    protected static function startOf(int $pid): ?string
    {
        $out = (string) shell_exec('LC_ALL=C TZ=UTC ps -o lstart= -p '.$pid.' 2>/dev/null');
        $out = trim((string) preg_replace('/\s+/', ' ', $out));

        return $out === '' ? null : $out;
    }

    /**
     * The identity of a live process that this test started: its PID and
     * start time, read right after the test learned the PID.
     *
     * @return array{pid: int, start: string}
     */
    protected static function identityOf(int $pid): array
    {
        $start = self::startOf($pid);
        self::assertNotNull($start, "process {$pid} is not running");

        return ['pid' => $pid, 'start' => $start];
    }

    /**
     * Sends a signal to exactly that process, and only while it still has
     * the recorded start time. A test never signals by name or pattern.
     *
     * @param  array{pid: int, start: string}  $identity
     */
    protected static function signalIdentity(array $identity, int $signal): bool
    {
        if (self::startOf($identity['pid']) !== $identity['start']) {
            return false;
        }

        return posix_kill($identity['pid'], $signal);
    }

    protected static function isAlive(int $pid): bool
    {
        // A PID that no process has (ESRCH) needs no ps call. Every other
        // answer (a live process, a zombie, a process of another user) goes
        // to ps, which tells a zombie apart.
        if (function_exists('posix_kill') && ! posix_kill($pid, 0) && posix_get_last_error() === 3) {
            return false;
        }

        $state = trim((string) shell_exec('ps -o stat= -p '.$pid.' 2>/dev/null'));

        return $state !== '' && ! str_contains($state, 'Z');
    }

    protected function waitUntil(callable $condition, float $seconds, string $message): void
    {
        $deadline = microtime(true) + $seconds;

        while (true) {
            $checked = microtime(true);

            if ($condition()) {
                return;
            }

            if (microtime(true) >= $deadline) {
                self::fail($message);
            }

            // At least 10 ms, and at least as long as the check took: a check
            // that runs ps then uses at most half of a core while it waits.
            usleep(max(10_000, (int) ((microtime(true) - $checked) * 1_000_000)));
            clearstatcache();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function readJson(string $path): array
    {
        self::assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data, "{$path} is not a JSON object");

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fakeState(string $registry): array
    {
        return self::readJson($registry.'/fake-ops.json');
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    protected static function changeFakeState(string $registry, callable $change): void
    {
        self::writeJson($registry.'/fake-ops.json', $change(self::fakeState($registry)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function fakeCalls(string $registry): array
    {
        if (! file_exists($registry.'/fake-ops.json')) {
            return [];
        }

        return self::fakeState($registry)['calls'];
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return list<string>
     */
    protected static function operations(array $calls): array
    {
        return array_map(static fn (array $call): string => $call['op'], $calls);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function registryData(string $registry): array
    {
        return self::readJson($registry.'/registry.json');
    }

    /**
     * @return list<string>
     */
    protected static function stubRecords(string $dir): array
    {
        $files = glob($dir.'/stub-*.json') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Stops every process that the test started, then deletes the test's
     * directories. When a stop cannot be confirmed, the directories stay and
     * the test fails.
     */
    protected function cleanUpScripts(): void
    {
        if (! $this->stopScriptProcesses()) {
            $kept = $this->scriptTempDirs;
            $this->scriptTempDirs = [];
            $this->scriptTmpDir = null;
            $this->scriptOwner = null;
            self::fail('processes that this test started could not be confirmed stopped; kept '.implode(', ', $kept));
        }

        $this->removeScriptTempDirs();
    }

    /**
     * 1. SIGTERM to every running process that the test started, then a wait
     *    longer than bin/test's grace plus a margin, so each run can complete
     *    its own cleanup first.
     * 2. SIGKILL to what is left.
     * 3. The shutdown of this test's owner record: it stops every process
     *    group that a run below this test recorded (for example the PHPUnit
     *    group of a run whose supervisor died), and only after that it
     *    removes the directories that the runs recorded.
     *
     * @return bool whether every process is confirmed stopped
     */
    protected function stopScriptProcesses(): bool
    {
        $running = array_values(array_filter($this->scriptProcesses, static fn (Process $process): bool => $process->isRunning()));

        foreach ($running as $process) {
            try {
                $process->signal(15);
            } catch (\Throwable) {
                // It ended between the check and the signal.
            }
        }

        $deadline = microtime(true) + self::TEARDOWN_WAIT;

        while (array_filter($running, static fn (Process $process): bool => $process->isRunning()) !== [] && microtime(true) < $deadline) {
            usleep(50_000);
        }

        foreach ($running as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        $this->scriptProcesses = [];

        if ($this->scriptOwner === null) {
            $this->releaseSlot();

            return true;
        }

        $shutdown = new Process([PHP_BINARY, '-n', self::testdbPath(), 'shutdown', '--owner='.$this->scriptOwner, '--grace=2'], self::repoRoot(), self::scrubbedEnv(), null, 120.0);
        $shutdown->run();
        // Last: the runs that used the slot are stopped.
        $this->releaseSlot();

        if ($shutdown->getExitCode() !== 0) {
            fwrite(STDERR, "shutdown --owner={$this->scriptOwner}: {$shutdown->getErrorOutput()}\n");

            return false;
        }

        $this->scriptOwner = null;

        return true;
    }

    protected function removeScriptTempDirs(): void
    {
        // A tearDown can take the slot again after stopScriptProcesses(), for
        // example for a drop of its names (TestDbDockerTest).
        $this->releaseSlot();

        foreach (array_reverse($this->scriptTempDirs) as $dir) {
            self::removeTree($dir);
        }

        $this->scriptTempDirs = [];
        $this->scriptTmpDir = null;
        $this->scriptOwner = null;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        @chmod($path, 0700);

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
