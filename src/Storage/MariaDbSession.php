<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Database\MariaDbConnection;

/**
 * A package session on MariaDb: Laravel's connection class, built by
 * PackageSession itself, that reports its transaction round trips to the
 * test recorder and reconnects only after a connect that succeeded.
 */
final class MariaDbSession extends MariaDbConnection
{
    use ReconnectsOnlyAfterAConnect;
    use RecordsRoundTrips;
}
