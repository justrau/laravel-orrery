<?php

/*
 * A stand-in for vendor/bin/phpunit in the logic tests of bin/test. The wrapper
 * runs it as "<php> <this file> <phpunit arguments>" when ORRERY_REGISTRY_TEST=1
 * and ORRERY_TEST_PHPUNIT point to it.
 *
 * It passes the startup gate like PHPUnit (through tests/bootstrap.php), then
 * writes stub-<pid>.json into ORRERY_STUB_OUT: its arguments, its environment,
 * the gate variable it started with, and (with ORRERY_STUB_STATUS=1) the
 * registry status as it saw it.
 *
 * - ORRERY_STUB_HOLD=<dir> holds it until <dir>/release exists.
 * - ORRERY_STUB_EXIT sets the exit code.
 * - ORRERY_STUB_DESCENDANT=1 starts descendant.php first and leaves it running.
 *   ORRERY_STUB_DESCENDANT=bash starts /bin/bash with a sleep instead: both
 *   ignore SIGTERM, and macOS hides their environment.
 * - ORRERY_STUB_IGNORE_TERM=1 ignores SIGTERM, like a process that is slow to stop.
 * - ORRERY_STUB_EVENTS=<JSON list of lines> appends the lines to the file of
 *   --log-events-text, like the event log of PHPUnit.
 * - ORRERY_STUB_GRACEFUL=1: on SIGTERM it stops holding, waits until its nested
 *   run ends, and exits with 143.
 * - ORRERY_STUB_WATCH=<dir> with ORRERY_STUB_WATCH_LOG=<file>: while it holds,
 *   it appends a line to the log if <dir> disappears while it is alive.
 * - ORRERY_STUB_NESTED=<wrapper arguments> starts a nested bin/test, for example
 *   "sqlite --sqlite-file" or "mysql --no-provision" ("1" means "sqlite"), and
 *   leaves it running. With ORRERY_TEST_BASH set, that interpreter runs it. The
 *   nested run gets this process's environment without ORRERY_STUB_*, plus
 *   every ORRERY_STUB_INNER_<X> as ORRERY_STUB_<X>, plus the variables of the
 *   JSON object ORRERY_STUB_NESTED_ENV. For the level that uses it, the value
 *   "@outer-sqlite" names the directory of this run's SQLite file, and
 *   "@outer-run" names this run's own directory. The exit status of the nested
 *   run goes to nested-<pid>.json when this process sees it end.
 */

declare(strict_types=1);

$gate = getenv('ORRERY_START_GATE');

// Only the startup gate: this stand-in touches no database, so it skips the
// harness lease proof (see tests/bootstrap.php).
define('ORRERY_BOOTSTRAP_GATE_ONLY', true);

require __DIR__.'/../../bootstrap.php';

$terminated = false;

if (getenv('ORRERY_STUB_GRACEFUL') === '1') {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$terminated): void {
        $terminated = true;
    });
} elseif (getenv('ORRERY_STUB_IGNORE_TERM') === '1') {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
}

$record = [
    'pid' => getmypid(),
    // bin/test's anchor: the leader of this process group.
    'ppid' => posix_getppid(),
    'argv' => array_slice($_SERVER['argv'], 1),
    'cwd' => getcwd(),
    'gate' => $gate === false ? null : $gate,
    'env' => getenv(),
];

if (getenv('ORRERY_STUB_STATUS') === '1') {
    $lib = dirname(__DIR__, 3).'/bin/lib/testdb.php';
    $output = shell_exec(escapeshellarg(PHP_BINARY).' -n '.escapeshellarg($lib).' status --no-services 2>/dev/null');
    $record['status'] = json_decode((string) $output, true);
}

$sqlite = getenv('ORRERY_TEST_SQLITE_FILE');

if (is_string($sqlite) && $sqlite !== '') {
    $record['sqlite'] = [
        'file' => is_file($sqlite) && ! is_link($sqlite),
        'mirror' => is_file($sqlite.'.mirror') && ! is_link($sqlite.'.mirror'),
        'dir_mode' => fileperms(dirname($sqlite)) & 0777,
    ];
    $record['sqlite_canonical'] = realpath($sqlite);
}

// This run's own directory: the gate's directory (mysql, pgsql), else the
// directory of the last owner record, which is the run's private record.
$owners = explode("\n", (string) getenv('ORRERY_OWNERS'));
$ownRun = is_string($gate) && $gate !== '' ? dirname($gate) : (explode(' ', (string) end($owners), 2)[1] ?? null);
$record['run_dir'] = $ownRun;

$out = getenv('ORRERY_STUB_OUT');

// Like PHPUnit's --log-events-text: the lines of ORRERY_STUB_EVENTS (a JSON
// list) go to the event log that bin/test names.
$events = json_decode((string) getenv('ORRERY_STUB_EVENTS'), true);
$eventLog = array_search('--log-events-text', $record['argv'], true);

if (is_array($events) && $eventLog !== false && is_string($record['argv'][$eventLog + 1] ?? null)) {
    file_put_contents($record['argv'][$eventLog + 1], implode('', array_map(static fn (string $line): string => $line."\n", $events)), FILE_APPEND);
}

if (getenv('ORRERY_STUB_DESCENDANT') === 'bash') {
    // Two descendants that ignore SIGTERM and whose environment macOS hides
    // (platform binaries): /bin/bash and its sleep. The file gets "<bash
    // PID> <sleep PID>".
    $file = $out.'/bash-descendant';
    proc_open(['/bin/bash', '-c', 'trap "" TERM; sleep 60 & echo "$$ $!" > "$0.tmp"; mv "$0.tmp" "$0"; wait', $file], [], $pipes);
    $deadline = microtime(true) + 10;

    while (! file_exists($file) && microtime(true) < $deadline) {
        usleep(10_000);
        clearstatcache();
    }

    $record['bash_descendant'] = array_map('intval', explode(' ', trim((string) @file_get_contents($file))));
}

if (getenv('ORRERY_STUB_DESCENDANT') === '1') {
    // A worker-like descendant that this process does not stop or reap.
    $descendant = proc_open([PHP_BINARY, __DIR__.'/descendant.php', (string) $out], [], $pipes);
    $pid = is_resource($descendant) ? proc_get_status($descendant)['pid'] : 0;
    $deadline = microtime(true) + 10;

    while (! file_exists($out.'/descendant-'.$pid.'.ready') && microtime(true) < $deadline) {
        usleep(10_000);
        clearstatcache();
    }

    $record['descendant'] = $pid;
}

$nested = null;
$nestedValue = getenv('ORRERY_STUB_NESTED');

if (is_string($nestedValue) && $nestedValue !== '') {
    // A nested bin/test, as the script tests start one. It inherits this
    // process's environment, so its PHPUnit joins the owner records above.
    $env = [];

    foreach (getenv() as $name => $value) {
        if (! str_starts_with($name, 'ORRERY_STUB_')) {
            $env[$name] = $value;
        }
    }

    foreach (getenv() as $name => $value) {
        if (! str_starts_with($name, 'ORRERY_STUB_INNER_')) {
            continue;
        }

        $inner = 'ORRERY_STUB_'.substr($name, strlen('ORRERY_STUB_INNER_'));

        // A symbolic value is resolved by the level that uses it, not on its
        // way down.
        if (! str_starts_with($inner, 'ORRERY_STUB_INNER_') && $inner !== 'ORRERY_STUB_NESTED_ENV') {
            $value = match ($value) {
                '@outer-sqlite' => is_string($sqlite) && $sqlite !== '' ? dirname($sqlite) : $value,
                '@outer-run' => $ownRun ?? $value,
                default => $value,
            };
        }

        $env[$inner] = $value;
    }

    $extra = json_decode((string) getenv('ORRERY_STUB_NESTED_ENV'), true);

    foreach (is_array($extra) ? $extra : [] as $name => $value) {
        $env[(string) $name] = (string) $value;
    }

    $bash = getenv('ORRERY_TEST_BASH');
    $arguments = $nestedValue === '1' ? ['sqlite'] : explode(' ', $nestedValue);
    $command = [...(is_string($bash) && $bash !== '' ? [$bash] : []), dirname(__DIR__, 3).'/bin/test', ...$arguments, '--php='.PHP_BINARY];
    $nested = proc_open($command, [], $pipes, null, $env);
    $record['nested'] = is_resource($nested) ? proc_get_status($nested)['pid'] : 0;
}

if (is_string($out) && $out !== '') {
    file_put_contents($out.'/stub-'.getmypid().'.json', json_encode($record, JSON_UNESCAPED_SLASHES));
}

// Records the exit status of the nested run once it has ended.
$nestedDone = false;
$checkNested = static function () use (&$nested, &$nestedDone, $out, $record): bool {
    if ($nestedDone || ! is_resource($nested)) {
        return true;
    }

    $status = proc_get_status($nested);

    if ($status['running']) {
        return false;
    }

    $nestedDone = true;

    if (is_string($out) && $out !== '') {
        file_put_contents($out.'/nested-'.getmypid().'.json', json_encode([
            'pid' => $record['nested'],
            'exit' => $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'],
            'signaled' => $status['signaled'],
        ]));
    }

    return true;
};

$hold = getenv('ORRERY_STUB_HOLD');

if (is_string($hold) && $hold !== '') {
    $watch = getenv('ORRERY_STUB_WATCH');
    $log = getenv('ORRERY_STUB_WATCH_LOG');
    $seen = false;
    touch($hold.'/reached');
    $deadline = microtime(true) + 60;

    while (! file_exists($hold.'/release') && ! $terminated && microtime(true) < $deadline) {
        if (! $seen && is_string($watch) && $watch !== '' && is_string($log) && $log !== '' && ! is_dir($watch)) {
            file_put_contents($log, getmypid()." saw {$watch} removed while it was alive\n", FILE_APPEND);
            $seen = true;
        }

        $checkNested();
        usleep(10_000);
        clearstatcache();
    }
}

if ($terminated) {
    // Graceful: the nested run ends first, like a test that waits for its
    // own subprocess.
    $deadline = microtime(true) + 60;

    while (! $checkNested() && microtime(true) < $deadline) {
        usleep(10_000);
    }

    exit(143);
}

$checkNested();

exit((int) (getenv('ORRERY_STUB_EXIT') ?: 0));
