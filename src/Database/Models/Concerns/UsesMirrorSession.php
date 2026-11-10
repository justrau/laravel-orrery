<?php

namespace Justrau\Orrery\Database\Models\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\PackageSession;

/**
 * A package model reads and writes its table only on the package session:
 * the mirror session (PackageSession::mirror(),
 * or the configured connection in-memory SQLite in tests). A package session
 * is not a connection of Laravel's DatabaseManager, so the model takes the
 * Connection object itself, not only its name.
 */
trait UsesMirrorSession
{
    public function getConnectionName(): ?string
    {
        return (string) Container::getInstance()->make(PackageSession::class)->mirror()->getName();
    }

    /**
     * @return Connection
     */
    public function getConnection()
    {
        return Container::getInstance()->make(PackageSession::class)->mirror();
    }
}
