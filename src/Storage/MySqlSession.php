<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Database\MySqlConnection;

/**
 * A package session on MySql: Laravel's connection class, built by
 * PackageSession itself, that reports its transaction round trips to the
 * test recorder and reconnects only after a connect that succeeded.
 */
final class MySqlSession extends MySqlConnection
{
    use ReconnectsOnlyAfterAConnect;
    use RecordsRoundTrips;
}
