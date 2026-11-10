<?php

namespace Justrau\Orrery\Storage;

use RuntimeException;

/**
 * The package refuses its mirror connection (D17): an explicit
 * orrery.database on the SQLite file of a connection that the package uses,
 * or a `file:` URI default connection without orrery.database. The package
 * writes nothing there. ErrorKinds treats it as a dependency error, so the
 * watcher stays degraded instead of retrying every observation, and
 * /api/health and orrery:install show the message.
 */
final class MirrorRefused extends RuntimeException
{
}
