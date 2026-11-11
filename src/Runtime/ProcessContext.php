<?php

namespace Justrau\Orrery\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Queue\Events\WorkerStarting;
use Justrau\Orrery\Storage\Clock;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * What this process is.
 *
 * - Bound command: the first argv token, resolved through the running
 *   Artisan application (abbreviations and aliases as Artisan resolves them).
 *   Only when it is queue:work, the command's definition is merged with the
 *   application's definition (mergeApplicationDefinition(), as Symfony does
 *   before it binds a command), and $_SERVER['argv'] is bound to it with
 *   ArgvInput. So the connection argument and global options such as --env
 *   bind as for the real run, and the command's own defaults apply. The
 *   evidence holds --tries, --timeout, the connection, --queue and --once.
 *   Every other command, or a binding error, gives null (unknown evidence):
 *   the options are never guessed.
 * - Kind: worker for queue:work, tick for orrery:tick, cli for another
 *   console command (or a console process without a resolvable command, such
 *   as PHPUnit), web otherwise.
 * - Start time, computed once per process: field 22 of /proc/<pid>/stat
 *   with btime from /proc/stat (a clock tick of 100 when `getconf CLK_TCK`
 *   fails), else `ps -o lstart=` (in UTC, whole seconds), else the
 *   WorkerStarting time, else $_SERVER['REQUEST_TIME_FLOAT']. /proc first:
 *   containers that share a host name and start in the same
 *   second would share one identity with the whole seconds of ps.
 * - Identity: host:pid:start, with the start as Clock::toDecimal().
 *
 * WorkerStarting (daemon mode) confirms the evidence: it replaces tries and
 * timeout with the options that the worker really uses. It never creates
 * evidence for a command that is not queue:work.
 */
final class ProcessContext
{
    public const WORKER = 'worker';

    public const WEB = 'web';

    public const CLI = 'cli';

    public const TICK = 'tick';

    /** Worker modes. */
    public const DAEMON = 'daemon';

    public const ONCE = 'once';

    public const LISTEN_CHILD = 'listen-child';

    /**
     * The parent process: null until parent() reads it once.
     *
     * @var array{ppid: ?int, started_at: ?float, command: ?string}|null
     */
    private ?array $parent = null;

    /** The precise start of this process for the terminate decision (ownControlStart()). */
    private ?float $controlStart = null;

    private function __construct(
        private string $kind,
        private readonly string $host,
        private readonly int $pid,
        private float $startedAt,
        private string $startSource,
        private ?WorkerEvidence $evidence,
    ) {}

    /**
     * Records the first package registration of this process. A package
     * module calls it in register(), so the time is early and never earlier
     * than the process start.
     */
    public static function markBooted(): void
    {
        self::state()->bootedAt ??= Clock::now();
    }

    /**
     * The start that the terminate decision compares with: a
     * terminate applies when it was issued later than this time. It is the
     * precise start of this queue:work process, also for a queue:listen
     * child: LARAVEL_START (the microtime that Laravel's artisan defines on
     * its first line), else the first package registration of the process,
     * else the time of the first control check. None of them is earlier
     * than the real start. The ps start (whole seconds) is never used here.
     *
     * The parent start of a queue:listen child is for grouping only. A
     * terminate does not stop the queue:listen parent, and a child that
     * started after the signal ignores that signal.
     */
    public function controlStartedAt(): float
    {
        return $this->controlStart ??= self::ownControlStart();
    }

    private static function ownControlStart(): float
    {
        $laravelStart = self::laravelStart();

        if (is_numeric($laravelStart)) {
            return (float) $laravelStart;
        }

        return self::state()->bootedAt ?? Clock::now();
    }

    /**
     * LARAVEL_START as the host process defined it, or null when it is not
     * defined. The host defines it, so its value can have any type.
     */
    private static function laravelStart(): mixed
    {
        return defined('LARAVEL_START') ? constant('LARAVEL_START') : null;
    }

    public static function current(): self
    {
        $state = self::state();

        return $state->testing ?? ($state->current ??= self::detect());
    }

    /**
     * The process facts of the application (a container singleton).
     */
    private static function state(): ProcessState
    {
        return Container::getInstance()->make(ProcessState::class);
    }

    /**
     * A context with the given facts, for tests only (use it with
     * useForTesting()). The parent facts replace the ps reads: a null ppid
     * is a process without a known parent.
     */
    public static function forTesting(
        string $kind,
        string $host,
        int $pid,
        float $startedAt,
        ?WorkerEvidence $evidence = null,
        ?int $ppid = null,
        ?float $parentStartedAt = null,
        ?string $parentCommand = null,
        ?float $controlStartedAt = null,
    ): self {
        $context = new self($kind, $host, $pid, $startedAt, 'testing', $evidence);
        $context->parent = ['ppid' => $ppid, 'started_at' => $parentStartedAt, 'command' => $parentCommand];
        $context->controlStart = $controlStartedAt ?? $startedAt;

        return $context;
    }

    /**
     * Replaces current() in tests; null restores the detected context.
     */
    public static function useForTesting(?self $context): void
    {
        self::state()->testing = $context;
    }

    /**
     * A copy of this context with another kind (tests only: PHPUnit runs a
     * web request or a command inside its own console process).
     */
    public function withKindForTesting(string $kind): self
    {
        $copy = clone $this;
        $copy->kind = $kind;

        return $copy;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function identity(): string
    {
        return $this->host.':'.$this->pid.':'.Clock::toDecimal($this->startedAt);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    /**
     * ps, proc, worker_starting or request.
     */
    public function startSource(): string
    {
        return $this->startSource;
    }

    /**
     * The bound command name is queue:work.
     */
    public function isWorker(): bool
    {
        return $this->kind === self::WORKER;
    }

    /**
     * The parent PID (posix_getppid()), or null without ext-posix. A PID of
     * 0 is no parent.
     */
    public function ppid(): ?int
    {
        return $this->parent()['ppid'];
    }

    /**
     * The start of the parent process from `ps -o lstart=` or /proc, or null.
     */
    public function parentStartedAt(): ?float
    {
        return $this->parent()['started_at'];
    }

    /**
     * The worker mode:
     * - listen-child when the parent command (`ps -o command= -p <ppid>`)
     *   contains queue:listen and the parent start is known, so the child
     *   can be grouped by host, ppid and parent start;
     * - daemon for queue:work without --once;
     * - once otherwise.
     * Without ps or a ppid, a process is never a listen child: no grouping.
     */
    public function mode(): string
    {
        $parent = $this->parent();

        if ($parent['ppid'] !== null
            && $parent['started_at'] !== null
            && $parent['command'] !== null
            && str_contains($parent['command'], 'queue:listen')) {
            return self::LISTEN_CHILD;
        }

        if ($this->kind !== self::WORKER) {
            return self::ONCE;
        }

        // A queue:work process whose command line could not be bound
        // (unknown evidence) counts as a daemon.
        return $this->evidence?->once === true ? self::ONCE : self::DAEMON;
    }

    /**
     * The worker options of this process's own command line; null when they
     * are unknown.
     */
    public function workerEvidence(): ?WorkerEvidence
    {
        return $this->evidence;
    }

    /**
     * WorkerStarting (daemon mode) confirms the evidence with the options
     * that the worker uses, and gives the start time when neither ps nor
     * /proc gave one.
     */
    public function confirm(WorkerStarting $event): void
    {
        if ($this->startSource === 'request') {
            $this->startedAt = Clock::now();
            $this->startSource = 'worker_starting';
        }

        if ($this->evidence === null) {
            return;
        }

        $options = $event->workerOptions;

        $this->evidence = new WorkerEvidence(
            (int) $options->maxTries,
            (int) $options->timeout,
            $this->evidence->connection,
            $this->evidence->queue,
            $this->evidence->once,
            $this->evidence->sleep,
            $this->evidence->rest,
        );
    }

    /**
     * The --rest of the queue:listen parent of a listen child (the parent
     * rests between two children), from the parent's command line; null
     * when this is no listen child or the parent names no --rest.
     */
    public function listenRest(): ?float
    {
        if ($this->mode() !== self::LISTEN_CHILD) {
            return null;
        }

        $command = (string) $this->parent()['command'];

        return preg_match('/(?:^|\s)--rest(?:=|\s+)(\d+(?:\.\d+)?)(?:\s|$)/', $command, $match) === 1 ? (float) $match[1] : null;
    }

    /**
     * @return array{kind: string, identity: string, host: string, pid: int, started_at: float, start_source: string, is_worker: bool, evidence: array{tries: ?int, timeout: ?int, connection: ?string, queue: ?string, once: bool}|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'identity' => $this->identity(),
            'host' => $this->host,
            'pid' => $this->pid,
            'started_at' => $this->startedAt,
            'start_source' => $this->startSource,
            'is_worker' => $this->isWorker(),
            'evidence' => $this->evidence?->toArray(),
        ];
    }

    private static function detect(): self
    {
        $host = gethostname() ?: 'unknown';
        $pid = getmypid() ?: 0;
        [$startedAt, $source] = self::startTime($pid);
        [$kind, $evidence] = self::commandContext();

        return new self($kind, $host, $pid, $startedAt, $source, $evidence);
    }

    /**
     * @return array{0: string, 1: ?WorkerEvidence}
     */
    private static function commandContext(): array
    {
        $container = Container::getInstance();
        $console = method_exists($container, 'runningInConsole') ? (bool) $container->runningInConsole() : PHP_SAPI === 'cli';

        if (! $console) {
            return [self::WEB, null];
        }

        try {
            $artisan = self::runningArtisan($container);
            $argv = $_SERVER['argv'] ?? null;

            if ($artisan === null || ! is_array($argv)) {
                return [self::CLI, null];
            }

            $argv = array_values(array_map('strval', $argv));
            $command = self::boundCommand($artisan, $argv);

            if ($command === null) {
                return [self::CLI, null];
            }

            $name = $command->getName();

            if ($name === 'orrery:tick') {
                return [self::TICK, null];
            }

            if ($name !== 'queue:work') {
                return [self::CLI, null];
            }

            return [self::WORKER, self::evidence($command, $argv)];
        } catch (Throwable) {
            return [self::CLI, null];
        }
    }

    /**
     * The Artisan application that the console kernel already built, or null.
     * It never builds one.
     */
    private static function runningArtisan(Container $container): ?ConsoleApplication
    {
        if (! $container->bound(ConsoleKernel::class) || ! $container->resolved(ConsoleKernel::class)) {
            return null;
        }

        // Any console kernel of the host: only the Laravel kernel has the property.
        /** @var ConsoleKernel $kernel */
        $kernel = $container->make(ConsoleKernel::class);

        if (! property_exists($kernel, 'artisan')) {
            return null;
        }

        $artisan = (fn () => $this->artisan)->call($kernel);

        return $artisan instanceof ConsoleApplication ? $artisan : null;
    }

    /**
     * @param  list<string>  $argv
     */
    private static function boundCommand(ConsoleApplication $artisan, array $argv): ?Command
    {
        $probe = new ArgvInput($argv);

        try {
            $probe->bind($artisan->getDefinition());
        } catch (Throwable) {
            // Symfony ignores these errors here too; the command validates its input later.
        }

        $name = $probe->getFirstArgument();

        if (! is_string($name) || $name === '') {
            return null;
        }

        try {
            return $artisan->find($name);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $argv
     */
    private static function evidence(Command $command, array $argv): ?WorkerEvidence
    {
        try {
            $command->mergeApplicationDefinition();
            $input = new ArgvInput($argv, $command->getDefinition());
        } catch (Throwable) {
            return null;
        }

        $connection = $input->hasArgument('connection') ? $input->getArgument('connection') : null;
        $queue = $input->hasOption('queue') ? $input->getOption('queue') : null;

        return new WorkerEvidence(
            self::integerOption($input, 'tries'),
            self::integerOption($input, 'timeout'),
            is_string($connection) && $connection !== '' ? $connection : null,
            is_string($queue) && $queue !== '' ? $queue : null,
            $input->hasOption('once') && (bool) $input->getOption('once'),
            self::numberOption($input, 'sleep'),
            self::numberOption($input, 'rest'),
        );
    }

    private static function numberOption(ArgvInput $input, string $name): ?float
    {
        if (! $input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);

        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    private static function integerOption(ArgvInput $input, string $name): ?int
    {
        if (! $input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{0: float, 1: string}
     */
    private static function startTime(int $pid): array
    {
        $proc = self::procStart($pid);

        if ($proc !== null) {
            return [$proc, 'proc'];
        }

        $ps = self::psStart($pid);

        if ($ps !== null) {
            return [$ps, 'ps'];
        }

        $request = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return [is_numeric($request) ? (float) $request : Clock::now(), 'request'];
    }

    /**
     * The parent facts, read once per process: the ppid from
     * posix_getppid(), the parent's start and its command line from ps
     * (the start also from /proc). Every read that fails gives null.
     *
     * @return array{ppid: ?int, started_at: ?float, command: ?string}
     */
    private function parent(): array
    {
        if ($this->parent !== null) {
            return $this->parent;
        }

        $ppid = function_exists('posix_getppid') ? posix_getppid() : null;
        $ppid = is_int($ppid) && $ppid > 0 ? $ppid : null;

        // The parent start is the grouping key of a listen child (never a
        // terminate decision): /proc (clock ticks, Linux), else ps (whole
        // seconds). Every child of one parent reads the same value.
        $proc = $ppid === null ? null : self::procStart($ppid);

        return $this->parent = [
            'ppid' => $ppid,
            'started_at' => $proc ?? ($ppid === null ? null : self::psStart($ppid)),
            'command' => $ppid === null ? null : self::psCommand($ppid),
        ];
    }

    private static function psCommand(int $pid): ?string
    {
        try {
            $process = new Process(['ps', '-o', 'command=', '-p', (string) $pid], null, ['LC_ALL' => 'C'], null, 5.0);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $command = trim($process->getOutput());

            return $command === '' ? null : $command;
        } catch (Throwable) {
            return null;
        }
    }

    private static function psStart(int $pid): ?float
    {
        try {
            $process = new Process(['ps', '-o', 'lstart=', '-p', (string) $pid], null, ['LC_ALL' => 'C', 'TZ' => 'UTC'], null, 5.0);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $text = trim((string) preg_replace('/\s+/', ' ', $process->getOutput()));
            $start = DateTimeImmutable::createFromFormat('!D M j H:i:s Y', $text, new DateTimeZone('UTC'));

            return $start === false ? null : (float) $start->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private static function procStart(int $pid): ?float
    {
        try {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            $system = @file_get_contents('/proc/stat');

            if (! is_string($stat) || ! is_string($system)) {
                return null;
            }

            // The command name (field 2) can hold spaces: count from its closing parenthesis.
            $fields = preg_split('/\s+/', trim(substr($stat, (int) strrpos($stat, ')') + 1)));

            // After the name, field 3 is index 0, so field 22 is index 19.
            $ticks = $fields[19] ?? null;

            if (! is_numeric($ticks) || preg_match('/^btime\s+(\d+)$/m', $system, $match) !== 1) {
                return null;
            }

            return (int) $match[1] + ((int) $ticks / self::clockTicks());
        } catch (Throwable) {
            return null;
        }
    }

    private static function clockTicks(): int
    {
        try {
            $process = new Process(['getconf', 'CLK_TCK'], null, null, null, 5.0);
            $process->run();
            $value = trim($process->getOutput());

            return $process->isSuccessful() && ctype_digit($value) && (int) $value > 0 ? (int) $value : 100;
        } catch (Throwable) {
            return 100;
        }
    }
}
