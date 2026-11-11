<?php

/*
 * A descendant of the PHPUnit stub. It stands for a queue worker that PHPUnit
 * started and did not stop. The stub starts it with proc_open, so it stays in
 * the process group of bin/test's child.
 *
 * On SIGTERM it writes descendant-<pid>.json into the directory of its last
 * argument (as PHPUnit's stand-in, bin/test puts --log-events-text first):
 * whether the SQLite directory of the run still existed at that moment. Then
 * it exits. Without a signal it lives for 60 seconds, or, with
 * ORRERY_STUB_LIVE_WHILE=<pid>, while process <pid> (the test) lives: then no
 * lifetime can run out before the test stops it on a slow machine.
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
$holder = (int) getenv('ORRERY_STUB_LIVE_WHILE');
$deadline = microtime(true) + 60;

while ($holder > 0 ? posix_kill($holder, 0) : microtime(true) < $deadline) {
    usleep(20_000);
}
