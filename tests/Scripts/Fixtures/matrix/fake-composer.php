<?php

/*
 * A stand-in for Composer in the fixture tests of bin/matrix
 * (tests/Scripts/MatrixRunnerTest.php). bin/matrix runs it as
 * `<leg PHP> fake-composer.php <arguments>` in the leg copy.
 *
 * It appends each call, with its COMPOSER_NO_BLOCKING value, to the calls
 * file of the scenario (ORRERY_MATRIX_SCENARIO). `update` writes the lock of
 * the scenario for this leg: "locks" maps a leg id (the name of the working
 * directory) to a lock, and "*" is the default. "composer_exit" maps
 * "<leg>/<command>" to an exit status. "composer_hold" lists
 * "<leg>/<command>" calls that create <calls>.composer-held and wait up to
 * 60 seconds for SIGTERM, then exit 143 (or "composer_hold_exit", for a step
 * that ends with success although it got the signal).
 *
 * The call record has its start time ("started") and pid; when the process
 * ends, an "end" record gets the pid and the end time.
 */

declare(strict_types=1);

$scenario = json_decode((string) file_get_contents((string) getenv('ORRERY_MATRIX_SCENARIO')), true);
$arguments = array_slice($argv, 1);
$command = $arguments[0] ?? '';
$leg = basename((string) getcwd());
$blocking = getenv('COMPOSER_NO_BLOCKING');

file_put_contents($scenario['calls'], json_encode([
    'tool' => 'composer',
    'leg' => $leg,
    'command' => $command,
    'arguments' => $arguments,
    'cwd' => getcwd(),
    'COMPOSER_NO_BLOCKING' => $blocking === false ? null : $blocking,
    'pid' => getmypid(),
    'started' => microtime(true),
], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
register_shutdown_function(static function () use ($scenario): void {
    file_put_contents($scenario['calls'], json_encode(['tool' => 'end', 'pid' => getmypid(), 'ended' => microtime(true)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
});

if (in_array("{$leg}/{$command}", $scenario['composer_hold'] ?? [], true)) {
    $terminated = false;
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$terminated): void {
        $terminated = true;
    });
    touch($scenario['calls'].'.composer-held');
    $deadline = microtime(true) + 60;

    while (! $terminated && microtime(true) < $deadline) {
        usleep(10_000);
    }

    exit($terminated ? (int) ($scenario['composer_hold_exit'] ?? 143) : 1);
}

$exit = $scenario['composer_exit']["{$leg}/{$command}"] ?? 0;

if ($command === 'update' && $exit === 0) {
    $lock = $scenario['locks'][$leg] ?? $scenario['locks']['*'];
    file_put_contents(getcwd().'/composer.lock', json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

echo "fake composer {$command}\n";
exit($exit);
