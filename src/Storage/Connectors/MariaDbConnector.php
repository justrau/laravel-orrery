<?php

namespace Justrau\Orrery\Storage\Connectors;

use Illuminate\Database\Connectors\MariaDbConnector as LaravelConnector;

/**
 * Laravel's MariaDB connector for a package session, which connects once
 * (ConnectsOnce, SessionConnectionFactory).
 */
final class MariaDbConnector extends LaravelConnector
{
    use ConnectsOnce;
}
