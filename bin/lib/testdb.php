<?php

/*
 * Test database lifecycle for laravel-orrery.
 *
 * This program owns the Docker Compose project `orrery-testing`, the lease
 * registry, the database name locks and the endpoint token. `bin/test-db` and
 * `bin/test` are thin wrappers around it. It is plain PHP without the Composer
 * autoloader, so it works in every checkout and matrix copy.
 *
 * Every command prints one JSON object on stdout. Messages go to stderr.
 * Every command that reads or changes the registry holds one exclusive
 * registry lock from its first decision to its last action.
 *
 * Ownership. Every run of bin/test has one owner record: its lease in the
 * registry, or (in the modes without a registry) a private record in its own
 * temporary directory, which has the same file format and lock. A record
 * lists the run's children: process groups (bin/test's PHPUnit, and the
 * PHPUnit of every nested bin/test below it) and plain processes (workers
 * that add-child records). It also lists the run's temporary directories.
 * ORRERY_OWNERS names the records of a process group: one "<run id>
 * <directory>" per line, outermost first. A record that is closing accepts
 * nothing new.
 *
 * Exit codes:
 *   0  success
 *   1  internal error
 *   64 invalid arguments or an invalid database name
 *   66 no such lease or owner record, or no token
 *   69 a project port is in use by a process outside the project
 *   70 an endpoint marker differs from the registry token
 *   71 the services are not reusable while leases are live
 *   72 the heavy-run slot did not become free within --wait (slot-hold)
 *   73 a database name is held by another run
 *   74 an external operation (docker compose, a database command, a process
 *      inspection, a registry read or write, a removal) failed
 *   75 refused because a lease or a recorded child is still live
 *   76 the owner record is closing: nothing can join it
 *   77 `down --force` refused (no interactive terminal, or no confirmation)
 *   78 configuration refused (registry override rules, backend selection)
 */

declare(strict_types=1);

namespace OrreryTestDb;

const PROJECT = 'orrery-testing';
// The default services. A command without --alt checks, starts and marks only them.
const SERVICES = ['mysql', 'postgres', 'mariadb'];
// The services of the Compose profile "alt": a second server of MySQL and of Postgres.
const ALT_SERVICES = ['mysql-alt', 'pgsql-alt'];
// Every service of the project, in the order that status lists them.
const ALL_SERVICES = ['mysql', 'postgres', 'mariadb', 'mysql-alt', 'pgsql-alt'];
const ENGINE_SERVICES = ['mysql' => ['mysql'], 'pgsql' => ['postgres'], 'mariadb' => ['mariadb'], 'all' => ['mysql', 'postgres', 'mariadb']];
// The alt service of each engine. An --alt lease records it too. MariaDB has none (altServices()).
const ENGINE_ALT_SERVICES = ['mysql' => ['mysql-alt'], 'pgsql' => ['pgsql-alt'], 'all' => ['mysql-alt', 'pgsql-alt']];
// The client of each MySQL-family service: the MariaDB image has no `mysql` client.
const MYSQL_CLIENTS = ['mysql' => 'mysql', 'mysql-alt' => 'mysql', 'mariadb' => 'mariadb'];
// The port that a Postgres service listens on inside its container.
const PGSQL_CONTAINER_PORTS = ['postgres' => 5432, 'pgsql-alt' => 5433];
const DEFAULT_PORTS = ['mysql' => 33106, 'postgres' => 54336, 'mariadb' => 33108, 'mysql-alt' => 33107, 'pgsql-alt' => 54337];
const PORT_VARIABLES = [
    'mysql' => 'ORRERY_TEST_MYSQL_PORT',
    'postgres' => 'ORRERY_TEST_PGSQL_PORT',
    'mariadb' => 'ORRERY_TEST_MARIADB_PORT',
    'mysql-alt' => 'ORRERY_TEST_MYSQL_ALT_PORT',
    'pgsql-alt' => 'ORRERY_TEST_PGSQL_ALT_PORT',
];
const PASSWORD = 'orrery';
const RUN_ID_PATTERN = '/^run_[0-9]{1,10}_[0-9a-f]{8}$/D';
const TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';
// The environment variable with the random token of a process group that
// bin/test starts. Every descendant inherits it, and an owner record keeps it
// in the group's entry.
const GROUP_TOKEN = 'ORRERY_GROUP_TOKEN';
// The environment variable with the token of the heavy-run slot (see Slot).
// The holder exports it, and its descendants enter the slot with it.
const SLOT_TOKEN = 'ORRERY_SLOT_TOKEN';

const EXIT_FAILURE = 1;
const EXIT_USAGE = 64;
const EXIT_NO_LEASE = 66;
const EXIT_PORT_TAKEN = 69;
const EXIT_MARKER = 70;
const EXIT_SERVICES = 71;
const EXIT_SLOT = 72;
const EXIT_NAME_HELD = 73;
const EXIT_OPERATION = 74;
const EXIT_LIVE = 75;
const EXIT_CLOSING = 76;
const EXIT_FORCE = 77;
const EXIT_CONFIG = 78;

final class Failure extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, public readonly int $exitCode, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}

/**
 * The database name rules.
 */
final class Names
{
    public const MARKER = 'orrery_marker';

    public const BASE_PATTERN = '/^orrery_[a-z0-9_]{1,40}$/D';

    public const MIRROR_PATTERN = '/^orrery_[a-z0-9_]{1,47}$/D';

    public const SYSTEM = ['mysql', 'information_schema', 'performance_schema', 'sys', 'postgres', 'template0', 'template1'];

    public static function base(string $name): string
    {
        if (preg_match(self::BASE_PATTERN, $name) !== 1) {
            throw new Failure(sprintf(
                'invalid database name %s: a base name must match ^orrery_[a-z0-9_]{1,40}$ (at most 47 characters)',
                self::quote($name),
            ), EXIT_USAGE);
        }

        if ($name === self::MARKER) {
            throw new Failure('invalid database name orrery_marker: it is reserved for the endpoint marker', EXIT_USAGE);
        }

        if (str_ends_with($name, '_mirror')) {
            throw new Failure(sprintf(
                'invalid database name %s: a base name must not end in _mirror, because <base>_mirror is the derived mirror name',
                $name,
            ), EXIT_USAGE);
        }

        if (in_array($name, self::SYSTEM, true)) {
            throw new Failure(sprintf('invalid database name %s: it is a system database', $name), EXIT_USAGE);
        }

        return $name;
    }

    public static function mirror(string $base): string
    {
        $mirror = self::base($base).'_mirror';

        if (preg_match(self::MIRROR_PATTERN, $mirror) !== 1 || in_array($mirror, self::SYSTEM, true) || $mirror === self::MARKER) {
            throw new Failure(sprintf(
                'invalid mirror name %s: a mirror name must match ^orrery_[a-z0-9_]{1,47}$ (at most 54 characters)',
                $mirror,
            ), EXIT_USAGE);
        }

        return $mirror;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function pair(string $base): array
    {
        return [self::base($base), self::mirror($base)];
    }

    /**
     * A last check right before a name reaches SQL: a valid base name or a
     * valid mirror name, never the marker or a system database.
     */
    public static function assertPhysical(string $name): void
    {
        $valid = preg_match(self::MIRROR_PATTERN, $name) === 1
            && $name !== self::MARKER
            && ! in_array($name, self::SYSTEM, true);

        if (! $valid) {
            throw new Failure(sprintf('refusing to touch database %s: not a test database name', self::quote($name)), EXIT_USAGE);
        }
    }

    public static function quote(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '"?"';
    }
}

/**
 * Runs an external command without a shell and captures its output.
 */
final class Run
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env  the complete environment, or null to inherit
     * @return array{0: int, 1: string, 2: string, 3: bool} status, stdout, stderr, whether a signal ended it
     */
    public static function capture(array $command, ?array $env = null, float $timeout = 60.0): array
    {
        $pipes = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

        if (! is_resource($process)) {
            throw new Failure('could not start '.$command[0], EXIT_OPERATION);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = ['', ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = microtime(true) + $timeout;

        while ($open !== []) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                proc_terminate($process, 9);
                foreach ($open as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);

                throw new Failure(sprintf('%s timed out after %d seconds', implode(' ', array_slice($command, 0, 4)), (int) $timeout), EXIT_OPERATION);
            }

            $read = array_values($open);
            $write = null;
            $except = null;
            $seconds = (int) floor($remaining);
            $micro = (int) (($remaining - $seconds) * 1_000_000);

            if (@stream_select($read, $write, $except, $seconds, $micro) === false) {
                continue;
            }

            foreach ($read as $pipe) {
                $index = array_search($pipe, $open, true);
                $chunk = fread($pipe, 65536);

                if (is_string($chunk) && $chunk !== '') {
                    $output[$index - 1] .= $chunk;
                }

                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$index]);
                }
            }
        }

        // The exit status, and whether a signal ended the process (then the
        // status is 128 + the signal number).
        $signaled = false;

        while (true) {
            $state = proc_get_status($process);

            if (! $state['running']) {
                $signaled = $state['signaled'];
                $status = $signaled ? 128 + $state['termsig'] : $state['exitcode'];

                break;
            }

            usleep(1_000);
        }

        proc_close($process);

        return [$status, $output[0], $output[1], $signaled];
    }
}

/**
 * Process identity: a PID plus its start time, so a reused PID is never
 * mistaken for the recorded process.
 */
final class Processes
{
    public static function normalise(string $start): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $start));
    }

    /**
     * The start of each PID that is alive, or null for a PID that is
     * confirmed absent or a zombie.
     *
     * An inspection that fails is never read as absence: an unexpected exit
     * status or output that cannot be parsed throws a Failure (74), so no
     * lease, name, database or token is released on bad evidence.
     *
     * @param  list<int>  $pids
     * @return array<int, string|null>
     */
    public static function starts(array $pids): array
    {
        $pids = array_values(array_unique(array_filter($pids, static fn ($pid): bool => is_int($pid) && $pid > 1)));

        if (count($pids) <= 12) {
            // On macOS, `ps -p a,b` takes about 250 ms when one PID in the
            // list does not exist, and about 3 ms for a single PID.
            $result = [];

            foreach ($pids as $pid) {
                $result[$pid] = self::one($pid);
            }

            return $result;
        }

        $all = self::snapshot();
        $result = [];

        foreach ($pids as $pid) {
            $process = $all[$pid] ?? null;
            $result[$pid] = ($process === null || $process['zombie']) ? null : $process['start'];
        }

        return $result;
    }

    /**
     * The live processes of a recorded process group, split into the ones
     * that are proved to be in the recorded group ("owned") and the ones that
     * only share its group ID ("unverified"). Only owned processes may be
     * signalled. An unverified process keeps the entry live, so every caller
     * fails closed.
     *
     * The group ID alone proves nothing: after the recorded group ended, a
     * later group can get the same ID. The rules:
     *
     * - The leader (PID = group ID) exists, live or a zombie, with the
     *   recorded start: this is the recorded group, because a PID is not
     *   reused while its process group exists. Every member is owned.
     * - The leader exists with another start, live or a zombie: the recorded
     *   group ended, and its ID belongs to a later group. The result is null,
     *   and nothing is owned.
     * - The leader is gone: a member is owned only when it carries the
     *   group's token (ORRERY_GROUP_TOKEN in its environment, see
     *   carryToken()), or when this caller proved it owned before ($known,
     *   PID => start). A member that started before the recorded leader is
     *   not in the recorded group. Every other member is unverified.
     *
     * @param  array<int, array{pid: int, ppid: int, pgid: int, zombie: bool, start: string}>  $all  a snapshot()
     * @param  callable(list<int>): array<int, bool>  $carriesToken  which PIDs carry the group's token
     * @param  array<int, string>  $known  processes that this caller already proved owned
     * @return array{owned: array<int, string>, unverified: array<int, string>}|null PID => start
     */
    public static function groupMembers(array $all, int $pgid, string $leaderStart, callable $carriesToken, array $known = [], ?int $exclude = null): ?array
    {
        $leader = $all[$pgid] ?? null;

        if ($leader !== null && $leader['start'] !== $leaderStart) {
            return null;
        }

        $members = [];

        foreach ($all as $pid => $process) {
            if (! $process['zombie'] && $pid !== $exclude && $process['pgid'] === $pgid) {
                $members[$pid] = $process['start'];
            }
        }

        if ($leader !== null) {
            // The recorded leader itself, also when it left its group.
            if (! $leader['zombie'] && $pgid !== $exclude) {
                $members[$pgid] = $leader['start'];
            }

            ksort($members);

            return ['owned' => $members, 'unverified' => []];
        }

        $owned = [];
        $candidates = [];
        $leaderTime = self::time($leaderStart);

        foreach ($members as $pid => $start) {
            if (($known[$pid] ?? null) === $start) {
                $owned[$pid] = $start;
            } elseif ($leaderTime === null || self::time($start) === null || self::time($start) >= $leaderTime) {
                $candidates[$pid] = $start;
            }
        }

        $unverified = [];
        $carries = $candidates === [] ? [] : $carriesToken(array_keys($candidates));

        foreach ($candidates as $pid => $start) {
            if ($carries[$pid] ?? false) {
                $owned[$pid] = $start;
            } else {
                $unverified[$pid] = $start;
            }
        }

        return ['owned' => $owned, 'unverified' => $unverified];
    }

    /**
     * The Unix time of a normalised lstart value, or null when it does not
     * parse. lstart is in UTC (see ps()).
     */
    public static function time(string $start): ?int
    {
        $time = \DateTimeImmutable::createFromFormat('!D M j H:i:s Y', $start, new \DateTimeZone('UTC'));

        return $time === false ? null : $time->getTimestamp();
    }

    /**
     * Which processes carry the group token in their environment: exactly
     * one ORRERY_GROUP_TOKEN entry, whose value is $token. false means that
     * the token is missing or different, or that the environment cannot be
     * read exactly.
     *
     * Only Linux gives exact proof: /proc/<pid>/environ holds the environment
     * entries alone, separated by NUL bytes. macOS and the BSDs have no exact
     * source without root: `ps -E` joins the arguments and the environment
     * with spaces, so a token in an argument or in the value of another
     * variable looks the same as the real entry, and macOS hides the
     * environment of its platform binaries (/bin/bash, /bin/sleep) anyway.
     * There, every process counts as without the token, so a group without
     * its anchor fails closed (see groupMembers()).
     *
     * @param  list<int>  $pids
     * @return array<int, bool>
     */
    public static function carryToken(array $pids, ?string $token): array
    {
        $result = array_fill_keys($pids, false);

        if ($token === null || preg_match(TOKEN_PATTERN, $token) !== 1 || PHP_OS_FAMILY !== 'Linux') {
            return $result;
        }

        foreach ($pids as $pid) {
            $environ = @file_get_contents("/proc/{$pid}/environ");
            $result[$pid] = is_string($environ) && self::environHasToken($environ, $token);
        }

        return $result;
    }

    /**
     * Whether a NUL-separated environment block (/proc/<pid>/environ) has
     * exactly one ORRERY_GROUP_TOKEN entry, with the value $token. Two such
     * entries are ambiguous (getenv() returns the first), so they count as
     * no proof.
     */
    public static function environHasToken(string $environ, string $token): bool
    {
        $values = [];

        foreach (explode("\0", $environ) as $entry) {
            if (str_starts_with($entry, GROUP_TOKEN.'=')) {
                $values[] = substr($entry, strlen(GROUP_TOKEN) + 1);
            }
        }

        return $values === [$token];
    }

    /**
     * Sends a signal only when the PID still has the given start time.
     */
    public static function signalIfSame(int $pid, string $start, int $signal): bool
    {
        if (self::starts([$pid])[$pid] !== $start) {
            return false;
        }

        self::signal($pid, $signal);

        return true;
    }

    private static function one(int $pid): ?string
    {
        return self::info($pid)['start'] ?? null;
    }

    /**
     * One live process, or null when it is confirmed absent or a zombie.
     *
     * @return array{pid: int, ppid: int, pgid: int, zombie: bool, start: string}|null
     */
    public static function info(int $pid): ?array
    {
        [$status, $out, $err] = self::ps(['-o', 'pid=,ppid=,pgid=,stat=,lstart=', '-p', (string) $pid]);
        $lines = self::lines($out);

        // The only proof of absence: ps exits 1 and prints nothing at all.
        if ($status === 1 && $lines === [] && trim($err) === '') {
            return null;
        }

        if ($status !== 0 || count($lines) !== 1) {
            throw self::unusable("ps -p {$pid}", $status, $out, $err);
        }

        $process = self::parseLine($lines[0]);

        if ($process === null || $process['pid'] !== $pid) {
            throw self::unusable("ps -p {$pid}", $status, $out, $err);
        }

        return $process['zombie'] ? null : $process;
    }

    /**
     * The parent of this process.
     */
    public static function parentPid(): int
    {
        if (function_exists('posix_getppid')) {
            return posix_getppid();
        }

        $self = self::info((int) getmypid());

        if ($self === null) {
            throw new Failure('process inspection failed (this process is not listed)', EXIT_OPERATION);
        }

        return $self['ppid'];
    }

    /**
     * Every process of the host. The listing must parse completely and must
     * contain this process, else it is not evidence of anything.
     *
     * @return array<int, array{pid: int, ppid: int, pgid: int, zombie: bool, start: string}>
     */
    public static function snapshot(): array
    {
        [$status, $out, $err] = self::ps(['-A', '-o', 'pid=,ppid=,pgid=,stat=,lstart=']);
        $processes = [];

        if ($status !== 0) {
            throw self::unusable('ps -A', $status, $out, $err);
        }

        foreach (self::lines($out) as $line) {
            $process = self::parseLine($line);

            if ($process === null) {
                throw self::unusable('ps -A', $status, $out, $err);
            }

            $processes[$process['pid']] = $process;
        }

        if (! isset($processes[getmypid()])) {
            throw self::unusable('ps -A (the listing does not contain this process)', $status, $out, $err);
        }

        return $processes;
    }

    /**
     * @param  list<string>  $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private static function ps(array $arguments): array
    {
        $env = getenv();
        $env['LC_ALL'] = 'C';
        $env['TZ'] = 'UTC';

        // A ps that a signal ended is no evidence either way. The stop of an
        // outer bin/test run sends SIGTERM to every process of this run's
        // groups, and a child of PHP does not keep an ignored SIGTERM. So it
        // runs again, and after 10 such ends the inspection fails (74).
        for ($attempt = 1; ; $attempt++) {
            [$status, $out, $err, $signaled] = Run::capture(['ps', ...$arguments], $env, 20.0);

            if (! $signaled || $attempt >= 10) {
                return [$status, $out, $err];
            }

            usleep(20_000);
        }
    }

    /**
     * @return list<string>
     */
    private static function lines(string $out): array
    {
        return array_values(array_filter(preg_split('/\R/', $out) ?: [], static fn (string $line): bool => trim($line) !== ''));
    }

    /**
     * @return array{pid: int, ppid: int, pgid: int, zombie: bool, start: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        // lstart looks like "Wed Sep 23 02:05:01 2026".
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(\S+)\s+([A-Z][a-z]{2} [A-Z][a-z]{2} +\d{1,2} \d{2}:\d{2}:\d{2} \d{4})\s*$/', $line, $match) !== 1) {
            return null;
        }

        return ['pid' => (int) $match[1], 'ppid' => (int) $match[2], 'pgid' => (int) $match[3], 'zombie' => str_starts_with($match[4], 'Z'), 'start' => self::normalise($match[5])];
    }

    private static function unusable(string $what, int $status, string $out, string $err): Failure
    {
        return new Failure(sprintf(
            'process inspection failed (%s exited %d%s); nothing was released or changed',
            $what,
            $status,
            trim($err) === '' ? (trim($out) === '' ? '' : ', output: '.substr(trim($out), 0, 120)) : ', '.substr(trim($err), 0, 120),
        ), EXIT_OPERATION);
    }

    public static function signal(int $pid, int $signal): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);

            return;
        }

        Run::capture(['kill', '-'.$signal, (string) $pid], null, 10.0);
    }

    /**
     * Whether process $pid exists. bin/test runs this file with php -n, and
     * where posix is a shared extension (setup-php on Ubuntu) -n leaves it
     * out. Then /proc answers without a child process: a child would be a
     * member of the caller's group, which a group stop counts and signals.
     * kill -0 is the last fallback, and a kill that a signal ended is no
     * evidence, so it counts as existing.
     */
    public static function exists(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        if (is_dir('/proc/self')) {
            return is_dir('/proc/'.$pid);
        }

        [$status, , , $signaled] = Run::capture(['kill', '-0', (string) $pid], null, 10.0);

        return $status === 0 || $signaled;
    }
}

/**
 * The lease registry: the project token, the leases (which hold the name
 * locks), and the orphaned databases of stale leases that drop their names
 * (generated names, and explicit names admitted with --drop-at-release).
 *
 * A private owner store of bin/test (see the file header) is a directory in
 * the same format, with one owner record in "leases".
 */
final class Registry
{
    /** @var resource|null */
    private $lock = null;

    public function __construct(public readonly string $dir, public readonly bool $test, private readonly bool $create = true) {}

    /**
     * The store of an owner entry: the registry of a lease, or a private
     * owner store. It is never created here, because a missing directory
     * means that its run has ended.
     */
    public static function store(string $dir): self
    {
        return new self(self::normalisedAbsolute($dir, 'an owner directory'), false, false);
    }

    public static function uid(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        [$status, $out] = Run::capture(['id', '-u'], null, 10.0);

        if ($status !== 0 || preg_match('/^\d+$/', trim($out)) !== 1) {
            throw new Failure('cannot determine the user id', EXIT_FAILURE);
        }

        return (int) trim($out);
    }

    /**
     * The one canonical registry. It is never derived from TMPDIR.
     */
    public static function canonicalPath(): string
    {
        return '/tmp/orrery-testing-'.self::uid();
    }

    public static function resolve(): self
    {
        $override = getenv('ORRERY_REGISTRY_DIR');

        if (Environment::testMode()) {
            if (! is_string($override) || $override === '') {
                throw new Failure('ORRERY_REGISTRY_TEST=1 requires ORRERY_REGISTRY_DIR with an absolute path', EXIT_CONFIG);
            }

            $dir = self::normalisedAbsolute($override, 'ORRERY_REGISTRY_DIR');

            // The real registry is always protected. Tests of this rule name
            // a disposable stand-in for it, so they never touch the real one.
            $protected = [self::canonicalPath()];
            $standIn = getenv('ORRERY_TEST_CANONICAL_REGISTRY');

            if (is_string($standIn) && $standIn !== '') {
                $protected[] = self::normalisedAbsolute($standIn, 'ORRERY_TEST_CANONICAL_REGISTRY');
            }

            foreach ($protected as $canonical) {
                if (self::sameLocation($dir, $canonical)) {
                    throw new Failure("a test registry must not be the canonical registry {$canonical} (ORRERY_REGISTRY_DIR={$override})", EXIT_CONFIG);
                }
            }

            return new self($dir, true);
        }

        if (is_string($override) && $override !== '') {
            fwrite(STDERR, "testdb: ignoring ORRERY_REGISTRY_DIR, because ORRERY_REGISTRY_TEST is not 1\n");
        }

        return new self(self::canonicalPath(), false);
    }

    /**
     * An absolute path without ".", ".." or empty components, so the name
     * that is checked is the name that is used.
     */
    public static function normalisedAbsolute(string $path, string $variable): string
    {
        $trimmed = rtrim($path, '/');

        if ($trimmed === '' || $trimmed[0] !== '/') {
            throw new Failure("{$variable} must be an absolute path", EXIT_CONFIG);
        }

        foreach (explode('/', substr($trimmed, 1)) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new Failure("{$variable} must be a normalised path without '.', '..' or empty components: {$path}", EXIT_CONFIG);
            }
        }

        return $trimmed;
    }

    /**
     * Whether two paths name the same directory, through any alias: a
     * symlink, a symlinked parent, or another mount of the same directory.
     */
    private static function sameLocation(string $a, string $b): bool
    {
        clearstatcache();
        $statA = @stat($a);
        $statB = @stat($b);

        if ($statA !== false && $statB !== false) {
            return $statA['dev'] === $statB['dev'] && $statA['ino'] === $statB['ino'];
        }

        if (basename($a) === basename($b)) {
            $parentA = @stat(dirname($a));
            $parentB = @stat(dirname($b));

            if ($parentA !== false && $parentB !== false && $parentA['dev'] === $parentB['dev'] && $parentA['ino'] === $parentB['ino']) {
                return true;
            }
        }

        return self::resolved($a) === self::resolved($b);
    }

    private static function resolved(string $path): string
    {
        $real = realpath($path);

        if ($real !== false) {
            return $real;
        }

        $parent = realpath(dirname($path));

        return ($parent === false ? dirname($path) : $parent).'/'.basename($path);
    }

    /**
     * Creates the directory with mode 0700, or checks the existing one: it
     * must be a real directory (not a symlink), owned by this user, and not
     * accessible to anybody else.
     */
    public function ensureDirectory(): void
    {
        clearstatcache();

        if (! file_exists($this->dir) && ! is_link($this->dir)) {
            if (! $this->create) {
                throw new Failure("owner directory {$this->dir} does not exist: its run has ended", EXIT_NO_LEASE);
            }

            $umask = umask(0077);
            @mkdir($this->dir, 0700);
            umask($umask);
            clearstatcache();
        }

        if (is_link($this->dir)) {
            throw new Failure("registry {$this->dir} is a symlink; refusing to use it", EXIT_CONFIG);
        }

        $stat = @lstat($this->dir);

        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw new Failure("registry {$this->dir} is not a directory", EXIT_CONFIG);
        }

        if ($stat['uid'] !== self::uid()) {
            throw new Failure("registry {$this->dir} is owned by uid {$stat['uid']}, not by this user; refusing to use it", EXIT_CONFIG);
        }

        if (($stat['mode'] & 0077) !== 0) {
            throw new Failure(sprintf('registry %s has mode %o; it must be 0700', $this->dir, $stat['mode'] & 0777), EXIT_CONFIG);
        }
    }

    public function lock(): void
    {
        $this->ensureDirectory();
        $path = $this->dir.'/lock';

        if (is_link($path)) {
            throw new Failure("registry lock {$path} is a symlink; refusing to use it", EXIT_CONFIG);
        }

        $before = Environment::testMode() ? getenv('ORRERY_TEST_BEFORE_LOCK') : false;

        if (is_string($before) && $before !== '') {
            touch($before);
        }

        $umask = umask(0077);
        $handle = @fopen($path, 'c');
        umask($umask);

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new Failure("cannot lock {$path}; nothing was changed", EXIT_OPERATION);
        }

        $this->lock = $handle;
    }

    public function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /**
     * @return array{version: int, token: string|null, leases: array<string, array<string, mixed>>, orphans: list<array{name: string, services: list<string>}>}
     */
    public function load(): array
    {
        $path = $this->dir.'/registry.json';
        $empty = ['version' => 1, 'token' => null, 'leases' => [], 'orphans' => []];

        clearstatcache();

        if (! file_exists($path) && ! is_link($path)) {
            return $empty;
        }

        // A file that cannot be read is no evidence of anything: it is never
        // taken as an empty registry.
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new Failure("cannot read {$path}; nothing was changed, stopped or removed", EXIT_OPERATION);
        }

        $data = json_decode($json, true);

        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ! is_array($data['leases'] ?? null)) {
            throw new Failure("registry file {$path} is corrupt; inspect it, then remove it (only while no test run is active)", EXIT_CONFIG);
        }

        return [
            'version' => 1,
            'token' => is_string($data['token'] ?? null) ? $data['token'] : null,
            'leases' => $data['leases'],
            'orphans' => is_array($data['orphans'] ?? null) ? array_values($data['orphans']) : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void
    {
        $path = $this->dir.'/registry.json';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if (file_exists($path) && @file_get_contents($path) === $json) {
            return;
        }

        // Written in full, then renamed: a crash never leaves a partial registry.
        $temporary = $path.'.'.getmypid().'.tmp';
        $umask = umask(0077);

        try {
            if (@file_put_contents($temporary, $json) !== strlen($json) || ! @rename($temporary, $path)) {
                @unlink($temporary);

                throw new Failure("cannot write {$path}; it was not changed", EXIT_OPERATION);
            }
        } finally {
            umask($umask);
        }
    }
}

final class Environment
{
    public static function testMode(): bool
    {
        return getenv('ORRERY_REGISTRY_TEST') === '1';
    }

    /**
     * The interval of a poll in seconds: $default, or in test mode the value
     * of ORRERY_TEST_POLL_MS (a whole number of milliseconds from 1 to 999)
     * when it is set and shorter. bin/test reads the same variable, so a
     * logic test can shorten every poll of a run without a change of real
     * runs. main() refuses an invalid value in test mode.
     */
    public static function poll(float $default): float
    {
        $milliseconds = self::testPollMilliseconds();

        return $milliseconds === null ? $default : min($default, $milliseconds / 1000);
    }

    /**
     * @throws Failure in test mode, for a value that is not a whole number of milliseconds from 1 to 999
     */
    public static function testPollMilliseconds(): ?int
    {
        $value = self::testMode() ? getenv('ORRERY_TEST_POLL_MS') : false;

        if ($value === false || $value === '') {
            return null;
        }

        if (preg_match('/^[0-9]{1,3}$/', $value) !== 1 || (int) $value < 1) {
            throw new Failure('ORRERY_TEST_POLL_MS must be a whole number of milliseconds from 1 to 999', EXIT_USAGE);
        }

        return (int) $value;
    }

    /**
     * @return array<string, int> the configured port of each service of ALL_SERVICES
     */
    public static function ports(): array
    {
        $ports = [];

        foreach (PORT_VARIABLES as $service => $variable) {
            $value = getenv($variable);

            if ($value === false || $value === '') {
                $ports[$service] = DEFAULT_PORTS[$service];

                continue;
            }

            if (preg_match('/^[0-9]{4,5}$/D', $value) !== 1 || (int) $value < 1024 || (int) $value > 65535) {
                throw new Failure("{$variable} must be a port number from 1024 to 65535", EXIT_USAGE);
            }

            $ports[$service] = (int) $value;
        }

        return $ports;
    }

    public static function composeFile(): string
    {
        return dirname(__DIR__, 2).'/docker/testing/compose.yaml';
    }

    public static function ops(Registry $registry, string $command): Ops
    {
        $selected = getenv('ORRERY_OPS');
        $selected = ($selected === false || $selected === '') ? 'docker' : $selected;

        if ($selected === 'fake') {
            if (! self::testMode() || ! $registry->test) {
                throw new Failure('ORRERY_OPS=fake requires ORRERY_REGISTRY_TEST=1 and ORRERY_REGISTRY_DIR', EXIT_CONFIG);
            }

            return new FakeOps($registry->dir, self::ports(), $command);
        }

        if ($selected !== 'docker') {
            throw new Failure("unknown ORRERY_OPS backend {$selected}", EXIT_CONFIG);
        }

        if (self::testMode() || $registry->test) {
            throw new Failure('the Docker backend refuses a test registry override (ORRERY_REGISTRY_TEST=1)', EXIT_CONFIG);
        }

        return new DockerOps(self::composeFile(), self::ports());
    }
}

/**
 * The heavy-run slot: one machine-wide slot for the runs that load the
 * machine most (bin/test on MySQL or Postgres with a lease, and bin/matrix).
 *
 * The slot is an exclusive flock on <registry>/slot.lock. The kernel releases
 * the lock when its holder ends, also after a crash, so no stale slot can
 * remain. <registry>/slot.json describes the holder (its token, run id, pid,
 * checkout and command); it is trusted only while the lock is held. Each
 * waiter holds a lock on its own file in <registry>/slot-waiters/, so a file
 * whose lock is free belongs to a waiter that has ended.
 *
 * Nesting: the holder's token goes to its descendants in ORRERY_SLOT_TOKEN. A
 * request with the token of the live holder enters without the lock.
 */
final class Slot
{
    /**
     * @param  resource|null  $handle  the locked slot.lock, or null for a nested entry
     */
    private function __construct(
        private $handle,
        public readonly string $token,
        public readonly bool $nested,
        private readonly string $holderPath,
    ) {}

    public static function lockPath(Registry $registry): string
    {
        return $registry->dir.'/slot.lock';
    }

    public static function holderPath(Registry $registry): string
    {
        return $registry->dir.'/slot.json';
    }

    public static function waitersDir(Registry $registry): string
    {
        return $registry->dir.'/slot-waiters';
    }

    /**
     * Opens a file of the registry and tries to lock it without waiting.
     *
     * @return resource|null the locked handle, or null while another process holds a conflicting lock
     */
    private static function tryLock(string $path, int $operation)
    {
        if (is_link($path)) {
            throw new Failure("{$path} is a symlink; refusing to use it", EXIT_CONFIG);
        }

        $umask = umask(0077);
        $handle = @fopen($path, 'c+');
        umask($umask);

        if ($handle === false) {
            throw new Failure("cannot open {$path}", EXIT_OPERATION);
        }

        if (flock($handle, $operation | LOCK_NB)) {
            return $handle;
        }

        fclose($handle);

        return null;
    }

    /**
     * The holder while the slot is held, else null. A holder that has not
     * written its record yet has the token null.
     *
     * @return array<string, mixed>|null
     */
    public static function holder(Registry $registry): ?array
    {
        $registry->ensureDirectory();
        $probe = self::tryLock(self::lockPath($registry), LOCK_SH);

        if ($probe !== null) {
            flock($probe, LOCK_UN);
            fclose($probe);

            return null;
        }

        $record = json_decode((string) @file_get_contents(self::holderPath($registry)), true);

        return is_array($record) ? $record : ['token' => null];
    }

    /**
     * The live waiters, oldest first. The files of ended waiters are removed.
     *
     * @return list<array<string, mixed>>
     */
    public static function waiters(Registry $registry): array
    {
        $waiters = [];

        foreach (glob(self::waitersDir($registry).'/*.json') ?: [] as $path) {
            $probe = self::tryLock($path, LOCK_SH);

            if ($probe !== null) {
                // Its waiter has ended: nothing holds its lock.
                flock($probe, LOCK_UN);
                fclose($probe);
                @unlink($path);

                continue;
            }

            $record = json_decode((string) @file_get_contents($path), true);
            $waiters[] = is_array($record) ? $record : ['run_id' => null];
        }

        usort($waiters, static fn (array $a, array $b): int => ($a['since'] ?? 0) <=> ($b['since'] ?? 0));

        return $waiters;
    }

    /**
     * @param  array<string, mixed>|null  $holder
     */
    public static function describe(?array $holder): string
    {
        if ($holder === null) {
            return 'nobody';
        }

        if (! is_string($holder['run_id'] ?? null)) {
            return 'a process that has not written its record yet';
        }

        return sprintf(
            'run %s (pid %s, %s: %s) since %s',
            $holder['run_id'],
            (string) ($holder['pid'] ?? '?'),
            (string) ($holder['checkout'] ?? '?'),
            (string) ($holder['command'] ?? '?'),
            gmdate('H:i:s', (int) ($holder['since'] ?? 0)).' UTC',
        );
    }

    public static function validToken(mixed $token): bool
    {
        return is_string($token) && preg_match(TOKEN_PATTERN, $token) === 1;
    }

    /**
     * Takes the slot, or enters it with the holder's token.
     *
     * @param  array{run_id: string, pid: int, checkout: string, command: string}  $identity
     * @param  callable(string): void  $report  gets one line when the wait starts and then every $every seconds
     * @param  callable(float): bool  $abandoned  waits up to the given seconds and tells whether the requester is gone
     */
    public static function acquire(Registry $registry, array $identity, int $wait, ?string $inherited, callable $report, callable $abandoned, float $every = 60.0): self
    {
        $registry->ensureDirectory();
        $holderPath = self::holderPath($registry);

        if (self::validToken($inherited)) {
            $holder = self::holder($registry);

            if ($holder !== null && is_string($holder['token'] ?? null) && hash_equals($holder['token'], (string) $inherited)) {
                return new self(null, (string) $inherited, true, $holderPath);
            }
        }

        $started = microtime(true);
        $nextReport = $started;
        $waiter = null;
        $waiterPath = null;

        try {
            while (($handle = self::tryLock(self::lockPath($registry), LOCK_EX)) === null) {
                $waited = microtime(true) - $started;

                if ($waited >= $wait) {
                    $holder = self::holder($registry);

                    throw new Failure(sprintf('the heavy-run slot is held by %s; waited %d s (--slot-wait=%d)', self::describe($holder), (int) $waited, $wait), EXIT_SLOT, ['holder' => $holder]);
                }

                if ($waiter === null) {
                    [$waiter, $waiterPath] = self::registerWaiter($registry, $identity + ['since' => $started]);
                }

                if (microtime(true) >= $nextReport) {
                    $report(sprintf('waiting for the heavy-run slot, held by %s; waited %d s of %d s (--slot-wait)', self::describe(self::holder($registry)), (int) $waited, $wait));
                    $nextReport += $every;
                }

                if ($abandoned(min(0.2, max(0.01, $wait - $waited)))) {
                    throw new Failure('the process that asked for the heavy-run slot has ended', EXIT_FAILURE);
                }
            }
        } finally {
            if ($waiter !== null) {
                @unlink((string) $waiterPath);
                flock($waiter, LOCK_UN);
                fclose($waiter);
            }
        }

        $token = bin2hex(random_bytes(16));
        $record = ['token' => $token] + $identity + ['since' => microtime(true), 'helper_pid' => getmypid()];
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        $temporary = $holderPath.'.'.getmypid().'.tmp';
        $umask = umask(0077);
        $written = @file_put_contents($temporary, $json) === strlen($json) && @rename($temporary, $holderPath);
        umask($umask);

        if (! $written) {
            @unlink($temporary);
            flock($handle, LOCK_UN);
            fclose($handle);

            throw new Failure("cannot write {$holderPath}; the heavy-run slot was released", EXIT_OPERATION);
        }

        return new self($handle, $token, false, $holderPath);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{0: resource, 1: string}
     */
    private static function registerWaiter(Registry $registry, array $record): array
    {
        $dir = self::waitersDir($registry);

        if (! is_dir($dir) && ! @mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new Failure("cannot create {$dir}", EXIT_OPERATION);
        }

        $path = $dir.'/'.getmypid().'-'.bin2hex(random_bytes(4)).'.json';
        $handle = self::tryLock($path, LOCK_EX);

        if ($handle === null) {
            throw new Failure("cannot lock {$path}", EXIT_OPERATION);
        }

        ftruncate($handle, 0);
        fwrite($handle, json_encode($record + ['helper_pid' => getmypid()], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        fflush($handle);

        return [$handle, $path];
    }

    /**
     * Gives the slot up. A nested entry holds nothing.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        $record = json_decode((string) @file_get_contents($this->holderPath), true);

        if (is_array($record) && ($record['token'] ?? null) === $this->token) {
            @unlink($this->holderPath);
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}

/**
 * The owner entries of ORRERY_OWNERS: one "<run id> <absolute directory>" per
 * line, outermost first. bin/test exports its inherited entries plus its own
 * entry to its child.
 */
final class Owners
{
    /**
     * @return list<array{run: string, dir: string}>
     */
    public static function chain(): array
    {
        $value = getenv('ORRERY_OWNERS');

        if ($value === false || $value === '') {
            return [];
        }

        return array_map(self::parse(...), explode("\n", $value));
    }

    /**
     * @return array{run: string, dir: string}
     */
    public static function parse(string $entry): array
    {
        if (preg_match('/^(run_[0-9]{1,10}_[0-9a-f]{8}) (\/[^\n]*)$/D', $entry, $match) !== 1) {
            throw new Failure(sprintf(
                'unusable owner entry %s: expected "<run id> <absolute directory>" (in ORRERY_OWNERS, one per line; unset ORRERY_OWNERS when no bin/test run owns this process)',
                Names::quote($entry),
            ), EXIT_CONFIG);
        }

        return ['run' => $match[1], 'dir' => Registry::normalisedAbsolute($match[2], 'an owner directory')];
    }

    /**
     * @param  array{run: string, dir: string}  $owner
     */
    public static function entry(array $owner): string
    {
        return $owner['run'].' '.$owner['dir'];
    }
}

/**
 * The temporary directories that an owner record lists. Only such a
 * directory is ever removed: an absolute, normalised path whose last
 * component starts with "orrery", which is a real directory (not a symlink)
 * of this user.
 */
final class Paths
{
    public static function validate(string $path): string
    {
        $path = Registry::normalisedAbsolute($path, 'a recorded directory');

        if (! str_starts_with(basename($path), 'orrery')) {
            throw new Failure("refusing to record {$path}: an owned directory must have a name that starts with orrery", EXIT_USAGE);
        }

        return $path;
    }

    public static function assertOwnedDirectory(string $path): void
    {
        clearstatcache();
        $stat = @lstat($path);

        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw new Failure("{$path} is not a directory (a symlink is refused)", EXIT_USAGE);
        }

        if ($stat['uid'] !== Registry::uid()) {
            throw new Failure("{$path} is not owned by this user", EXIT_USAGE);
        }
    }

    /**
     * Removes the directories in order, and $last after all others. When one
     * fails, $last stays, so an owner store in $last keeps its record.
     *
     * @param  list<string>  $paths
     * @return array{0: list<string>, 1: list<string>} removed, failed
     */
    public static function removeAll(array $paths, ?string $last = null): array
    {
        $ordered = array_values(array_filter(array_unique($paths), static fn (string $path): bool => $path !== $last));
        $removed = [];
        $failed = [];

        foreach ($ordered as $path) {
            try {
                self::remove($path);
                $removed[] = $path;
            } catch (Failure $failure) {
                fwrite(STDERR, "testdb: {$failure->getMessage()}\n");
                $failed[] = $path;
            }
        }

        if ($last !== null && in_array($last, $paths, true)) {
            if ($failed !== []) {
                $failed[] = $last;
            } else {
                try {
                    self::hold('store', $last);
                    self::removeStore($last);
                    $removed[] = $last;
                } catch (Failure $failure) {
                    fwrite(STDERR, "testdb: {$failure->getMessage()}\n");
                    $failed[] = $last;
                }
            }
        }

        return [$removed, $failed];
    }

    /**
     * Removes a recorded directory with its content. A missing path counts
     * as removed.
     */
    public static function remove(string $path): void
    {
        $path = self::validate($path);
        clearstatcache();
        $stat = @lstat($path);

        if ($stat === false) {
            return;
        }

        if (($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== Registry::uid()) {
            throw new Failure("did not remove {$path}: it is no longer a directory of this user", EXIT_OPERATION);
        }

        @chmod($path, 0700);
        self::removeContents($path);
        self::hold('rmdir', $path);
        @rmdir($path);
        clearstatcache();

        if (file_exists($path) || is_link($path)) {
            throw new Failure("could not remove {$path}", EXIT_OPERATION);
        }
    }

    /**
     * Removes an owner store. A helper that did not see the close (a late
     * owner-join or owner-wait) locks the store by its path, and the lock
     * creates <store>/lock. Inside the tree that is removed, that file would
     * make the rmdir fail after the record was already gone. So the store
     * first moves to a new sibling name: from then on its path is missing,
     * such a helper fails without writing, and nothing can enter the tree
     * that is removed. When that removal fails, the store moves back, so
     * the record stays at its path for the recovery.
     */
    public static function removeStore(string $path): void
    {
        $path = self::validate($path);
        clearstatcache();
        $stat = @lstat($path);

        if ($stat === false) {
            return;
        }

        if (($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== Registry::uid()) {
            throw new Failure("did not remove {$path}: it is no longer a directory of this user", EXIT_OPERATION);
        }

        // rename() replaces an empty directory, so the new name must be free.
        $removing = $path.'.removing-'.bin2hex(random_bytes(6));

        if (@lstat($removing) !== false || ! @rename($path, $removing)) {
            throw new Failure("could not remove {$path}: it could not be moved to {$removing}", EXIT_OPERATION);
        }

        try {
            self::remove($removing);
        } catch (Failure) {
            clearstatcache();

            if (! file_exists($path) && ! is_link($path) && @rename($removing, $path)) {
                throw new Failure("could not remove {$path}", EXIT_OPERATION);
            }

            throw new Failure("could not remove {$path}; its content stays in {$removing}", EXIT_OPERATION);
        }
    }

    private static function removeTree(string $path): void
    {
        $stat = @lstat($path);

        if ($stat === false) {
            return;
        }

        if (($stat['mode'] & 0170000) !== 0040000) {
            @unlink($path);

            return;
        }

        @chmod($path, 0700);
        self::removeContents($path);
        @rmdir($path);
    }

    private static function removeContents(string $path): void
    {
        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path.'/'.$entry);
            }
        }
    }

    /**
     * A test hook (test mode only): with ORRERY_TEST_REMOVAL_HOLD=<dir> and
     * ORRERY_TEST_REMOVAL_HOLD_AT=<point>, the removal writes the path into
     * <dir>/<point>.reached and waits (at most 60 s) for <dir>/<point>.release.
     * The points: "closed", after a shutdown closed its owner record and
     * before it stops anything; "store", before an owner store is removed;
     * and "rmdir", after a directory was emptied and before its rmdir.
     */
    public static function hold(string $point, string $path): void
    {
        $dir = Environment::testMode() ? getenv('ORRERY_TEST_REMOVAL_HOLD') : false;

        if (! is_string($dir) || $dir === '' || getenv('ORRERY_TEST_REMOVAL_HOLD_AT') !== $point) {
            return;
        }

        @file_put_contents("{$dir}/{$point}.tmp", $path."\n");
        @rename("{$dir}/{$point}.tmp", "{$dir}/{$point}.reached");
        $deadline = microtime(true) + 60;

        while (! file_exists("{$dir}/{$point}.release") && microtime(true) < $deadline) {
            usleep(20_000);
            clearstatcache();
        }
    }
}

/**
 * Every external action goes through this interface. The same decision and
 * locking code runs with DockerOps and with FakeOps.
 */
interface Ops
{
    /**
     * @return array<string, array{state: string, health: string, ports: list<int>, id: string}>
     */
    public function services(): array;

    public function portOccupied(int $port): bool;

    /**
     * Starts or confirms the default services, and with $alt also the
     * services of the profile "alt". With $only, just those services (the
     * services of one engine).
     *
     * @param  list<string>|null  $only
     */
    public function up(bool $alt = false, ?array $only = null): void;

    public function readMarker(string $service): ?string;

    public function writeMarker(string $service, string $token): void;

    /**
     * @param  list<string>  $names
     */
    public function createDatabases(string $service, array $names): void;

    /**
     * @param  list<string>  $names
     */
    public function dropDatabases(string $service, array $names): void;

    /**
     * @return list<string>
     */
    public function listDatabases(string $service): array;

    public function down(): void;
}

final class DockerOps implements Ops
{
    /** @var \Closure(list<string>, array<string, string>, float): array{0: int, 1: string, 2: string} */
    private readonly \Closure $runner;

    /**
     * @param  array<string, int>  $ports  the configured port of each service of ALL_SERVICES
     * @param  (\Closure(list<string> $command, array<string, string> $env, float $timeout): array{0: int, 1: string, 2: string})|null  $runner
     *                                                                                                                                           runs every Compose command; Run::capture() by default. Tests record the commands with it.
     */
    public function __construct(private readonly string $composeFile, private readonly array $ports, ?\Closure $runner = null)
    {
        $this->runner = $runner ?? static fn (array $command, array $env, float $timeout): array => Run::capture($command, $env, $timeout);
    }

    /**
     * One Compose command of the project. With $profile, `--profile alt`
     * comes directly before the subcommand, so the command also sees the
     * alt services.
     *
     * @param  list<string>  $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private function compose(array $arguments, float $timeout = 120.0, bool $profile = false): array
    {
        $env = [];

        foreach (getenv() as $name => $value) {
            if (! str_starts_with($name, 'COMPOSE_')) {
                $env[$name] = $value;
            }
        }

        foreach (PORT_VARIABLES as $service => $variable) {
            $env[$variable] = (string) $this->ports[$service];
        }

        $command = ['docker', 'compose', '--project-name', PROJECT, '--file', $this->composeFile, ...($profile ? ['--profile', 'alt'] : []), ...$arguments];
        $result = ($this->runner)($command, $env, $timeout);

        return [(int) $result[0], (string) $result[1], (string) $result[2]];
    }

    public function services(): array
    {
        // With the profile, so the list has the alt services too.
        [$status, $out, $err] = $this->compose(['ps', '--all', '--format', 'json'], 60.0, true);

        if ($status !== 0) {
            throw new Failure('docker compose ps failed: '.trim($err), EXIT_OPERATION);
        }

        $rows = [];
        $trimmed = trim($out);

        if ($trimmed !== '' && $trimmed[0] === '[') {
            $rows = json_decode($trimmed, true) ?: [];
        } elseif ($trimmed !== '') {
            foreach (preg_split('/\R/', $trimmed) ?: [] as $line) {
                $row = json_decode($line, true);

                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        $services = [];

        foreach (ALL_SERVICES as $service) {
            $services[$service] = ['state' => 'absent', 'health' => '', 'ports' => [], 'id' => ''];
        }

        foreach ($rows as $row) {
            $service = $row['Service'] ?? '';

            if (! is_string($service) || ! isset($services[$service])) {
                continue;
            }

            $ports = [];

            foreach ($row['Publishers'] ?? [] as $publisher) {
                if (is_array($publisher) && (int) ($publisher['PublishedPort'] ?? 0) > 0) {
                    $ports[] = (int) $publisher['PublishedPort'];
                }
            }

            $services[$service] = [
                'state' => strtolower((string) ($row['State'] ?? '')),
                'health' => strtolower((string) ($row['Health'] ?? '')),
                'ports' => array_values(array_unique($ports)),
                'id' => (string) ($row['ID'] ?? ''),
            ];
        }

        return $services;
    }

    public function portOccupied(int $port): bool
    {
        $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 1.0);

        if ($client !== false) {
            fclose($client);

            return true;
        }

        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);

        if ($server === false) {
            return true;
        }

        fclose($server);

        return false;
    }

    public function up(bool $alt = false, ?array $only = null): void
    {
        fwrite(STDERR, 'testdb: no live lease, so docker compose up --wait starts or confirms the orrery-testing services'.($only === null ? '' : ' '.implode(', ', $only)).($alt ? ' with the profile alt' : '')."\n");
        [$status, , $err] = $this->compose(['up', '--detach', '--wait', '--wait-timeout', '180', ...($only ?? [])], 300.0, $alt);

        if ($status !== 0) {
            throw new Failure('docker compose up failed: '.trim($err), EXIT_OPERATION);
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function mysql(string $service, string $sql): array
    {
        return $this->compose([
            'exec', '-T', '--env', 'MYSQL_PWD='.PASSWORD, $service,
            MYSQL_CLIENTS[$service], '--user=root', '--batch', '--skip-column-names', '--execute='.$sql,
        ], 120.0, in_array($service, ALT_SERVICES, true));
    }

    /**
     * Each command runs in its own transaction, so CREATE DATABASE works.
     *
     * @param  list<string>  $commands
     * @return array{0: int, 1: string, 2: string}
     */
    private function psql(string $service, array $commands): array
    {
        $arguments = [
            'exec', '-T', $service,
            'psql', '--username=postgres', '--dbname=postgres', '--port='.PGSQL_CONTAINER_PORTS[$service], '--no-psqlrc', '--quiet',
            '--tuples-only', '--no-align', '--set=ON_ERROR_STOP=1',
        ];

        foreach ($commands as $command) {
            $arguments[] = '--command='.$command;
        }

        return $this->compose($arguments, 120.0, in_array($service, ALT_SERVICES, true));
    }

    /**
     * A MySQL or MariaDB service: one SQL dialect for the markers and the databases.
     */
    private static function isMysql(string $service): bool
    {
        return isset(MYSQL_CLIENTS[$service]);
    }

    /**
     * @param  array{0: int, 1: string, 2: string}  $result
     */
    private function check(array $result, string $what): string
    {
        if ($result[0] !== 0) {
            throw new Failure("{$what} failed: ".trim($result[2]), EXIT_OPERATION);
        }

        return $result[1];
    }

    public function readMarker(string $service): ?string
    {
        if (self::isMysql($service)) {
            [$status, $out] = $this->mysql($service, 'SELECT token FROM orrery_marker.marker');
            $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn ($line) => $line !== ''));

            return ($status === 0 && count($lines) === 1 && preg_match(TOKEN_PATTERN, $lines[0]) === 1) ? $lines[0] : null;
        }

        // Postgres: the table row, plus the database comment that any
        // connection can read through the shared catalog. Both must agree.
        [$status, $out] = $this->psql($service, [
            "SELECT COALESCE(shobj_description(oid, 'pg_database'), '') FROM pg_database WHERE datname = 'orrery_marker'",
            '\\connect orrery_marker',
            'SELECT token FROM marker',
        ]);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn ($line) => $line !== ''));

        if ($status !== 0 || count($lines) !== 2 || $lines[0] !== $lines[1] || preg_match(TOKEN_PATTERN, $lines[0]) !== 1) {
            return null;
        }

        return $lines[0];
    }

    public function writeMarker(string $service, string $token): void
    {
        if (preg_match(TOKEN_PATTERN, $token) !== 1) {
            throw new Failure('invalid token', EXIT_FAILURE);
        }

        if (self::isMysql($service)) {
            $this->check($this->mysql(
                $service,
                'DROP DATABASE IF EXISTS orrery_marker; CREATE DATABASE orrery_marker; '
                .'CREATE TABLE orrery_marker.marker (token VARCHAR(64) NOT NULL PRIMARY KEY); '
                ."INSERT INTO orrery_marker.marker (token) VALUES ('{$token}');"
            ), 'writing the MySQL marker');

            return;
        }

        $this->check($this->psql($service, [
            'DROP DATABASE IF EXISTS orrery_marker WITH (FORCE)',
            'CREATE DATABASE orrery_marker',
            "COMMENT ON DATABASE orrery_marker IS '{$token}'",
            '\\connect orrery_marker',
            'CREATE TABLE marker (token varchar(64) NOT NULL PRIMARY KEY)',
            "INSERT INTO marker (token) VALUES ('{$token}')",
        ]), 'writing the Postgres marker');
    }

    public function createDatabases(string $service, array $names): void
    {
        foreach ($names as $name) {
            Names::assertPhysical($name);
        }

        if (self::isMysql($service)) {
            $sql = 'SET SESSION lock_wait_timeout = 30;';
            foreach ($names as $name) {
                $sql .= " DROP DATABASE IF EXISTS `{$name}`; CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
            }
            $this->check($this->mysql($service, $sql), 'creating MySQL databases');

            return;
        }

        $commands = [];
        foreach ($names as $name) {
            $commands[] = "DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)";
            $commands[] = "CREATE DATABASE \"{$name}\"";
        }
        $this->check($this->psql($service, $commands), 'creating Postgres databases');
    }

    public function dropDatabases(string $service, array $names): void
    {
        foreach ($names as $name) {
            Names::assertPhysical($name);
        }

        if ($names === []) {
            return;
        }

        if (self::isMysql($service)) {
            $sql = 'SET SESSION lock_wait_timeout = 30;';
            foreach ($names as $name) {
                $sql .= " DROP DATABASE IF EXISTS `{$name}`;";
            }
            $this->check($this->mysql($service, $sql), 'dropping MySQL databases');

            return;
        }

        $commands = [];
        foreach ($names as $name) {
            $commands[] = "DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)";
        }
        $this->check($this->psql($service, $commands), 'dropping Postgres databases');
    }

    public function listDatabases(string $service): array
    {
        $out = self::isMysql($service)
            ? $this->check($this->mysql($service, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE LEFT(SCHEMA_NAME, 7) = 'orrery_' ORDER BY 1"), 'listing MySQL databases')
            : $this->check($this->psql($service, ["SELECT datname FROM pg_database WHERE left(datname, 7) = 'orrery_' ORDER BY 1"]), 'listing Postgres databases');

        return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn ($line) => $line !== ''));
    }

    public function down(): void
    {
        // With the profile, so down stops the alt services too.
        [$status, , $err] = $this->compose(['down', '--volumes', '--timeout', '10'], 180.0, true);

        if ($status !== 0) {
            throw new Failure('docker compose down failed: '.trim($err), EXIT_OPERATION);
        }
    }
}

/**
 * The test backend. Its state (services, markers, databases, occupied ports,
 * and a record of every call) is a JSON file in the test registry. Process
 * checks are real, so tests use real processes as supervisors and children.
 *
 * ORRERY_FAKE_HOLD_AT=<operation> holds the command inside that operation:
 * it creates <operation>.reached and waits for <operation>.release in
 * ORRERY_FAKE_BARRIER_DIR (default: the registry directory).
 */
final class FakeOps implements Ops
{
    private readonly string $file;

    private readonly ?string $holdAt;

    private readonly string $barrierDir;

    /**
     * @param  array<string, int>  $ports  the configured port of each service of ALL_SERVICES
     */
    public function __construct(string $dir, private readonly array $ports, private readonly string $command)
    {
        $this->file = $dir.'/fake-ops.json';
        $hold = getenv('ORRERY_FAKE_HOLD_AT');
        $this->holdAt = ($hold === false || $hold === '') ? null : $hold;
        $barrier = getenv('ORRERY_FAKE_BARRIER_DIR');
        $this->barrierDir = ($barrier === false || $barrier === '') ? $dir : $barrier;
    }

    /**
     * @return array<string, mixed>
     */
    public static function initialState(): array
    {
        $services = [];

        foreach (ALL_SERVICES as $service) {
            $services[$service] = ['state' => 'absent', 'health' => '', 'ports' => [], 'id' => '', 'marker' => null, 'databases' => [], 'generation' => 0];
        }

        return [
            'definition' => 'v1',
            'running_definition' => null,
            'services' => $services,
            'health_after_up' => [],
            'ports_taken' => [],
            'fail' => [],
            'calls' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if (! file_exists($this->file)) {
            return self::initialState();
        }

        $state = json_decode((string) file_get_contents($this->file), true);

        if (! is_array($state)) {
            throw new Failure("fake backend state {$this->file} is corrupt", EXIT_FAILURE);
        }

        $initial = self::initialState();
        $merged = array_replace($initial, $state);
        // A state file that a test wrote may name only some services.
        $merged['services'] = array_replace($initial['services'], is_array($state['services'] ?? null) ? $state['services'] : []);

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(array $state): void
    {
        file_put_contents($this->file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function record(string $operation, array $arguments = []): array
    {
        $state = $this->load();
        $state['calls'][] = ['op' => $operation, 'args' => $arguments, 'command' => $this->command, 'pid' => getmypid()];

        if (in_array($operation, $state['fail'], true)) {
            $this->save($state);

            throw new Failure("fake backend: {$operation} failed", EXIT_OPERATION);
        }

        return $state;
    }

    private function hold(string $operation): void
    {
        if ($this->holdAt !== $operation) {
            return;
        }

        touch($this->barrierDir.'/'.$operation.'.reached');
        $deadline = microtime(true) + 60;

        while (! file_exists($this->barrierDir.'/'.$operation.'.release')) {
            if (microtime(true) >= $deadline) {
                throw new Failure("fake backend: the hold at {$operation} was never released", EXIT_FAILURE);
            }

            usleep(10_000);
            clearstatcache();
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function requireRunning(array $state, string $service, string $operation): void
    {
        if ($state['services'][$service]['state'] !== 'running') {
            throw new Failure("fake backend: {$operation} on {$service}, which is not running", EXIT_OPERATION);
        }
    }

    public function services(): array
    {
        $state = $this->record('ps');
        $this->save($state);
        $services = [];

        foreach (ALL_SERVICES as $service) {
            $current = $state['services'][$service];
            $services[$service] = ['state' => $current['state'], 'health' => $current['health'], 'ports' => $current['ports'], 'id' => $current['id']];
        }

        return $services;
    }

    public function portOccupied(int $port): bool
    {
        return in_array($port, $this->load()['ports_taken'], true);
    }

    public function up(bool $alt = false, ?array $only = null): void
    {
        $this->hold('up');
        $state = $this->record('up', $only === null ? ['alt' => $alt] : ['alt' => $alt, 'only' => $only]);
        $unhealthy = [];

        // Like Compose: without the profile, the alt services stay as they are; with names, only those services.
        foreach ($only ?? ($alt ? ALL_SERVICES : SERVICES) as $service) {
            $current = &$state['services'][$service];
            // Like Compose: each service runs the definition of its own last start.
            $recreate = $current['state'] !== 'running'
                || ($current['definition'] ?? $state['running_definition']) !== $state['definition']
                || $current['ports'] !== [$this->ports[$service]];

            if ($recreate) {
                $current['generation']++;
                $current['id'] = "fake-{$service}-{$current['generation']}";
                $current['marker'] = null;
                $current['databases'] = [];
            }

            $current['state'] = 'running';
            $current['definition'] = $state['definition'];
            $current['health'] = $state['health_after_up'][$service] ?? 'healthy';
            $current['ports'] = [$this->ports[$service]];

            if ($current['health'] !== 'healthy') {
                $unhealthy[] = $service;
            }

            unset($current);
        }

        if ($only === null) {
            $state['running_definition'] = $state['definition'];
        }

        $this->save($state);

        if ($unhealthy !== []) {
            throw new Failure('fake backend: docker compose up failed: '.implode(', ', $unhealthy).' did not become healthy', EXIT_OPERATION);
        }
    }

    public function readMarker(string $service): ?string
    {
        $state = $this->record('read_marker', ['service' => $service]);
        $this->save($state);
        $current = $state['services'][$service];

        return $current['state'] === 'running' ? $current['marker'] : null;
    }

    public function writeMarker(string $service, string $token): void
    {
        $this->hold('write_marker');
        $state = $this->record('write_marker', ['service' => $service, 'token' => $token]);
        $this->requireRunning($state, $service, 'write_marker');
        $state['services'][$service]['marker'] = $token;
        $state['services'][$service]['databases'] = array_values(array_unique([...$state['services'][$service]['databases'], Names::MARKER]));
        $this->save($state);
    }

    public function createDatabases(string $service, array $names): void
    {
        foreach ($names as $name) {
            Names::assertPhysical($name);
        }

        $this->hold('create_databases');
        $state = $this->record('create_databases', ['service' => $service, 'names' => $names]);
        $this->requireRunning($state, $service, 'create_databases');
        $state['services'][$service]['databases'] = array_values(array_unique([...$state['services'][$service]['databases'], ...$names]));
        $this->save($state);
    }

    public function dropDatabases(string $service, array $names): void
    {
        foreach ($names as $name) {
            Names::assertPhysical($name);
        }

        $this->hold('drop_databases');
        $state = $this->record('drop_databases', ['service' => $service, 'names' => $names]);
        $this->requireRunning($state, $service, 'drop_databases');
        $state['services'][$service]['databases'] = array_values(array_diff($state['services'][$service]['databases'], $names));
        $this->save($state);
    }

    public function listDatabases(string $service): array
    {
        $current = $this->load()['services'][$service];

        return $current['state'] === 'running' ? array_values($current['databases']) : [];
    }

    public function down(): void
    {
        $this->hold('down');
        $state = $this->record('down');

        foreach (ALL_SERVICES as $service) {
            $state['services'][$service] = array_replace($state['services'][$service], ['state' => 'absent', 'health' => '', 'ports' => [], 'id' => '', 'marker' => null, 'databases' => []]);
        }

        $state['running_definition'] = null;
        $this->save($state);
    }
}

/**
 * The commands. Each registry command runs inside one lock.
 */
final class App
{
    private ?Ops $ops = null;

    public function __construct(private readonly string $command) {}

    private function registry(): Registry
    {
        static $registry = null;

        return $registry ??= Registry::resolve();
    }

    private function ops(): Ops
    {
        return $this->ops ??= Environment::ops($this->registry(), $this->command);
    }

    /**
     * Runs $callback with the registry data under the exclusive lock. The
     * data is saved when the callback returns or throws, so a pruned stale
     * lease stays pruned even when a later step fails.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>&): T  $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $registry = $this->registry();
        $this->ops();
        $registry->lock();

        try {
            $data = $registry->load();

            try {
                return $callback($data);
            } finally {
                $registry->save($data);
            }
        } finally {
            $registry->unlock();
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    public function run(array $arguments): array
    {
        return match ($this->command) {
            'path' => $this->path($arguments),
            'validate-name' => $this->validateName($arguments),
            'up' => $this->up($arguments),
            'admit' => $this->admit($arguments),
            'add-child' => $this->addChild($arguments),
            'create' => $this->createOrDrop($arguments, true),
            'drop' => $this->createOrDrop($arguments, false),
            'release' => $this->release($arguments),
            'stop-children' => $this->stopChildren($arguments),
            'stop-group' => $this->stopGroup($arguments),
            'owner-init' => $this->ownerInit($arguments),
            'owner-path' => $this->ownerPath($arguments),
            'owner-join' => $this->ownerJoin($arguments),
            'owner-wait' => $this->ownerWait($arguments),
            'anchor-linger' => $this->anchorLinger($arguments),
            'shutdown' => $this->shutdown($arguments),
            'down' => $this->down($arguments),
            'token' => $this->token($arguments),
            'status' => $this->status($arguments),
            'slot-hold' => $this->slotHold($arguments),
            'running-test' => $this->runningTest($arguments),
            default => throw new Failure(usage(), EXIT_USAGE),
        };
    }

    /**
     * @param  list<string>  $arguments
     * @param  int|array{0: int, 1: int}  $positional  an exact count, or [min, max]
     * @param  array<string, bool>  $options  option name => takes a value
     * @return array{0: list<string>, 1: array<string, string|true>}
     */
    private static function parse(array $arguments, int|array $positional, array $options): array
    {
        $values = [];
        $parsed = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);

                if (! array_key_exists($name, $options) || ($options[$name] === ($value === null))) {
                    throw new Failure("unknown or malformed option {$argument}\n".usage(), EXIT_USAGE);
                }

                $parsed[$name] = $value ?? true;

                continue;
            }

            $values[] = $argument;
        }

        [$min, $max] = is_int($positional) ? [$positional, $positional] : $positional;

        if (count($values) < $min || count($values) > $max) {
            throw new Failure('expected '.($min === $max ? $min : "{$min} to {$max}")." argument(s)\n".usage(), EXIT_USAGE);
        }

        return [$values, $parsed];
    }

    private static function runId(string $runId): string
    {
        if (preg_match(RUN_ID_PATTERN, $runId) !== 1) {
            throw new Failure('invalid run id '.Names::quote($runId).': expected run_<pid>_<8 hex>', EXIT_USAGE);
        }

        return $runId;
    }

    private static function pid(string $pid): int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $pid) !== 1 || (int) $pid < 2) {
            throw new Failure('invalid pid '.Names::quote($pid), EXIT_USAGE);
        }

        return (int) $pid;
    }

    /**
     * A grace period in seconds before SIGKILL (default 10).
     *
     * @param  array<string, string|true>  $options
     */
    private static function grace(array $options): float
    {
        $value = (string) ($options['grace'] ?? '10');

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 600) {
            throw new Failure('--grace must be a number of seconds from 0 to 600', EXIT_USAGE);
        }

        return (float) $value;
    }

    /**
     * The identity of a live process: the given start must be its real start
     * (`LC_ALL=C TZ=UTC ps -o lstart= -p <pid>`), or "auto" to look it up.
     *
     * @return array{pid: int, start: string}
     */
    private static function identity(int $pid, string $start): array
    {
        $actual = Processes::starts([$pid])[$pid] ?? null;

        if ($actual === null) {
            throw new Failure("pid {$pid} is not a running process", EXIT_USAGE);
        }

        if ($start !== 'auto' && Processes::normalise($start) !== $actual) {
            throw new Failure("pid {$pid} started at {$actual}, not at ".Processes::normalise($start), EXIT_USAGE);
        }

        return ['pid' => $pid, 'start' => $actual];
    }

    /**
     * @return list<string>
     */
    private static function engineServices(string $engine): array
    {
        if (! isset(ENGINE_SERVICES[$engine])) {
            throw new Failure("invalid engine {$engine}: expected mysql, pgsql, mariadb or all", EXIT_USAGE);
        }

        return ENGINE_SERVICES[$engine];
    }

    /**
     * The alt services of an engine (--alt). MariaDB has no alt server.
     *
     * @return list<string>
     */
    private static function altServices(string $engine): array
    {
        self::engineServices($engine);

        if (! isset(ENGINE_ALT_SERVICES[$engine])) {
            throw new Failure("--alt: the engine {$engine} has no alt server", EXIT_USAGE);
        }

        return ENGINE_ALT_SERVICES[$engine];
    }

    /**
     * Which recorded processes of a record live. Each entry is judged by its
     * own identity (PID and start time) against the inspection, never by
     * another entry with the same PID: two records can hold the same PID
     * with different start times, one current and one reused.
     *
     * "children" lists the PIDs of the entries with a live, owned process.
     * "unverified" lists the processes that have the group ID of a recorded
     * group but cannot be proved to belong to it. A record with either one
     * is live (see isLive()): an unverified process fails closed.
     *
     * @param  array<string, mixed>  $record
     * @param  array{all: array<int, array{pid: int, ppid: int, pgid: int, zombie: bool, start: string}>|null, starts: array<int, string|null>}  $inspection
     * @return array{supervisor: bool, children: list<int>, unverified: list<int>}
     */
    private static function aliveProcesses(array $record, array $inspection): array
    {
        $children = [];
        $unverified = [];

        foreach ($record['children'] as $child) {
            [$alive, $unknown] = self::entryAlive($child, $inspection);

            if ($alive) {
                $children[] = $child['pid'];
            }

            $unverified = [...$unverified, ...$unknown];
        }

        return ['supervisor' => self::entryAlive($record['supervisor'], $inspection)[0], 'children' => $children, 'unverified' => $unverified];
    }

    /**
     * @param  array{supervisor: bool, children: list<int>, unverified: list<int>}  $alive
     */
    private static function isLive(array $alive): bool
    {
        return $alive['supervisor'] || $alive['children'] !== [] || $alive['unverified'] !== [];
    }

    /**
     * A plain entry lives while its PID has its start time. A group entry
     * (bin/test's PHPUnit) lives while a process of the recorded group lives,
     * registered or not, or while a process with its group ID cannot be
     * verified (see Processes::groupMembers()).
     *
     * @param  array<string, mixed>  $entry
     * @param  array{all: array<int, array{pid: int, ppid: int, pgid: int, zombie: bool, start: string}>|null, starts: array<int, string|null>}  $inspection
     * @return array{0: bool, 1: list<int>} live, and the unverified PIDs
     */
    private static function entryAlive(array $entry, array $inspection): array
    {
        if ($entry['group'] ?? false) {
            $token = is_string($entry['token'] ?? null) ? $entry['token'] : null;
            $members = Processes::groupMembers($inspection['all'] ?? [], $entry['pid'], $entry['start'], static fn (array $pids): array => Processes::carryToken($pids, $token));

            if ($members === null) {
                return [false, []];
            }

            return [$members['owned'] !== [], array_keys($members['unverified'])];
        }

        return [($inspection['starts'][$entry['pid']] ?? null) === $entry['start'], []];
    }

    /**
     * One inspection of every process that the records name. A group entry
     * needs the full process listing. A failed inspection throws (exit 74).
     *
     * @param  list<array<string, mixed>>  $records
     * @return array{all: array<int, array{pid: int, ppid: int, pgid: int, zombie: bool, start: string}>|null, starts: array<int, string|null>}
     */
    private static function inspect(array $records, bool $supervisors = true): array
    {
        $pids = [];
        $groups = false;

        foreach ($records as $record) {
            if ($supervisors) {
                $pids[] = $record['supervisor']['pid'];
            }

            foreach ($record['children'] as $child) {
                $pids[] = $child['pid'];
                $groups = $groups || ($child['group'] ?? false);
            }
        }

        if (! $groups) {
            return ['all' => null, 'starts' => $pids === [] ? [] : Processes::starts($pids)];
        }

        $all = Processes::snapshot();
        $starts = [];

        foreach ($pids as $pid) {
            $process = $all[$pid] ?? null;
            $starts[$pid] = ($process === null || $process['zombie']) ? null : $process['start'];
        }

        return ['all' => $all, 'starts' => $starts];
    }

    /**
     * Removes every lease whose supervisor and recorded children are all gone.
     * The databases of a removed lease that drops its names (generated, or
     * --drop-at-release) become orphans, which the next command with healthy
     * services drops.
     *
     * @param  array<string, mixed>  $data
     * @param  array{all: array<int, array<string, mixed>>|null, starts: array<int, string|null>}|null  $inspection  an inspection already made in this command
     * @return list<string> the removed run ids
     */
    private function pruneStale(array &$data, ?array $inspection = null): array
    {
        // An inspection that fails throws (exit 74) before any lease changes.
        $starts = $inspection ?? self::inspect(array_values($data['leases']));
        $removed = [];

        foreach ($data['leases'] as $runId => $lease) {
            // An unverified process keeps the lease: it is never proof of absence.
            if (self::isLive(self::aliveProcesses($lease, $starts))) {
                continue;
            }

            if (self::drops($lease)) {
                foreach ($lease['names'] as $name) {
                    $data['orphans'][] = ['name' => $name, 'services' => $lease['services']];
                }
            }

            unset($data['leases'][$runId]);
            $removed[] = (string) $runId;
        }

        return $removed;
    }

    /**
     * Whether the release of a lease drops its databases: a generated name,
     * or an explicit one admitted with --drop-at-release. A lease that an
     * older checkout wrote has no "drop" field.
     *
     * @param  array<string, mixed>  $lease
     */
    private static function drops(array $lease): bool
    {
        return (bool) ($lease['drop'] ?? $lease['generated'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{run_id: string, supervisor: array<string, mixed>}|null
     */
    private static function holder(array $data, string $name): ?array
    {
        foreach ($data['leases'] as $runId => $lease) {
            if (in_array($name, $lease['names'], true)) {
                return ['run_id' => (string) $runId, 'supervisor' => $lease['supervisor']];
            }
        }

        return null;
    }

    /**
     * The service ensure step. Callers prune stale leases first, so every
     * remaining lease is live.
     *
     * With a live lease it never changes the services: they must be running,
     * healthy, publish the configured ports and carry the registry token.
     * Without one it checks the ports, runs `up --wait`, and reconciles the
     * markers (a new token when anything is missing or different).
     *
     * Without $alt it covers the default services only. With $alt (up --alt,
     * admit --alt) it covers the alt services too: under a live lease they
     * must already be running with the token, else it starts them. With
     * $only (an admission: the services of its engine) it covers only
     * those, so a MySQL run never needs the Postgres or MariaDB server. A
     * new token goes to every running service, the uncovered ones included,
     * so a later call for them under a live lease finds matching markers.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $only
     * @return string "kept" or "new"
     */
    private function ensureServices(array &$data, bool $alt = false, ?array $only = null): string
    {
        $ops = $this->ops();
        $ports = Environment::ports();
        $services = $ops->services();
        $covered = $only ?? ($alt ? ALL_SERVICES : SERVICES);

        if ($data['leases'] !== []) {
            $holders = implode(', ', array_keys($data['leases']));

            foreach ($covered as $service) {
                $current = $services[$service];

                if ($current['state'] !== 'running' || $current['health'] !== 'healthy') {
                    throw new Failure(sprintf(
                        'service %s is %s%s while live leases exist (%s); no service is started, restarted or recreated while a lease is live',
                        $service,
                        $current['state'],
                        $current['health'] === '' ? '' : " ({$current['health']})",
                        $holders,
                    ), EXIT_SERVICES);
                }

                if (! in_array($ports[$service], $current['ports'], true)) {
                    if ($ops->portOccupied($ports[$service])) {
                        throw new Failure("port 127.0.0.1:{$ports[$service]} is in use by a process outside the orrery-testing project; it was not touched", EXIT_PORT_TAKEN);
                    }

                    throw new Failure(sprintf(
                        'service %s publishes %s, not the configured port %d, while live leases exist (%s); the services cannot change while a lease is live',
                        $service,
                        $current['ports'] === [] ? 'no port' : 'port '.implode(', ', $current['ports']),
                        $ports[$service],
                        $holders,
                    ), EXIT_SERVICES);
                }
            }

            foreach ($covered as $service) {
                $marker = $ops->readMarker($service);

                if ($data['token'] === null || $marker !== $data['token']) {
                    throw new Failure(sprintf(
                        'the %s endpoint marker (%s) differs from the registry token (%s) while live leases exist (%s); nothing was changed',
                        $service,
                        $marker ?? 'missing',
                        $data['token'] ?? 'none',
                        $holders,
                    ), EXIT_MARKER);
                }
            }

            return 'kept';
        }

        foreach ($covered as $service) {
            $ours = in_array($ports[$service], $services[$service]['ports'], true);

            if (! $ours && $ops->portOccupied($ports[$service])) {
                throw new Failure("port 127.0.0.1:{$ports[$service]} is in use by a process outside the orrery-testing project; it was not touched", EXIT_PORT_TAKEN);
            }
        }

        $ops->up($alt, $only);
        $agree = $data['token'] !== null;

        foreach ($covered as $service) {
            if ($ops->readMarker($service) !== $data['token']) {
                $agree = false;
            }
        }

        if ($agree) {
            return 'kept';
        }

        $token = bin2hex(random_bytes(16));
        $marked = $covered;

        // up() left the uncovered services as they were: a running one gets the new token too.
        foreach (ALL_SERVICES as $service) {
            if (! in_array($service, $marked, true) && $services[$service]['state'] === 'running' && $services[$service]['health'] === 'healthy') {
                $marked[] = $service;
            }
        }

        foreach ($marked as $service) {
            $ops->writeMarker($service, $token);
        }

        $data['token'] = $token;

        return 'new';
    }

    /**
     * Drops the orphaned databases of stale leases. A failure keeps them
     * listed for the next attempt.
     *
     * @param  array<string, mixed>  $data
     */
    private function dropOrphans(array &$data): void
    {
        $remaining = [];
        $byService = [];

        foreach ($data['orphans'] as $orphan) {
            if (self::holder($data, $orphan['name']) !== null) {
                continue;
            }

            foreach ($orphan['services'] as $service) {
                $byService[$service][] = $orphan['name'];
            }
        }

        foreach ($byService as $service => $names) {
            try {
                $this->ops()->dropDatabases($service, array_values(array_unique($names)));
            } catch (Failure $failure) {
                fwrite(STDERR, "testdb: could not drop orphaned databases on {$service}: {$failure->getMessage()}\n");

                foreach (array_unique($names) as $name) {
                    $remaining[] = ['name' => $name, 'services' => [$service]];
                }
            }
        }

        $data['orphans'] = $remaining;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function path(array $arguments): array
    {
        self::parse($arguments, 0, []);

        return ['path' => $this->registry()->dir];
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function validateName(array $arguments): array
    {
        [[$base]] = self::parse($arguments, 1, []);
        [$base, $mirror] = Names::pair($base);

        return ['base' => $base, 'mirror' => $mirror, 'base_length' => strlen($base), 'mirror_length' => strlen($mirror)];
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function up(array $arguments): array
    {
        [, $options] = self::parse($arguments, 0, ['alt' => false]);
        $alt = isset($options['alt']);

        return $this->locked(function (array &$data) use ($alt): array {
            $pruned = $this->pruneStale($data);
            $generation = $this->ensureServices($data, $alt);
            $this->dropOrphans($data);

            return ['token' => $data['token'], 'generation' => $generation, 'live_leases' => array_keys($data['leases']), 'pruned' => $pruned];
        });
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function admit(array $arguments): array
    {
        [[$runId, $pid, $start, $base], $options] = self::parse($arguments, 4, ['generated' => false, 'explicit' => false, 'drop-at-release' => false, 'engine' => true, 'path' => true, 'alt' => false]);
        $runId = self::runId($runId);
        $pid = self::pid($pid);
        [$base, $mirror] = Names::pair($base);

        if (isset($options['generated']) === isset($options['explicit'])) {
            throw new Failure('admit needs exactly one of --generated and --explicit', EXIT_USAGE);
        }

        if (isset($options['drop-at-release']) && ! isset($options['explicit'])) {
            throw new Failure('--drop-at-release applies to --explicit only: the databases of a generated name are always dropped', EXIT_USAGE);
        }

        // Whether the release drops both databases: always for a generated
        // name, and for an explicit name that the run owns only for its own
        // duration (bin/test --drop-database, which bin/matrix uses).
        $drop = isset($options['generated']) || isset($options['drop-at-release']);

        $engine = (string) ($options['engine'] ?? 'all');
        $alt = isset($options['alt']);
        // An --alt lease also holds its names on the alt service of its
        // engine, so the admission creates and the release drops them there.
        $services = [...self::engineServices($engine), ...($alt ? self::altServices($engine) : [])];
        $paths = [];

        if (isset($options['path'])) {
            $paths[] = Paths::validate((string) $options['path']);
            Paths::assertOwnedDirectory($paths[0]);
        }

        return $this->locked(function (array &$data) use ($runId, $pid, $start, $base, $mirror, $options, $drop, $engine, $alt, $services, $paths): array {
            $supervisor = self::identity($pid, $start);
            $pruned = $this->pruneStale($data);

            if (isset($data['leases'][$runId])) {
                throw new Failure("run {$runId} already has a lease", EXIT_USAGE);
            }

            // Only the servers of the run's engine: a MySQL run never needs the Postgres or MariaDB server.
            $generation = $this->ensureServices($data, $alt, $services);
            $this->dropOrphans($data);

            foreach ([$base, $mirror] as $name) {
                $holder = self::holder($data, $name);

                if ($holder !== null) {
                    throw new Failure(sprintf(
                        'database name %s is held by run %s (supervisor pid %d); nothing was changed',
                        $name,
                        $holder['run_id'],
                        $holder['supervisor']['pid'],
                    ), EXIT_NAME_HELD, ['holder' => $holder]);
                }
            }

            // The decision: reserve both names and write the lease. It is
            // saved before the action, and the lock stays held until the
            // databases exist.
            $data['orphans'] = array_values(array_filter($data['orphans'], static fn (array $orphan): bool => ! in_array($orphan['name'], [$base, $mirror], true)));
            $data['leases'][$runId] = [
                'run_id' => $runId,
                'kind' => 'lease',
                'supervisor' => $supervisor,
                'children' => [],
                'paths' => $paths,
                'closing' => false,
                'base' => $base,
                'names' => [$base, $mirror],
                'generated' => isset($options['generated']),
                'drop' => $drop,
                'engine' => $engine,
                'alt' => $alt,
                'services' => $services,
                'token' => $data['token'],
                'admitted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            $this->registry()->save($data);

            try {
                foreach ($services as $service) {
                    $this->ops()->createDatabases($service, [$base, $mirror]);
                }
            } catch (Failure $failure) {
                unset($data['leases'][$runId]);

                foreach ($services as $service) {
                    try {
                        $this->ops()->dropDatabases($service, [$base, $mirror]);
                    } catch (Failure) {
                        $data['orphans'][] = ['name' => $base, 'services' => [$service]];
                        $data['orphans'][] = ['name' => $mirror, 'services' => [$service]];
                    }
                }

                throw new Failure('creating the databases failed; the lease was withdrawn: '.$failure->getMessage(), EXIT_OPERATION);
            }

            return [
                'run_id' => $runId,
                'base' => $base,
                'mirror' => $mirror,
                'token' => $data['token'],
                'generated' => isset($options['generated']),
                'drop' => $drop,
                'engine' => $engine,
                'alt' => $alt,
                'services' => $services,
                'generation' => $generation,
                'pruned' => $pruned,
                'registry' => $this->registry()->dir,
            ];
        });
    }

    /**
     * add-child <run-id> <pid> <start|auto>: records a plain child process
     * (for example a worker) in a live lease. A closing lease refuses it (76).
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function addChild(array $arguments): array
    {
        [[$runId, $pid, $start]] = self::parse($arguments, 3, []);
        $runId = self::runId($runId);
        $pid = self::pid($pid);

        return $this->locked(function (array &$data) use ($runId, $pid, $start): array {
            $this->pruneStale($data);

            if (! isset($data['leases'][$runId])) {
                throw new Failure("no live lease for run {$runId}", EXIT_NO_LEASE);
            }

            $lease = &self::openRecord($data, ['run' => $runId, 'dir' => $this->registry()->dir]);
            $child = self::identity($pid, $start);
            self::addEntry($lease['children'], $child);
            unset($lease);

            return ['run_id' => $runId, 'child' => $child, 'children' => $data['leases'][$runId]['children']];
        });
    }

    /**
     * Adds an entry, or merges it into the entry with the same PID and start.
     *
     * @param  list<array<string, mixed>>  $children
     * @param  array<string, mixed>  $entry
     */
    private static function addEntry(array &$children, array $entry): void
    {
        foreach ($children as $index => $existing) {
            if ($existing['pid'] === $entry['pid'] && $existing['start'] === $entry['start']) {
                $children[$index] = array_merge($existing, $entry);

                return;
            }
        }

        $children[] = $entry;
    }

    /**
     * The record of an owner entry that can still take a new process group,
     * child or directory: it exists and it is not closing.
     *
     * @param  array<string, mixed>  $data
     * @param  array{run: string, dir: string}  $owner
     * @return array<string, mixed>
     */
    private static function &openRecord(array &$data, array $owner): array
    {
        if (! isset($data['leases'][$owner['run']])) {
            throw new Failure("run {$owner['run']} has no owner record in {$owner['dir']} (it has ended); nothing joined it", EXIT_NO_LEASE);
        }

        $record = &$data['leases'][$owner['run']];

        if ($record['closing'] ?? false) {
            throw new Failure("run {$owner['run']} is closing: no process group, child or directory can join it now", EXIT_CLOSING);
        }

        $record['children'] ??= [];
        $record['paths'] ??= [];

        return $record;
    }

    /**
     * Runs $callback with the data of an owner store under its lock, then
     * saves it. When the callback throws, nothing is saved.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>&): T  $callback
     * @return T
     */
    private static function withStore(string $dir, callable $callback): mixed
    {
        $store = Registry::store($dir);
        $store->lock();

        try {
            $data = $store->load();
            $result = $callback($data);
            $store->save($data);

            return $result;
        } finally {
            $store->unlock();
        }
    }

    /**
     * The owner record of an entry, read under the store's lock. A record
     * that cannot be read, or that is missing, throws: it is never taken as
     * a record without processes.
     *
     * @param  array{run: string, dir: string}  $owner
     * @return array<string, mixed>
     */
    private static function readRecord(array $owner): array
    {
        $store = Registry::store($owner['dir']);
        $store->lock();

        try {
            $data = $store->load();
        } finally {
            $store->unlock();
        }

        if (! isset($data['leases'][$owner['run']])) {
            throw new Failure("the owner record of run {$owner['run']} is missing from {$owner['dir']}; nothing more is stopped or removed", EXIT_OPERATION);
        }

        return $data['leases'][$owner['run']];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{pid: int, start: string, group: bool, token: string|null}>
     */
    private static function targets(array $record): array
    {
        return array_map(
            static fn (array $child): array => ['pid' => $child['pid'], 'start' => $child['start'], 'group' => (bool) ($child['group'] ?? false), 'token' => is_string($child['token'] ?? null) ? $child['token'] : null],
            array_values($record['children'] ?? []),
        );
    }

    /**
     * create/drop: validate the name, hold its lock for the whole command,
     * and run inside the containers. With ORRERY_TEST_RUN_ID set, that
     * run must hold the name. Without it, the name must be free, and the
     * command holds it for its own duration.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function createOrDrop(array $arguments, bool $create): array
    {
        [[$base], $options] = self::parse($arguments, 1, ['engine' => true, 'alt' => false]);
        [$base, $mirror] = Names::pair($base);
        $runId = getenv('ORRERY_TEST_RUN_ID');
        $runId = ($runId === false || $runId === '') ? null : self::runId($runId);

        return $this->locked(function (array &$data) use ($base, $mirror, $options, $runId, $create): array {
            $this->pruneStale($data);
            $holder = self::holder($data, $base) ?? self::holder($data, $mirror);

            if ($runId !== null && ($holder === null || $holder['run_id'] !== $runId)) {
                throw new Failure(sprintf(
                    'run %s does not hold %s%s; nothing was changed',
                    $runId,
                    $base,
                    $holder === null ? '' : " (run {$holder['run_id']} holds it)",
                ), EXIT_NAME_HELD, $holder === null ? [] : ['holder' => $holder]);
            }

            if ($runId === null && $holder !== null) {
                throw new Failure("database name {$base} is held by run {$holder['run_id']} (supervisor pid {$holder['supervisor']['pid']}); nothing was changed", EXIT_NAME_HELD, ['holder' => $holder]);
            }

            $default = $runId !== null ? $data['leases'][$runId]['engine'] : 'all';
            $engine = (string) ($options['engine'] ?? $default);
            $alt = isset($options['alt']) || ($runId !== null && ! isset($options['engine']) && ($data['leases'][$runId]['alt'] ?? false));
            $services = [...self::engineServices($engine), ...($alt ? self::altServices($engine) : [])];
            $status = $this->ops()->services();

            foreach ($services as $service) {
                if ($status[$service]['state'] !== 'running' || $status[$service]['health'] !== 'healthy') {
                    throw new Failure("service {$service} is not running and healthy; run bin/test-db up first", EXIT_SERVICES);
                }
            }

            foreach ($services as $service) {
                $create
                    ? $this->ops()->createDatabases($service, [$base, $mirror])
                    : $this->ops()->dropDatabases($service, [$base, $mirror]);
            }

            $data['orphans'] = array_values(array_filter($data['orphans'], static fn (array $orphan): bool => ! in_array($orphan['name'], [$base, $mirror], true)));

            return [($create ? 'created' : 'dropped') => [$base, $mirror], 'services' => $services, 'run_id' => $runId];
        });
    }

    /**
     * release <run-id>: refused while a recorded process of the lease lives.
     * Otherwise it removes the lease's recorded directories, drops its
     * databases when the lease drops its names (a generated name, or
     * --drop-at-release), and deletes it.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function release(array $arguments): array
    {
        [[$runId]] = self::parse($arguments, 1, []);
        $runId = self::runId($runId);

        return $this->locked(function (array &$data) use ($runId): array {
            if (! isset($data['leases'][$runId])) {
                return ['run_id' => $runId, 'released' => false, 'note' => 'no lease for this run'];
            }

            return $this->releaseLocked($data, $runId);
        });
    }

    /**
     * The release under the registry lock. Every process of every lease is
     * inspected before anything changes, so a failed inspection (exit 74)
     * leaves the lease, the names, the directories and the databases in
     * place. A directory that cannot be removed keeps the lease (exit 74).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function releaseLocked(array &$data, string $runId): array
    {
        $inspection = self::inspect(array_values($data['leases']));
        $lease = $data['leases'][$runId];
        self::assertNothingAlive($runId, self::aliveProcesses($lease, $inspection), 'the lease, its directories and its databases stay');

        [$removed, $failed] = Paths::removeAll(array_values($lease['paths'] ?? []));

        if ($failed !== []) {
            $data['leases'][$runId]['paths'] = $failed;

            throw new Failure("could not remove the directories of run {$runId} (".implode(', ', $failed).'); the lease keeps them', EXIT_OPERATION, ['removed' => $removed, 'failed' => $failed]);
        }

        $data['leases'][$runId]['paths'] = [];
        $dropped = [];

        // Only after assertNothingAlive() above, and while the lease still
        // holds both names.
        if (self::drops($lease)) {
            $status = $this->ops()->services();

            foreach ($lease['services'] as $service) {
                // A stopped or removed container lost its tmpfs data.
                if ($status[$service]['state'] !== 'running') {
                    continue;
                }

                $this->ops()->dropDatabases($service, $lease['names']);
                $dropped[$service] = $lease['names'];
            }
        }

        unset($data['leases'][$runId]);
        $pruned = $this->pruneStale($data, $inspection);

        return ['run_id' => $runId, 'released' => true, 'dropped' => $dropped, 'removed' => $removed, 'pruned' => $pruned];
    }

    /**
     * The precondition of every release and removal: no recorded process
     * lives (75), and no process with a recorded group ID is left unverified
     * (74). An unverified process is never taken as foreign.
     *
     * @param  array{supervisor: bool, children: list<int>, unverified: list<int>}  $alive
     */
    private static function assertNothingAlive(string $runId, array $alive, string $kept): void
    {
        if ($alive['children'] !== []) {
            throw new Failure("run {$runId} still has live child processes (pid ".implode(', ', $alive['children'])."); {$kept}", EXIT_LIVE, ['children' => $alive['children']]);
        }

        if ($alive['unverified'] !== []) {
            throw new Failure(sprintf(
                'run %s: process %s has the group ID of a recorded process group whose leader is gone, and it does not carry the group token (ORRERY_GROUP_TOKEN), or its environment cannot be read. It was not signalled, and %s. Stop it, or wait until it ends, then run the command again',
                $runId,
                implode(', ', $alive['unverified']),
                $kept,
            ), EXIT_OPERATION, ['unverified' => $alive['unverified']]);
        }
    }

    /**
     * Stops the recorded children of a run: SIGTERM, a grace period, then
     * SIGKILL. A group entry is stopped with its whole process group. The
     * lease is read under the lock on every round. The signals and the wait
     * happen outside the lock, so other runs are not blocked.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function stopChildren(array $arguments): array
    {
        [[$runId], $options] = self::parse($arguments, 1, ['grace' => true]);
        $runId = self::runId($runId);
        $grace = self::grace($options);

        // The backend selection and the registry rules apply as for every
        // registry command.
        $this->locked(static fn (array &$data): bool => true);
        $dir = $this->registry()->dir;

        return ['run_id' => $runId] + self::stopTargets(static function () use ($runId, $dir): array {
            $store = Registry::store($dir);
            $store->lock();

            try {
                $data = $store->load();
            } finally {
                $store->unlock();
            }

            return isset($data['leases'][$runId]) ? self::targets($data['leases'][$runId]) : [];
        }, $grace);
    }

    /**
     * stop-group <pgid> <start> [--token=<group token>] [--grace=10]: stops a
     * process group whose leader has this start time, with every member of
     * the group. It needs no registry. After the leader is gone, a member is
     * stopped only when it carries the token (see Processes::groupMembers()).
     * A group whose PID now belongs to another process is left alone, and
     * "skipped" reports it. Exit 75 when a process survives SIGKILL, and 74
     * when the processes cannot be inspected or a member cannot be verified.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function stopGroup(array $arguments): array
    {
        [[$pgid, $start], $options] = self::parse($arguments, 2, ['grace' => true, 'token' => true]);
        $token = isset($options['token']) ? (string) $options['token'] : null;

        if ($token !== null && preg_match(TOKEN_PATTERN, $token) !== 1) {
            throw new Failure('--token must be 32 hexadecimal characters', EXIT_USAGE);
        }

        $target = ['pid' => self::pid($pgid), 'start' => Processes::normalise($start), 'group' => true, 'token' => $token];
        $result = self::stopTargets(static fn (): array => [$target], self::grace($options));

        return ['pgid' => $target['pid'], 'skipped' => in_array($target['pid'], $result['skipped'], true)] + $result;
    }

    /**
     * Stops processes and process groups together: SIGTERM, the grace period,
     * then SIGKILL. Every signal checks the PID's start time first.
     *
     * $targets is called again on every round, so a target that appears
     * during the stop is stopped too. A failed read or inspection throws
     * (exit 74): it is never taken as "nothing left".
     *
     * A target with "group" false is one process with that PID and start. A
     * target with "group" true is a process group: the members that
     * Processes::groupMembers() proves owned. A process that it proved owned
     * once stays owned for this stop while it keeps its start time, also
     * after the group's leader is gone. The leader itself gets its signals
     * only when it is the last member of its group, so it proves the others
     * until they are gone. An unverified member is never signalled, but the
     * stop waits for it within the grace period: a process that is still
     * exiting, or a short-lived child of a stopped leader (for example the ps
     * of bin/test's anchor), ends by itself. If one is left at the end, the
     * stop fails (74), so the caller keeps its resources.
     *
     * @param  callable(): list<array{pid: int, start: string, group: bool, token?: string|null}>  $targets
     * @return array{skipped: list<int>, terminated: list<int>, killed: list<int>}
     */
    private static function stopTargets(callable $targets, float $grace): array
    {
        $self = getmypid();
        $skipped = [];
        $known = [];
        $unverified = [];
        $owned = static function () use ($targets, $self, &$skipped, &$known, &$unverified): array {
            $list = $targets();
            $unverified = [];

            if ($list === []) {
                return [];
            }

            $all = Processes::snapshot();
            $owned = [];

            foreach ($list as $target) {
                if ($target['group']) {
                    $token = is_string($target['token'] ?? null) ? $target['token'] : null;
                    $members = Processes::groupMembers($all, $target['pid'], $target['start'], static fn (array $pids): array => Processes::carryToken($pids, $token), $known, $self);

                    if ($members === null) {
                        $skipped[$target['pid']] = true;

                        continue;
                    }

                    // The leader (bin/test's anchor) goes last: while it
                    // exists, it proves every member of its group.
                    $group = $members['owned'];

                    if (count($group) > 1 && isset($group[$target['pid']])) {
                        unset($group[$target['pid']]);
                    }

                    $owned += $group;
                    $unverified += $members['unverified'];

                    continue;
                }

                $process = $all[$target['pid']] ?? null;

                if ($process !== null && ! $process['zombie'] && $process['start'] === $target['start']) {
                    $owned[$target['pid']] = $process['start'];
                }
            }

            $known = $owned + $known;

            return $owned;
        };

        $current = $owned();
        $terminated = [];
        $deadline = microtime(true) + $grace;

        // An unverified member keeps the wait going, but only the owned
        // processes get signals.
        while ($current !== [] || $unverified !== []) {
            self::reportUnverifiedWait($unverified);

            foreach ($current as $pid => $start) {
                if (! in_array($pid, $terminated, true) && Processes::signalIfSame($pid, $start, 15)) {
                    $terminated[] = $pid;
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep((int) (Environment::poll(0.1) * 1_000_000));
            $current = $owned();
        }

        $killed = [];
        $confirm = microtime(true) + 3.0;

        // SIGKILL for everything that is left, including a target that
        // appears during this wait.
        while ($current !== []) {
            foreach ($current as $pid => $start) {
                if (! in_array($pid, $killed, true) && Processes::signalIfSame($pid, $start, 9)) {
                    $killed[] = $pid;
                }
            }

            if (microtime(true) >= $confirm) {
                break;
            }

            usleep((int) (Environment::poll(0.05) * 1_000_000));
            $current = $owned();
        }

        if ($current !== []) {
            throw new Failure('processes survived SIGKILL: '.implode(', ', array_keys($current)), EXIT_LIVE, ['alive' => array_keys($current)]);
        }

        if ($unverified !== []) {
            throw new Failure(sprintf(
                'process %s has the group ID of a recorded process group whose leader is gone, and it does not carry the group token (ORRERY_GROUP_TOKEN), or its environment cannot be read. It was not signalled, and nothing more is removed. Stop it, or wait until it ends, then run the command again',
                implode(', ', array_keys($unverified)),
            ), EXIT_OPERATION, ['unverified' => array_keys($unverified), 'terminated' => $terminated, 'killed' => $killed]);
        }

        return ['skipped' => array_keys($skipped), 'terminated' => $terminated, 'killed' => $killed];
    }

    /**
     * A test hook (test mode only): with ORRERY_TEST_UNVERIFIED_WAIT=<file>,
     * each round of the stop that waits for unverified members writes their
     * PIDs into <file> (a temporary file, then a rename). A test waits for
     * this file before it lets such a member end, so it proves that the stop
     * saw the member and kept waiting.
     *
     * @param  array<int, string>  $unverified
     */
    private static function reportUnverifiedWait(array $unverified): void
    {
        $file = Environment::testMode() ? getenv('ORRERY_TEST_UNVERIFIED_WAIT') : false;

        if ($unverified === [] || ! is_string($file) || $file === '') {
            return;
        }

        @file_put_contents($file.'.tmp', implode(' ', array_keys($unverified))."\n");
        @rename($file.'.tmp', $file);
    }

    /**
     * owner-init <directory> <run-id> <pid> <start|auto>: creates the private
     * owner record of a run without a registry. <directory> must be a
     * private directory of this user whose name starts with orrery (bin/test's
     * own temporary directory). The record lists that directory, so the
     * shutdown removes it last.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function ownerInit(array $arguments): array
    {
        [[$dir, $runId, $pid, $start]] = self::parse($arguments, 4, []);
        $dir = Paths::validate($dir);
        Paths::assertOwnedDirectory($dir);
        $runId = self::runId($runId);
        $supervisor = self::identity(self::pid($pid), $start);

        $record = self::withStore($dir, static function (array &$data) use ($dir, $runId, $supervisor): array {
            if ($data['leases'] !== []) {
                throw new Failure("{$dir} already holds an owner record", EXIT_CONFIG);
            }

            $data['leases'][$runId] = [
                'run_id' => $runId,
                'kind' => 'owner',
                'supervisor' => $supervisor,
                'children' => [],
                'paths' => [$dir],
                'closing' => false,
                'names' => [],
                'generated' => false,
                'services' => [],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];

            return $data['leases'][$runId];
        });

        return ['run_id' => $runId, 'owner' => $runId.' '.$dir, 'record' => $record];
    }

    /**
     * owner-path <directory>: records a directory in every owner record of
     * ORRERY_OWNERS, outermost first. A missing or closing record refuses it
     * (66, 76), and a failed read or write exits 74.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function ownerPath(array $arguments): array
    {
        [[$path]] = self::parse($arguments, 1, []);
        $path = Paths::validate($path);
        Paths::assertOwnedDirectory($path);
        $recorded = [];

        foreach (Owners::chain() as $owner) {
            self::withStore($owner['dir'], static function (array &$data) use ($owner, $path): void {
                $record = &self::openRecord($data, $owner);

                if (! in_array($path, $record['paths'], true)) {
                    $record['paths'][] = $path;
                }
            });
            $recorded[] = $owner['run'];
        }

        return ['path' => $path, 'recorded' => $recorded];
    }

    /**
     * owner-join: bin/test's child runs this as its first step, before it
     * starts PHPUnit. Its parent (the child, which leads a new process group)
     * joins every owner record of ORRERY_OWNERS as a group entry, outermost
     * first, so the entry in the child's own record (the last one) means
     * that every record above has it too. The entry keeps the group token
     * from ORRERY_GROUP_TOKEN, which the child exported for its descendants.
     *
     * The own record also needs its supervisor alive and the parent of the
     * group leader. A missing record (66), a closing record (76), or a failed
     * read, write or inspection (74) stops the join, and the caller must not
     * start PHPUnit.
     *
     * ORRERY_TEST_PAUSE_BEFORE_REGISTER=<dir> (a test hook) holds the join
     * before its first record: it writes <dir>/paused with the group leader's
     * PID and waits for <dir>/resume. The hold ends early when the leader or
     * its parent changes.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function ownerJoin(array $arguments): array
    {
        self::parse($arguments, 0, []);
        $owners = Owners::chain();

        if ($owners === []) {
            throw new Failure('owner-join needs ORRERY_OWNERS; bin/test sets it for its child', EXIT_USAGE);
        }

        $pid = Processes::parentPid();
        $leader = Processes::info($pid);

        if ($leader === null) {
            throw new Failure("the joining process {$pid} is gone", EXIT_OPERATION);
        }

        if ($leader['pgid'] !== $pid) {
            throw new Failure("process {$pid} does not lead its own process group (group {$leader['pgid']}), so it cannot join as a group", EXIT_USAGE);
        }

        // The random token of this group: the joining process exported it,
        // so every descendant of PHPUnit inherits it. It proves membership
        // once the leader is gone (see Processes::groupMembers()).
        $token = getenv(GROUP_TOKEN);

        if (! is_string($token) || preg_match(TOKEN_PATTERN, $token) !== 1) {
            throw new Failure('owner-join needs '.GROUP_TOKEN.' (32 hexadecimal characters) in its environment; bin/test sets it for its child', EXIT_USAGE);
        }

        self::pauseBeforeJoin($leader);

        $own = $owners[count($owners) - 1];
        $entry = ['pid' => $pid, 'start' => $leader['start'], 'group' => true, 'run' => $own['run'], 'token' => $token];
        $joined = [];

        foreach ($owners as $index => $owner) {
            $isOwn = $index === count($owners) - 1;

            self::withStore($owner['dir'], static function (array &$data) use ($owner, $entry, $isOwn): void {
                $record = &self::openRecord($data, $owner);

                if ($isOwn) {
                    // The supervisor of the own record started this group and
                    // still waits for it. After its death nothing starts.
                    $current = Processes::info($entry['pid']);
                    $supervisor = $record['supervisor'];
                    $supervisorStart = Processes::starts([$supervisor['pid']])[$supervisor['pid']] ?? null;

                    if ($current === null || $current['start'] !== $entry['start'] || $current['ppid'] !== $supervisor['pid'] || $supervisorStart !== $supervisor['start']) {
                        throw new Failure("the supervisor of run {$owner['run']} (pid {$supervisor['pid']}) is gone or did not start process {$entry['pid']}; nothing starts", EXIT_NO_LEASE);
                    }
                }

                self::addEntry($record['children'], $entry);
            });
            $joined[] = $owner['run'];
        }

        return ['leader' => ['pid' => $pid, 'start' => $entry['start']], 'joined' => $joined];
    }

    /**
     * @param  array{pid: int, ppid: int, pgid: int, zombie: bool, start: string}  $leader
     */
    private static function pauseBeforeJoin(array $leader): void
    {
        $dir = getenv('ORRERY_TEST_PAUSE_BEFORE_REGISTER');

        if (! is_string($dir) || $dir === '') {
            return;
        }

        @file_put_contents($dir.'/paused.tmp', $leader['pid']."\n");
        @rename($dir.'/paused.tmp', $dir.'/paused');
        $deadline = microtime(true) + 120;
        $checked = 0.0;
        // Tests that prove bin/test itself stops this child turn off the
        // early end, as for the startup gate.
        $followParent = getenv('ORRERY_TEST_GATE_IGNORE_PARENT') !== '1';

        while (! file_exists($dir.'/resume') && microtime(true) < $deadline) {
            if ($followParent && microtime(true) - $checked >= 0.1) {
                $checked = microtime(true);

                try {
                    $current = Processes::info($leader['pid']);
                } catch (Failure) {
                    // The join checks the identity again after the hold.
                    $current = $leader;
                }

                if (Processes::parentPid() !== $leader['pid'] || $current === null || $current['ppid'] !== $leader['ppid']) {
                    return;
                }
            }

            usleep(20_000);
            clearstatcache();
        }
    }

    /**
     * owner-wait --owner="<run id> <directory>" <pid>: waits until the
     * process group of <pid> has joined that record (it prints its start),
     * or until <pid> is gone without a join ("joined": false). A missing
     * record or a failed read or inspection exits 74.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function ownerWait(array $arguments): array
    {
        [[$pid], $options] = self::parse($arguments, 1, ['owner' => true, 'timeout' => true]);
        $pid = self::pid($pid);
        $owner = Owners::parse((string) ($options['owner'] ?? ''));
        $timeout = (float) ($options['timeout'] ?? '600');
        $deadline = microtime(true) + $timeout;
        $joined = static function () use ($owner, $pid): ?array {
            foreach (self::readRecord($owner)['children'] ?? [] as $child) {
                if ($child['pid'] === $pid && ($child['group'] ?? false)) {
                    return $child;
                }
            }

            return null;
        };

        while (true) {
            $entry = $joined();

            if ($entry !== null) {
                return ['joined' => true, 'pid' => $pid, 'start' => $entry['start']];
            }

            if ((Processes::starts([$pid])[$pid] ?? null) === null) {
                // Gone. One more read covers a join right before the end.
                $entry = $joined();

                return $entry === null ? ['joined' => false, 'pid' => $pid] : ['joined' => true, 'pid' => $pid, 'start' => $entry['start']];
            }

            if (microtime(true) >= $deadline) {
                throw new Failure("process {$pid} did not join run {$owner['run']} within {$timeout} seconds", EXIT_OPERATION);
            }

            usleep(20_000);
        }
    }

    /**
     * anchor-linger: the last step of bin/test's anchor, which runs it with
     * exec, so this process is the anchor (the leader of its process group).
     * bin/test is gone, and its shutdown did not stop the anchor: it was
     * killed, or its shutdown failed. The anchor stays while any other
     * process of its group lives, so a later shutdown, stop-children or
     * outer run still has the leader that proves the group. It checks once a
     * second. A failed process listing is no evidence, so it keeps waiting.
     * SIGTERM ends it, as it does in every stop (the leader last).
     *
     * A test hook (test mode only): with ORRERY_TEST_ANCHOR_STAYS=<pid>, an
     * empty group does not end it, so only a stop's SIGTERM does, or the end
     * of process <pid> (the test that set it). Without it, a test of the
     * stop's order races this check: a stop whose process listing is slow (a
     * loaded machine) can find the anchor already gone, and then it never
     * signals the anchor. A fixed lifetime raced the same way on a machine
     * slow enough.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function anchorLinger(array $arguments): array
    {
        self::parse($arguments, 0, []);
        $self = (int) getmypid();
        $holder = Environment::testMode() ? getenv('ORRERY_TEST_ANCHOR_STAYS') : false;
        $stays = is_string($holder) && preg_match('/^[1-9][0-9]*$/', $holder) === 1 ? (int) $holder : null;

        // Once a second; in test mode at most 10 times a second with
        // ORRERY_TEST_POLL_MS (each check lists every process).
        $interval = max(0.1, Environment::poll(1.0));

        while (true) {
            usleep((int) ($interval * 1_000_000));

            if ($stays !== null) {
                if (! Processes::exists($stays)) {
                    return ['anchor' => $self, 'group' => 'held'];
                }

                continue;
            }

            try {
                // This process's own children (the ps of the listing) are in
                // its group too. PHPUnit, its only other child, has ended.
                $others = array_filter(Processes::snapshot(), static fn (array $process): bool => $process['pgid'] === $self && $process['pid'] !== $self && $process['ppid'] !== $self && ! $process['zombie']);
            } catch (Failure) {
                continue;
            }

            if ($others === []) {
                return ['anchor' => $self, 'group' => 'empty'];
            }
        }
    }

    /**
     * shutdown --owner="<run id> <directory>" [--grace=10] [--missing-ok]:
     * the cleanup of bin/test.
     *
     * 1. It marks the record closing, under its lock. From then on nothing
     *    can join it: no process group, child or directory.
     * 2. It stops every recorded process group and child (SIGTERM, the
     *    grace, SIGKILL) and reads the record again on every round, until
     *    every recorded process is confirmed gone.
     * 3. Only then it removes the recorded directories and, for a lease,
     *    drops the databases of a lease that drops its names (generated, or
     *    --drop-at-release) and deletes the lease. A private
     *    record goes with its directory, which is removed last.
     *
     * A failed read or inspection (74) or a survivor (75) stops it after
     * step 1, and the record, its directories and its databases stay.
     * --missing-ok: a missing record is no error (bin/test before its
     * record exists).
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function shutdown(array $arguments): array
    {
        [, $options] = self::parse($arguments, 0, ['owner' => true, 'grace' => true, 'missing-ok' => false]);

        if (! isset($options['owner'])) {
            throw new Failure("shutdown needs --owner=\"<run id> <directory>\"\n".usage(), EXIT_USAGE);
        }

        $owner = Owners::parse((string) $options['owner']);
        $grace = self::grace($options);
        $missingOk = isset($options['missing-ok']);

        clearstatcache();

        if ($missingOk && ! file_exists($owner['dir']) && ! is_link($owner['dir'])) {
            return ['run_id' => $owner['run'], 'record' => false];
        }

        $record = self::withStore($owner['dir'], static function (array &$data) use ($owner, $missingOk): ?array {
            if (! isset($data['leases'][$owner['run']])) {
                if ($missingOk) {
                    return null;
                }

                throw new Failure("run {$owner['run']} has no owner record in {$owner['dir']}; nothing was stopped or removed", EXIT_OPERATION);
            }

            $data['leases'][$owner['run']]['closing'] = true;

            return $data['leases'][$owner['run']];
        });

        if ($record === null) {
            return ['run_id' => $owner['run'], 'record' => false];
        }

        Paths::hold('closed', $owner['dir']);
        $lease = ($record['kind'] ?? 'lease') !== 'owner';

        if ($lease && $owner['dir'] !== $this->registry()->dir) {
            throw new Failure("the lease of run {$owner['run']} is in {$owner['dir']}, not in the registry {$this->registry()->dir} of this command", EXIT_CONFIG);
        }

        $stopped = self::stopTargets(static fn (): array => self::targets(self::readRecord($owner)), $grace);

        if ($lease) {
            $finished = $this->locked(function (array &$data) use ($owner): array {
                if (! isset($data['leases'][$owner['run']])) {
                    return ['released' => false, 'note' => 'no lease for this run'];
                }

                return $this->releaseLocked($data, $owner['run']);
            });
        } else {
            $finished = self::finishOwner($owner);
        }

        return ['run_id' => $owner['run'], 'record' => true] + $stopped + $finished;
    }

    /**
     * The last step of the shutdown of a private owner record. The record is
     * closed and its processes were stopped: a fresh inspection confirms
     * that none lives. Then nothing can join or use its directories, and they
     * are removed, the owner store last.
     *
     * @param  array{run: string, dir: string}  $owner
     * @return array<string, mixed>
     */
    private static function finishOwner(array $owner): array
    {
        $record = self::withStore($owner['dir'], static function (array &$data) use ($owner): array {
            if (! isset($data['leases'][$owner['run']])) {
                throw new Failure("the owner record of run {$owner['run']} is missing from {$owner['dir']}; nothing was removed", EXIT_OPERATION);
            }

            $record = $data['leases'][$owner['run']];
            self::assertNothingAlive($owner['run'], self::aliveProcesses($record, self::inspect([$record], false)), 'its record and its directories stay');

            return $record;
        });

        [$removed, $failed] = Paths::removeAll(array_values($record['paths'] ?? []), $owner['dir']);

        if ($failed !== []) {
            throw new Failure('could not remove '.implode(', ', $failed)."; the owner record of run {$owner['run']} keeps them", EXIT_OPERATION, ['removed' => $removed, 'failed' => $failed]);
        }

        return ['removed' => $removed];
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function down(array $arguments): array
    {
        [, $options] = self::parse($arguments, 0, ['dry-run' => false, 'force' => false]);

        if (isset($options['force'])) {
            if (isset($options['dry-run'])) {
                throw new Failure('--force and --dry-run cannot be combined', EXIT_USAGE);
            }

            return $this->forceDown();
        }

        return $this->locked(function (array &$data) use ($options): array {
            $pruned = $this->pruneStale($data);

            if ($data['leases'] !== []) {
                $holders = [];

                foreach ($data['leases'] as $runId => $lease) {
                    $holders[] = ['run_id' => $runId, 'supervisor' => $lease['supervisor'], 'names' => $lease['names']];
                }

                throw new Failure('live leases exist ('.implode(', ', array_keys($data['leases'])).'); the services stay up', EXIT_LIVE, ['leases' => $holders]);
            }

            if (isset($options['dry-run'])) {
                return ['stopped' => false, 'dry_run' => true, 'pruned' => $pruned];
            }

            $this->ops()->down();
            $data['token'] = null;
            $data['orphans'] = [];

            return ['stopped' => true, 'pruned' => $pruned];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function forceDown(): array
    {
        if (! stream_isatty(STDIN)) {
            throw new Failure('down --force is for humans only: it needs an interactive terminal', EXIT_FORCE);
        }

        fwrite(STDERR, 'This stops the '.PROJECT.' services and discards every lease, including live ones. Type '.PROJECT.' to confirm: ');
        $answer = fgets(STDIN);

        if (! is_string($answer) || trim($answer) !== PROJECT) {
            throw new Failure('not confirmed; nothing was changed', EXIT_FORCE);
        }

        return $this->locked(function (array &$data): array {
            $discarded = array_keys($data['leases']);
            $this->ops()->down();
            $data['token'] = null;
            $data['leases'] = [];
            $data['orphans'] = [];

            return ['stopped' => true, 'forced' => true, 'discarded' => $discarded];
        });
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function token(array $arguments): array
    {
        self::parse($arguments, 0, []);
        $registry = $this->registry();
        $registry->lock();

        try {
            $token = $registry->load()['token'];
        } finally {
            $registry->unlock();
        }

        if ($token === null) {
            throw new Failure('the registry has no token; run bin/test-db up', EXIT_NO_LEASE);
        }

        return ['token' => $token];
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function status(array $arguments): array
    {
        [, $options] = self::parse($arguments, 0, ['no-services' => false]);

        return $this->locked(function (array &$data) use ($options): array {
            $inspection = self::inspect(array_values($data['leases']));
            $leases = [];
            $names = [];

            foreach ($data['leases'] as $runId => $lease) {
                $alive = self::aliveProcesses($lease, $inspection);
                $lease['live'] = self::isLive($alive);
                $lease['alive'] = $alive;
                $leases[$runId] = $lease;

                foreach ($lease['names'] as $name) {
                    $names[$name] = (string) $runId;
                }
            }

            $result = [
                'registry' => $this->registry()->dir,
                'token' => $data['token'],
                'leases' => (object) $leases,
                'names' => (object) $names,
                'orphans' => $data['orphans'],
                'slot' => ['holder' => Slot::holder($this->registry()), 'waiters' => Slot::waiters($this->registry())],
            ];

            if (! isset($options['no-services'])) {
                $services = $this->ops()->services();

                foreach ($services as $service => $current) {
                    $services[$service]['databases'] = ($current['state'] === 'running' && $current['health'] === 'healthy')
                        ? $this->ops()->listDatabases($service)
                        : [];
                }

                $result['services'] = $services;
                $result['ports'] = Environment::ports();
            }

            return $result;
        });
    }

    /**
     * Takes the heavy-run slot for the process --pid (bin/test), writes the
     * result to --out, and holds the slot until stdin reaches end-of-file:
     * bin/test keeps the write end of a pipe open until it exits. A request
     * with the holder's token (ORRERY_SLOT_TOKEN) enters at once and holds
     * nothing.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function slotHold(array $arguments): array
    {
        [, $options] = self::parse($arguments, 0, ['run-id' => true, 'pid' => true, 'checkout' => true, 'command' => true, 'wait' => true, 'out' => true]);

        foreach (['run-id', 'pid', 'checkout', 'command', 'wait', 'out'] as $required) {
            if (! is_string($options[$required] ?? null)) {
                throw new Failure("slot-hold needs --{$required}=<value>\n".usage(), EXIT_USAGE);
            }
        }

        $wait = (string) $options['wait'];

        if (preg_match('/^[0-9]{1,6}$/D', $wait) !== 1) {
            throw new Failure('--wait must be a whole number of seconds from 0 to 999999', EXIT_USAGE);
        }

        $out = Registry::normalisedAbsolute((string) $options['out'], '--out');
        $identity = [
            'run_id' => self::runId((string) $options['run-id']),
            'pid' => self::pid((string) $options['pid']),
            'checkout' => (string) $options['checkout'],
            'command' => (string) $options['command'],
        ];
        $every = 60.0;
        $override = Environment::testMode() ? getenv('ORRERY_TEST_SLOT_REPORT_SECONDS') : false;

        if (is_string($override) && is_numeric($override) && (float) $override > 0) {
            $every = (float) $override;
        }

        $stdin = STDIN;
        stream_set_blocking($stdin, false);
        // A second sign that the requester is gone: this process gets a new
        // parent when its parent ends.
        $parent = function_exists('posix_getppid') ? posix_getppid() : null;
        $gone = static fn (float $seconds): bool => self::endOfInput($stdin, $seconds) || ($parent !== null && posix_getppid() !== $parent);
        $inherited = getenv(SLOT_TOKEN);
        $slot = Slot::acquire(
            $this->registry(),
            $identity,
            (int) $wait,
            is_string($inherited) && $inherited !== '' ? $inherited : null,
            static function (string $line): void {
                fwrite(STDERR, "testdb: {$line}\n");
            },
            $gone,
            $every,
        );
        $result = ['acquired' => true, 'nested' => $slot->nested, 'token' => $slot->token];
        $json = json_encode($result, JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $out.'.tmp';

        if (@file_put_contents($temporary, $json) !== strlen($json) || ! @rename($temporary, $out)) {
            @unlink($temporary);
            $slot->release();

            throw new Failure("cannot write {$out}; the heavy-run slot was released", EXIT_OPERATION);
        }

        if (! $slot->nested) {
            while (! $gone(1.0)) {
                // Held while the requester lives.
            }

            $slot->release();
        }

        return $result + ['released' => ! $slot->nested];
    }

    /**
     * Waits up to $seconds for input, and tells whether the stream has ended.
     *
     * @param  resource  $stream
     */
    private static function endOfInput($stream, float $seconds): bool
    {
        $read = [$stream];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, (int) $seconds, (int) (fmod($seconds, 1.0) * 1_000_000));

        if ($ready === false) {
            // Interrupted by a signal: look again on the next call.
            usleep(10_000);

            return false;
        }

        if ($ready === 0) {
            return false;
        }

        $data = fread($stream, 8192);

        return ($data === '' || $data === false) && feof($stream);
    }

    /**
     * The last test that PHPUnit's event log (--log-events-text) shows as
     * started, and whether it has finished.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function runningTest(array $arguments): array
    {
        [[$path]] = self::parse($arguments, 1, []);
        $test = null;
        $finished = false;
        $handle = @fopen($path, 'r');

        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if (preg_match('/^Test Preparation Started \((.+)\)$/D', $line, $match) === 1) {
                    $test = $match[1];
                    $finished = false;
                } elseif ($test !== null && $line === "Test Finished ({$test})") {
                    $finished = true;
                }
            }

            fclose($handle);
        }

        $text = match (true) {
            $test === null => 'before the first test started',
            $finished => "after {$test} finished, between tests",
            default => "while it ran {$test}",
        };

        return ['test' => $test, 'finished' => $finished, 'text' => $text];
    }
}

function usage(): string
{
    return <<<'USAGE'
        Usage: php bin/lib/testdb.php <command> [arguments]

          path                                    print the registry path
          validate-name <base>                    check a base name and derive its mirror name
          up [--alt]                              start or verify the services, marker and token;
                                                  --alt: the alt services too (they start only without a live lease)
          admit <run-id> <pid> <start|auto> <base> --generated|--explicit [--drop-at-release] [--engine=mysql|pgsql|mariadb|all] [--path=<dir>] [--alt]
                                                  take a lease and both names, then recreate the databases;
                                                  --drop-at-release: the release drops an explicit name too;
                                                  --alt: also on the alt service of the engine
          add-child <run-id> <pid> <start|auto>   record a child process (for example a worker) in a lease
          create <base> [--engine=...] [--alt]    recreate <base> and <base>_mirror (name lock required)
          drop <base> [--engine=...] [--alt]      drop <base> and <base>_mirror (name lock required);
                                                  --alt: on the alt services too
          release <run-id>                        remove its directories, drop the databases of a generated
                                                  or --drop-at-release name, delete the lease
          stop-children <run-id> [--grace=10]     SIGTERM, then SIGKILL, the recorded children and groups of a run
          stop-group <pgid> <start> [--token=<group token>] [--grace=10]
                                                  stop a process group whose leader has this start (no registry)
          owner-init <dir> <run-id> <pid> <start|auto>
                                                  create the private owner record of a run without a registry
          owner-path <dir>                        record a directory in every owner record of ORRERY_OWNERS
          owner-join                              bin/test's child: join every owner record of ORRERY_OWNERS
          owner-wait --owner="<run> <dir>" <pid>  wait until <pid> joined that record, or is gone
          anchor-linger                           bin/test's anchor: stay while other processes of its group live
          shutdown --owner="<run> <dir>" [--grace=10] [--missing-ok]
                                                  close the record, stop its groups, then remove its
                                                  directories and release its lease
          down [--dry-run]                        stop the services when no live lease exists
          down --force                            humans only: stop even with live leases (needs a terminal)
          token                                   print the registry token
          status [--no-services]                  leases, names, token, the heavy-run slot and the project containers
          slot-hold --run-id=<id> --pid=<pid> --checkout=<dir> --command=<text> --wait=<seconds> --out=<file>
                                                  bin/test's helper: take the heavy-run slot (or enter it with
                                                  ORRERY_SLOT_TOKEN), write the token to <file>, hold until stdin ends
          running-test <events file>              the last started test of a PHPUnit --log-events-text file
        USAGE;
}

/**
 * @param  array<string, mixed>  $data
 */
function emit(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
}

/**
 * @param  list<string>  $argv
 */
function main(array $argv): int
{
    // stdout carries exactly one JSON object; a PHP warning must not reach it.
    ini_set('display_errors', 'stderr');
    $command = $argv[1] ?? '';

    try {
        if ($command === '' || $command === '--help' || $command === '-h') {
            fwrite(STDERR, usage()."\n");

            return $command === '' ? EXIT_USAGE : 0;
        }

        // An invalid poll override fails before the command does anything.
        Environment::testPollMilliseconds();
        emit(['ok' => true] + (new App($command))->run(array_slice($argv, 2)));

        return 0;
    } catch (Failure $failure) {
        fwrite(STDERR, "testdb: {$failure->getMessage()}\n");
        emit(['ok' => false, 'exit' => $failure->exitCode, 'error' => $failure->getMessage()] + $failure->details);

        return $failure->exitCode;
    } catch (\Throwable $throwable) {
        fwrite(STDERR, 'testdb: internal error: '.$throwable->getMessage().' at '.$throwable->getFile().':'.$throwable->getLine()."\n");
        emit(['ok' => false, 'exit' => EXIT_FAILURE, 'error' => $throwable->getMessage()]);

        return EXIT_FAILURE;
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(main($_SERVER['argv']));
}
