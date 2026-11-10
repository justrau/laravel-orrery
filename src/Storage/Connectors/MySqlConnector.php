<?php

namespace Justrau\Orrery\Storage\Connectors;

use Illuminate\Database\Connectors\MySqlConnector as LaravelConnector;

/**
 * Laravel's MySQL connector for a package session, which connects once
 * (ConnectsOnce, SessionConnectionFactory).
 */
final class MySqlConnector extends LaravelConnector
{
    use ConnectsOnce;
}
