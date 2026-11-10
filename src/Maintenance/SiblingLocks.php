<?php

namespace Justrau\Orrery\Maintenance;

/**
 * The schedule locks that this process holds (SiblingFileMutex): process
 * state, a container singleton. PHP closes a lock file, and
 * so releases its lock, when the last reference to its handle goes.
 */
final class SiblingLocks
{
    /** @var array<string, resource> the held locks, by lock file */
    public array $held = [];
}
