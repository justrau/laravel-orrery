<?php

namespace Justrau\Orrery\Storage;

use RuntimeException;

/**
 * The sibling SQLite mirror file cannot be created, or its schema
 * cannot be used. ErrorKinds treats it as a dependency error, so the
 * breaker opens instead of every observation retrying.
 */
final class SiblingFileUnavailable extends RuntimeException
{
    public function __construct(public readonly string $path, string $reason)
    {
        parent::__construct("Orrery cannot use its SQLite mirror file {$path}: {$reason}.");
    }
}
