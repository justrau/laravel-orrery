<?php

namespace Justrau\Orrery\Maintenance;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;

/**
 * The overlap mutex of a package schedule event when the scheduler's cache
 * store writes to a host SQLite file (D17): a flock() on a lock file of
 * its own (`<file>.orrery-tick.lock` next to the mirror's SQLite file, else
 * next to the host file that made the store unsafe), never a cache write.
 * The lock file is created group-writable. The lock ends with its process,
 * so a crashed tick never blocks the next one. That setup is single-server,
 * so the event has no onOneServer().
 *
 * A lock file that cannot be opened is reported (Watcher::report() and the
 * health events), never a silent skip: the event does not run.
 */
final class SiblingFileMutex implements EventMutex
{
    public const OPERATION = 'schedule:mutex';

    public function __construct(public readonly string $path)
    {
    }

    /**
     * The lock file of a schedule event next to a SQLite file.
     */
    public static function pathFor(string $file, string $event): string
    {
        return $file.'.orrery-'.$event.'.lock';
    }

    public function create(Event $event): bool
    {
        if (isset(self::locks()->held[$this->path])) {
            return false;
        }

        $handle = $this->open();

        if ($handle === null) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        self::locks()->held[$this->path] = $handle;

        return true;
    }

    public function exists(Event $event): bool
    {
        if (isset(self::locks()->held[$this->path])) {
            return true;
        }

        $handle = $this->open();

        if ($handle === null) {
            // The event does not run; open() reported why.
            return true;
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }

            flock($handle, LOCK_UN);

            return false;
        } finally {
            fclose($handle);
        }
    }

    public function forget(Event $event): void
    {
        $handle = self::locks()->held[$this->path] ?? null;
        unset(self::locks()->held[$this->path]);

        if ($handle !== null) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource|null
     */
    private function open()
    {
        $created = ! is_file($this->path);
        $handle = @fopen($this->path, 'c');

        if ($handle === false) {
            $problem = "The schedule lock file {$this->path} cannot be opened, so the package event does not run.";
            Degradation::error(self::OPERATION, $problem);
            Watcher::instance()->report(self::OPERATION, $problem);

            return null;
        }

        if ($created) {
            @chmod($this->path, 0664);
        }

        return $handle;
    }

    /**
     * The locks of this process (a container singleton).
     */
    private static function locks(): SiblingLocks
    {
        return Container::getInstance()->make(SiblingLocks::class);
    }
}
