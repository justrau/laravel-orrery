<?php

namespace Justrau\Orrery\Control;

/**
 * The worker's signal cache, process state: a container
 * singleton. SignalState reads and writes it, so every
 * SignalState object of the application shares one cache, and a new
 * application starts with an empty one.
 */
final class SignalCache
{
    /**
     * The rows of the last successful read (plain arrays), or [] when the
     * worker failed open.
     *
     * @var list<array{signal: string, scope: string, scope_value: string, issued_at: float, expires_at: ?int}>
     */
    public array $rows = [];

    /** The watcher's monotonic time of the last read attempt; 0.0 before the first. */
    public float $refreshedAt = 0.0;

    /** The watcher's monotonic time of the last successful read, or null. */
    public ?float $lastReadAt = null;

    /** The worker failed open after the stale window (reported once). */
    public bool $failedOpen = false;

    /**
     * The calibration of this process: the database clock minus the
     * local clock, or null before a successful calibration. It is kept for
     * the process (until SignalState::flushCache()).
     */
    public ?float $clockOffset = null;

    /** The $refreshedAt of the last failed calibration: one attempt per signal read. */
    public ?float $clockFailedAt = null;
}
