<?php

namespace Justrau\Orrery\Storage\Connectors;

use Illuminate\Database\Connectors\PostgresConnector as LaravelConnector;

/**
 * Laravel's Postgres connector for a package session, which connects once
 * (ConnectsOnce, SessionConnectionFactory).
 */
final class PostgresConnector extends LaravelConnector
{
    use ConnectsOnce;
}
