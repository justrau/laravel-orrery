<?php

/*
 * A process that leads its own process group, like the PHPUnit child of
 * bin/test. The tests of owner records and liveness record it as a group entry.
 *
 * argv[1]: a directory that gets group-<pid>.ready once the group exists.
 * - ORRERY_GROUP_IGNORE_TERM=1 ignores SIGTERM, so only SIGKILL ends it.
 * - ORRERY_GROUP_RECORD_TERM=1: on SIGTERM it writes term-<pid> into the
 *   directory, then exits.
 * - ORRERY_GROUP_WATCH=<dir> with ORRERY_GROUP_WATCH_LOG=<file>: it appends a
 *   line to the log if <dir> disappears while it is alive.
 * - ORRERY_GROUP_MEMBER=1: the leader first starts a member of its group (this
 *   script with the argument "member") and writes the member's PID into
 *   group-<pid>.ready. The member inherits the environment, so it carries the
 *   same ORRERY_GROUP_TOKEN, and the options above.
 * - ORRERY_GROUP_LEADER_EXIT=<file>: the leader exits as soon as <file>
 *   exists, and the member stays in the group without its leader.
 * - ORRERY_GROUP_MEMBER_ARGUMENT=<text>: one more argument for the member.
 * - ORRERY_GROUP_MEMBER_TOKEN=<value>: the member's ORRERY_GROUP_TOKEN, and
 *   "-" removes it. The member's other variables are the leader's.
 * - ORRERY_GROUP_MEMBER_EXIT=<file>: the member exits as soon as <file>
 *   exists, as a short-lived process of the group (for example a ps child of
 *   bin/test's anchor) ends by itself.
 *
 * Each process lives for 60 seconds.
 */

declare(strict_types=1);

$dir = $argv[1];
$member = ($argv[2] ?? '') === 'member';

if (! $member) {
    posix_setpgid(0, 0);
}

pcntl_async_signals(true);

if (getenv('ORRERY_GROUP_IGNORE_TERM') === '1') {
    pcntl_signal(SIGTERM, SIG_IGN);
} elseif (getenv('ORRERY_GROUP_RECORD_TERM') === '1') {
    pcntl_signal(SIGTERM, static function () use ($dir): void {
        touch($dir.'/term-'.getmypid());

        exit(143);
    });
}

$ready = getmypid()."\n";

if ($member) {
    touch($dir.'/member-'.getmypid().'.ready');
} elseif (getenv('ORRERY_GROUP_MEMBER') === '1') {
    $env = getenv();
    unset($env['ORRERY_GROUP_MEMBER'], $env['ORRERY_GROUP_LEADER_EXIT']);
    $memberToken = getenv('ORRERY_GROUP_MEMBER_TOKEN');

    if ($memberToken === '-') {
        unset($env['ORRERY_GROUP_TOKEN']);
    } elseif (is_string($memberToken) && $memberToken !== '') {
        $env['ORRERY_GROUP_TOKEN'] = $memberToken;
    }

    $argument = getenv('ORRERY_GROUP_MEMBER_ARGUMENT');
    $command = [PHP_BINARY, __FILE__, $dir, 'member', ...(is_string($argument) && $argument !== '' ? [$argument] : [])];
    $process = proc_open($command, [], $pipes, null, $env);
    $pid = is_resource($process) ? proc_get_status($process)['pid'] : 0;
    $deadline = microtime(true) + 10;

    while (! file_exists($dir.'/member-'.$pid.'.ready') && microtime(true) < $deadline) {
        usleep(10_000);
        clearstatcache();
    }

    $ready = $pid."\n";
}

file_put_contents($dir.'/group-'.getmypid().'.ready.tmp', $ready);
rename($dir.'/group-'.getmypid().'.ready.tmp', $dir.'/group-'.getmypid().'.ready');

$watch = getenv('ORRERY_GROUP_WATCH');
$log = getenv('ORRERY_GROUP_WATCH_LOG');
$exit = $member ? getenv('ORRERY_GROUP_MEMBER_EXIT') : getenv('ORRERY_GROUP_LEADER_EXIT');
$seen = false;
$deadline = microtime(true) + 60;

while (microtime(true) < $deadline) {
    if (! $seen && is_string($watch) && $watch !== '' && is_string($log) && $log !== '' && ! is_dir($watch)) {
        file_put_contents($log, getmypid()." saw {$watch} removed while it was alive\n", FILE_APPEND);
        $seen = true;
    }

    if (is_string($exit) && $exit !== '' && file_exists($exit)) {
        exit(0);
    }

    usleep(10_000);
    clearstatcache();
}
