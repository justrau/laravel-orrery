<?php

/*
 * An observer outside every run and outside the helpers' process list. It
 * appends a line to <log> when <dir> is missing while a process that a stub
 * record (<records>/stub-*.json) names is still alive. So a run that removes
 * a directory before every process that can use it is gone leaves a trace,
 * even when that process never watches the directory itself.
 *
 * Usage: watcher.php <log> <dir> <records>
 *
 * It lives for 120 seconds, or until the test stops it.
 */

declare(strict_types=1);

[, $log, $dir, $records] = $argv;
$deadline = microtime(true) + 120;
$reported = [];

$alive = static function (int $pid): bool {
    if ($pid <= 1 || ! posix_kill($pid, 0)) {
        return false;
    }

    $state = trim((string) shell_exec('ps -o stat= -p '.$pid.' 2>/dev/null'));

    return $state !== '' && ! str_contains($state, 'Z');
};

while (microtime(true) < $deadline) {
    clearstatcache();

    if (! is_dir($dir)) {
        foreach (glob($records.'/stub-*.json') ?: [] as $file) {
            $pid = (int) (json_decode((string) file_get_contents($file), true)['pid'] ?? 0);

            if (! isset($reported[$pid]) && $alive($pid)) {
                file_put_contents($log, "{$dir} was removed while process {$pid} was alive\n", FILE_APPEND);
                $reported[$pid] = true;
            }
        }
    }

    usleep(10_000);
}
