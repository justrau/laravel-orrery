<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Database\SQLiteConnection;

/**
 * A package session on SQLite: Laravel's connection class, built by
 * PackageSession itself, that reports its transaction round trips to the
 * test recorder.
 */
final class SQLiteSession extends SQLiteConnection
{
    use RecordsRoundTrips;
}
