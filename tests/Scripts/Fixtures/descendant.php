<?php

/*
 * A descendant of the PHPUnit stub. It stands for a queue worker that PHPUnit
 * started and did not stop. The stub starts it with proc_open, so it stays in
 * the process group of bin/test's child.
 *
 * On SIGTERM it writes descendant-<pid>.json into the directory of its last
 * argument (as PHPUnit's stand-in, bin/test puts --log-events-text first):
 * whether the SQLite directory of the run still existed at that moment. Then
 * it exits. Without a signal it lives for 60 seconds.
 */

declare(strict_types=1);

$out = (string) end($argv);
$sqlite = getenv('ORRERY_TEST_SQLITE_FILE');

pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use ($out, $sqlite): void {
    file_put_contents($out.'/descendant-'.getmypid().'.json', json_encode([
        'pid' => getmypid(),
        'sqlite_dir_existed' => is_string($sqlite) && $sqlite !== '' ? is_dir(dirname($sqlite)) : null,
    ]));

    exit(0);
});

touch($out.'/descendant-'.getmypid().'.ready');
$deadline = microtime(true) + 60;

while (microtime(true) < $deadline) {
    usleep(20_000);
}
